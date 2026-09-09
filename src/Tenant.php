<?php

declare(strict_types=1);

namespace CrazyGoat\FoundationDB;

use CrazyGoat\FoundationDB\Future\FutureBool;
use CrazyGoat\FoundationDB\Future\FutureInt64;
use CrazyGoat\FoundationDB\Future\FutureKeyRangeArray;
use CrazyGoat\FoundationDB\Future\FutureVoid;
use FFI;
use FFI\CData;

final readonly class Tenant
{
    public function __construct(
        private CData $tpointer,
        private Database $db,
        private NativeClient $client,
    ) {
    }

    public function createTransaction(): Transaction
    {
        $trPointer = $this->client->fdb->new('FDBTransaction*');
        $this->client->checkError(
            $this->client->fdb->fdb_tenant_create_transaction($this->tpointer, FFI::addr($trPointer)),
        );

        return new Transaction($trPointer, $this->db, $this->client);
    }

    public function getId(): int
    {
        $future = new FutureInt64(
            $this->client->fdb->fdb_tenant_get_id($this->tpointer),
            $this->client,
        );

        return $future->await();
    }

    /**
     * Request that the given range be blobbified (materialized to blob
     * storage as blob granules). Tenant-scoped variant of
     * `Database::blobbifyRange()`, backed by `fdb_tenant_blobbify_range`.
     *
     * Requires the cluster to have blob granules enabled
     * (`blob_granules_enabled=1`).
     */
    public function blobbifyRange(string $begin, string $end): void
    {
        $beginLength = KeyValueLimits::assertValidRangeEndpoint($begin);
        $endLength = KeyValueLimits::assertValidRangeEndpoint($end);

        $future = new FutureVoid(
            $this->client->fdb->fdb_tenant_blobbify_range(
                $this->tpointer,
                $begin,
                $beginLength,
                $end,
                $endLength,
            ),
            $this->client,
        );

        $future->await();
    }

    /**
     * Blocking variant of `blobbifyRange()` that only returns once the
     * blobbified range is durably committed. Backed by
     * `fdb_tenant_blobbify_range_blocking`.
     */
    public function blobbifyRangeBlocking(string $begin, string $end): void
    {
        $beginLength = KeyValueLimits::assertValidRangeEndpoint($begin);
        $endLength = KeyValueLimits::assertValidRangeEndpoint($end);

        $future = new FutureVoid(
            $this->client->fdb->fdb_tenant_blobbify_range_blocking(
                $this->tpointer,
                $begin,
                $beginLength,
                $end,
                $endLength,
            ),
            $this->client,
        );

        $future->await();
    }

    /**
     * Remove the blob manager's knowledge of the given blobbified range,
     * deleting the granule data it holds. Backed by
     * `fdb_tenant_unblobbify_range`.
     */
    public function unblobbifyRange(string $begin, string $end): void
    {
        $beginLength = KeyValueLimits::assertValidRangeEndpoint($begin);
        $endLength = KeyValueLimits::assertValidRangeEndpoint($end);

        $future = new FutureVoid(
            $this->client->fdb->fdb_tenant_unblobbify_range(
                $this->tpointer,
                $begin,
                $beginLength,
                $end,
                $endLength,
            ),
            $this->client,
        );

        $future->await();
    }

    /**
     * List the blobbified ranges within the given range. Backed by
     * `fdb_tenant_list_blobbified_ranges`.
     *
     * @param int $rangeLimit Maximum number of ranges to return (0 = unlimited).
     *
     * @return list<KeyRange>
     */
    public function listBlobbifiedRanges(string $begin, string $end, int $rangeLimit = 0): array
    {
        $beginLength = KeyValueLimits::assertValidRangeEndpoint($begin);
        $endLength = KeyValueLimits::assertValidRangeEndpoint($end);

        $future = new FutureKeyRangeArray(
            $this->client->fdb->fdb_tenant_list_blobbified_ranges(
                $this->tpointer,
                $begin,
                $beginLength,
                $end,
                $endLength,
                $rangeLimit,
            ),
            $this->client,
        );

        return $future->await();
    }

    /**
     * Verify that the given range is blobbified at (or after) the given
     * version. Backed by `fdb_tenant_verify_blob_range`.
     *
     * @param int $version Version to verify; `Database::LATEST_VERSION` (-2) for
     *                     the latest version.
     */
    public function verifyBlobRange(string $begin, string $end, int $version = Database::LATEST_VERSION): bool
    {
        $beginLength = KeyValueLimits::assertValidRangeEndpoint($begin);
        $endLength = KeyValueLimits::assertValidRangeEndpoint($end);

        $future = new FutureBool(
            $this->client->fdb->fdb_tenant_verify_blob_range(
                $this->tpointer,
                $begin,
                $beginLength,
                $end,
                $endLength,
                $version,
            ),
            $this->client,
        );

        return $future->await();
    }

    /**
     * Flush the given range to blob storage, optionally compacting the
     * granule files. Backed by `fdb_tenant_flush_blob_range`.
     *
     * @param int $version Version to flush at; `Database::LATEST_VERSION` (-2) for
     *                     the latest version.
     */
    public function flushBlobRange(
        string $begin,
        string $end,
        bool $compact = false,
        int $version = Database::LATEST_VERSION,
    ): void {
        $beginLength = KeyValueLimits::assertValidRangeEndpoint($begin);
        $endLength = KeyValueLimits::assertValidRangeEndpoint($end);

        $future = new FutureVoid(
            $this->client->fdb->fdb_tenant_flush_blob_range(
                $this->tpointer,
                $begin,
                $beginLength,
                $end,
                $endLength,
                $compact ? 1 : 0,
                $version,
            ),
            $this->client,
        );

        $future->await();
    }

    /**
     * Purge the blob granules for the given range: clears the data from
     * FDB's storage servers once it is safely in blob storage. Backed by
     * `fdb_tenant_purge_blob_granules`.
     *
     * Follow up with `waitPurgeGranulesComplete()` using the key returned
     * for tracking, or the range's end key.
     *
     * @param int $purgeVersion Version to purge to; `Database::LATEST_VERSION` (-2) for
     *                          the latest version.
     */
    public function purgeBlobGranules(
        string $begin,
        string $end,
        bool $force = false,
        int $purgeVersion = Database::LATEST_VERSION,
    ): void {
        $beginLength = KeyValueLimits::assertValidRangeEndpoint($begin);
        $endLength = KeyValueLimits::assertValidRangeEndpoint($end);

        $future = new FutureVoid(
            $this->client->fdb->fdb_tenant_purge_blob_granules(
                $this->tpointer,
                $begin,
                $beginLength,
                $end,
                $endLength,
                $purgeVersion,
                $force ? 1 : 0,
            ),
            $this->client,
        );

        $future->await();
    }

    /**
     * Block until all granules for the range started by
     * `purgeBlobGranules()` have been purged. Backed by
     * `fdb_tenant_wait_purge_granules_complete`.
     */
    public function waitPurgeGranulesComplete(string $purgeKey): void
    {
        $purgeKeyLength = KeyValueLimits::assertValidRangeEndpoint($purgeKey);

        $future = new FutureVoid(
            $this->client->fdb->fdb_tenant_wait_purge_granules_complete(
                $this->tpointer,
                $purgeKey,
                $purgeKeyLength,
            ),
            $this->client,
        );

        $future->await();
    }

    public function __destruct()
    {
        $this->client->fdb->fdb_tenant_destroy($this->tpointer);
    }
}
