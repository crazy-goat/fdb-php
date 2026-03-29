# FoundationDB PHP Client — Design Specification

**Date**: 2026-03-30
**Namespace**: `CrazyGoat\FoundationDB`
**Repository**: https://github.com/crazy-goat/fdb-php
**PHP Version**: 8.2+ (FFI required)
**License**: MIT

## Overview

A full-featured FoundationDB client for PHP using the FFI extension to bind to `libfdb_c.so`. The architecture mirrors the official Python, Ruby, and Go bindings — a thin wrapper around the C client library providing idiomatic PHP access to all FoundationDB features.

### Why FFI (not pure PHP, not C extension)

All official FDB bindings (Python/ctypes, Ruby/ffi, Go/cgo, Java/JNI) wrap `libfdb_c.so`. The FDB wire protocol is undocumented, unstable, and changes with every release. No third-party reimplementation exists in any language. FFI provides:

- Zero compilation — works with `composer require`
- Identical architecture to the Python binding (the reference implementation)
- Full access to all `libfdb_c` features
- Path to a C extension later if performance requires it

### Requirements

- PHP 8.2+ with `ext-ffi` enabled
- `libfdb_c.so` installed (`apt install foundationdb-clients` or equivalent)
- `ext-gmp` optional (required only for integers > 8 bytes in tuple layer)

---

## Architecture

### Component Map

```
CrazyGoat\FoundationDB\
├── FoundationDB                    — Static entry point (apiVersion, open, options)
├── NativeClient                    — FFI singleton (libfdb_c + libpthread)
├── FDBException                    — Error with FDB code + lazy message
│
├── Future\                         — Async result wrappers
│   ├── Future (abstract)
│   ├── FutureVoid
│   ├── FutureInt64
│   ├── FutureValue
│   ├── FutureKey
│   ├── FutureKeyValueArray
│   ├── FutureKeyArray
│   └── FutureStringArray
│
├── Database                        — FDBDatabase* wrapper
├── Tenant                          — FDBTenant* wrapper
├── ReadTransaction                 — Read-only transaction operations
├── Transaction                     — Full transaction (extends ReadTransaction)
├── Snapshot                        — Snapshot reads (extends ReadTransaction)
│
├── Option\                         — Typed option setters
│   ├── NetworkOptions
│   ├── DatabaseOptions
│   └── TransactionOptions
│
├── Enum\                           — PHP backed enums
│   ├── StreamingMode
│   ├── MutationType
│   └── ConflictRangeType
│
├── KeySelector                     — Key selector (key, orEqual, offset)
├── KeyValue                        — Readonly key-value pair
├── RangeOptions                    — Readonly range query config
├── RangeResult                     — Lazy batched range iteration
│
├── Tuple\                          — Tuple layer (pure PHP)
│   ├── Tuple
│   ├── Bytes
│   ├── SingleFloat
│   ├── Uuid
│   └── Versionstamp
│
├── Subspace                        — Key prefix management (pure PHP)
│
└── Directory\                      — Directory layer (pure PHP, transactional)
    ├── DirectoryLayer
    ├── DirectorySubspace
    ├── DirectoryPartition
    └── HighContentionAllocator
```

### Interfaces

```php
interface Transactor {
    public function transact(callable $fn): mixed;
}

interface ReadTransactor {
    public function readTransact(callable $fn): mixed;
}

interface KeyConvertible {
    public function asFoundationDbKey(): string;
}
```

`Database` implements `Transactor`, `ReadTransactor`.
`Transaction` implements `Transactor`.
`Snapshot` implements `ReadTransactor`.
`Subspace`, `DirectorySubspace` implement `KeyConvertible`.

---

## Component Details

### 1. NativeClient (FFI Layer)

Singleton responsible for loading `libfdb_c.so` and `libpthread.so`, declaring all C functions, and managing the network thread lifecycle.

**C function declarations** (~42 functions, matching Python binding exactly):

