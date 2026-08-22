# Advanced Features Documentation

## Overview

Advanced features: Locality API, Key Utilities, Database Monitoring, Connection Strings, Explicit Lifecycle Management.

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
