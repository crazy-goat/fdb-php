# Blob Granules

Blob granules let a key range be *materialized* to external blob storage as
self-contained files (snapshots + deltas) that can then be read directly,
bypassing the storage servers. This is how bulk/analytical reads avoid loading
FDB's storage tier, and how the storage tier can be relieved of cold data.

> Blob granules must be enabled on the cluster first:
> `fdbcli --exec "configure blob_granules_enabled=1"` (or at creation:
> `configure new double ssd blob_granules_enabled=1`).

> **Note:** `blobbifyRangeBlocking()` (and its tenant variant) only returns
> once the blob manager has durably committed the granule files, which
> requires blob-manager workers to be recruited (typically backed by a real
> blob store). On small test clusters no blob workers are recruited and the
> blocking call never completes — prefer the non-blocking `blobbifyRange()`
> in such setups.

## API overview

All range endpoints are raw keys (`string`). Version arguments accept
`Database::LATEST_VERSION` (`-2`) to mean "the latest version".

### Database level (`CrazyGoat\FoundationDB\Database`)

| Method | Backed by | Description |
|--------|-----------|-------------|
| `blobbifyRange($begin, $end)` | `fdb_database_blobbify_range` | Request that the range be blobbified. |
| `blobbifyRangeBlocking($begin, $end)` | `fdb_database_blobbify_range_blocking` | Same, but returns only once the blobbified range is durably committed. |
| `unblobbifyRange($begin, $end)` | `fdb_database_unblobbify_range` | Drop the blob manager's knowledge of the range (deletes its granule data). |
| `listBlobbifiedRanges($begin, $end, $rangeLimit = 0)` | `fdb_database_list_blobbified_ranges` | List the blobbified sub-ranges; returns `list<KeyRange>`. |
| `verifyBlobRange($begin, $end, $version = Database::LATEST_VERSION)` | `fdb_database_verify_blob_range` | Whether the range is blobbified at/after `$version`; returns `bool`. |
| `flushBlobRange($begin, $end, $compact = false, $version = Database::LATEST_VERSION)` | `fdb_database_flush_blob_range` | Flush the range to blob storage, optionally compacting granule files. |
| `purgeBlobGranules($begin, $end, $force = false, $purgeVersion = Database::LATEST_VERSION)` | `fdb_database_purge_blob_granules` | Clear the range's data from FDB storage servers once it is safe in blob storage. |
| `waitPurgeGranulesComplete($purgeKey)` | `fdb_database_wait_purge_granules_complete` | Block until a purge started earlier has completed. |

### Tenant level (`CrazyGoat\FoundationDB\Tenant`)

The same eight operations exist as tenant-scoped variants
(`$tenant->blobbifyRange()`, `$tenant->listBlobbifiedRanges()`, …), backed by
the `fdb_tenant_*` symbols. Keys are relative to the tenant's prefix.

### Transaction level (`CrazyGoat\FoundationDB\ReadTransaction`)

| Method | Backed by | Description |
|--------|-----------|-------------|
| `getBlobGranuleRanges($begin, $end, $rangeLimit = 0)` | `fdb_transaction_get_blob_granule_ranges` | List the granule boundaries within the range; returns a future of `list<KeyRange>`. |

Direct granule *reads* (`fdb_transaction_read_blob_granules` and the
`FDBReadBlobGranuleContext` load/free callback machinery) are not yet bound —
see [issue #93](https://github.com/s2x/fdb-php/issues/93) for the remaining
scope.

## KeyRange

`CrazyGoat\FoundationDB\KeyRange` is a simple value object with public
`$begin` / `$end` string properties and a `toString()` helper that renders
printable endpoints.

## Typical workflow

```php
use CrazyGoat\FoundationDB\FoundationDB;

FoundationDB::apiVersion(730);
$db = FoundationDB::open();

// 1. Write some data
$db->transact(function ($tr): void {
    for ($i = 0; $i < 1000; $i++) {
        $tr->set("analytics:$i", "value-$i");
    }
});

$begin = "analytics:";
$end   = "analytics;"; // strinc("analytics:")

// 2. Materialize the range to blob storage (durable)
$db->blobbifyRangeBlocking($begin, $end);

// 3. Inspect / verify
foreach ($db->listBlobbifiedRanges($begin, $end) as $range) {
    echo $range->toString(), "\n";
}
var_dump($db->verifyBlobRange($begin, $end));

// 4. Optionally compact the granule files
$db->flushBlobRange($begin, $end, compact: true);

// 5. Optionally purge the data from the storage servers
$db->purgeBlobGranules($begin, $end);
$db->waitPurgeGranulesComplete($end);

// 6. When the data is no longer needed in blob storage either
$db->unblobbifyRange($begin, $end);
```

## Integration tests

`tests/Integration/BlobGranuleTest.php` exercises the whole lifecycle against
a blob-granules-enabled cluster; the `docker-compose.yml` cluster is
configured with `blob_granules_enabled=1`, and the test suite re-asserts it at
runtime (skipping when the configuration is not available).