```c
// Types
typedef int fdb_error_t;
typedef int fdb_bool_t;
typedef struct FDB_future FDBFuture;
typedef struct FDB_database FDBDatabase;
typedef struct FDB_tenant FDBTenant;
typedef struct FDB_transaction FDBTransaction;

typedef struct {
    const void* key;
    int key_length;
    const void* value;
    int value_length;
} FDBKeyValue;

typedef struct {
    const void* key;
    int key_length;
} FDBKey;

// Network
fdb_error_t fdb_select_api_version_impl(int runtime_version, int header_version);
int fdb_get_max_api_version(void);
const char* fdb_get_error(fdb_error_t code);
fdb_error_t fdb_network_set_option(int option, const void* value, int value_length);
fdb_error_t fdb_setup_network(void);
fdb_error_t fdb_run_network(void);
fdb_error_t fdb_stop_network(void);

// Future
void fdb_future_destroy(FDBFuture* f);
void fdb_future_release_memory(FDBFuture* f);
void fdb_future_cancel(FDBFuture* f);
fdb_error_t fdb_future_block_until_ready(FDBFuture* f);
fdb_bool_t fdb_future_is_ready(FDBFuture* f);
fdb_error_t fdb_future_get_error(FDBFuture* f);
fdb_error_t fdb_future_get_int64(FDBFuture* f, int64_t* out);
fdb_error_t fdb_future_get_uint64(FDBFuture* f, uint64_t* out);
fdb_error_t fdb_future_get_key(FDBFuture* f, const uint8_t** out_key, int* out_key_length);
fdb_error_t fdb_future_get_value(FDBFuture* f, fdb_bool_t* out_present, const uint8_t** out_value, int* out_value_length);
fdb_error_t fdb_future_get_keyvalue_array(FDBFuture* f, const FDBKeyValue** out_kv, int* out_count, fdb_bool_t* out_more);
fdb_error_t fdb_future_get_key_array(FDBFuture* f, const FDBKey** out_keys, int* out_count);
fdb_error_t fdb_future_get_string_array(FDBFuture* f, const char*** out_strings, int* out_count);

// Database
fdb_error_t fdb_create_database(const char* cluster_file_path, FDBDatabase** out_database);
void fdb_database_destroy(FDBDatabase* d);
fdb_error_t fdb_database_set_option(FDBDatabase* d, int option, const void* value, int value_length);
fdb_error_t fdb_database_create_transaction(FDBDatabase* d, FDBTransaction** out_transaction);
fdb_error_t fdb_database_open_tenant(FDBDatabase* d, const uint8_t* tenant_name, int tenant_name_length, FDBTenant** out_tenant);

// Tenant
void fdb_tenant_destroy(FDBTenant* t);
fdb_error_t fdb_tenant_create_transaction(FDBTenant* t, FDBTransaction** out_transaction);

// Transaction
void fdb_transaction_destroy(FDBTransaction* tr);
void fdb_transaction_cancel(FDBTransaction* tr);
fdb_error_t fdb_transaction_set_option(FDBTransaction* tr, int option, const void* value, int value_length);
void fdb_transaction_set_read_version(FDBTransaction* tr, int64_t version);
FDBFuture* fdb_transaction_get_read_version(FDBTransaction* tr);
FDBFuture* fdb_transaction_get(FDBTransaction* tr, const uint8_t* key_name, int key_name_length, fdb_bool_t snapshot);
FDBFuture* fdb_transaction_get_key(FDBTransaction* tr, const uint8_t* key_name, int key_name_length, fdb_bool_t or_equal, int offset, fdb_bool_t snapshot);
FDBFuture* fdb_transaction_get_range(FDBTransaction* tr, const uint8_t* begin_key_name, int begin_key_name_length, fdb_bool_t begin_or_equal, int begin_offset, const uint8_t* end_key_name, int end_key_name_length, fdb_bool_t end_or_equal, int end_offset, int limit, int target_bytes, int streaming_mode, int iteration, fdb_bool_t snapshot, fdb_bool_t reverse);
FDBFuture* fdb_transaction_get_estimated_range_size_bytes(FDBTransaction* tr, const uint8_t* begin_key_name, int begin_key_name_length, const uint8_t* end_key_name, int end_key_name_length);
FDBFuture* fdb_transaction_get_range_split_points(FDBTransaction* tr, const uint8_t* begin_key_name, int begin_key_name_length, const uint8_t* end_key_name, int end_key_name_length, int64_t chunk_size);
FDBFuture* fdb_transaction_get_addresses_for_key(FDBTransaction* tr, const uint8_t* key_name, int key_name_length);
void fdb_transaction_set(FDBTransaction* tr, const uint8_t* key_name, int key_name_length, const uint8_t* value, int value_length);
void fdb_transaction_clear(FDBTransaction* tr, const uint8_t* key_name, int key_name_length);
void fdb_transaction_clear_range(FDBTransaction* tr, const uint8_t* begin_key_name, int begin_key_name_length, const uint8_t* end_key_name, int end_key_name_length);
void fdb_transaction_atomic_op(FDBTransaction* tr, const uint8_t* key_name, int key_name_length, const uint8_t* param, int param_length, int operation_type);
FDBFuture* fdb_transaction_commit(FDBTransaction* tr);
FDBFuture* fdb_transaction_get_committed_version(FDBTransaction* tr, int64_t* version);
FDBFuture* fdb_transaction_get_approximate_size(FDBTransaction* tr);
FDBFuture* fdb_transaction_get_versionstamp(FDBTransaction* tr);
FDBFuture* fdb_transaction_watch(FDBTransaction* tr, const uint8_t* key_name, int key_name_length);
FDBFuture* fdb_transaction_on_error(FDBTransaction* tr, fdb_error_t error);
void fdb_transaction_reset(FDBTransaction* tr);
fdb_error_t fdb_transaction_add_conflict_range(FDBTransaction* tr, const uint8_t* begin_key_name, int begin_key_name_length, const uint8_t* end_key_name, int end_key_name_length, int type);
```

