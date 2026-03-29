# FoundationDB PHP Client — Implementation Plan

**Design spec**: `docs/2026-03-30-fdb-php-design.md`
**Repository**: https://github.com/crazy-goat/fdb-php

---

## Phases Overview

The implementation is split into 7 phases. Each phase produces working, tested code that builds on the previous phase. Phases 1–4 are the core (usable client). Phases 5–7 add higher-level layers.

| Phase | What | Files | Depends on | Testable without FDB |
|-------|------|-------|------------|---------------------|
| 1 | Project scaffold + Tuple layer | 12 | — | Yes |
| 2 | FFI layer + Network lifecycle | 4 | Phase 1 | No |
| 3 | Future hierarchy + Database + Transaction | 16 | Phase 2 | No |
| 4 | Range reads + Options + Enums + Convenience | 10 | Phase 3 | No |
| 5 | Snapshot + Conflict ranges + Atomic ops + Watches | 2 | Phase 4 | No |
| 6 | Subspace | 2 | Phase 1 | Yes |
| 7 | Directory layer | 5 | Phase 6 + Phase 4 | No |

---

## Phase 1: Project Scaffold + Tuple Layer

**Goal**: Working composer package with the tuple layer fully implemented and tested. This phase requires no FDB installation — pure PHP.

### Task 1.1: Project scaffold

Create the base project structure with all configuration files.

**Files to create:**

