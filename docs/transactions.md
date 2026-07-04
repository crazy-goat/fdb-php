# Transactions

**Namespace:** `CrazyGoat\FoundationDB`

FoundationDB transactions provide ACID guarantees with strict serializability. The PHP client handles the complexity of FoundationDB's optimistic concurrency control through automatic retry loops.

---

## Overview

FoundationDB transactions have these key properties:

- **ACID**: Atomic, Consistent, Isolated, Durable
- **Serializable**: All transactions appear to execute in a serial order
- **Optimistically Concurrent**: Transactions proceed without locking; conflicts are detected at commit time
- **Automatic Retry**: The `transact()` method automatically retries on transient failures

---

## The transact() Pattern

The primary way to use transactions is through the `transact()` method on `Database`:

```php
use CrazyGoat\FoundationDB\FoundationDB as FDB;
use CrazyGoat\FoundationDB\Transaction;

FDB::apiVersion(730);
$db = FDB::open();

$result = $db->transact(function (Transaction $tr) {
    $value = $tr->get('key')->await();
    $tr->set('key', 'new-value');
    return $value; // returned from transact()
});
```

### Key behaviors:

- **Retry loop**: The callback may be called multiple times if the transaction conflicts with another
- **Return values**: Whatever the callback returns is passed through from `transact()`
- **Automatic commit**: Commit happens automatically after the callback returns successfully
- **Error handling**: `FDBException` with retryable errors triggers automatic retry via `$tr->onError()`

---

## Read Operations

All read operations return **Future** objects. You must call `->await()` to get the result:

### Single Key Reads

```php
// Get a single value — returns ?string (null if key doesn't exist)
$value = $tr->get(string|KeyConvertible $key): FutureValue;
$result = $value->await();

// Get a key using a selector — returns string
$key = $tr->getKey(KeySelector $selector): FutureKey;
$result = $key->await();

// Get the read version — returns int
$version = $tr->getReadVersion(): FutureInt64;
$result = $version->await();
```

### Range Reads

```php
// Lazy iteration over a range — returns RangeResult (iterable)
$range = $tr->getRange(
    string|KeySelector $begin,
    string|KeySelector $end,
    ?RangeOptions $options = null
): RangeResult;

foreach ($range as $kv) {
    echo $kv->key . ' = ' . $kv->value . "\n";
}

// Prefix-based range read
$range = $tr->getRangeStartsWith(string $prefix): RangeResult;

// Eager fetch — returns list<KeyValue>
$all = $tr->getRangeAll(
    string|KeySelector $begin,
    string|KeySelector $end,
    ?RangeOptions $options = null
): array;

// Eager prefix range — returns list<KeyValue>
$all = $tr->getRangeAllStartsWith(string $prefix): array;
```

### Metadata Reads

```php
// Get estimated size of a range in bytes
$size = $tr->getEstimatedRangeSizeBytes(string $begin, string $end): FutureInt64;

// Get split points for parallel processing
$points = $tr->getRangeSplitPoints(string $begin, string $end, int $chunkSize): FutureKeyArray;

// Get storage server addresses for a key
$addresses = $tr->getAddressesForKey(string $key): FutureStringArray;
```

---

## Write Operations

Write operations are **immediate** (no Future returned):

```php
// Set a key-value pair
$tr->set(string|KeyConvertible $key, string $value): void;

// Delete a single key
$tr->clear(string|KeyConvertible $key): void;

// Delete a range of keys [begin, end)
$tr->clearRange(string $begin, string $end): void;

// Delete all keys with a given prefix
$tr->clearRangeStartsWith(string $prefix): void;
```

---

## Commit and Lifecycle

### Manual Control

```php
// Explicitly commit the transaction
$future = $tr->commit(): FutureVoid;
$future->await();

// Reset the transaction for reuse
$tr->reset(): void;

// Cancel the transaction
$tr->cancel(): void;

// Handle errors (used by the retry loop)
$future = $tr->onError(int $code): FutureVoid;
$future->await();
```

### Notes:

