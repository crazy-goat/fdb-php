<?php

declare(strict_types=1);

namespace CrazyGoat\FoundationDB\Tests\Integration;

use CrazyGoat\FoundationDB\Directory\DirectoryLayer;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Functional tests for native transaction handle lifetime (#38).
 *
 * Transaction::snapshot() used to cache the Snapshot on the Transaction,
 * closing a reference cycle (Transaction -> Snapshot -> Transaction) that
 * deferred fdb_transaction_destroy() to the cycle collector. A long-running
 * worker therefore accumulated undestroyed native transaction handles —
 * instantly visible as cyclic garbage that only gc_collect_cycles() could
 * reclaim.
 *
 * These tests assert the handles are released deterministically: weak
 * references die without running the collector, and directory creation
 * (which allocates through HighContentionAllocator, a heavy snapshot()
 * user) leaves no cyclic garbage behind.
 */
final class TransactionLifecycleTest extends TestCase
{
    use DatabaseCleanupTrait;

    #[Test]
    public function transactionsWithSnapshotsAreDestroyedWithoutCycleCollector(): void
    {
        $db = $this->getDatabase();

        // Remove any pre-existing cyclic garbage so the assertions below
        // measure only what this loop leaves behind.
        gc_collect_cycles();

        $weakTransactions = [];

        for ($i = 0; $i < 100; $i++) {
            $transaction = $db->createTransaction();
            $snapshot = $transaction->snapshot();

            // Exercise a real read through the snapshot handle.
            $snapshot->getReadVersion()->await();

            $weakTransactions[] = \WeakReference::create($transaction);
            unset($transaction, $snapshot);
        }

        foreach ($weakTransactions as $i => $weakTransaction) {
            self::assertNull(
                $weakTransaction->get(),
                sprintf(
                    'Transaction #%d was not destroyed deterministically — reference cycle is present',
                    $i,
                ),
            );
        }
    }

    #[Test]
    public function directoryCreationDoesNotLeaveCyclicGarbage(): void
    {
        $db = $this->getDatabase();
        $directoryLayer = new DirectoryLayer();
        $root = 'lifecycle-' . bin2hex(random_bytes(4));

        try {
            gc_collect_cycles();

            for ($i = 0; $i < 30; $i++) {
                $directoryLayer->createOrOpen($db, [$root, 'dir' . $i]);
            }

            self::assertSame(
                0,
                gc_collect_cycles(),
                'Directory creation leaked cyclic Transaction/Snapshot pairs',
            );
        } finally {
            $directoryLayer->removeIfExists($db, [$root]);
        }
    }
}
