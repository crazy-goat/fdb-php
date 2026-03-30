# FoundationDB PHP Client

[![CI](https://github.com/crazy-goat/fdb-php/actions/workflows/ci.yml/badge.svg)](https://github.com/crazy-goat/fdb-php/actions/workflows/ci.yml)
[![License: MIT](https://img.shields.io/badge/License-MIT-blue.svg)](LICENSE)

A full-featured [FoundationDB](https://www.foundationdb.org/) client for PHP, built on PHP FFI bindings to `libfdb_c.so`.

## Features

- **Full transactional API** — get, set, clear, commit, with automatic retry loop and conflict resolution
- **Range reads** — lazy Generator-based iteration and eager fetching (`getRangeAll`)
- **Atomic operations** — add, bitAnd, bitOr, bitXor, max, min, byteMax, byteMin, compareAndClear, versionstamps
- **Snapshot reads** — conflict-free reads within transactions
- **Watches** — get notified when a key changes (`watch`, `getAndWatch`, `setAndWatch`, `clearAndWatch`)
- **Tuple layer** — binary-compatible with Python/Go/Java tuple encoding, with `compare()` and `hasIncompleteVersionstamp()`
- **Subspace** — key prefix management with `packWithVersionstamp` support
- **Directory layer** — hierarchical namespace management with HighContentionAllocator and partitions
- **Typed options** — fluent API for network, database, and transaction options
- **Multi-tenancy** — Tenant support with `getId()` and scoped transactions
- **Admin client** — cluster administration (tenant management, server exclusion, configuration, status, force recovery)
- **Database monitoring** — `getMainThreadBusyness()`, `getClientStatus()`
- **Locality API** — `getBoundaryKeys()` for data distribution analysis
- **Key utilities** — `strinc()`, `printable()`, `prefixRange()`
- **Error predicates** — `isRetryable()`, `isMaybeCommitted()`, `isRetryableNotCommitted()`
- **Connection string support** — `openWithConnectionString()`
- **Explicit lifecycle management** — `Database::close()`

## Requirements

- PHP 8.2+
- `ext-ffi` — PHP FFI extension
- `ext-gmp` — for arbitrary-precision integers in the tuple layer (optional)
- `libfdb_c.so` — FoundationDB C client library ([install guide](https://apple.github.io/foundationdb/getting-started-linux.html))

## Installation

```bash
composer require crazy-goat/foundationdb
```

Make sure `libfdb_c.so` is installed and accessible. On Ubuntu/Debian:

```bash
wget https://github.com/apple/foundationdb/releases/download/7.3.75/foundationdb-clients_7.3.75-1_amd64.deb
sudo dpkg -i foundationdb-clients_7.3.75-1_amd64.deb
```

## Quick Start

```php
use CrazyGoat\FoundationDB\FoundationDB as FDB;
use CrazyGoat\FoundationDB\Transaction;

FDB::apiVersion(730);
$db = FDB::open();

// Simple read/write (auto-transact with retry)
$db->set('hello', 'world');
echo $db->get('hello'); // "world"

// Transactional
$db->transact(function (Transaction $tr) {
    $tr->set('key1', 'value1');
    $tr->set('key2', 'value2');
    $value = $tr->get('key1')->await();
});
```

## Usage

### Range Reads

```php
use CrazyGoat\FoundationDB\RangeOptions;

// Lazy iteration (Generator) — memory-efficient for large ranges
$db->transact(function (Transaction $tr) {
    foreach ($tr->getRangeStartsWith('users/') as $kv) {
        echo $kv->key . ' = ' . $kv->value . "\n";
    }
});

// Eager fetching — all results in memory at once
$results = $db->getRangeAllStartsWith('users/');

// With options
$results = $db->getRangeStartsWith('users/', new RangeOptions(
    limit: 100,
    reverse: true,
));
```

### Tuple Layer

```php
use CrazyGoat\FoundationDB\Tuple\Tuple;

$packed = Tuple::pack(['users', 42, 'name']);
$unpacked = Tuple::unpack($packed); // ['users', 42, 'name']

// Sort-order preserving — binary comparison matches logical order
assert(Tuple::pack([1]) < Tuple::pack([2]));
assert(Tuple::pack(['a']) < Tuple::pack(['b']));

// Compare tuples
Tuple::compare(['users', 1], ['users', 2]); // -1

// Get range boundaries for a tuple prefix
[$begin, $end] = Tuple::range(['users']);
```

### Subspace

```php
use CrazyGoat\FoundationDB\Subspace;

$users = new Subspace(['users']);

$db->transact(function (Transaction $tr) use ($users) {
    $tr->set($users->pack([42, 'name']), 'Alice');
    $tr->set($users->pack([42, 'email']), 'alice@example.com');

    foreach ($tr->getRangeStartsWith($users->pack([42])) as $kv) {
        $tuple = $users->unpack($kv->key);
        // [42, 'name'] or [42, 'email']
    }
});
```

### Directory Layer

```php
use CrazyGoat\FoundationDB\Directory\DirectoryLayer;

$dir = new DirectoryLayer();

$users = $dir->createOrOpen($db, ['app', 'users']);
$orders = $dir->createOrOpen($db, ['app', 'orders']);

// Each directory gets a unique short prefix — no key collisions
$db->set($users->pack([42, 'name']), 'Alice');
$db->set($orders->pack([1001]), 'order data');

// List, move, remove
$subdirs = $dir->list($db, ['app']); // ['users', 'orders']
$dir->move($db, ['app', 'users'], ['app', 'customers']);
$dir->remove($db, ['app', 'orders']);
```

### Atomic Operations

```php
$db->transact(function (Transaction $tr) {
    // Increment a little-endian counter
    $tr->add('counter', pack('P', 1));

    // Bitwise operations
    $tr->bitXor('flag', pack('C', 1));
    $tr->bitOr('permissions', pack('C', 0b00001100));

    // Compare and clear
    $tr->compareAndClear('temp', pack('P', 0));
});
```

### Snapshot Reads

```php
// Read-only transaction with snapshot isolation
$value = $db->readTransact(function ($snap) {
    return $snap->get('key')->await();
});

// Or use snapshot within a write transaction
$db->transact(function (Transaction $tr) {
    // No conflict range added — won't cause transaction conflicts
    $value = $tr->snapshot()->get('frequently-read-key')->await();

    // Selectively add conflict for keys you care about
    $tr->addReadConflictKey('important-key');
});
```

### Watches

```php
// Watch a key — returns FutureVoid that resolves when value changes
$watch = $db->watch('config:version');

// Get current value AND set up watch atomically
[$value, $watch] = $db->getAndWatch('config:version');
echo "Current: {$value}\n";

// Set value AND watch for future changes
$watch = $db->setAndWatch('config:version', '2');

// Clear value AND watch
$watch = $db->clearAndWatch('config:version');
```

### Multi-Tenancy

```php
// Create tenant via admin client
$db->admin()->createTenant('tenant-a');

// Open tenant — transactions are scoped to tenant's key space
$tenant = $db->openTenant('tenant-a');
$tr = $tenant->createTransaction();
$tr->set('key', 'value'); // isolated to tenant-a
$tr->commit()->await();

// Get tenant ID
$id = $tenant->getId();
```

### Admin Client

```php
$admin = $db->admin();

// Tenant management
$admin->createTenant('new-tenant');
$admin->deleteTenant('old-tenant');
$tenants = $admin->listTenants();

// Cluster status
$status = $admin->getClusterStatus(); // array<string, mixed>
$isConsistent = $admin->consistencyCheck();

// Server management
$admin->excludeServer('10.0.0.1:4500');
$admin->includeServer('10.0.0.1:4500');
$admin->rebootWorker('10.0.0.1:4500');

// Configuration
$admin->configure('double ssd');
```

### Options

```php
// Network options (before opening database)
FDB::networkOptions()
    ->setTraceEnable('/var/log/fdb/')
    ->setTraceFormat('json');

// Database-level defaults
$db->options()
    ->setTransactionTimeout(10_000)
    ->setTransactionRetryLimit(5);

// Transaction options (fluent API)
$db->transact(function (Transaction $tr) {
    $tr->options()
        ->setTimeout(5000)
        ->setRetryLimit(3)
        ->setPriorityBatch();
    // ...
});
```

### Error Handling

```php
use CrazyGoat\FoundationDB\FDBException;

try {
    $db->transact(function (Transaction $tr) {
        $tr->set('key', 'value');
    });
} catch (FDBException $e) {
    echo "FDB error {$e->fdbCode}: {$e->getMessage()}\n";

    $e->isRetryable();            // safe to retry?
    $e->isMaybeCommitted();       // may have committed?
    $e->isRetryableNotCommitted(); // safe to retry, definitely not committed?
}
```

### Key Utilities

```php
use CrazyGoat\FoundationDB\KeyUtil;

$end = KeyUtil::strinc('prefix');                // increment last byte
$readable = KeyUtil::printable("\x00\x01\xFF");  // '\x00\x01\xff'
[$begin, $end] = KeyUtil::prefixRange('users/'); // ['users/', 'users0']
```

### Database Monitoring

```php
$busyness = $db->getMainThreadBusyness(); // 0.0 to 1.0
$status = $db->getClientStatus();          // JSON string
```

### Locality API

```php
use CrazyGoat\FoundationDB\Locality;

$boundaries = Locality::getBoundaryKeys($db, "\x00", "\xFF");
```

### Connection Strings

```php
$db = FDB::openWithConnectionString('my_cluster:abc123@127.0.0.1:4500');
```

## Documentation

Detailed documentation is available in the [`docs/`](docs/) directory:

| Guide | Description |
|-------|-------------|
| [Getting Started](docs/getting-started.md) | Installation, configuration, first program |
| [Transactions](docs/transactions.md) | Transaction lifecycle, retry loops, snapshot reads, conflict ranges |
| [Tuple Layer](docs/tuple-layer.md) | Binary encoding, types, comparison, versionstamps |
| [Subspaces](docs/subspaces.md) | Key prefix management, nesting, range queries |
| [Directory Layer](docs/directory-layer.md) | Hierarchical namespaces, partitions, HighContentionAllocator |
| [Range Reads](docs/range-reads.md) | Lazy vs eager fetching, KeySelector, StreamingMode |
| [Atomic Operations](docs/atomic-operations.md) | Counters, bitwise ops, compare-and-clear |
| [Watches](docs/watches.md) | Key monitoring, getAndWatch, setAndWatch |
| [Tenants](docs/tenants.md) | Multi-tenancy, isolated key spaces |
| [Admin Client](docs/admin.md) | Cluster administration, tenant management, status |
| [Options](docs/options.md) | Network, database, and transaction options |
| [Error Handling](docs/error-handling.md) | FDBException, error predicates, retry logic |
| [Advanced](docs/advanced.md) | Locality, KeyUtil, monitoring, Futures, lifecycle |

See also the [`examples/`](examples/) directory for runnable PHP scripts.

## Architecture

```
FoundationDB (entry point)
├── NativeClient (FFI singleton: libfdb_c + libpthread + libdl)
├── Database (FDBDatabase*, retry loop, convenience methods)
│   ├── Transaction (full read/write/atomic/commit)
│   │   ├── ReadTransaction (read operations base)
│   │   └── Snapshot (conflict-free reads)
│   ├── Tenant (multi-tenancy, scoped transactions)
│   └── AdminClient (cluster administration via Special Keys)
├── Future hierarchy (8 types wrapping FDBFuture*)
├── Tuple layer (binary encoding, cross-language compatible)
├── Subspace (key prefix management)
├── Directory layer
│   ├── DirectoryLayer (create/open/move/remove/list/exists)
│   ├── DirectorySubspace (directory + subspace)
│   ├── DirectoryPartition (isolated sub-tree)
│   └── HighContentionAllocator (unique prefix allocation)
├── Locality (data distribution, boundary keys)
├── KeyUtil (strinc, printable, prefixRange)
├── Error handling (FDBException, error predicates)
└── Option wrappers (NetworkOptions, DatabaseOptions, TransactionOptions)
```

## Development

### Prerequisites

- PHP 8.2+ with `ext-ffi`
- Docker + Docker Compose (for integration tests)
- Composer

### Setup

```bash
composer install
```

### Linting

```bash
composer lint          # PHPStan + PHPCS + Rector (dry-run)
composer lint:fix      # Auto-fix code style
composer phpstan       # PHPStan level 9
composer cs            # PHP CodeSniffer
composer rector        # Rector dry-run
```

### Testing

```bash
composer test:unit     # 317 unit tests (no FDB required)
```

Integration tests require a running FoundationDB instance:

```bash
docker compose up -d
docker compose exec php vendor/bin/phpunit --testsuite=Integration
docker compose down -v
```

## License

MIT — see [LICENSE](LICENSE).
