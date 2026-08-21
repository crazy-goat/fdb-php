<?php

declare(strict_types=1);

namespace CrazyGoat\FoundationDB\Tests\Integration;

use CrazyGoat\FoundationDB\Database;
use CrazyGoat\FoundationDB\FoundationDB;
use CrazyGoat\FoundationDB\Future\FutureVoid;
use CrazyGoat\FoundationDB\Snapshot;
use CrazyGoat\FoundationDB\Transaction;
use CrazyGoat\FoundationDB\TransactionRetryLimitExceededException;
use PHPUnit\Framework\Attributes\After;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Integration tests for the opt-in retry ceiling introduced to fix #52.
 *
 * The unit tests in `tests/Unit/TransactionRetryLimitTest.php` cover the
 * pure-PHP predicate (`Database::checkRetryLimit()`) and the configuration
 * setters. This file confirms the wired-up behaviour against a live
 * FoundationDB cluster using **real** transaction conflicts — never
 * synthetic exceptions, because a fabricated `FDBException` fed to the
 * native `fdb_transaction_on_error()` describes an error the transaction
 * never experienced and its future may never resolve.
 *
 * Conflict recipe (deterministic): inside the `transact()` callback, read
 * a key (establishing a read-conflict range), have an interferer
 * transaction commit a change to that key, then write the key and commit.
 * The interferer's commit lands between our read and our commit, so our
 * commit fails with the retryable `not_committed` (1020) error and the
 * loop retries through the real `on_error()` path. While the interferer
 * keeps firing, the loop can never succeed — so a configured attempt
 * ceiling produces `TransactionRetryLimitExceededException` after exactly
 * ceiling+1 callback invocations, and a wall-clock ceiling terminates the
 * loop regardless of the attempt count.
 */
final class TransactionRetryLimitTest extends TestCase
{
    use DatabaseCleanupTrait;

    private const KEY = 'retry-limit-probe-key';

    #[After]
    protected function resetRetryConfig(): void
    {
        FoundationDB::reset();
    }

    /**
     * Invoke `$callable` and return the thrown exception (if any).
     * Returns `null` if the callable completed without throwing. We
     * avoid the idiomatic `try { call; fail(); } catch (...) { ... }`
     * pattern because its `never`-returning `fail()` makes the
     * post-call assertions unreachable, which trips PHPStan.
     *
     * @template T
     *
     * @param callable(): T $callable
     *
     * @return array{0: T|null, 1: \Throwable|null}
     */
    private function capture(callable $callable): array
    {
        try {
            $result = $callable();
        } catch (\Throwable $e) {
            return [null, $e];
        }

        return [$result, null];
    }

    /**
     * Commit a change to the probe key from an independent transaction,
     * invalidating any read conflict range established before this call.
     */
    private function interfere(Database $db, int $round): void
    {
        $interferer = $db->createTransaction();
        $interferer->set(self::KEY, 'interference-' . $round);
        $interferer->commit()->await();
    }

    #[Test]
    public function transactThrowsRetryLimitExceededExceptionWhenCeilingIsExhausted(): void
    {
        $db = $this->getDatabase();

        FoundationDB::defaultTransactionRetryLimit(2);

        try {
            [, $exception] = $this->capture(fn (): mixed => $db->transact(
                function (Transaction $tr) use ($db): string {
                    static $round = 0;
                    ++$round;

                    // Read establishes the conflict range; the interferer's
                    // commit invalidates it before our commit below.
                    $tr->get(self::KEY)->await();

                    if ($round <= 10) {
                        $this->interfere($db, $round);
                    }

                    $tr->set(self::KEY, 'attempt-' . $round);

                    return 'committed';
                },
            ));

            self::assertInstanceOf(
                TransactionRetryLimitExceededException::class,
                $exception,
                'Expected the attempt ceiling to abort the conflicting loop',
            );
            // Retries 1 and 2 are on the boundary (limit 2); the third
            // retry exceeds it.
            self::assertSame(3, $exception->attempts);
            self::assertGreaterThanOrEqual(0.0, $exception->elapsedSeconds);
        } finally {
            $db->clear(self::KEY);
        }
    }

    #[Test]
    public function transactWithDefaultCeilingRecoversWhenConflictsStop(): void
    {
        $db = $this->getDatabase();

        // No ceiling configured (the default): the loop must behave exactly
        // like the historical unbounded loop — retry through real conflicts
        // and succeed once they stop, without any library-level exception.
        try {
            $result = $db->transact(
                function (Transaction $tr) use ($db): string {
                    static $round = 0;
                    ++$round;

                    $tr->get(self::KEY)->await();

                    if ($round <= 3) {
                        $this->interfere($db, $round);
                    }

                    $tr->set(self::KEY, 'final-value');

                    return 'committed';
                },
            );

            self::assertSame('committed', $result);
            self::assertSame('final-value', $db->get(self::KEY));
        } finally {
            $db->clear(self::KEY);
        }
    }

    #[Test]
    public function wallClockTimeoutTerminatesLoopRegardlessOfAttemptCount(): void
    {
        $db = $this->getDatabase();

        // Attempt ceiling left unbounded; only the wall-clock budget is
        // configured, so the timeout is the only way out while conflicts
        // keep coming.
        FoundationDB::defaultTransactionTimeoutSeconds(0.05);

        try {
            [, $exception] = $this->capture(fn (): mixed => $db->transact(
                function (Transaction $tr) use ($db): string {
                    static $round = 0;
                    ++$round;

                    $tr->get(self::KEY)->await();
                    $this->interfere($db, $round);
                    $tr->set(self::KEY, 'attempt-' . $round);

                    return 'committed';
                },
            ));

            self::assertInstanceOf(
                TransactionRetryLimitExceededException::class,
                $exception,
                'Expected the wall-clock ceiling to abort the conflicting loop',
            );
            self::assertGreaterThan(0, $exception->attempts, 'The loop must have retried at least once');
            self::assertGreaterThan(0.0, $exception->elapsedSeconds);
        } finally {
            $db->clear(self::KEY);
        }
    }

    #[Test]
    public function readTransactWorksWithACeilingConfigured(): void
    {
        $db = $this->getDatabase();

        FoundationDB::defaultTransactionRetryLimit(5);

        // Snapshot reads cannot conflict, so a clean read proves the
        // readTransact path is wired through the bounded loop without
        // changing its behaviour.
        $value = $db->readTransact(static fn (Snapshot $snap): ?string => $snap->get(self::KEY)->await());

        self::assertNull($value);
    }

    #[Test]
    public function watchWorksWithACeilingConfigured(): void
    {
        $db = $this->getDatabase();

        FoundationDB::defaultTransactionRetryLimit(5);

        $future = $db->watch(self::KEY);

        self::assertInstanceOf(FutureVoid::class, $future);

        $future->cancel();
    }
}