- In `transact()`, commit is automatic — you don't need to call it manually
- `reset()` clears all operations and allows reusing the transaction object
- `cancel()` aborts the transaction immediately
- `onError()` implements the retry backoff logic for transient errors

---

## Version Control

```php
// Set a specific read version
$tr->setReadVersion(int $version): void;

// Get the committed version (after successful commit)
$version = $tr->getCommittedVersion(): int;

// Get approximate transaction size in bytes
$size = $tr->getApproximateSize(): FutureInt64;

// Get the versionstamp (after commit) — returns the versionstamp key
$versionstamp = $tr->getVersionstamp(): FutureKey;
```

---

## Snapshot Reads

Snapshot reads are conflict-free — they don't add read conflict ranges. This is useful for reads that shouldn't cause transaction conflicts:

```php
$db->transact(function (Transaction $tr) {
    // Snapshot read — no conflict range added
    $value = $tr->snapshot()->get('frequently-read-key')->await();
    
    // Regular read — adds conflict range
    $important = $tr->get('important-key')->await();
    
    // Selectively add conflict for keys you care about
    $tr->addReadConflictKey('another-important-key');
});
```

### Key points:

- `$tr->snapshot()` returns a `Snapshot` object (extends `ReadTransaction`)
- Snapshot reads don't add read conflict ranges
- Useful for frequently-read data that changes often but doesn't need strict consistency
- You can mix snapshot and regular reads in the same transaction

---

## Read-Only Transactions

For read-only operations, use `readTransact()` — it automatically uses snapshot reads:

```php
$value = $db->readTransact(function (Snapshot $snap) {
    return $snap->get('key')->await();
});
```

### Benefits:

- Uses snapshot reads automatically (no conflicts)
- No commit needed (faster)
- Still retries on errors
- Simpler code for pure read operations

---

## Conflict Ranges

You can manually add conflict ranges for fine-grained control:

```php
// Add a read conflict range [begin, end)
$tr->addReadConflictRange(string $begin, string $end): void;

// Add a write conflict range [begin, end)
$tr->addWriteConflictRange(string $begin, string $end): void;

// Add a read conflict for a single key
$tr->addReadConflictKey(string $key): void;

// Add a write conflict for a single key
$tr->addWriteConflictKey(string $key): void;
```

### Use cases:

- Adding conflicts for keys read via snapshot
- Extending conflict ranges beyond what was actually read
- Implementing custom conflict detection logic

---

## Manual Transaction Management

For cases where you need more control, create transactions manually:

```php
// Create a transaction
$tr = $db->createTransaction();

try {
    $tr->set('key', 'value');
    $tr->commit()->await();
} catch (FDBException $e) {
    // Handle error manually
    if ($e->fdbCode === 1020) { // not_committed
        // Conflict — retry with backoff
        $tr->onError($e->fdbCode)->await();
        // Retry logic here...
    }
} finally {
    // Transaction is destroyed when garbage collected
    unset($tr);
}
```

### When to use manual management:

- Custom retry logic
- Long-running transactions with periodic commits
- Fine-grained error handling
- Integration with external transaction coordinators

---

## Transaction Options

Configure transaction behavior using the fluent options API:

```php
$db->transact(function (Transaction $tr) {
    $tr->options()
        ->setTimeout(5000)           // 5 second timeout
        ->setRetryLimit(3)           // Max 3 retries
        ->setPriorityBatch();        // Lower priority (batch operations)
    
    // ... your operations ...
});
```

### Common options:

| Option | Description |
|--------|-------------|
| `setTimeout(int $ms)` | Transaction timeout in milliseconds |
| `setRetryLimit(int $limit)` | Maximum number of retries |
| `setMaxRetryDelay(int $ms)` | Maximum retry delay |
| `setPriorityBatch()` | Lower priority for batch operations |
| `setPrioritySystemImmediate()` | Higher priority for urgent operations |
| `setReadYourWritesDisable()` | Disable read-your-writes optimization |
| `setSnapshotReadDangerous()` | Enable dangerous snapshot read mode |

See [options.md](options.md) for the complete list of transaction options.

---

## KeyConvertible Interface

