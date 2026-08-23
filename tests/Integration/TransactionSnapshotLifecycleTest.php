<?php

declare(strict_types=1);

namespace CrazyGoat\FoundationDB\Tests\Integration;

use CrazyGoat\FoundationDB\Directory\DirectoryLayer;
use CrazyGoat\FoundationDB\Transaction;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Functional coverage for the Transaction <-> Snapshot lifetime fix (issue #38).
 *
 * The lifecycle itself is proven deterministically by
 * `tests/Unit/TransactionSnapshotLifecycleTest.php` against a stubbed native
 * library. What a live cluster adds here is confidence that returning a fresh
 * Snapshot per `snapshot()` call does not regress the read paths that consume
 * it: `Database::readTransact()`, snapshot reads inside `transact()`, and the
 * directory layer (whose HighContentionAllocator snapshots on every
 * create/open).
 *
 * A long-running worker that leaked native transaction handles before the fix
 * would accumulate them across iterations; these loops would surface the leak
 * as growing latency or fd exhaustion at sufficient scale.
 */
final class TransactionSnapshotLifecycleTest extends TestCase
{
    use DatabaseCleanupTrait;

    #[Test]
    public function readTransactLoopStaysHealthyAcrossManyIterations(): void
    {
        $db = $this->getDatabase();

        // Seed a small keyspace under test/snapshot-lifecycle/.
        $db->transact(static function (Transaction $tr): void {
            for ($i = 0; $i < 10; $i++) {
                $tr->set("test/snapshot-lifecycle/key$i", "value$i");
            }
        });

        // 200 short-lived read transactions through the snapshot path — the
        // shape of a polling worker. Before the fix each iteration cached a
        // cyclic Snapshot on its Transaction, deferring destruction to the
        // cycle collector.
        $deadline = microtime(true) + 30.0;
        for ($i = 0; $i < 200; $i++) {
            $value = $db->readTransact(
                static fn ($snapshot) => $snapshot->get("test/snapshot-lifecycle/key" . ($i % 10))->await(),
            );

            self::assertSame('value' . ($i % 10), $value);

            if (microtime(true) > $deadline) {
                self::fail('readTransact loop exceeded 30s — suspected handle accumulation');
            }
        }
    }

    #[Test]
    public function directoryCreateOpenLoopStaysHealthy(): void
    {
        $db = $this->getDatabase();
        $directoryLayer = new DirectoryLayer();

        $created = $directoryLayer->createOrOpen($db, ['snapshot-lifecycle']);
        self::assertSame(['snapshot-lifecycle'], $created->getPath());

        // Every createOrOpen runs HighContentionAllocator::allocate(), which
        // takes snapshot reads on short-lived transactions.
        $deadline = microtime(true) + 60.0;
        for ($i = 0; $i < 100; $i++) {
            $dir = $directoryLayer->createOrOpen($db, ['snapshot-lifecycle', "iter$i"]);
            self::assertSame(['snapshot-lifecycle', "iter$i"], $dir->getPath());

            if (microtime(true) > $deadline) {
                self::fail('directory loop exceeded 60s — suspected handle accumulation');
            }
        }
    }
}
