<?php

declare(strict_types=1);

namespace CrazyGoat\FoundationDB\Tests\Unit;

use CrazyGoat\FoundationDB\BatchTooLargeException;
use CrazyGoat\FoundationDB\KeyValueLimits;
use CrazyGoat\FoundationDB\MutationBudget;
use CrazyGoat\FoundationDB\Subspace;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Pure unit tests for the mutation-budget accounting shared by the batch
 * write helpers (issue #116). The type is pure byte math — no FoundationDB
 * cluster or native client is required; batch behaviour against a live
 * cluster is covered by tests/Integration/SetBatchTest.php.
 */
final class MutationBudgetTest extends TestCase
{
    // -- assertWithinTransactionLimit ------------------------------------------

    #[Test]
    public function limitAcceptsExactlyTheFdbBudget(): void
    {
        MutationBudget::assertWithinTransactionLimit(MutationBudget::TRANSACTION_MUTATION_LIMIT);

        self::addToAssertionCount(1); // no exception
    }

    #[Test]
    public function limitRejectsOneByteOverTheFdbBudget(): void
    {
        try {
            MutationBudget::assertWithinTransactionLimit(MutationBudget::TRANSACTION_MUTATION_LIMIT + 1);
            self::fail('Expected BatchTooLargeException');
        } catch (BatchTooLargeException $e) {
            self::assertSame(MutationBudget::TRANSACTION_MUTATION_LIMIT + 1, $e->batchSize);
            self::assertSame(MutationBudget::TRANSACTION_MUTATION_LIMIT, $e->maxBatchSize);
            self::assertStringContainsString('mutation budget', $e->getMessage());
            self::assertStringContainsString('Database::setBatch(..., split: true)', $e->getMessage());
        }
    }

    // -- entrySize --------------------------------------------------------------

    #[Test]
    public function entrySizeSumsKeyAndValueBytes(): void
    {
        self::assertSame(12, MutationBudget::entrySize(['123456', 'abcdef']));
    }

    #[Test]
    public function entrySizeMeasuresPlainStringKeys(): void
    {
        self::assertSame(9, MutationBudget::entrySize(['key123', 'val']));
        self::assertSame(0, MutationBudget::entrySize(['', '']));
        self::assertSame(KeyValueLimits::MAX_KEY_SIZE + 5, MutationBudget::entrySize([
            str_repeat('k', KeyValueLimits::MAX_KEY_SIZE),
            '12345',
        ]));
    }

    #[Test]
    public function entrySizeMeasuresResolvedKeyConvertibleKeys(): void
    {
        $subspace = new Subspace([], 'prefix/');
        $packed = $subspace->pack(['user', 42]);

        self::assertSame(
            strlen($packed) + strlen('value'),
            MutationBudget::entrySize([$subspace->pack(['user', 42]), 'value']),
        );
    }

    // -- constants ---------------------------------------------------------------

    #[Test]
    public function splitTargetIsSafelyBelowTheHardLimit(): void
    {
        // A split group may overshoot its target by at most one entry
        // (max key + max value), and must still stay under the hard limit.
        $worstCase = MutationBudget::SPLIT_TARGET_BYTES
            + KeyValueLimits::MAX_KEY_SIZE
            + KeyValueLimits::MAX_VALUE_SIZE;

        self::assertLessThan(MutationBudget::TRANSACTION_MUTATION_LIMIT, $worstCase);
    }
}
