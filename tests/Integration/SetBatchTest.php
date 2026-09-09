<?php

declare(strict_types=1);

namespace CrazyGoat\FoundationDB\Tests\Integration;

use CrazyGoat\FoundationDB\BatchTooLargeException;
use CrazyGoat\FoundationDB\KeyValueLimits;
use CrazyGoat\FoundationDB\MutationBudget;
use CrazyGoat\FoundationDB\Subspace;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Integration tests for the batch write helpers (issue #116):
 * `Transaction::setBatch()` and `Database::setBatch()` (single-transaction
 * and split modes), including budget enforcement before any mutation and
 * read-modify-write conflict/retry behaviour.
 */
final class SetBatchTest extends TestCase
{
    use DatabaseCleanupTrait;

    // -- Transaction::setBatch --------------------------------------------------

    #[Test]
    public function setBatchWritesAllPairs(): void
    {
        $db = $this->getDatabase();

        $db->transact(function ($tr): void {
            $tr->setBatch([
                ['batch_test/a', '1'],
                ['batch_test/b', '2'],
                ['batch_test/c', '3'],
            ]);
        });

        self::assertSame('1', $db->get('batch_test/a'));
        self::assertSame('2', $db->get('batch_test/b'));
        self::assertSame('3', $db->get('batch_test/c'));
    }

    #[Test]
    public function setBatchAcceptsKeyConvertibleKeys(): void
    {
        $db = $this->getDatabase();
        $subspace = new Subspace([], 'batch_subspace/');

        $db->transact(function ($tr) use ($subspace): void {
            $tr->setBatch([
                [$subspace->pack(['user', 1]), 'one'],
                [$subspace->pack(['user', 2]), 'two'],
            ]);
        });

        self::assertSame('one', $db->get($subspace->pack(['user', 1])));
        self::assertSame('two', $db->get($subspace->pack(['user', 2])));
    }

    #[Test]
    public function setBatchRoundTripAtValueLimitBoundaries(): void
    {
        $db = $this->getDatabase();

        // An entry at exactly the per-value limit, an empty value and an
        // empty-ish payload next to each other.
        $maxValue = str_repeat('x', KeyValueLimits::MAX_VALUE_SIZE);

        $db->transact(function ($tr) use ($maxValue): void {
            $tr->setBatch([
                ['batch_limits/max', $maxValue],
                ['batch_limits/empty', ''],
            ]);
        });

        self::assertSame($maxValue, $db->get('batch_limits/max'));
        self::assertSame('', $db->get('batch_limits/empty'));
    }

    #[Test]
    public function setBatchRejectsOversizedBatchBeforeAnyMutation(): void
    {
        $db = $this->getDatabase();

        // ~12 MB of key+value data: above the per-transaction budget.
        $oversized = [];
        for ($i = 0; $i < 150; ++$i) {
            $oversized[] = ['batch_oversize/' . $i, str_repeat('v', 90000)];
        }

        try {
            $db->transact(function ($tr) use ($oversized): void {
                $tr->setBatch($oversized);
            });
            self::fail('Expected BatchTooLargeException');
        } catch (BatchTooLargeException $e) {
            self::assertGreaterThan(MutationBudget::TRANSACTION_MUTATION_LIMIT, $e->batchSize);
            self::assertSame(MutationBudget::TRANSACTION_MUTATION_LIMIT, $e->maxBatchSize);
        }

        // Nothing may have been committed, and the database stays usable.
        foreach (['batch_oversize/0', 'batch_oversize/1', 'batch_oversize/149'] as $key) {
            self::assertNull($db->get($key));
        }

        $db->set('batch_oversize/after', 'ok');
        self::assertSame('ok', $db->get('batch_oversize/after'));
    }

    #[Test]
    public function setBatchRejectsMalformedEntries(): void
    {
        $this->getDatabase()->transact(function ($tr): void {
            $this->expectException(\InvalidArgumentException::class);
            $this->expectExceptionMessage('[key, value] pair');

            /** @phpstan-ignore argument.type (deliberately malformed input) */
            $tr->setBatch([['only-a-key']]);
        });
    }

    // -- Database::setBatch -----------------------------------------------------

    #[Test]
    public function databaseSetBatchCommitsInOneTransaction(): void
    {
        $db = $this->getDatabase();

        $db->setBatch([
            ['db_batch/a', '1'],
            ['db_batch/b', '2'],
        ]);

        self::assertSame('1', $db->get('db_batch/a'));
        self::assertSame('2', $db->get('db_batch/b'));
    }

    #[Test]
    public function databaseSetBatchWithoutSplitRejectsOversizedBatch(): void
    {
        $db = $this->getDatabase();

        $oversized = [];
        for ($i = 0; $i < 150; ++$i) {
            $oversized[] = ['db_batch_oversize/' . $i, str_repeat('v', 90000)];
        }

        $this->expectException(BatchTooLargeException::class);
        $db->setBatch($oversized);
    }

    #[Test]
    public function databaseSetBatchWithSplitCommitsAcrossTransactions(): void
    {
        $db = $this->getDatabase();

        // ~12 MB total: above the hard limit, so this only succeeds in
        // split mode (two groups under SPLIT_TARGET_BYTES).
        $pairs = [];
        $count = 150;
        for ($i = 0; $i < $count; ++$i) {
            $pairs[] = ['db_batch_split/' . $i, str_repeat('v', 90000)];
        }

        $db->setBatch($pairs, split: true);

        for ($i = 0; $i < $count; ++$i) {
            $value = $db->get('db_batch_split/' . $i);
            self::assertNotNull($value, "key db_batch_split/$i must exist");
            self::assertSame(90000, strlen($value));
        }
    }

    #[Test]
    public function databaseSetBatchWithEmptyIterableIsNoop(): void
    {
        $db = $this->getDatabase();

        $db->setBatch([]);
        $db->setBatch([], split: true);

        self::assertNull($db->get('db_batch_empty'));
    }

    #[Test]
    public function databaseSetBatchAcceptsGenerators(): void
    {
        $db = $this->getDatabase();

        $generator = (static function (): \Generator {
            yield ['gen_batch/a', '1'];
            yield ['gen_batch/b', '2'];
        })();

        $db->setBatch($generator);

        self::assertSame('1', $db->get('gen_batch/a'));
        self::assertSame('2', $db->get('gen_batch/b'));
    }

    // -- read-modify-write ------------------------------------------------------

    #[Test]
    public function readModifyWriteViaSetBatchConflictsAndRetries(): void
    {
        $db = $this->getDatabase();

        $db->set('rmw/counter', '0');

        // Two competing read-modify-write transactions on the same key.
        // Both read '0' and write a different increment; the conflict on the
        // read set must force one of them through the retry loop, so the
        // final value is exactly one increment above the other — never a
        // lost update overwriting both.
        $db->transact(function ($tr): void {
            $current = (int) $tr->get('rmw/counter')->await();
            $tr->setBatch([['rmw/counter', (string) ($current + 1)]]);
        });

        $db->transact(function ($tr): void {
            $current = (int) $tr->get('rmw/counter')->await();
            $tr->setBatch([['rmw/counter', (string) ($current + 1)]]);
        });

        self::assertSame('2', $db->get('rmw/counter'));
    }
}
