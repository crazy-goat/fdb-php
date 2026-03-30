<?php

declare(strict_types=1);

namespace CrazyGoat\FoundationDB\Tests\Integration;

use CrazyGoat\FoundationDB\Tenant;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class TenantTest extends TestCase
{
    use DatabaseCleanupTrait;

    protected function setUp(): void
    {
        parent::setUp();
        $this->configureTenantMode();
    }

    #[Test]
    public function openTenantReturnsTenantInstance(): void
    {
        $this->createTenantViaFdbcli('test_tenant_open');

        try {
            $tenant = $this->getDatabase()->openTenant('test_tenant_open');
            self::assertInstanceOf(Tenant::class, $tenant);
        } finally {
            $this->deleteTenantViaFdbcli('test_tenant_open');
        }
    }

    #[Test]
    public function tenantGetIdReturnsPositiveInteger(): void
    {
        $this->createTenantViaFdbcli('test_tenant_id');

        try {
            $tenant = $this->getDatabase()->openTenant('test_tenant_id');
            $id = $tenant->getId();
            self::assertGreaterThan(0, $id);
        } finally {
            $this->deleteTenantViaFdbcli('test_tenant_id');
        }
    }

    #[Test]
    public function tenantGetIdReturnsSameIdForSameTenant(): void
    {
        $this->createTenantViaFdbcli('test_tenant_same_id');

        try {
            $tenant1 = $this->getDatabase()->openTenant('test_tenant_same_id');
            $tenant2 = $this->getDatabase()->openTenant('test_tenant_same_id');
            self::assertSame($tenant1->getId(), $tenant2->getId());
        } finally {
            $this->deleteTenantViaFdbcli('test_tenant_same_id');
        }
    }

    #[Test]
    public function tenantGetIdReturnsDifferentIdsForDifferentTenants(): void
    {
        $this->createTenantViaFdbcli('test_tenant_diff_a');
        $this->createTenantViaFdbcli('test_tenant_diff_b');

        try {
            $tenantA = $this->getDatabase()->openTenant('test_tenant_diff_a');
            $tenantB = $this->getDatabase()->openTenant('test_tenant_diff_b');
            self::assertNotSame($tenantA->getId(), $tenantB->getId());
        } finally {
            $this->deleteTenantViaFdbcli('test_tenant_diff_a');
            $this->deleteTenantViaFdbcli('test_tenant_diff_b');
        }
    }

    #[Test]
    public function tenantCanCreateTransactionAndPerformCrud(): void
    {
        $this->createTenantViaFdbcli('test_tenant_crud');

        try {
            $tenant = $this->getDatabase()->openTenant('test_tenant_crud');
            $tr = $tenant->createTransaction();
            $tr->set('tenant_key', 'tenant_value');
            $tr->commit()->await();

            $tr2 = $tenant->createTransaction();
            $value = $tr2->get('tenant_key')->await();
            self::assertSame('tenant_value', $value);

            $tr3 = $tenant->createTransaction();
            $tr3->clear('tenant_key');
            $tr3->commit()->await();
        } finally {
            $this->deleteTenantViaFdbcli('test_tenant_crud');
        }
    }

    private function configureTenantMode(): void
    {
        $clusterFile = getenv('FDB_CLUSTER_FILE') ?: '/etc/foundationdb/fdb.cluster';
        $output = (string) shell_exec(
            "fdbcli -C {$clusterFile} --exec 'configure tenant_mode=optional_experimental' 2>&1",
        );
        if (!str_contains($output, 'committed') && !str_contains($output, 'already') && !str_contains($output, 'Configuration changed')) {
            self::markTestSkipped('Could not configure tenant mode: ' . $output);
        }
    }

    private function createTenantViaFdbcli(string $name): void
    {
        $clusterFile = getenv('FDB_CLUSTER_FILE') ?: '/etc/foundationdb/fdb.cluster';
        $output = (string) shell_exec(
            "fdbcli -C {$clusterFile} --exec 'createtenant {$name}' 2>&1",
        );
        if (!str_contains($output, 'created') && !str_contains($output, 'already exists')) {
            self::markTestSkipped('Could not create tenant: ' . $output);
        }
    }

    private function deleteTenantViaFdbcli(string $name): void
    {
        $clusterFile = getenv('FDB_CLUSTER_FILE') ?: '/etc/foundationdb/fdb.cluster';
        shell_exec("fdbcli -C {$clusterFile} --exec 'deletetenant {$name}' 2>&1");
    }
}