Any object implementing `KeyConvertible` can be used as a key:

```php
interface KeyConvertible {
    public function asFoundationDbKey(): string;
}
```

### Built-in implementations:

- `Subspace` — packs tuple prefix
- `DirectorySubspace` — directory layer subspace

### Example:

```php
use CrazyGoat\FoundationDB\Subspace;

$users = new Subspace(['users']);

$db->transact(function (Transaction $tr) use ($users) {
    // Subspace implements KeyConvertible
    $tr->set($users->pack([42]), 'Alice');
    
    // Can also use the packed key directly
    $value = $tr->get($users->pack([42]))->await();
});
```

---

## Best Practices

1. **Keep transactions short**: Long transactions increase conflict probability
2. **Use `transact()`**: Let the library handle retry logic
3. **Use `readTransact()` for reads**: Automatic snapshot reads, no commit overhead
4. **Use snapshot reads wisely**: For data that changes often but doesn't need strict consistency
5. **Handle idempotency**: Your callback may run multiple times — ensure it's safe to retry
6. **Avoid external side effects**: Don't perform I/O or external mutations inside `transact()`
7. **Use subspaces**: Organize keys logically and avoid key collisions

---

## Error Handling

Common error codes you might encounter:

| Code | Constant | Description |
|------|----------|-------------|
| 1020 | `not_committed` | Transaction conflict — will be retried automatically |
| 1021 | `commit_unknown_result` | Commit status unknown — may need application-level handling |
| 1023 | `transaction_cancelled` | Transaction was cancelled |
| 1025 | `transaction_timed_out` | Transaction exceeded timeout |
| 1031 | `future_released` | Future was released before completion |

The `transact()` and `readTransact()` methods handle retryable errors automatically. For manual transactions, use `$tr->onError($code)->await()` to implement proper retry backoff.

---

## Key / Value Size Limits

FoundationDB enforces hard limits on the bytes you can store, and the PHP client validates them up front so failures happen at the call site instead of as an opaque error on `commit()`.

### Limits

| Payload                 | Limit                  | FDB error code |
|-------------------------|------------------------|----------------|
| Key (read or write)     | 10,000 bytes (10 KB)   | 2102           |
| Value                   | 100,000 bytes (100 KB) | 2103           |
| Aggregated transaction  | 10,000,000 bytes (10 MB) by default | 2101 |

The transaction-size limit is aggregate and is still reported by `libfdb_c` on `commit()` (PHP-side does not pre-compute the running total). Key and value limits are checked on every call so you get them at the offending line rather than at the end of the retry loop.

### Behaviour

```php
use CrazyGoat\FoundationDB\KeyValueLimits;

assert(KeyValueLimits::MAX_KEY_SIZE === 10_000);
assert(KeyValueLimits::MAX_VALUE_SIZE === 100_000);
assert(KeyValueLimits::MAX_FFI_LENGTH === 2_147_483_647);
```

An oversize write throws `\InvalidArgumentException` immediately, with the expected length and the limit in the message:

```php
$oversize = str_repeat('a', 10_001);

try {
    $tr->set($oversize, 'value');
} catch (\InvalidArgumentException $e) {
    // "FoundationDB key exceeds maximum size: 10001 bytes (limit is 10000 bytes)"
}
```

The same applies to `clear()`, `clearRange()`, `atomicOp()`, `watch()`, `get()`, `getKey()`, `getRange()`, `addReadConflictRange()`, `addWriteConflictRange()` and `setOption()`.

> **Note**: `transact()` retries on `FDBException` only. A guard rejection is treated as a programmer error, not a transient conflict, so it propagates out of `transact()` immediately — the retry loop will not silently re-attempt an oversize write.

### Why pre-flight

`libfdb_c`'s length parameters are 32-bit `int`; PHP `strlen()` is 64-bit. Pre-validation
keeps a `> 2 GB` payload from silently truncating at the C boundary. The defensive
FFI guard (`KeyValueLimits::MAX_FFI_LENGTH`) fires only for pathological inputs —
every realistic FoundationDB payload is well below it.
