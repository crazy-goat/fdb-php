<?php

declare(strict_types=1);

namespace CrazyGoat\FoundationDB\Tests\Integration;

use PHPUnit\Framework\Attributes\RequiresPhpExtension;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Destructive/operational AdminClient tests for the direct C API bindings
 * added in issue #97: createSnapshot() and forceRecoveryWithDataLoss().
 *
 * Both operations are destructive and cluster-wide:
 *
 * - createSnapshot() requires snapshot support configured on the cluster.
 * - forceRecoveryWithDataLoss() abandons data written since the target
 *   datacenter's last usable state — data loss is in the name.
 *
 * They are therefore SKIPPED unless the environment variable
 * FDB_ENABLE_DESTRUCTIVE_ADMIN_TESTS is set to "1". CI does not set it;
 * run them manually against a disposable cluster:
 *
 *   FDB_ENABLE_DESTRUCTIVE_ADMIN_TESTS=1 \
 *     docker compose exec php vendor/bin/phpunit \
 *     --testsuite=Integration --filter AdminDestructiveOperationsTest
 */
#[RequiresPhpExtension('ffi')]
final class AdminDestructiveOperationsTest extends TestCase
{
    use DatabaseCleanupTrait;

    public static function setUpBeforeClass(): void
    {
        if (getenv('FDB_ENABLE_DESTRUCTIVE_ADMIN_TESTS') !== '1') {
            self::markTestSkipped(
                'Destructive admin tests disabled. Set FDB_ENABLE_DESTRUCTIVE_ADMIN_TESTS=1 '
                . 'to run them against a disposable cluster.',
            );
        }
    }

    #[Test]
    public function createSnapshotRejectsInvalidUidBeforeReachingFdb(): void
    {
        $admin = $this->getDatabase()->admin();

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('32 hexadecimal characters');

        $admin->createSnapshot('not-a-uid', 'start');
    }

    #[Test]
    public function createSnapshotRejectsNonPrintableCommand(): void
    {
        $admin = $this->getDatabase()->admin();

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('non-printable byte');

        $admin->createSnapshot(str_repeat('0', 32), "start\nrm -rf");
    }

    #[Test]
    public function forceRecoveryWithDataLossRejectsInvalidDcIdBeforeReachingFdb(): void
    {
        $admin = $this->getDatabase()->admin();

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('forceRecoveryWithDataLoss');

        $admin->forceRecoveryWithDataLoss('../primary');
    }

    #[Test]
    public function forceRecoveryWithDataLossRunsAgainstADisposableCluster(): void
    {
        // Only reached with the env flag set. Write a marker key first so an
        // operator running this against a live cluster sees the danger.
        $db = $this->getDatabase();
        $marker = 'test/destructive/force-recovery/' . uniqid();
        $db->set($marker, 'this-cluster-must-be-disposable');
        self::assertNotNull($db->get($marker));

        $dcId = getenv('FDB_DR_TARGET_DC_ID');
        if ($dcId === false || $dcId === '') {
            self::markTestSkipped('Set FDB_DR_TARGET_DC_ID to the datacenter ID to recover into.');
        }

        $db->admin()->forceRecoveryWithDataLoss($dcId);

        // If recovery completes without an error, the call went through the
        // C API cleanly. Cluster consistency is checked afterwards via the
        // status special key.
        $status = $db->admin()->getClusterStatus();
        self::assertArrayHasKey('cluster', $status);
    }
}