**Network thread** — started via `pthread_create` through FFI to `libpthread.so`:

```c
// libpthread declarations
typedef unsigned long pthread_t;
int pthread_create(pthread_t* thread, const void* attr, void* (*start_routine)(void*), void* arg);
int pthread_join(pthread_t thread, void** retval);
```

The network thread is started lazily on first `FoundationDB::open()` call (Go pattern). Shutdown via `register_shutdown_function` calling `fdb_stop_network()` + `pthread_join()`.

**Error checking**: Every C function returning `fdb_error_t` is checked. Non-zero throws `FDBException`.

### 2. FDBException

```php
class FDBException extends \RuntimeException
{
    public function __construct(
        public readonly int $fdbCode,
    ) {
        parent::__construct(
            FFI::string(NativeClient::getInstance()->fdb->fdb_get_error($fdbCode)),
            $fdbCode
        );
    }
}
```

Message resolved eagerly from `fdb_get_error()` at construction time. The `$fdbCode` is the raw FDB error code (e.g., 1020 = not_committed, 1021 = commit_unknown_result).

### 3. Future Hierarchy

All futures wrap an `FDBFuture*` pointer. Base class provides blocking and lifecycle:

```php
abstract class Future
{
    protected bool $resolved = false;
    protected mixed $cachedResult = null;

    public function __construct(
        protected \FFI\CData $fpointer,
    ) {}

    public function isReady(): bool;          // fdb_future_is_ready
    public function cancel(): void;           // fdb_future_cancel
    abstract public function wait(): mixed;   // block + extract

    protected function blockUntilReady(): void
    {
        // fdb_future_block_until_ready($this->fpointer)
        // fdb_future_get_error($this->fpointer) → throw on error
    }

    public function __destruct()
    {
        // fdb_future_destroy($this->fpointer)
    }
}
```

**Concrete futures:**

