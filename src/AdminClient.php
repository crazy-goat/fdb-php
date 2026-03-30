<?php

declare(strict_types=1);

namespace CrazyGoat\FoundationDB;

/**
 * Administrative operations for FoundationDB cluster management.
 *
 * This class provides methods for cluster administration that require
 * elevated privileges. These operations are separate from normal
 * database operations (CRUD) which are handled by the Database class.
 */
final class AdminClient
{
    /** @phpstan-ignore property.onlyWritten */
    private readonly Database $database;

    /** @phpstan-ignore property.onlyWritten */
    private readonly NativeClient $client;

    public function __construct(
        Database $database,
        NativeClient $client,
    ) {
        $this->database = $database;
        $this->client = $client;
    }

    /**
     * Create a new tenant in the cluster.
     *
     * @param string $name The name of the tenant to create
     * @throws \RuntimeException If tenant creation fails
     */
    public function createTenant(string $name): void
    {
        // Implementation using fdb_database_create_tenant or fdbcli
        throw new \RuntimeException('Not implemented yet');
    }

    /**
     * Delete a tenant from the cluster.
     *
     * @param string $name The name of the tenant to delete
     * @throws \RuntimeException If tenant deletion fails
     */
    public function deleteTenant(string $name): void
    {
        // Implementation using fdb_database_delete_tenant or fdbcli
        throw new \RuntimeException('Not implemented yet');
    }

    /**
     * List all tenants in the cluster.
     *
     * @return list<string> List of tenant names
     * @throws \RuntimeException If listing fails
     */
    public function listTenants(): array
    {
        // Implementation
        throw new \RuntimeException('Not implemented yet');
    }

    /**
     * Configure the database.
     *
     * @param string $configuration Configuration string (e.g., "double ssd")
     * @throws \RuntimeException If configuration fails
     */
    public function configure(string $configuration): void
    {
        // Implementation using fdbcli or admin API
        throw new \RuntimeException('Not implemented yet');
    }

    /**
     * Exclude a server from the database.
     *
     * @param string $address Server address (e.g., "127.0.0.1:4500")
     * @throws \RuntimeException If exclusion fails
     */
    public function excludeServer(string $address): void
    {
        // Implementation
        throw new \RuntimeException('Not implemented yet');
    }

    /**
     * Include a previously excluded server back into the database.
     *
     * @param string $address Server address (e.g., "127.0.0.1:4500")
     * @throws \RuntimeException If inclusion fails
     */
    public function includeServer(string $address): void
    {
        // Implementation
        throw new \RuntimeException('Not implemented yet');
    }

    /**
     * Run a consistency check on the database.
     *
     * @return bool True if database is consistent
     * @throws \RuntimeException If check fails
     */
    public function consistencyCheck(): bool
    {
        // Implementation
        throw new \RuntimeException('Not implemented yet');
    }

    /**
     * Get detailed cluster status.
     *
     * @return array<string, mixed> Structured cluster status information
     * @throws \RuntimeException If status retrieval fails
     */
    public function getClusterStatus(): array
    {
        // Implementation returning parsed JSON from fdbcli
        throw new \RuntimeException('Not implemented yet');
    }

    /**
     * Force database recovery (use with caution!).
     *
     * @throws \RuntimeException If recovery fails
     */
    public function forceRecovery(): void
    {
        // Implementation
        throw new \RuntimeException('Not implemented yet');
    }
}
