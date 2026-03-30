<?php

declare(strict_types=1);

namespace CrazyGoat\FoundationDB;

/**
 * Administrative operations for FoundationDB cluster management.
 *
 * This class provides methods for cluster administration that require
 * elevated privileges. These operations are separate from normal
 * database operations (CRUD) which are handled by the Database class.
 *
 * All admin operations use FoundationDB Special Keys (keys starting with \xff\xff)
 * which provide a programmatic interface to administrative functions without
 * requiring external CLI tools.
 */
final readonly class AdminClient
{
    /** Special key prefix for tenant management */
    private const TENANT_MAP_PREFIX = "\xff\xff/management/tenant/map/";

    public function __construct(
        private Database $database,
        private NativeClient $client
    ) {
    }

    /**
     * Create a new tenant in the cluster.
     *
     * Uses special key: \xff\xff/management/tenant/map/<tenant>
     *
     * @param string $name The name of the tenant to create
     * @throws FDBException If tenant creation fails
     */
    public function createTenant(string $name): void
    {
        $this->database->transact(function (Transaction $tr) use ($name): void {
            // Enable writes to special key space
            $tr->options()->setSpecialKeySpaceEnableWrites();

            $key = self::TENANT_MAP_PREFIX . $name;
            $tr->set($key, '{}'); // Empty JSON object as value
        });
    }

    /**
     * Delete a tenant from the cluster.
     *
     * Uses special key: \xff\xff/management/tenant/map/<tenant>
     *
     * @param string $name The name of the tenant to delete
     * @throws FDBException If tenant deletion fails
     */
    public function deleteTenant(string $name): void
    {
        $this->database->transact(function (Transaction $tr) use ($name): void {
            // Enable writes to special key space
            $tr->options()->setSpecialKeySpaceEnableWrites();

            $key = self::TENANT_MAP_PREFIX . $name;
            $tr->clear($key);
        });
    }

    /**
     * List all tenants in the cluster.
     *
     * Uses special key range: \xff\xff/management/tenant/map/
     *
     * @return list<string> List of tenant names
     * @throws FDBException If listing fails
     */
    public function listTenants(): array
    {
        /** @var list<KeyValue> $results */
        $results = $this->database->transact(function (Transaction $tr): array {
            $begin = self::TENANT_MAP_PREFIX;
            $end = self::TENANT_MAP_PREFIX . '\xff';

            return $tr->getRange($begin, $end)->toArray();
        });

        $tenants = [];
        foreach ($results as $kv) {
            // Extract tenant name from key (remove prefix)
            $tenantName = substr($kv->key, strlen(self::TENANT_MAP_PREFIX));
            if ($tenantName !== '') {
                $tenants[] = $tenantName;
            }
        }

        return $tenants;
    }

    /**
     * Reboots a FoundationDB worker process.
     *
     * @param string $address The network address of the worker to reboot (e.g., "127.0.0.1:4500")
     * @param bool $checkFile If true, checks that a file exists at the specified path before rebooting
     * @param int $suspendDuration Duration in seconds to suspend the process (0 for immediate restart)
     *
     * @throws RebootWorkerException If the reboot operation fails
     *
     * @warning Do not close the Database immediately after calling this method, as the operation
     *          may still be in progress. Allow sufficient time for the operation to complete.
     */
    public function rebootWorker(string $address, bool $checkFile = false, int $suspendDuration = 0): void
    {
        $future = new Future\FutureInt64(
            // @phpstan-ignore method.notFound
            $this->client->fdb->fdb_database_reboot_worker(
                $this->database->getDatabasePointer(),
                $address,
                strlen($address),
                $checkFile ? 1 : 0,
                $suspendDuration,
            ),
            $this->client,
        );

        $result = $future->await();

        if ($result === 0) {
            throw new RebootWorkerException($address);
        }
    }

    /**
     * Configure the database.
     *
     * @throws \RuntimeException Not implemented yet
     */
    public function configure(): never
    {
        throw new \RuntimeException('Not implemented yet');
    }

    /**
     * Exclude a server from the database.
     *
     * @throws \RuntimeException Not implemented yet
     */
    public function excludeServer(): never
    {
        throw new \RuntimeException('Not implemented yet');
    }

    /**
     * Include a previously excluded server back into the database.
     *
     * @throws \RuntimeException Not implemented yet
     */
    public function includeServer(): never
    {
        throw new \RuntimeException('Not implemented yet');
    }

    /**
     * Run a consistency check on the database.
     *
     * @return bool True if database is consistent
     * @throws \RuntimeException Not implemented yet
     */
    public function consistencyCheck(): bool
    {
        throw new \RuntimeException('Not implemented yet');
    }

    /**
     * Get detailed cluster status.
     *
     * @return array<string, mixed> Structured cluster status information
     * @throws \RuntimeException Not implemented yet
     */
    public function getClusterStatus(): array
    {
        throw new \RuntimeException('Not implemented yet');
    }

    /**
     * Force database recovery (use with caution!).
     *
     * @throws \RuntimeException Not implemented yet
     */
    public function forceRecovery(): never
    {
        throw new \RuntimeException('Not implemented yet');
    }
}