| Class | C getter | Return type |
|-------|----------|-------------|
| `FutureVoid` | `fdb_future_get_error` only | `void` |
| `FutureInt64` | `fdb_future_get_int64` | `int` |
| `FutureValue` | `fdb_future_get_value` | `?string` (null = not found) |
| `FutureKey` | `fdb_future_get_key` | `string` |
| `FutureKeyValueArray` | `fdb_future_get_keyvalue_array` | `FutureKvResult{kvs, count, more}` |
| `FutureKeyArray` | `fdb_future_get_key_array` | `string[]` |
| `FutureStringArray` | `fdb_future_get_string_array` | `string[]` |

Results are cached after first `wait()`. `fdb_future_release_memory()` is called after extraction to free C-side buffers.

### 4. Database

Wraps `FDBDatabase*`. Cached by cluster file path (like Python/Ruby).

```php
class Database implements Transactor, ReadTransactor
{
    public readonly DatabaseOptions $options;

    public function createTransaction(): Transaction;
    public function openTenant(string $name): Tenant;

    // Retry loop with commit
    public function transact(callable $fn): mixed
    {
        $tr = $this->createTransaction();
        while (true) {
            try {
                $result = $fn($tr);
                $tr->commit()->wait();
                return $result;
            } catch (FDBException $e) {
                $tr->onError($e->fdbCode)->wait();
            }
        }
    }

    // Retry loop without commit (read-only)
    public function readTransact(callable $fn): mixed
    {
        $tr = $this->createTransaction();
        while (true) {
            try {
                return $fn($tr->snapshot());
            } catch (FDBException $e) {
                $tr->onError($e->fdbCode)->wait();
            }
        }
    }

    // Convenience methods (each creates a one-shot transaction)
    public function get(string|KeyConvertible $key): ?string;
    public function set(string|KeyConvertible $key, string $value): void;
    public function clear(string|KeyConvertible $key): void;
    public function clearRange(string $begin, string $end): void;
    public function clearRangeStartsWith(string $prefix): void;
    public function getRange(...): array;
    public function getRangeStartsWith(...): array;
    public function getAndWatch(string $key): array;  // [?string, FutureVoid]
    public function setAndWatch(string $key, string $value): FutureVoid;
    public function clearAndWatch(string $key): FutureVoid;

    public function __destruct(); // fdb_database_destroy
}
```

### 5. Tenant

```php
class Tenant
{
    public function createTransaction(): Transaction;
    public function __destruct(); // fdb_tenant_destroy
}
```

### 6. ReadTransaction, Transaction, Snapshot

**ReadTransaction** — base class for read operations:

```php
class ReadTransaction
{
    public function __construct(
        protected \FFI\CData $tpointer,
        protected Database $db,
        protected bool $isSnapshot,
    ) {}

    public function get(string|KeyConvertible $key): FutureValue;
    public function getKey(KeySelector $selector): FutureKey;
    public function getRange(
        string|KeySelector $begin,
        string|KeySelector $end,
        ?RangeOptions $options = null,
    ): RangeResult;
    public function getRangeStartsWith(
        string $prefix,
        ?RangeOptions $options = null,
    ): RangeResult;
    public function getReadVersion(): FutureInt64;
    public function getEstimatedRangeSizeBytes(string $begin, string $end): FutureInt64;
    public function getRangeSplitPoints(string $begin, string $end, int $chunkSize): FutureKeyArray;
    public function getAddressesForKey(string $key): FutureStringArray;

    // Composability — passthrough
    public function transact(callable $fn): mixed { return $fn($this); }
}
```

**Transaction** — extends with write operations:

