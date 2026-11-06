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
> in such setups. The same cluster capability is what gates
> `readBlobGranules()` and `summarizeBlobGranules()` — see below.

## Compose stack

`docker-compose.yml` enables blob granules on its test cluster
(`blob_granules_enabled=1`) and gives the blob manager a real file-backed
store, so it does not crash-loop. `docker/fdb/start-fdb.sh` starts every
`fdbserver` with `--knob-bg_url=file:///var/fdb/blobs/` (override it with the
`FDB_BG_URL` environment variable). The `fdb-blobs` named volume is mounted at
`/var/fdb/blobs` in the five FDB services, so the blob manager can see the files.
`docker compose down -v` removes that volume together with the server data.

The knob name is **lowercase**: `--knob-bg_url` works, while `--knob-BG_URL`
is silently ignored. An ignored URL brings back the crash loop:

```text
BlobManagerDied Error="backup_invalid_url" ErrorDescription="Backup Container URL invalid"
```

Each FDB service has a healthcheck that fails when its trace logs contain
`Error="backup_invalid_url"`, and `fdb-config` waits for all five services to
report healthy. A crash-looping blob manager therefore shows up as `unhealthy`
in `docker compose ps` instead of silently passing. Normal events such as
`BlobManagerDied Error="worker_removed"` during startup or rebalancing do not
match the healthcheck.

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
| `readBlobGranules($begin, $end, $beginVersion, $loader = null, $readVersion = null, $debugNoMaterialize = false, $granuleParallelism = 1)` | `fdb_transaction_read_blob_granules` | Materialize the granules covering the range; returns `list<KeyValue>` (synchronous `FDBResult` call, not a future). |
| `summarizeBlobGranules($begin, $end, $summaryVersion = null, $rangeLimit = 100)` | `fdb_transaction_summarize_blob_granules` | Summarize the granules in the range; returns a future of `list<BlobGranuleSummary>`. `rangeLimit` must be >= 1 (the client library asserts `chunkLimit > 0`). |

#### `readBlobGranules()` and the loader

`fdb_transaction_read_blob_granules` fetches granule file data through
caller-supplied callbacks (the `FDBReadBlobGranuleContext` struct). In PHP
this is expressed as the `CrazyGoat\FoundationDB\BlobGranuleLoader`
interface:

```php
interface BlobGranuleLoader
{
    public function startLoad(string $filename, int $offset, int $length, int $fullFileLength): int;
    public function getLoad(int $loadId): string;
    public function freeLoad(int $loadId): void;
}
```

`startLoad()` begins a load and returns a unique id; `getLoad()` returns the
bytes for that id (the data must stay valid until `freeLoad()` is called);
`freeLoad()` releases it. The PHP callbacks are wired into the native struct
by `CrazyGoat\FoundationDB\BlobGranuleReadContext`, which keeps references to
the callbacks and to any in-flight data buffers.

With `$debugNoMaterialize = true` the loader is never called and only the
request to the blob workers is issued (useful for testing). A loader is
required unless `$debugNoMaterialize` is true — otherwise an
`InvalidArgumentException` is thrown.

> **Note:** both `readBlobGranules()` and `summarizeBlobGranules()` need a
> cluster with working blob-manager workers and a real granule blob store.
> On a cluster without one the C API reports
> `Operation is not supported` (2108) for reads and
> "Read version is older than blob granule history supports" (1064) for
> summaries; the integration tests skip accordingly.

The parse-file family (`fdb_readbg_parse_snapshot_file`,
`fdb_readbg_parse_delta_file`, `fdb_future_readbg_get_descriptions`) — needed
only by tools reading granule files directly — remains unbound; see
[issue #93](https://github.com/s2x/fdb-php/issues/93).

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
configured with `blob_granules_enabled=1` and a file-backed blob store, and
the test suite re-asserts the configuration at runtime (skipping when it is
not available).

Most of the class runs against the compose stack: `blobbifyRangeAndListBlobbifiedRanges`,
`getBlobGranuleRangesListsGranuleBoundaries`,
`flushBlobRangeCompactsAndKeepsRangeVerified`, `purgeBlobGranulesCompletes`,
`tenantBlobGranuleLifecycle`, `readBlobGranulesRequiresLoader` and
`summarizeBlobGranulesRejectsZeroRangeLimit`. Two tests may still skip, because
a small five-process cluster runs a blob manager but recruits no blob workers.
A read needs a real loader that can fetch the granule files, and a summary
needs granule history.

- `readBlobGranulesIssuesRequest` skips when the C API returns 1064, 2018 or
  2108 (`Operation is not supported`).
- `summarizeBlobGranulesReturnsSummaries` skips on 1064/2018
  (`Read version is older than blob granule history supports`).

Those two are expected. The whole integration suite reports 6 skips: four
come from the suite-level skip in `AdminDestructiveOperationsTest` (destructive
tests run only with `FDB_ENABLE_DESTRUCTIVE_ADMIN_TESTS=1`) and these two from
`BlobGranuleTest`. Run PHPUnit with `--display-skipped` to list them. On a
cluster where blob granules cannot be enabled at all, `enableBlobGranules()`
skips the entire class with `Could not enable blob granules`.

The class removes every range it creates in `tearDown()`, including skip and
failure paths. `unblobbifyRange()` alone is not enough: it marks the range
inactive but leaves its key in FDB's `\xff\x02/blobRange/` system range, and
ratekeeper's `checkAnyBlobRanges()` treats any key there as an active range.
On a cluster without blob workers that sets the transaction limit to 0, so the
cleanup also clears the inactive range metadata. A completed test run leaves
no blob ranges behind on the no-worker compose cluster.