- `composer.json` — package `crazy-goat/foundationdb`, autoload `CrazyGoat\FoundationDB\` → `src/`, require PHP 8.2+, require-dev phpunit + phpstan
- `phpunit.xml` — test suites: Unit (`tests/Unit`), Integration (`tests/Integration`)
- `phpstan.neon` — level 8, paths `src/`
- `.gitignore` — vendor/, composer.lock, .phpunit.cache/
- `LICENSE` — MIT

**Acceptance criteria:**
- `composer install` succeeds
- `vendor/bin/phpunit` runs (0 tests)
- `vendor/bin/phpstan analyse` passes

### Task 1.2: Tuple helper types

Create the value wrapper types used by the tuple layer.

**Files to create:**

- `src/Tuple/Bytes.php` — `final readonly class Bytes { public function __construct(public string $data) {} }`
- `src/Tuple/SingleFloat.php` — `final readonly class SingleFloat { public function __construct(public float $value) {} }`
- `src/Tuple/Uuid.php` — `final readonly class Uuid` with 16-byte validation in constructor
- `src/Tuple/Versionstamp.php` — `final class Versionstamp` with `$trVersion` (10 bytes), `$userVersion` (int 0-65535), `isComplete(): bool`

**Acceptance criteria:**
- PHPStan passes
- Constructors validate input lengths

### Task 1.3: Tuple encoder

Implement `Tuple::pack()` and `Tuple::packWithVersionstamp()`.

**File to create:**

- `src/Tuple/Tuple.php` — static class with `pack(array $elements, string $prefix = ''): string`

**Encoding rules (from spec):**

| Type | Code | Encoding |
|------|------|----------|
| `null` | `0x00` | Single byte |
| `Bytes` | `0x01` | `\x01` + null-escaped data + `\x00` |
| `string` | `0x02` | `\x02` + null-escaped UTF-8 + `\x00` |
| `array` | `0x05` | `\x05` + recursive elements (null→`\x00\xFF`) + `\x00` |
| `int` (0) | `0x14` | Single byte |
| `int` (positive, 1-8 bytes) | `0x15`–`0x1c` | Code + big-endian bytes |
| `int` (negative, 1-8 bytes) | `0x0c`–`0x13` | Code + offset-encoded big-endian |
| `int`/`\GMP` (>8 bytes positive) | `0x1d` | `\x1d` + length byte + big-endian |
| `int`/`\GMP` (>8 bytes negative) | `0x0b` | `\x0b` + inverted length + adjusted bytes |
| `SingleFloat` | `0x20` | `\x20` + 4 bytes IEEE 754 sign-adjusted |
| `float` | `0x21` | `\x21` + 8 bytes IEEE 754 sign-adjusted |
| `false` | `0x26` | Single byte |
| `true` | `0x27` | Single byte |
| `Uuid` | `0x30` | `\x30` + 16 raw bytes |
| `Versionstamp` | `0x33` | `\x33` + 12 raw bytes |

**Key implementation details:**
- Null-byte escaping: `str_replace("\x00", "\x00\xFF", $data)`
- Integer size detection: `bisectLeft` on size limits `[0, 255, 65535, 16777215, ..., 2^64-1]`
- Float sign adjustment: negative → flip all bits; positive → flip sign bit only
- Use `pack()`/`unpack()` for binary encoding (`'G'` for big-endian double, `'N'` for 32-bit, `'J'` for 64-bit)
- GMP fallback for integers > `PHP_INT_MAX` or < `PHP_INT_MIN`

**Acceptance criteria:**
- Encodes all types correctly
- `packWithVersionstamp` records versionstamp position as 4-byte LE suffix

### Task 1.4: Tuple decoder

Implement `Tuple::unpack()` and `Tuple::range()`.

**Add to `src/Tuple/Tuple.php`:**

- `unpack(string $data, int $prefixLength = 0): array` — dispatch on first byte, consume bytes, return array
- `range(array $elements): array{string, string}` — returns `[pack($elements) . "\x00", pack($elements) . "\xFF"]`

**Acceptance criteria:**
- `unpack(pack($input)) === $input` for all supported types (roundtrip)
- Handles nested tuples correctly
- Handles incomplete data gracefully (throws exception)

### Task 1.5: Tuple tests

**Files to create:**

- `tests/Unit/Tuple/TupleTest.php` — comprehensive tests:
  - Null encoding/decoding
  - Bytes encoding/decoding (with null bytes in data)
  - String encoding/decoding (UTF-8, empty string)
  - Integer encoding/decoding (0, 1, -1, 255, 256, -256, PHP_INT_MAX, PHP_INT_MIN, large positive, large negative)
  - Float encoding/decoding (0.0, -0.0, 1.5, -1.5, INF, -INF, NAN)
  - SingleFloat encoding/decoding
  - Bool encoding/decoding
  - Uuid encoding/decoding
  - Versionstamp encoding/decoding
  - Nested tuple encoding/decoding (with null inside)
  - Empty tuple
  - Tuple::range() returns correct begin/end keys
  - Roundtrip: `unpack(pack(x)) === x` for all types
  - Sort order preservation: `pack([1]) < pack([2])`, `pack([-1]) < pack([0])`, `pack(['a']) < pack(['b'])`
  - Cross-language compatibility: known encoded values from Python/Go (hardcoded expected bytes)
- `tests/Unit/Tuple/BytesTest.php` — constructor test
- `tests/Unit/Tuple/SingleFloatTest.php` — constructor test
- `tests/Unit/Tuple/UuidTest.php` — constructor validation (16 bytes required)
- `tests/Unit/Tuple/VersionstampTest.php` — constructor, isComplete()

**Acceptance criteria:**
- All tests pass
- PHPStan level 8 passes
- Cross-language compatibility vectors match

### Task 1.6: Interfaces + KeySelector + KeyValue + RangeOptions

Create the shared types that don't depend on FFI.

**Files to create:**

- `src/Transactor.php` — interface with `transact(callable $fn): mixed`
- `src/ReadTransactor.php` — interface with `readTransact(callable $fn): mixed`
- `src/KeyConvertible.php` — interface with `asFoundationDbKey(): string`
- `src/KeySelector.php` — `final readonly class` with factory methods and `add()`/`subtract()`
- `src/KeyValue.php` — `final readonly class KeyValue { public string $key; public string $value; }`
- `src/RangeOptions.php` — `final readonly class` with `?int $limit`, `bool $reverse`, `StreamingMode $mode`
- `src/Enum/StreamingMode.php`
- `src/Enum/MutationType.php`
- `src/Enum/ConflictRangeType.php`

**Tests:**

- `tests/Unit/KeySelectorTest.php` — factory methods, add/subtract, immutability

**Acceptance criteria:**
- PHPStan passes
- KeySelector tests pass

---

## Phase 2: FFI Layer + Network Lifecycle

**Goal**: Load `libfdb_c.so`, declare all C functions, start/stop network thread. Requires FDB installed.

### Task 2.1: NativeClient — FFI bootstrap

**File to create:**

- `src/NativeClient.php` — singleton class

**Implementation:**

1. `getInstance()` — lazy singleton with `$instance` static property
2. Constructor:
   - Load `libfdb_c.so` via `FFI::cdef($headerString, 'libfdb_c.so')` with all ~42 function declarations
   - Load `libpthread.so` via `FFI::cdef($pthreadHeader, 'libpthread.so')` with `pthread_create`, `pthread_join`
   - Store both FFI handles as properties
3. `checkError(int $code): void` — throw `FDBException` if non-zero
4. `ensureNetwork(): void`:
   - Guard: if already started, return
   - Call `fdb_setup_network()`
   - Create `pthread_t` via `FFI::new('unsigned long')`
   - Call `pthread_create(&tid, null, fdb_run_network, null)`
   - Set `$networkStarted = true`
   - Register shutdown function: `fdb_stop_network()` + `pthread_join()`
5. `stopNetwork(): void` — for explicit shutdown

**Key detail — passing `fdb_run_network` as function pointer to `pthread_create`:**
PHP FFI allows obtaining a function pointer via `FFI::addr()` on the function symbol. If this doesn't work directly, alternative: create a tiny C helper inline via FFI that wraps the call.

**Acceptance criteria:**
- Singleton loads both libraries without error
- Network thread starts and stops cleanly
- Process exits cleanly (no segfault, no hang)

### Task 2.2: FDBException

**File to create:**

- `src/FDBException.php`

**Implementation:**
```php
class FDBException extends \RuntimeException
{
    public function __construct(public readonly int $fdbCode)
    {
        $message = FFI::string(NativeClient::getInstance()->fdb->fdb_get_error($fdbCode));
        parent::__construct($message, $fdbCode);
    }
}
```

**Acceptance criteria:**
- Constructs with FDB error code
- Message comes from C library
- `$e->fdbCode` and `$e->getCode()` both return the code

### Task 2.3: FoundationDB entry point (partial)

**File to create:**

- `src/FoundationDB.php` — static class, initially just `apiVersion()` and `getMaxApiVersion()`

**Implementation:**
- `apiVersion(int $version)` — calls `fdb_select_api_version_impl($version, 730)`, stores version
- `getMaxApiVersion()` — calls `fdb_get_max_api_version()`
- Validates version is set before any other operation

**Acceptance criteria:**
- `FoundationDB::apiVersion(730)` succeeds
- Calling twice throws exception
- Calling with unsupported version throws FDBException

### Task 2.4: Integration test — network lifecycle

**File to create:**

- `tests/Integration/NetworkLifecycleTest.php`

**Tests:**
- API version selection works
- Network starts on first open (tested in Phase 3, but verify no crash here)
- Shutdown hook runs cleanly

**Acceptance criteria:**
- Test passes with FDB installed
- No segfaults or hangs

---

## Phase 3: Future Hierarchy + Database + Transaction

**Goal**: Working transactional get/set/clear/commit with retry loop.

### Task 3.1: Future base class

**File to create:**

- `src/Future/Future.php`

**Implementation:**
```php
abstract class Future
{
    protected bool $resolved = false;
    protected mixed $cachedResult = null;