```php
class Transaction extends ReadTransaction implements Transactor
{
    private ?Snapshot $snapshotInstance = null;
    public readonly TransactionOptions $options;

    // Write operations
    public function set(string|KeyConvertible $key, string $value): void;
    public function clear(string|KeyConvertible $key): void;
    public function clearRange(string $begin, string $end): void;
    public function clearRangeStartsWith(string $prefix): void;

    // Atomic operations
    public function add(string $key, string $param): void;
    public function bitAnd(string $key, string $param): void;
    public function bitOr(string $key, string $param): void;
    public function bitXor(string $key, string $param): void;
    public function max(string $key, string $param): void;
    public function min(string $key, string $param): void;
    public function byteMax(string $key, string $param): void;
    public function byteMin(string $key, string $param): void;
    public function compareAndClear(string $key, string $param): void;
    public function setVersionstampedKey(string $key, string $value): void;
    public function setVersionstampedValue(string $key, string $param): void;

    // Transaction lifecycle
    public function commit(): FutureVoid;
    public function watch(string $key): FutureVoid;
    public function onError(int $code): FutureVoid;
    public function reset(): void;
    public function cancel(): void;
    public function setReadVersion(int $version): void;
    public function getCommittedVersion(): int;
    public function getApproximateSize(): FutureInt64;
    public function getVersionstamp(): FutureKey;

    // Conflict ranges
    public function addReadConflictRange(string $begin, string $end): void;
    public function addWriteConflictRange(string $begin, string $end): void;
    public function addReadConflictKey(string $key): void;
    public function addWriteConflictKey(string $key): void;

    // Snapshot accessor
    public function snapshot(): Snapshot;

    // Composability — passthrough (no commit, no retry)
    public function transact(callable $fn): mixed { return $fn($this); }

    public function __destruct(); // fdb_transaction_destroy
}
```

**Snapshot** — thin wrapper forcing `isSnapshot=true`:

```php
class Snapshot extends ReadTransaction implements ReadTransactor
{
    // Constructor receives same tpointer as parent Transaction, isSnapshot=true
    // All read methods inherited from ReadTransaction pass isSnapshot=1 to C API

    public function readTransact(callable $fn): mixed { return $fn($this); }
}
```

The `isSnapshot` flag is passed as the last parameter to `fdb_transaction_get()`, `fdb_transaction_get_key()`, and `fdb_transaction_get_range()`.

### 7. KeySelector

```php
final readonly class KeySelector
{
    public function __construct(
        public string $key,
        public bool $orEqual,
        public int $offset,
    ) {}

    public static function lastLessThan(string $key): self;
    public static function lastLessOrEqual(string $key): self;
    public static function firstGreaterThan(string $key): self;
    public static function firstGreaterOrEqual(string $key): self;

    public function add(int $offset): self;       // returns new instance
    public function subtract(int $offset): self;  // returns new instance
}
```

### 8. RangeResult

Lazy batched iteration using PHP generators. First batch is fetched eagerly in constructor (like Python/Ruby).

```php
class RangeResult implements \IteratorAggregate
{
    public function getIterator(): \Generator
    {
        // Pagination loop:
        // 1. Call fdb_transaction_get_range with current selectors
        // 2. Wait for FutureKeyValueArray
        // 3. Yield each KeyValue
        // 4. If more && limit not reached: advance selectors, increment iteration
        // 5. Repeat
    }

    public function toArray(): array
    {
        // Switches streaming mode to WantAll/Exact for efficiency
        return iterator_to_array($this->getIterator(), false);
    }
}
```

### 9. Options

Three option classes, each wrapping the corresponding `fdb_*_set_option` C function:

```php
class NetworkOptions  { /* setTraceEnable, setTlsCertPath, ... */ }
class DatabaseOptions { /* setLocationCacheSize, setMaxWatches, ... */ }
class TransactionOptions { /* setTimeout, setRetryLimit, ... */ }
```

Each method delegates to a shared `setOption(int $code, ?string $param)`:
- Flag options (no parameter): `setOption($code, null)`
- String options: `setOption($code, $value)`
- Integer options: `setOption($code, pack('P', $value))` — 8 bytes little-endian

Options will be hand-written initially, with a generator script added later to produce them from the `fdb.options` XML source.

### 10. Enums

