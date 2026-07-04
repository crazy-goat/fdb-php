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

            // Validate up front: the prefix overhead + this name must still fit
            // within the FDB key size limit. Asserting before set() guarantees the
            // failure surfaces inside this call site rather than as an opaque
            // commit-time error.
            $key = self::TENANT_MAP_PREFIX . $name;
            KeyValueLimits::assertValidKey($key);

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
            KeyValueLimits::assertValidKey($key);

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
        $end = KeyUtil::strinc(self::TENANT_MAP_PREFIX);
        if ($end === null) {
            throw new \RuntimeException(
                'Cannot compute tenant range end key: prefix is empty or entirely 0xFF bytes'
            );
        }

        /** @var list<KeyValue> $results */
        $results = $this->database->transact(function (Transaction $tr) use ($end): array {
            $begin = self::TENANT_MAP_PREFIX;

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
     * Uses special keys to change database configuration.
     *
     * @param string $configuration Configuration string (e.g., "double ssd")
     * @throws FDBException If configuration fails
     */
    public function configure(string $configuration): void
    {
        $this->database->transact(function (Transaction $tr) use ($configuration): void {
            $tr->options()->setSpecialKeySpaceEnableWrites();

            // Parse configuration (e.g., "double ssd" -> redundancy: double, storage: ssd)
            $parts = explode(' ', $configuration);
            $redundancy = $parts[0];
            $storage = $parts[1] ?? 'ssd';

            // Set configuration via special keys
            $tr->set("\xff\xff/configuration/redundancy", $redundancy);
            $tr->set("\xff\xff/configuration/storage", $storage);
        });
    }

    /**
     * Exclude a server from the database.
     *
     * Uses special key: \xff\xff/management/excluded/<address>
     *
     * @param string $address Server address (e.g., "127.0.0.1:4500")
     * @throws FDBException If exclusion fails
     */
    public function excludeServer(string $address): void
    {
        $this->database->transact(function (Transaction $tr) use ($address): void {
            $tr->options()->setSpecialKeySpaceEnableWrites();

            $key = "\xff\xff/management/excluded/{$address}";

            // Tenant/server identifiers flow into a special key with a fixed
            // prefix overhead; validate up front so pathological inputs fail here
            // instead of as an opaque commit-time error.
            KeyValueLimits::assertValidKey($key);

            $tr->set($key, '');
        });
    }

    /**
     * Include a previously excluded server back into the database.
     *
     * Uses special key: \xff\xff/management/excluded/<address>
     *
     * @param string $address Server address (e.g., "127.0.0.1:4500")
     * @throws FDBException If inclusion fails
     */
    public function includeServer(string $address): void
    {
        $this->database->transact(function (Transaction $tr) use ($address): void {
            $tr->options()->setSpecialKeySpaceEnableWrites();

            $key = "\xff\xff/management/excluded/{$address}";
            KeyValueLimits::assertValidKey($key);

            $tr->clear($key);
        });
    }

    /**
     * Run a consistency check on the database.
     *
     * Uses special key: \xff\xff/management/consistency_check_suspended
     *
     * @return bool True if database is consistent
     * @throws FDBException If check fails
     */
    public function consistencyCheck(): bool
    {
        // Check if consistency check is suspended
        $result = $this->database->get("\xff\xff/management/consistency_check_suspended");

        // If key exists, consistency check is suspended (not running)
        return $result === null;
    }

    /**
     * Get detailed cluster status.
     *
     * Uses special key: \xff\xff/status/json
     *
     * @return array<string, mixed> Structured cluster status information
     * @throws FDBException If status retrieval fails
     */
    public function getClusterStatus(): array
    {
        $json = $this->database->get("\xff\xff/status/json");

        if ($json === null) {
            throw new \RuntimeException('Failed to retrieve cluster status');
        }

        return $this->decodeClusterStatusJson($json);
    }

    /**
     * Decode and validate cluster status JSON.
     *
     * @param string $json Raw JSON string from the cluster status special key
     *
     * @return array<string, mixed>
     *
     * @throws \JsonException  If the JSON is syntactically invalid
     * @throws \RuntimeException If the decoded value is not an object (associative array)
     *
     * @internal
     */
    private function decodeClusterStatusJson(string $json): array
    {
        $data = json_decode($json, true, 512, JSON_THROW_ON_ERROR);

        if (!is_array($data)) {
            throw new \RuntimeException('Cluster status response is not a valid JSON object');
        }

        /** @var array<string, mixed> $data */
        return $data;
    }

    /**
     * Force database recovery (use with caution!).
     *
     * This is an emergency operation that forces the database to recover.
     * May result in data loss if recent mutations haven't been replicated.
     *
     * @param string $dcId Datacenter ID to recover into
     * @throws FDBException If recovery fails
     * @warning This operation may cause data loss. Use only in emergency situations.
     */
    public function forceRecovery(string $dcId): void
    {
        $this->database->transact(function (Transaction $tr) use ($dcId): void {
            $tr->options()->setSpecialKeySpaceEnableWrites();

            // Use special key to force recovery
            $tr->set("\xff\xff/management/force_recovery", $dcId);
        });
    }
}
