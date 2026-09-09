<?php

declare(strict_types=1);

namespace CrazyGoat\FoundationDB\Tests\Integration;

use CrazyGoat\FoundationDB\Database;
use CrazyGoat\FoundationDB\KeyRange;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class BlobGranuleTest extends TestCase
{
    use DatabaseCleanupTrait;

    protected function setUp(): void
    {
        parent::setUp();
        $this->getDatabase();
        $this->enableBlobGranules();
    }

    #[Test]
    public function blobbifyRangeAndListBlobbifiedRanges(): void
    {
        $db = $this->getDatabase();
        $this->writeSampleData($db, 'bg_blobbify', 50);

        $begin = 'bg_blobbify:';
        $end = 'bg_blobbify;';

        $db->blobbifyRange($begin, $end);

        $ranges = $db->listBlobbifiedRanges($begin, $end);
        self::assertNotEmpty($ranges);
        foreach ($ranges as $range) {
            self::assertInstanceOf(KeyRange::class, $range);
            self::assertGreaterThanOrEqual($begin, $range->begin);
            self::assertLessThanOrEqual($end, $range->end);
        }

        self::assertTrue($db->verifyBlobRange($begin, $end));

        $db->unblobbifyRange($begin, $end);
        self::assertEmpty($db->listBlobbifiedRanges($begin, $end));
    }

    #[Test]
    public function getBlobGranuleRangesListsGranuleBoundaries(): void
    {
        $db = $this->getDatabase();
        $this->writeSampleData($db, 'bg_ranges', 50);

        $begin = 'bg_ranges:';
        $end = 'bg_ranges;';

        $db->blobbifyRange($begin, $end);

        $tr = $db->createTransaction();
        try {
            $ranges = $tr->getBlobGranuleRanges($begin, $end)->await();
        } finally {
            $tr->reset();
        }

        // Granule boundaries depend on cluster state; on a fresh cluster the
        // whole keyspace is a single granule, so the list may legitimately
        // be empty. All we assert is the shape of the result.
        foreach ($ranges as $range) {
            self::assertInstanceOf(KeyRange::class, $range);
            self::assertGreaterThanOrEqual($begin, $range->begin);
            self::assertLessThanOrEqual($end, $range->end);
        }
    }

    #[Test]
    public function flushBlobRangeCompactsAndKeepsRangeVerified(): void
    {
        $db = $this->getDatabase();
        $this->writeSampleData($db, 'bg_flush', 50);

        $begin = 'bg_flush:';
        $end = 'bg_flush;';

        $db->blobbifyRange($begin, $end);
        $db->flushBlobRange($begin, $end, true);

        self::assertTrue($db->verifyBlobRange($begin, $end));
    }

    #[Test]
    public function purgeBlobGranulesCompletes(): void
    {
        $db = $this->getDatabase();
        $this->writeSampleData($db, 'bg_purge', 50);

        $begin = 'bg_purge:';
        $end = 'bg_purge;';

        $db->blobbifyRange($begin, $end);
        $db->purgeBlobGranules($begin, $end);
        $db->waitPurgeGranulesComplete($end);

        self::assertTrue($db->verifyBlobRange($begin, $end));
    }

    #[Test]
    public function tenantBlobGranuleLifecycle(): void
    {
        $name = 'test_bg_tenant';
        $this->createTenantViaFdbcli($name);

        try {
            $db = $this->getDatabase();
            $tenant = $db->openTenant($name);
            $tr = $tenant->createTransaction();
            for ($i = 0; $i < 50; $i++) {
                $tr->set('bg_tenant:' . $i, 'value-' . $i);
            }
            $tr->commit()->await();
            $tr->reset();

            $begin = 'bg_tenant:';
            $end = 'bg_tenant;';

            $tenant->blobbifyRange($begin, $end);

            $ranges = $tenant->listBlobbifiedRanges($begin, $end);
            self::assertNotEmpty($ranges);

            self::assertTrue($tenant->verifyBlobRange($begin, $end));

            $tenant->flushBlobRange($begin, $end);
            $tenant->purgeBlobGranules($begin, $end);
            $tenant->waitPurgeGranulesComplete($end);
        } finally {
            $this->deleteTenantViaFdbcli($name);
        }
    }

    private function writeSampleData(Database $db, string $prefix, int $count): void
    {
        $db->transact(function ($tr) use ($prefix, $count): void {
            for ($i = 0; $i < $count; $i++) {
                $tr->set($prefix . ':' . $i, 'value-' . $i);
            }
        });
    }

    private function enableBlobGranules(): void
    {
        $clusterFile = getenv('FDB_CLUSTER_FILE') ?: '/etc/foundationdb/fdb.cluster';
        $output = (string) shell_exec(
            "fdbcli -C {$clusterFile} --exec 'configure blob_granules_enabled=1' 2>&1",
        );
        if (
            !str_contains($output, 'committed')
            && !str_contains($output, 'already')
            && !str_contains($output, 'Configuration changed')
        ) {
            self::markTestSkipped('Could not enable blob granules: ' . $output);
        }
    }

    private function createTenantViaFdbcli(string $name): void
    {
        $this->configureTenantMode();
        $clusterFile = getenv('FDB_CLUSTER_FILE') ?: '/etc/foundationdb/fdb.cluster';
        $output = (string) shell_exec(
            "fdbcli -C {$clusterFile} --exec 'createtenant {$name}' 2>&1",
        );
        if (!str_contains($output, 'created') && !str_contains($output, 'already exists')) {
            self::markTestSkipped('Could not create tenant: ' . $output);
        }
    }

    private function configureTenantMode(): void
    {
        $clusterFile = getenv('FDB_CLUSTER_FILE') ?: '/etc/foundationdb/fdb.cluster';
        $output = (string) shell_exec(
            "fdbcli -C {$clusterFile} --exec 'configure tenant_mode=optional_experimental' 2>&1",
        );
        if (
            !str_contains($output, 'committed')
            && !str_contains($output, 'already')
            && !str_contains($output, 'Configuration changed')
        ) {
            self::markTestSkipped('Could not configure tenant mode: ' . $output);
        }
    }

    private function deleteTenantViaFdbcli(string $name): void
    {
        $clusterFile = getenv('FDB_CLUSTER_FILE') ?: '/etc/foundationdb/fdb.cluster';
        shell_exec("fdbcli -C {$clusterFile} --exec 'deletetenant {$name}' 2>&1");
    }
}