```php
enum StreamingMode: int
{
    case WantAll = -2;
    case Iterator = -1;
    case Exact = -3;
    case Small = -4;
    case Medium = -5;
    case Large = -6;
    case Serial = -7;
}

enum MutationType: int
{
    case Add = 2;
    case BitAnd = 6;
    case BitOr = 7;
    case BitXor = 8;
    case AppendIfFits = 9;
    case Max = 12;
    case Min = 13;
    case SetVersionstampedKey = 14;
    case SetVersionstampedValue = 15;
    case ByteMin = 16;
    case ByteMax = 17;
    case CompareAndClear = 20;
}

enum ConflictRangeType: int
{
    case Read = 0;
    case Write = 1;
}
```

### 11. Tuple Layer (Pure PHP)

Implements the standard FoundationDB tuple encoding. Compatible with all other language bindings.

**Type codes:**

| Code | PHP Type | Notes |
|------|----------|-------|
| `0x00` | `null` | Single byte |
| `0x01` | `Bytes` wrapper | Binary data, null-escaped |
| `0x02` | `string` | UTF-8 string, null-escaped |
| `0x05` | `array` | Nested tuple, recursive |
| `0x0b`–`0x1d` | `int` or `\GMP` | Variable-length big-endian |
| `0x20` | `SingleFloat` | IEEE 754 float32, sign-adjusted |
| `0x21` | `float` | IEEE 754 float64, sign-adjusted |
| `0x26` | `false` | Single byte |
| `0x27` | `true` | Single byte |
| `0x30` | `Uuid` | 16 raw bytes |
| `0x33` | `Versionstamp` | 10-byte version + 2-byte user |

**Bytes vs string distinction**: PHP has no separate bytes type. The `Bytes` wrapper class signals binary encoding (`0x01`). Plain `string` uses UTF-8 encoding (`0x02`).

**Large integers**: PHP `int` is 64-bit. Values exceeding 8 bytes require `ext-gmp`. An exception is thrown if GMP is unavailable and a large integer is encountered.

**API:**

```php
Tuple::pack(array $elements, string $prefix = ''): string
Tuple::unpack(string $data, int $prefixLength = 0): array
Tuple::range(array $elements): array{string, string}
Tuple::packWithVersionstamp(array $elements, string $prefix = ''): string
```

**Helper types:**

```php
final readonly class Bytes { public string $data; }
final readonly class SingleFloat { public float $value; }
final readonly class Uuid { public string $bytes; } // 16 bytes
final class Versionstamp {
    public string $trVersion;   // 10 bytes
    public int $userVersion;    // 0-65535
    public function isComplete(): bool;
}
```

### 12. Subspace (Pure PHP)

```php
class Subspace implements KeyConvertible
{
    public readonly string $rawPrefix;

    public function __construct(array $prefixTuple = [], string $rawPrefix = '');
    public function key(): string;
    public function pack(array $tuple = []): string;
    public function unpack(string $key): array;
    public function range(array $tuple = []): array{string, string};
    public function contains(string $key): bool;
    public function subspace(mixed $element): self;
    public function asFoundationDbKey(): string;
}
```

### 13. Directory Layer (Pure PHP, Transactional)

All operations accept `Transactor` — work with both `Database` (auto-transact) and `Transaction` (passthrough).

**DirectoryLayer:**

```php
class DirectoryLayer
{
    public function __construct(
        ?Subspace $nodeSubspace = null,     // default: \xFE prefix
        ?Subspace $contentSubspace = null,  // default: empty prefix
    );

    public function createOrOpen(Transactor $dbOrTr, array $path, string $layer = ''): DirectorySubspace;
    public function create(Transactor $dbOrTr, array $path, string $layer = '', ?string $prefix = null): DirectorySubspace;
    public function open(Transactor $dbOrTr, array $path, string $layer = ''): DirectorySubspace;
    public function move(Transactor $dbOrTr, array $oldPath, array $newPath): DirectorySubspace;
    public function remove(Transactor $dbOrTr, array $path): bool;
    public function removeIfExists(Transactor $dbOrTr, array $path): bool;
    public function list(Transactor $dbOrTr, array $path = []): array;
    public function exists(Transactor $dbOrTr, array $path): bool;
}
```

