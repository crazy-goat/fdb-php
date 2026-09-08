<?php

declare(strict_types=1);

namespace CrazyGoat\FoundationDB\Tests\Integration;

use CrazyGoat\FoundationDB\Transaction;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Functional coverage for Transaction::getConflictingKeyRanges() (issue #95).
 *
 * Forces a write conflict between two transactions and asserts that the
 * conflicting range reported by the server matches the keys that both
 * transactions touched.
 */
final class ConflictingKeysTest extends TestCase
{
    use DatabaseCleanupTrait;

    #[Test]
    public function conflictingKeyRangesThrowsWithoutReportOption(): void
    {
        $db = $this->getDatabase();
        $tr = $db->createTransaction();

        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('setReportConflictingKeys');
        $tr->getConflictingKeyRanges();
    }

    #[Test]
    public function reportsConflictingRangesAfterNotCommitted(): void
    {
        $db = $this->getDatabase();
        $key = 'test/conflict/key';

        $db->transact(static function (Transaction $tr) use ($key): void {
            $tr->set($key, 'initial');
        });

        // Transaction A: reads the key, will try to write it back.
        $trA = $db->createTransaction();
        $trA->options()->setReportConflictingKeys();
        $read = $trA->get($key)->await();

        // Transaction B commits a conflicting write, invalidating A's read.
        $db->transact(static function (Transaction $tr) use ($key): void {
            $tr->set($key, 'bumped');
        });

        // Transaction A now writes and fails to commit with not_committed (1020).
        $trA->set($key, ($read ?? '') . '-a');
        try {
            $trA->commit()->await();
            self::fail('Expected transaction A to fail with a conflict');
        } catch (\CrazyGoat\FoundationDB\FDBException $e) {
            self::assertSame(1020, $e->fdbCode);
        }

        $ranges = $trA->getConflictingKeyRanges();
        self::assertNotEmpty($ranges);

        $covered = false;
        foreach ($ranges as $range) {
            if ($range['begin'] <= $key && $key < $range['end']) {
                $covered = true;
            }
        }
        self::assertTrue($covered, sprintf(
            'Expected one of the reported ranges [%s] to cover key "%s"',
            json_encode($ranges),
            $key,
        ));

        // Raw rows: keys are stripped of the special-key-space prefix, values
        // are start/end markers.
        foreach ($trA->getConflictingKeys() as $row) {
            self::assertStringNotContainsString("\xff\xff/transaction/conflicting_keys/", $row->key);
            self::assertContains($row->value, ['1', '0']);
        }
    }
}
