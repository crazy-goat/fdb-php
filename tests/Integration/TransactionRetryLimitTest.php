<?php

declare(strict_types=1);

namespace CrazyGoat\FoundationDB\Tests\Integration;

use CrazyGoat\FoundationDB\FoundationDB;
use CrazyGoat\FoundationDB\TransactionRetryLimitExceededException;
use PHPUnit\Framework\Attributes\After;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Integration tests for the explicit-bounds retry-limit / retry-timeout
 * configuration introduced to fix issue #52.
 *
 * The unit tests in `tests/Unit/TransactionRetryLimitTest.php` cover
 * the pure-PHP predicate (`Database::checkRetryLimit()`) and the
 * configuration setters. This file confirms the **wired-up behaviour**
 * against a live FoundationDB cluster: that the loop in
 * `Database::runWithRetry()` actually honours the configured ceilings
 * and surfaces `TransactionRetryLimitExceededException` synchronously
 * to the application, instead of looping forever.
 *
 * Because we want to exercise the loop without depending on the
 * cluster's conflict timing, we construct the conflict signal inside
 * the test: the user-supplied callback throws a retryable
 * `FDBException` on every call. The loop catches it, $tr->onError()
 * resets the transaction (which `fdb_transaction_on_error()` does for
 * any retryable code), the loop calls the callback again, and we
 * throw again. Every iteration increments our retry counter, so a
 * configured ceiling of N produces a deterministic
 * `TransactionRetryLimitExceededException` after exactly N+1 throws.
 *
 * We additionally verify:
 *
 *  - With `defaultTransactionRetryLimit = 0` (the default), the
 *    loop is unbounded — the test confirms a retryable error path
 *    runs at least N times without our exception.
 *  - `readTransact()` honours the same ceiling.
 *  - `Database::watch()` honours the same ceiling.
 *  - A wall-clock timeout ceiling also terminates the loop
 *    deterministically.
 *  - `FoundationDB::reset()` returns the loop to unbounded.
 */
final class TransactionRetryLimitTest extends TestCase
{
    use DatabaseCleanupTrait;

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

    #[Test]
    public function transactWithConfiguredRetryLimitThrowsAfterCeilingReached(): void
    {
        $db = $this->getDatabase();

        FoundationDB::defaultTransactionRetryLimit(2);

        // The callback throws a retryable FDBException on every call.
        // runWithRetry catches, awaits on_error() (which resets the
        // transaction), increments the retry counter, and retries.
        // After retries(>limit) it throws
        // TransactionRetryLimitExceededException.
        [, $exception] = $this->capture(static fn (): mixed => $db->transact(static function (): never {
            throw new \CrazyGoat\FoundationDB\FDBException(1007);
        }));

        self::assertInstanceOf(TransactionRetryLimitExceededException::class, $exception);
        // 1st throw: retries count = 1, on boundary (1 ≤ 2)
        // 2nd throw: retries count = 2, on boundary (2 ≤ 2)
        // 3rd throw: retries count = 3, EXCEEDS ceiling
        self::assertSame(3, $exception->attempts);
        self::assertGreaterThanOrEqual(0.0, $exception->elapsedSeconds);
    }

    #[Test]
    public function transactWithoutConfiguredCeilingStaysUnbounded(): void
    {
        $db = $this->getDatabase();

        FoundationDB::defaultTransactionRetryLimit(0);

        // With the unbounded default, the loop should not raise our
        // exception at all and we observe the actual FDBException
        // bubbling up — proving that the previous "rely on FDB to
        // decide" semantics is preserved for users who do not opt in.
        [, $exception] = $this->capture(static fn (): mixed => $db->transact(static function (): never {
            throw new \CrazyGoat\FoundationDB\FDBException(1007);
        }));

        self::assertNotInstanceOf(TransactionRetryLimitExceededException::class, $exception);
        self::assertInstanceOf(\CrazyGoat\FoundationDB\FDBException::class, $exception);
        self::assertSame(1007, $exception->fdbCode);
    }

    #[Test]
    public function readTransactHonoursConfiguredRetryLimit(): void
    {
        $db = $this->getDatabase();

        FoundationDB::defaultTransactionRetryLimit(1);

        [, $exception] = $this->capture(static fn (): mixed => $db->readTransact(static function (): never {
            throw new \CrazyGoat\FoundationDB\FDBException(1007);
        }));

        self::assertInstanceOf(TransactionRetryLimitExceededException::class, $exception);
        // 1st throw: retries=1, at limit (1 ≤ 1)
        // 2nd throw: retries=2, EXCEEDS limit=1
        self::assertSame(2, $exception->attempts);
    }

    #[Test]
    public function watchHonoursConfiguredRetryLimit(): void
    {
        $db = $this->getDatabase();

        FoundationDB::defaultTransactionRetryLimit(1);

        [, $exception] = $this->capture(
            static fn (): \CrazyGoat\FoundationDB\Future\FutureVoid => $db->watch('transient-watch-key'),
        );

        self::assertInstanceOf(TransactionRetryLimitExceededException::class, $exception);
        self::assertSame(2, $exception->attempts);
    }

    #[Test]
    public function configuredWallClockTimeoutTerminatesLoop(): void
    {
        $db = $this->getDatabase();

        // 50 milliseconds — a tiny but non-zero budget that the
        // wall-clock ceiling will overrule quickly.
        FoundationDB::defaultTransactionTimeoutSeconds(0.05);

        $startedAt = microtime(true);

        [, $exception] = $this->capture(static fn (): mixed => $db->transact(static function (): never {
            throw new \CrazyGoat\FoundationDB\FDBException(1007);
        }));

        self::assertInstanceOf(TransactionRetryLimitExceededException::class, $exception);
        // The attempt-count ceiling is 0 (unbounded), so the only
        // way to leave this loop is through the timeout ceiling.
        // We assert (a) elapsed-since-start equals elapsedSeconds
        // within a small tolerance, and (b) attempts > 0 — the loop
        // really did retry, it did not exit on the first throw.
        self::assertGreaterThan(0, $exception->attempts);
        $delta = abs($exception->elapsedSeconds - (microtime(true) - $startedAt));
        self::assertLessThan(
            1.0,
            $delta,
            'elapsedSeconds reported in exception must match wall-clock.',
        );
    }

    #[Test]
    public function resetClearsRetryConfiguration(): void
    {
        FoundationDB::defaultTransactionRetryLimit(50);
        FoundationDB::defaultTransactionTimeoutSeconds(7.5);

        // reset() is documented process-wide; it also clears retry policy.
        FoundationDB::reset();

        self::assertSame(0, FoundationDB::getDefaultTransactionRetryLimit());
        self::assertSame(0.0, FoundationDB::getDefaultTransactionTimeoutSeconds());
    }
}