**DirectorySubspace** extends `Subspace`, implements directory operations for subdirectories.

**DirectoryPartition** extends `DirectorySubspace`. Isolated sub-tree with its own `DirectoryLayer`. Blocks `pack()`/`unpack()`/`key()` — must open subdirectories.

**HighContentionAllocator** — windowed random prefix allocation:
- Window sizes: `<255 → 64`, `<65535 → 1024`, `≥65535 → 8192`
- Uses snapshot reads + explicit write conflict keys for high concurrency
- Returns tuple-encoded integer as prefix bytes

### 14. Entry Point

```php
final class FoundationDB
{
    public static function apiVersion(int $version): void;
    public static function open(?string $clusterFile = null): Database;
    public static function options(): NetworkOptions;
    public static function getMaxApiVersion(): int;
}
```

`open()` caches databases by cluster file path. Automatically starts the network thread on first call.

---

## Usage Examples

### Basic Operations

```php
use CrazyGoat\FoundationDB\FoundationDB as FDB;
use CrazyGoat\FoundationDB\Transaction;

FDB::apiVersion(730);
$db = FDB::open();

// Simple transactional read/write
$db->transact(function (Transaction $tr) {
    $tr->set('hello', 'world');
    $value = $tr->get('foo')->wait();
    $tr->clear('bar');
});

// Convenience (auto-transact)
$db->set('key', 'value');
$value = $db->get('key');
```

### Range Reads

```php
$db->transact(function (Transaction $tr) {
    // Lazy iteration with generator
    foreach ($tr->getRange('a', 'z') as $kv) {
        echo $kv->key . ' = ' . $kv->value . "\n";
    }

    // Prefix scan
    foreach ($tr->getRangeStartsWith('user:') as $kv) {
        // ...
    }

    // With options
    $range = $tr->getRange('a', 'z', new RangeOptions(limit: 100, reverse: true));
    $all = $range->toArray();
});
```

### Tuple Layer + Subspace

```php
use CrazyGoat\FoundationDB\Tuple\Tuple;
use CrazyGoat\FoundationDB\Subspace;

$users = new Subspace(['users']);

$db->transact(function (Transaction $tr) use ($users) {
    $tr->set($users->pack([42, 'name']), 'Alice');
    $tr->set($users->pack([42, 'email']), 'alice@example.com');

    // Range read all attributes of user 42
    foreach ($tr->getRangeStartsWith($users->pack([42])) as $kv) {
        $tuple = $users->unpack($kv->key);
        // $tuple = [42, 'name'] or [42, 'email']
    }
});
```

### Directory Layer

```php
use CrazyGoat\FoundationDB\Directory\DirectoryLayer;

$dir = new DirectoryLayer();
$users = $dir->createOrOpen($db, ['app', 'users']);
$orders = $dir->createOrOpen($db, ['app', 'orders']);

$db->transact(function (Transaction $tr) use ($users) {
    $tr->set($users->pack([42, 'name']), 'Alice');
});
```

### Atomic Operations

```php
$db->transact(function (Transaction $tr) {
    // Increment counter
    $tr->add('counter', pack('P', 1));

    // Toggle flag
    $tr->bitXor('flag', pack('C', 1));

    // Compare and clear
    $tr->compareAndClear('temp_key', pack('P', 0));
});
```

### Snapshot Reads

```php
$db->transact(function (Transaction $tr) {
    // Snapshot read — no conflict range added
    $value = $tr->snapshot()->get('frequently-read-key')->wait();

    // Selectively add conflict for the key we care about
    $tr->addReadConflictKey('important-key');
});
```

### Watches

