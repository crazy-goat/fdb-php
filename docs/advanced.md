# Advanced Features Documentation

## Overview

Advanced features: Locality API, Key Utilities, Database Monitoring, Connection Strings, Explicit Lifecycle Management, Grouped Future Waits & Range Read-Ahead.

## Locality API

Data distribution analysis:

```php
use CrazyGoat\FoundationDB\Locality;

$boundaries = Locality::getBoundaryKeys($db, "\x00", "\xFF");
// Returns list<string> — shard boundary keys

foreach ($boundaries as $boundary) {
    echo "Boundary: " . bin2hex($boundary) . "\n";
}
```

- Useful for understanding data distribution across storage servers
- Uses system keys (\xFF/keyServers/) internally
- Handles transaction timeouts with automatic retry

## Key Utilities

```php
use CrazyGoat\FoundationDB\KeyUtil;

// strinc — increment the last byte of a key (for range end)
$end = KeyUtil::strinc('users/'); // 'users0' (next byte after '/')
// Returns null if key is empty or all 0xFF bytes

// printable — human-readable representation of binary keys
$readable = KeyUtil::printable("\x00\x01hello\xFF");
// '\x00\x01hello\xff'

// prefixRange — get begin/end for a prefix
[$begin, $end] = KeyUtil::prefixRange('users/');
// ['users/', 'users0']
// Throws InvalidArgumentException if prefix is empty or all 0xFF
```

## Database Monitoring

```php
// Main thread busyness (0.0 to 1.0)
$busyness = $db->getMainThreadBusyness();
echo "Thread busyness: " . round($busyness * 100, 1) . "%\n";

// Client status (JSON string)
$statusJson = $db->getClientStatus();
$status = json_decode($statusJson, true);

// Or get the parsed array directly (consistent with AdminClient::getClusterStatus())
$status = $db->getClientStatus(asArray: true);

// Client library version of the loaded libfdb_c (useful in bug reports
// and in multi-version-client setups)
echo FoundationDB::getClientVersion();

// Protocol version spoken by the cluster — lets you detect whether the
// loaded client library can talk to it
echo $db->getServerProtocol();

// Run a callable once when the FDB network thread stops at process shutdown
// (useful for flushing traces/metrics). The callable runs on the PHP main
// thread, after the network thread has been joined — never on the network
// thread itself.
FoundationDB::onNetworkThreadCompletion(function (): void {
    // flush metrics/traces here
});
```

## Connection Strings

```php
use CrazyGoat\FoundationDB\FoundationDB as FDB;

FDB::apiVersion(730);

// Open with connection string instead of cluster file
$db = FDB::openWithConnectionString('my_cluster:abc123@127.0.0.1:4500');
```

## Database Cache (connection reuse & bounds)

`FoundationDB::open()` and `FoundationDB::openWithConnectionString()` keep a
process-wide cache of opened `Database` objects so that opening the same cluster
file or connection string twice returns the same instance (and does not create a
second native `FDBDatabase` handle). This is what makes opening the same database
repeatedly cheap.

The cache is **bounded** to avoid unbounded native-handle growth in applications
that open many distinct clusters or connection strings (multi-tenant, dynamic
connection strings, failover scripts). By default up to `8` distinct databases are
cached; opening more evicts the least-recently-used entry:

```php
// Raise or lower the bound (must be >= 1). Default is 8.
FDB::setMaxDatabases(32);
echo FDB::getMaxDatabases(); // 32
```

Eviction only drops the cache reference. If your application still holds the evicted
`Database` in a variable, it continues to work normally and its native handle is
released only once all references are gone (or you call `close()`). Re-opening an
evicted connection string simply creates a fresh database.

Two implications follow from `open()` / `openWithConnectionString()` reusing a
cached instance:

- **Do not call `close()` on a database you may open again** — `close()` removes it
  from the cache, so the next `open()` allocates a fresh native handle.
- Because the cache is process-wide and static, it is reset by `FoundationDB::reset()`
  (test-only and process-shutdown convenience), which also restores the default
  bound of `8`.

## Explicit Lifecycle Management

```php
$db = FDB::open();

// ... use database ...

// Explicitly close and release resources
$db->close();

// After close, all operations throw LogicException
// $db->get('key'); // throws LogicException: Database has been closed
```

- Without `close()`, resources are released when the Database object is garbage collected
- `close()` is idempotent — calling it multiple times is safe

## API Version Management

```php
// Must be called once before any other FDB operation
FDB::apiVersion(730);

// Check current version
$version = FDB::getApiVersion(); // 730 or null if not set

// Check max supported version
$maxVersion = FDB::getMaxApiVersion();
```

## Addresses for Key

```php
$db->transact(function (Transaction $tr) {
    $addresses = $tr->getAddressesForKey('my_key')->await();
    // list<string> — storage server addresses holding this key
    foreach ($addresses as $addr) {
        echo "Stored on: {$addr}\n";
    }
});
```

## Future Objects

All async operations return Futures:

```php
// Future types:
// FutureValue  — $tr->get()        → ?string
// FutureKey    — $tr->getKey()     → string
// FutureInt64  — $tr->getReadVersion() → int
// FutureVoid   — $tr->commit()     → null
// FutureKeyArray — $tr->getRangeSplitPoints() → list<string>
// FutureStringArray — $tr->getAddressesForKey() → list<string>

$future = $tr->get('key');
$future->isReady();  // check without blocking
$future->cancel();   // cancel the operation
$value = $future->await(); // block until ready
```

### Grouped waits: `Future::awaitAll()`

Futures can be fanned out and awaited as a group. All N requests are already
in flight, so the total wait is driven by the slowest future (roughly one
round trip) instead of N round trips:

```php
$values = Future::awaitAll([
    'a' => $tr->get('a'),
    'b' => $tr->get('b'),
    'c' => $tr->get('c'),
]);
// ['a' => '...', 'b' => '...', 'c' => '...']
```

- Results keep the input keys; resolution order matches the input order.
- Errors are still thrown from `await()` — after all futures are ready, in
  input order, so a failure in an early future never cancels the rest.
- Implemented by polling the non-blocking `fdb_future_is_ready()` for every
  future at once (1 ms spin interval). No PHP code ever runs on the FDB
  network thread.

### Completion hooks: `Future::onReady()`

```php
$future = $tr->get('key');
$future->onReady(function ($f) {
    echo "ready!\n";
});
$value = $future->await(); // hook fires just before the value is read
```

- The hook runs exactly once, on the PHP thread that resolved the future —
  never on the FDB network thread.
- Hooks registered after the future has already been resolved fire
  immediately. Exceptions thrown from a hook propagate to the caller.

### Range read-ahead

`RangeResult` iteration overlaps network round trips with consumption: while
the consumer is processing page N, the request for page N+1 is already in
flight. For typical multi-page range scans this removes one round trip of
latency per page. Nothing to configure — it is the default behavior of
`foreach ($range as $keyValue)` and `RangeResult::toArray()`.

### Limits of the current async model

- `await()` still blocks the calling (PHP) thread until the future is ready —
  there is no callback binding (`fdb_future_set_callback`) yet, because PHP
  code cannot safely run on the FDB network thread. Grouped waits
  (`awaitAll()`) and range read-ahead recover most of the practical
  parallelism without it.
- Fibers are not suspended by `await()`, so the library does not yet compose
  with Fiber-based event loops (Revolt/AMPHP/ReactPHP).
