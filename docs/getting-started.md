# Getting Started with FoundationDB PHP Client

This guide will help you get up and running with the FoundationDB PHP client library.

## Prerequisites

Before you begin, ensure you have the following:

- **PHP 8.2+** with the `ext-ffi` extension enabled
- **ext-gmp** (optional, but recommended for arbitrary-precision integers in the tuple layer)
- **FoundationDB server** (for running operations)
- **libfdb_c.so** (FoundationDB C client library)

## Installing FoundationDB

### Ubuntu/Debian

Download and install the FoundationDB client library from the official GitHub releases:

```bash
# Download and install the client library
wget https://github.com/apple/foundationdb/releases/download/7.3.75/foundationdb-clients_7.3.75-1_amd64.deb
sudo dpkg -i foundationdb-clients_7.3.75-1_amd64.deb
```

For local development, also install the FoundationDB server:

```bash
# Download and install the server
wget https://github.com/apple/foundationdb/releases/download/7.3.75/foundationdb-server_7.3.75-1_amd64.deb
sudo dpkg -i foundationdb-server_7.3.75-1_amd64.deb
```

### macOS

Download the `.pkg` installer from the [FoundationDB releases page](https://github.com/apple/foundationdb/releases) and run it.

### Docker

A `docker-compose.yml` file is available in this repository for easy local development with Docker.

## Installing the PHP Library

Install the library via Composer:

```bash
composer require crazy-goat/fdb-php
```

## Verifying PHP Extensions

### Check FFI Extension

```bash
php -m | grep FFI
```

If not enabled, add the following to your `php.ini`:

```ini
ffi.enable=true
```

### Check GMP Extension (Optional)

```bash
php -m | grep gmp
```

The GMP extension is required for working with arbitrary-precision integers in the tuple layer.

## Your First Program

Here is a complete working example to get you started:

```php
<?php
require_once __DIR__ . '/vendor/autoload.php';

use CrazyGoat\FoundationDB\FoundationDB as FDB;
use CrazyGoat\FoundationDB\Transaction;

// 1. Select API version (must be called once, before anything else)
FDB::apiVersion(730);

// 2. Open a database connection
$db = FDB::open();

// 3. Write data
$db->set('hello', 'world');

// 4. Read data
$value = $db->get('hello');
echo "hello = {$value}\n"; // hello = world

// 5. Use a transaction for multiple operations
$db->transact(function (Transaction $tr) {
    $tr->set('user:1:name', 'Alice');
    $tr->set('user:1:email', 'alice@example.com');
    
    $name = $tr->get('user:1:name')->await();
    echo "Name: {$name}\n";
});

// 6. Clean up
$db->clear('hello');
```

## Understanding the API Version

The `FDB::apiVersion(730)` call is crucial and must be made exactly once per process, before any other FoundationDB operations.

- **Locks API behavior**: The version number locks the API behavior to that specific version
- **Called once per process**: Must be called before any other FDB calls
- **Version correspondence**: The version number corresponds to the FoundationDB release (7.3.x = 730)
- **Maximum version**: Use `FDB::getMaxApiVersion()` to get the maximum API version supported by your installed client library

## Opening a Database

There are several ways to open a database connection:

### Default Cluster File

```php
$db = FDB::open();
```

Uses the default cluster file or the `FDB_CLUSTER_FILE` environment variable.

### Explicit Cluster File

```php
$db = FDB::open('/path/to/fdb.cluster');
```

### Connection String

```php
$db = FDB::openWithConnectionString('description:id@host:port');
```

### Database Instance Caching

Database instances are cached. Calling `open()` multiple times with the same arguments will return the same instance.

## Basic Operations

The `Database` class provides convenient methods for common operations:

| Method | Description |
|--------|-------------|
| `$db->get($key)` | Returns `?string` (null if key doesn't exist) |
| `$db->set($key, $value)` | Write a key-value pair |
| `$db->clear($key)` | Delete a single key |
| `$db->clearRange($begin, $end)` | Delete a range of keys |
| `$db->clearRangeStartsWith($prefix)` | Delete all keys with a given prefix |

All `Database` convenience methods automatically wrap operations in transactions with automatic retry logic.

## Transactions

For atomic, multi-operation transactions, use the `transact()` method:

```php
$db->transact(function (Transaction $tr) {
    // Reads return Futures - must call await()
    $value = $tr->get('key')->await();
    
    // Writes are immediate (no Future)
    $tr->set('key', 'value');
});
```

The `transact()` method provides:
- **Automatic retry**: On conflicts or transient errors
- **Atomic operations**: All operations succeed or fail together

For full details on transactions, see [docs/transactions.md](transactions.md).

## Next Steps

Now that you have the basics, explore these topics:

- **[Transactions in depth](transactions.md)** — Advanced transaction patterns and error handling
- **[Range reads](range-reads.md)** — Querying multiple keys efficiently
- **[Tuple layer](tuple-layer.md)** — Structured key encoding for complex data types
- **[Subspaces](subspaces.md)** — Organizing your keyspace
- **[Directory layer](directory-layer.md)** — Dynamic keyspace management
