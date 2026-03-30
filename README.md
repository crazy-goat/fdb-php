# FoundationDB PHP Client

A full-featured [FoundationDB](https://www.foundationdb.org/) client for PHP, built on PHP FFI bindings to `libfdb_c.so`.

[![CI](https://github.com/crazy-goat/fdb-php/actions/workflows/ci.yml/badge.svg)](https://github.com/crazy-goat/fdb-php/actions/workflows/ci.yml)
[![License: MIT](https://img.shields.io/badge/License-MIT-blue.svg)](LICENSE)

## Features

- **Full transactional API** — get, set, clear, commit, retry loop with automatic conflict resolution
- **Range reads** — lazy Generator-based iteration with automatic batched pagination
- **Atomic operations** — add, bitAnd, bitOr, bitXor, max, min, compareAndClear, versionstamps
- **Snapshot reads** — conflict-free reads within transactions
- **Watches** — get notified when a key changes
- **Tuple layer** — binary-compatible with Python/Go/Java tuple encoding
- **Subspace** — key prefix management for organizing data
- **Directory layer** — hierarchical namespace management with HighContentionAllocator
- **Typed options** — fluent API for network, database, and transaction options

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

// Simple read/write (auto-transact)
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

// Lazy iteration (Generator)
$db->transact(function (Transaction $tr) {
    foreach ($tr->getRangeStartsWith('users/') as $kv) {
        echo $kv->key . ' = ' . $kv->value . "\n";
    }
});

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

    // Toggle a flag
    $tr->bitXor('flag', pack('C', 1));

    // Compare and clear
    $tr->compareAndClear('temp', pack('P', 0));
});
```

### Snapshot Reads

```php
$db->transact(function (Transaction $tr) {
    // No conflict range added — won't cause transaction conflicts
    $value = $tr->snapshot()->get('frequently-read-key')->await();

    // Selectively add conflict for keys you care about
    $tr->addReadConflictKey('important-key');
});
```

### Options

```php
// Transaction options (fluent API)
$tr = $db->createTransaction();
$tr->options()
    ->setTimeout(5000)
    ->setRetryLimit(3)
    ->setPriorityBatch();

// Database-level defaults
$db->options()
    ->setTransactionTimeout(10_000)
    ->setTransactionRetryLimit(5);

// Network options (before opening database)
FDB::networkOptions()
    ->setTraceEnable('/var/log/fdb/')
    ->setTraceFormat('json');
```

### Watches

```php
$db->transact(function (Transaction $tr) {
    $watch = $tr->watch('config:version');
    $tr->set('config:version', '2');
    // $watch will fire after commit when value changes
});
```

## Architecture

The library mirrors the architecture of official FoundationDB bindings (Python, Go, Ruby):

```
FoundationDB (entry point)
├── NativeClient (FFI singleton: libfdb_c + libpthread + libdl)
├── Database (FDBDatabase*, retry loop, convenience methods)
│   ├── Transaction (full read/write/atomic/commit)
│   │   ├── ReadTransaction (read operations base)
│   │   └── Snapshot (conflict-free reads)
│   └── Tenant (multi-tenancy)
├── Future hierarchy (8 types wrapping FDBFuture*)
├── Tuple layer (binary encoding, cross-language compatible)
├── Subspace (key prefix management)
├── Directory layer
│   ├── DirectoryLayer (create/open/move/remove/list)
│   ├── DirectorySubspace (directory + subspace)
│   ├── DirectoryPartition (isolated sub-tree)
│   └── HighContentionAllocator (unique prefix allocation)
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
composer test:unit     # 229 unit tests (no FDB required)
```

Integration tests require a running FoundationDB instance:

```bash
docker compose up -d
docker compose exec php vendor/bin/phpunit --testsuite=Integration
docker compose down -v
```

## License

MIT — see [LICENSE](LICENSE).