    public function __construct(protected \FFI\CData $fpointer) {}

    public function isReady(): bool
    {
        return (bool) NativeClient::getInstance()->fdb->fdb_future_is_ready($this->fpointer);
    }

    public function cancel(): void
    {
        NativeClient::getInstance()->fdb->fdb_future_cancel($this->fpointer);
    }

    protected function blockUntilReady(): void
    {
        $client = NativeClient::getInstance();
        $client->checkError($client->fdb->fdb_future_block_until_ready($this->fpointer));
        $client->checkError($client->fdb->fdb_future_get_error($this->fpointer));
    }

    protected function releaseMemory(): void
    {
        NativeClient::getInstance()->fdb->fdb_future_release_memory($this->fpointer);
    }

    abstract public function wait(): mixed;

    public function __destruct()
    {
        NativeClient::getInstance()->fdb->fdb_future_destroy($this->fpointer);
    }
}
```

**Acceptance criteria:**
- Abstract class, cannot be instantiated directly
- `__destruct` calls `fdb_future_destroy`

### Task 3.2: Concrete future types

**Files to create:**

- `src/Future/FutureVoid.php` — `wait(): void` (blockUntilReady only)
- `src/Future/FutureInt64.php` — `wait(): int` (fdb_future_get_int64)
- `src/Future/FutureValue.php` — `wait(): ?string` (fdb_future_get_value with present flag)
- `src/Future/FutureKey.php` — `wait(): string` (fdb_future_get_key)
- `src/Future/FutureKvResult.php` — `final readonly class FutureKvResult { array $kvs; int $count; bool $more; }`
- `src/Future/FutureKeyValueArray.php` — `wait(): FutureKvResult` (fdb_future_get_keyvalue_array, iterate FDBKeyValue structs)
- `src/Future/FutureKeyArray.php` — `wait(): array` (fdb_future_get_key_array)
- `src/Future/FutureStringArray.php` — `wait(): array` (fdb_future_get_string_array)

**Key implementation detail — FutureKeyValueArray:**
```php
// Extract KV array from C struct pointer
$kvsPtr = $client->fdb->new('FDBKeyValue*');
$count = $client->fdb->new('int');
$more = $client->fdb->new('fdb_bool_t');
$client->checkError($client->fdb->fdb_future_get_keyvalue_array(
    $this->fpointer, FFI::addr($kvsPtr), FFI::addr($count), FFI::addr($more)
));