```php
$watch = $db->setAndWatch('config:version', '2');
// ... do other work ...
$watch->wait(); // blocks until value changes
```

### Key Selectors

```php
use CrazyGoat\FoundationDB\KeySelector;

$db->transact(function (Transaction $tr) {
    // Get the key after 'apple'
    $key = $tr->getKey(KeySelector::firstGreaterThan('apple'))->wait();

    // Inclusive range read
    $range = $tr->getRange(
        KeySelector::firstGreaterOrEqual('a'),
        KeySelector::firstGreaterThan('z'),
    );
});
```

---

## Memory Management

| Object | C destructor | PHP mechanism |
|--------|-------------|---------------|
| `Future` | `fdb_future_destroy` | `__destruct()` |
| `Database` | `fdb_database_destroy` | `__destruct()` |
| `Tenant` | `fdb_tenant_destroy` | `__destruct()` |
| `Transaction` | `fdb_transaction_destroy` | `__destruct()` on Transaction (not Snapshot) |
| Network | `fdb_stop_network` + `pthread_join` | `register_shutdown_function` |

Transaction owns the C pointer. Snapshot holds a reference to the parent Transaction (preventing GC) but does not destroy the pointer.

---

## Testing Strategy

- **Unit tests** (no FDB required): Tuple encoding/decoding, Subspace pack/unpack, KeySelector logic, RangeOptions, Bytes/SingleFloat/Uuid/Versionstamp
- **Integration tests** (require running FDB): Transaction CRUD, retry loop, range reads, atomic operations, watches, directories, tenant support
- **Compatibility tests**: Verify tuple encoding matches Python/Go/Ruby output for known inputs
- **PHPUnit** as test framework, PHPStan level 8 for static analysis

---

## File Structure

```
fdb-php/
├── composer.json
├── phpunit.xml
├── phpstan.neon
├── src/
│   ├── FoundationDB.php
│   ├── NativeClient.php
│   ├── FDBException.php
│   ├── Database.php
│   ├── Tenant.php
│   ├── ReadTransaction.php
│   ├── Transaction.php
│   ├── Snapshot.php
│   ├── KeySelector.php
│   ├── KeyValue.php
│   ├── RangeOptions.php
│   ├── RangeResult.php
│   ├── Transactor.php              (interface)
│   ├── ReadTransactor.php          (interface)
│   ├── KeyConvertible.php          (interface)
│   ├── Future/
│   │   ├── Future.php
│   │   ├── FutureVoid.php
│   │   ├── FutureInt64.php
│   │   ├── FutureValue.php
│   │   ├── FutureKey.php
│   │   ├── FutureKeyValueArray.php
│   │   ├── FutureKeyArray.php
│   │   ├── FutureStringArray.php
│   │   └── FutureKvResult.php
│   ├── Option/
│   │   ├── NetworkOptions.php
│   │   ├── DatabaseOptions.php
│   │   └── TransactionOptions.php
│   ├── Enum/
│   │   ├── StreamingMode.php
│   │   ├── MutationType.php
│   │   └── ConflictRangeType.php
│   ├── Tuple/
│   │   ├── Tuple.php
│   │   ├── Bytes.php
│   │   ├── SingleFloat.php
│   │   ├── Uuid.php
│   │   └── Versionstamp.php
│   ├── Subspace.php
│   └── Directory/
│       ├── DirectoryLayer.php
│       ├── DirectorySubspace.php
│       ├── DirectoryPartition.php
│       └── HighContentionAllocator.php
├── tests/
│   ├── Unit/
│   │   ├── Tuple/
│   │   ├── SubspaceTest.php
│   │   └── KeySelectorTest.php
│   └── Integration/
│       ├── BasicCrudTest.php
│       ├── TransactionTest.php
│       ├── RangeReadTest.php
│       ├── AtomicOperationsTest.php
│       ├── WatchTest.php
│       ├── DirectoryTest.php
│       └── TenantTest.php
└── docs/
    └── 2026-03-30-fdb-php-design.md
```