$result = [];
for ($i = 0; $i < $count->cdata; $i++) {
    $kv = $kvsPtr[$i];
    $result[] = new KeyValue(
        FFI::string($kv->key, $kv->key_length),
        FFI::string($kv->value, $kv->value_length),
    );
}
```

**Acceptance criteria:**
- Each future type extracts the correct value from C
- Results are cached (second `wait()` returns cached value)
- `releaseMemory()` called after extraction

### Task 3.3: Database

**File to create:**

- `src/Database.php`

**Implementation:**
- Constructor takes `FFI\CData $dpointer` (FDBDatabase*)
- `createTransaction(): Transaction` — calls `fdb_database_create_transaction`
- `openTenant(string $name): Tenant` — calls `fdb_database_open_tenant`
- `transact(callable $fn): mixed` — retry loop (see spec)
- `readTransact(callable $fn): mixed` — retry loop without commit
- `__destruct()` — `fdb_database_destroy`
- `$options` property — `DatabaseOptions` instance

**Acceptance criteria:**
- Creates transactions
- Retry loop retries on conflict (error 1020)
- Retry loop throws on non-retryable errors
- Destructor cleans up

### Task 3.4: Tenant

**File to create:**

- `src/Tenant.php`

**Implementation:**
- Constructor takes `FFI\CData $tpointer` (FDBTenant*)
- `createTransaction(): Transaction` — calls `fdb_tenant_create_transaction`
- `__destruct()` — `fdb_tenant_destroy`

### Task 3.5: ReadTransaction

**File to create:**

- `src/ReadTransaction.php`

**Implementation:**
- Constructor: `$tpointer`, `$db`, `$isSnapshot`
- `get(string|KeyConvertible $key): FutureValue` — resolve KeyConvertible, call `fdb_transaction_get` with `$isSnapshot`
- `getKey(KeySelector $sel): FutureKey` — call `fdb_transaction_get_key`
- `getRange(...)` — call `fdb_transaction_get_range`, return `RangeResult` (implemented in Phase 4, stub for now)
- `getRangeStartsWith(...)` — compute end key (`$prefix` with last byte incremented), delegate to `getRange`
- `getReadVersion(): FutureInt64`
- `getEstimatedRangeSizeBytes(...): FutureInt64`
- `getRangeSplitPoints(...): FutureKeyArray`
- `getAddressesForKey(...): FutureStringArray`
- Helper: `resolveKey(string|KeyConvertible $key): string`

### Task 3.6: Transaction

**File to create:**

- `src/Transaction.php`

**Implementation:**
- Extends `ReadTransaction` with `$isSnapshot = false`
- `set()`, `clear()`, `clearRange()`, `clearRangeStartsWith()` — void C calls
- `commit(): FutureVoid`
- `onError(int $code): FutureVoid`
- `reset(): void`, `cancel(): void`
- `setReadVersion(int $version): void`
- `getCommittedVersion(): int` — synchronous (not a future)
- `getApproximateSize(): FutureInt64`
- `getVersionstamp(): FutureKey`
- `watch(string $key): FutureVoid`
- `snapshot(): Snapshot` — lazy creation, cached in `$snapshotInstance`
- `transact(callable $fn): mixed` — passthrough: `return $fn($this)`
- `__destruct()` — `fdb_transaction_destroy`
- `$options` property — `TransactionOptions` instance

### Task 3.7: Snapshot

**File to create:**

- `src/Snapshot.php`

**Implementation:**
- Extends `ReadTransaction` with `$isSnapshot = true`
- Constructor receives same `$tpointer` as parent Transaction
- Holds reference to parent Transaction (prevents GC)
- Does NOT call `fdb_transaction_destroy` in `__destruct`
- `readTransact(callable $fn): mixed` — passthrough

### Task 3.8: FoundationDB entry point (complete)

**Update `src/FoundationDB.php`:**

- `open(?string $clusterFile = null): Database`:
  - Call `NativeClient::getInstance()->ensureNetwork()`
  - Check cache by cluster file
  - Call `fdb_create_database`
  - Wrap in `Database`, cache, return

### Task 3.9: Integration tests — basic CRUD

**Files to create:**

- `tests/Integration/BasicCrudTest.php`:
  - Set and get a key
  - Get non-existent key returns null
  - Clear a key
  - Clear range
  - Clear range starts with
  - Transaction commit
  - Transaction reset
  - Transaction cancel
- `tests/Integration/TransactionTest.php`:
  - Retry loop retries on conflict
  - Retry loop throws on non-retryable error
  - Composable transact (Transaction::transact passthrough)
  - Read-your-writes within transaction
  - Snapshot read does not conflict

**Acceptance criteria:**
- All tests pass against running FDB
- No memory leaks (no segfaults on repeated runs)

---

## Phase 4: Range Reads + Options + Convenience Methods

**Goal**: Complete range read iteration, all option classes, Database convenience methods.

### Task 4.1: RangeResult

**File to create:**

- `src/RangeResult.php`

**Implementation:**
- Constructor: `$transaction`, `$beginSelector`, `$endSelector`, `$options`, `$snapshot`
- `getIterator(): \Generator` — batched pagination loop:
  1. Call `fdb_transaction_get_range` with current selectors + iteration counter
  2. Wait for `FutureKeyValueArray`
  3. Yield each `KeyValue`
  4. If `$more && $limit !== $count`: advance selectors past last key, increment iteration
  5. Repeat
- `toArray(): array` — eager fetch, switches streaming mode to `WantAll`/`Exact`
- Internal `getRangeRaw(...)` method on `ReadTransaction` that calls `fdb_transaction_get_range` with all 15 parameters

**Update `src/ReadTransaction.php`:**
- Complete `getRange()` and `getRangeStartsWith()` to return `RangeResult`
- Add internal `getRangeRaw()` method

**Acceptance criteria:**
- Iterating large ranges works (pagination across multiple batches)
- `toArray()` returns all results
- Reverse iteration works
- Limit is respected
- Empty range returns empty iterator

### Task 4.2: Options classes

**Files to create:**

- `src/Option/NetworkOptions.php` — wraps `fdb_network_set_option`
- `src/Option/DatabaseOptions.php` — wraps `fdb_database_set_option`
- `src/Option/TransactionOptions.php` — wraps `fdb_transaction_set_option`

**Each class:**
- Constructor receives the appropriate FFI pointer (or NativeClient for network)
- Private `setOption(int $code, ?string $param): void` — calls the C function
- Public typed methods: `setTimeout(int $ms)`, `setRetryLimit(int $limit)`, etc.
- Parameter encoding: flag → `null`, string → raw bytes, int → `pack('P', $value)`

**Key options to implement (most commonly used):**

NetworkOptions: `setTraceEnable`, `setTraceMaxLogsSize`, `setTraceRollSize`, `setTraceFormat`, `setTlsCertPath`, `setTlsKeyPath`, `setTlsVerifyPeers`, `setTlsCertBytes`, `setTlsKeyBytes`, `setDisableMultiVersionClientApi`, `setExternalClientLibrary`, `setExternalClientDirectory`, `setCallbacksOnExternalThreads`

DatabaseOptions: `setLocationCacheSize`, `setMaxWatches`, `setMachineId`, `setDatacenterId`, `setTransactionTimeout`, `setTransactionRetryLimit`, `setTransactionMaxRetryDelay`, `setTransactionSizeLimit`, `setTransactionCausalReadRisky`, `setSnapshotRywEnable`, `setSnapshotRywDisable`

TransactionOptions: `setTimeout`, `setRetryLimit`, `setMaxRetryDelay`, `setSizeLimit`, `setCausalReadRisky`, `setSnapshotRywEnable`, `setSnapshotRywDisable`, `setReadYourWritesDisable`, `setAccessSystemKeys`, `setReadSystemKeys`, `setNextWriteNoWriteConflictRange`, `setTransactionLoggingMaxFieldLength`, `setDebugTransactionIdentifier`, `setLogTransaction`

**Acceptance criteria:**
- PHPStan passes
- Options can be set without error on Database and Transaction

### Task 4.3: Database convenience methods

**Update `src/Database.php`:**

- `get(string|KeyConvertible $key): ?string` — `transact(fn($tr) => $tr->get($key)->wait())`
- `set(string|KeyConvertible $key, string $value): void` — `transact(fn($tr) => $tr->set($key, $value))`
- `clear(string|KeyConvertible $key): void`
- `clearRange(string $begin, string $end): void`
- `clearRangeStartsWith(string $prefix): void`
- `getRange(...): array` — returns array (not generator)
- `getRangeStartsWith(...): array`
- `getAndWatch(string $key): array` — `[$value, $watch]`
- `setAndWatch(string $key, string $value): FutureVoid`
- `clearAndWatch(string $key): FutureVoid`

**Acceptance criteria:**
- All convenience methods work as one-shot transactions
- Watch methods return futures that resolve on value change

### Task 4.4: Integration tests — range reads + options

**Files to create:**

- `tests/Integration/RangeReadTest.php`:
  - Range read with begin/end keys
  - Range read with key selectors
  - Range read with prefix
  - Range read with limit
  - Range read reverse
  - Range read pagination (insert 1000+ keys, iterate)
  - Range read empty range
  - toArray()
- `tests/Integration/OptionsTest.php`:
  - Set transaction timeout
  - Set retry limit
  - Set database-level timeout

**Acceptance criteria:**
- All tests pass
- Large range reads don't hang or crash

---

## Phase 5: Snapshot + Conflict Ranges + Atomic Ops + Watches

**Goal**: Complete all transaction features.

### Task 5.1: Atomic operations

**Update `src/Transaction.php`:**

- `add(string $key, string $param): void` — `fdb_transaction_atomic_op` with `MutationType::Add`
- `bitAnd`, `bitOr`, `bitXor`, `max`, `min`, `byteMax`, `byteMin`, `compareAndClear`
- `setVersionstampedKey`, `setVersionstampedValue`
- Private `atomicOp(MutationType $type, string $key, string $param): void`

### Task 5.2: Conflict ranges

**Update `src/Transaction.php`:**

- `addReadConflictRange(string $begin, string $end): void` — `fdb_transaction_add_conflict_range` with `ConflictRangeType::Read`
- `addWriteConflictRange(string $begin, string $end): void`
- `addReadConflictKey(string $key): void` — range `[$key, $key . "\x00")`
- `addWriteConflictKey(string $key): void`

### Task 5.3: Integration tests — atomic + conflict + watches

**Files to create:**

- `tests/Integration/AtomicOperationsTest.php`:
  - Increment counter with add
  - Toggle flag with bitXor
  - Max/min operations
  - CompareAndClear
  - Atomic op does not cause self-conflict
- `tests/Integration/ConflictRangeTest.php`:
  - Snapshot read + explicit conflict key
  - Write conflict range causes conflict
- `tests/Integration/WatchTest.php`:
  - Watch fires on value change
  - Watch on non-existent key fires on creation
  - Watch cancel

**Acceptance criteria:**
- All atomic operations produce correct results
- Conflict ranges work as expected
- Watches fire correctly

---

## Phase 6: Subspace

**Goal**: Subspace layer — pure PHP, no FFI.

### Task 6.1: Subspace implementation

**File to create:**

- `src/Subspace.php`

**Implementation:**
- Constructor: `array $prefixTuple = [], string $rawPrefix = ''` → `$this->rawPrefix = $rawPrefix . Tuple::pack($prefixTuple)`
- `key(): string` → `$this->rawPrefix`
- `pack(array $tuple = []): string` → `$this->rawPrefix . Tuple::pack($tuple)`
- `unpack(string $key): array` → validate prefix, `Tuple::unpack(substr($key, strlen($this->rawPrefix)))`
- `range(array $tuple = []): array{string, string}` → `[$this->pack($tuple) . "\x00", $this->pack($tuple) . "\xFF"]`
- `contains(string $key): bool` → `str_starts_with($key, $this->rawPrefix)`
- `subspace(mixed $element): self` → `new self([$element], $this->rawPrefix)`
- `asFoundationDbKey(): string` → `$this->key()`

### Task 6.2: Subspace tests

**File to create:**

- `tests/Unit/SubspaceTest.php`:
  - Constructor with tuple prefix
  - Constructor with raw prefix
  - Constructor with both
  - pack/unpack roundtrip
  - range() returns correct bounds
  - contains() works
  - subspace() creates child
  - asFoundationDbKey() returns raw prefix
  - Nested subspaces
  - Unpack with wrong prefix throws exception

**Acceptance criteria:**
- All tests pass (no FDB needed)
- PHPStan passes

---

## Phase 7: Directory Layer

**Goal**: Full directory layer — pure PHP, uses transactions.

### Task 7.1: HighContentionAllocator

**File to create:**

- `src/Directory/HighContentionAllocator.php`

**Implementation:**
- Constructor: `Subspace $subspace` → `$this->counters = $subspace->subspace(0)`, `$this->recent = $subspace->subspace(1)`
- `allocate(Transactor $dbOrTr): string`:
  1. Find current window (snapshot read last counter key)
  2. If window > 50% full, advance window (clear old counters + recent)
  3. Pick random candidate in window
  4. Use snapshot read on `$this->recent->pack([$candidate])` + `addWriteConflictKey`
  5. If not taken, set it and return `Tuple::pack([$candidate])`
  6. Window sizes: `<255 → 64`, `<65535 → 1024`, `≥65535 → 8192`

### Task 7.2: DirectoryLayer

**File to create:**

- `src/Directory/DirectoryLayer.php`

**Implementation:**
- Constructor: optional `$nodeSubspace` (default `\xFE`), optional `$contentSubspace` (default empty)
- Internal constants: `SUBDIRS = 0`, version `[1, 0, 0]`
- `createOrOpen(Transactor $dbOrTr, array $path, string $layer = ''): DirectorySubspace`
- `create(...)`, `open(...)`, `move(...)`, `remove(...)`, `removeIfExists(...)`, `list(...)`, `exists(...)`
- Internal `find(Transaction $tr, array $path): Node` — walk tree node by node
- Internal `nodeWithPrefix(string $prefix): Subspace` — `$this->nodeSubspace->pack([$prefix])`
- Internal `contentsOfNode(...)` — extract prefix from node, create DirectorySubspace
- Version check on first access — initialize if needed

### Task 7.3: DirectorySubspace

**File to create:**

- `src/Directory/DirectorySubspace.php`

**Implementation:**
- Extends `Subspace`
- Holds `$path`, `$layer`, reference to parent `DirectoryLayer`
- Delegates directory operations (`createOrOpen`, `create`, `open`, `move`, `remove`, `list`, `exists`) to the DirectoryLayer with path relative to self
- `moveTo(Transactor $dbOrTr, array $newAbsolutePath): DirectorySubspace`
- `getPath(): array`, `getLayer(): string`

### Task 7.4: DirectoryPartition

**File to create:**

- `src/Directory/DirectoryPartition.php`

**Implementation:**
- Extends `DirectorySubspace`
- Creates internal `DirectoryLayer` with `nodeSubspace = prefix + \xFE`, `contentSubspace = prefix`
- Overrides `key()`, `pack()`, `unpack()`, `range()`, `contains()`, `asFoundationDbKey()` — all throw `\LogicException` ("Cannot use a directory partition as a subspace")
- Delegates directory operations to internal DirectoryLayer

### Task 7.5: Integration tests — directory layer

**File to create:**

- `tests/Integration/DirectoryTest.php`:
  - Create directory
  - Open existing directory
  - createOrOpen
  - List subdirectories
  - Move directory
  - Remove directory
  - removeIfExists
  - exists
  - Nested directories
  - Directory as subspace (pack/unpack/range)
  - DirectoryPartition blocks subspace operations
  - DirectoryPartition subdirectories work
  - HighContentionAllocator produces unique prefixes under concurrency

**Acceptance criteria:**
- All tests pass against running FDB
- Directory operations are compatible with Python/Go directory layer (can read directories created by other languages)

---

## Post-Implementation

After all 7 phases are complete:

1. **Push to GitHub** — `git push origin master`
2. **Tag v0.1.0** — first working release
3. **Submit to Packagist** — `composer require crazy-goat/foundationdb`
4. **Write README.md** — installation, requirements, quick start, API overview
5. **CI setup** — GitHub Actions: phpunit (unit only), phpstan, php-cs-fixer

---

## Phase Dependencies Graph

```
Phase 1 (Scaffold + Tuple)
    ├── Phase 2 (FFI + Network)
    │   └── Phase 3 (Future + DB + Transaction)
    │       └── Phase 4 (Range + Options + Convenience)
    │           └── Phase 5 (Atomic + Conflict + Watches)
    │           └── Phase 7 (Directory) ←── Phase 6
    └── Phase 6 (Subspace)
```

Phases 1 and 6 can be developed and tested without FDB.
Phases 2–5 and 7 require a running FDB instance.
Phase 6 depends only on Phase 1 (Tuple layer).
Phase 7 depends on Phase 6 (Subspace) and Phase 4 (transactional operations).
