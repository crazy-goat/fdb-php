# Error Handling Documentation

## Overview

FDBException wraps FoundationDB error codes with human-readable messages and error predicates.

## FDBException

```php
use CrazyGoat\FoundationDB\FDBException;

try {
    $db->transact(function (Transaction $tr) {
        // ... operations that might fail
    });
} catch (FDBException $e) {
    echo "FDB error {$e->fdbCode}: {$e->getMessage()}\n";
}
```

### Properties

- `$e->fdbCode` — FoundationDB error code (int, readonly)
- `$e->getMessage()` — human-readable error message from libfdb_c
- `$e->getCode()` — same as fdbCode (inherited from RuntimeException)

## Error Predicates

```php
try {
    // manual transaction
    $tr->commit()->await();
} catch (FDBException $e) {
    if ($e->isRetryable()) {
        // Safe to retry the transaction
    }
    if ($e->isMaybeCommitted()) {
        // Transaction may have been committed
    }
    if ($e->isRetryableNotCommitted()) {
        // Safe to retry, definitely not committed
    }
}
```

## ErrorPredicate Enum

| Predicate | Value | Description |
|-----------|-------|-------------|
| `Retryable` | 50000 | Error is retryable |
| `MaybeCommitted` | 50001 | Transaction may have committed |
| `RetryableNotCommitted` | 50002 | Retryable and definitely not committed |

## Static Predicate Testing

```php
use CrazyGoat\FoundationDB\Enum\ErrorPredicate;

$isRetryable = FDBException::testPredicate(ErrorPredicate::Retryable, $errorCode);
```

## Automatic Retry in transact()

The `transact()` method handles retryable errors automatically:

```php
// This handles retries for you — no need to catch FDBException
$db->transact(function (Transaction $tr) {
    // If this conflicts, transact() retries automatically
    $tr->set('key', 'value');
});
```

The retry loop calls `$tr->onError($code)->await()` which either resets the transaction for retry or throws if the error is not retryable.

## Conflicting Key Ranges

When a transaction fails to commit with `not_committed` (1020), the server can
report the key ranges that caused the conflict. Enable reporting with
`TransactionOptions::setReportConflictingKeys()` **before** the transaction
runs, then read the ranges after the failed `commit()` and before
`reset()`/`onError()`:

```php
$tr = $db->createTransaction();
$tr->options()->setReportConflictingKeys();
$old = $tr->get($key)->await();
// ... a concurrent transaction commits ...
$tr->set($key, $newValue);
try {
    $tr->commit()->await();
} catch (FDBException $e) {
    if ($e->fdbCode === 1020) {
        foreach ($tr->getConflictingKeyRanges() as $range) {
            echo "conflict: {$range['begin']} .. {$range['end']}\n";
        }
    }
}
```

- `Transaction::getConflictingKeyRanges()` returns
  `list<array{begin: string, end: string}>` in user key space.
- `Transaction::getConflictingKeys()` returns the raw rows
  (`list<KeyValue>`, keys stripped of the `\xff\xff/transaction/conflicting_keys/`
  prefix, value `"1"` for a range start and `"0"` for its end).
- Calling either method without the option enabled throws a `LogicException`.

## Other Exceptions

### DirectoryException

Extends `RuntimeException` — directory layer errors.

### RebootWorkerException

Extends `RuntimeException` — worker reboot failures.

- `$e->address` — the worker address (readonly)

### LogicException

Programming errors (e.g., using closed database, using partition as subspace).

### InvalidArgumentException

Invalid parameters at the FFI trust boundary. Thrown eagerly by:

- every write (`set`, `clear`, `clearRange`, `atomicOp`, `watch`) — when the
  key/value length exceeds the FoundationDB size limits
  (key ≤ 10,000 bytes, value ≤ 100,000 bytes);
- every read (`get`, `getKey`, `getRange`, `getAddressesForKey`,
  `getEstimatedRangeSizeBytes`, `getRangeSplitPoints`) — when the key or
  range-endpoint length exceeds the FDB key size limit;
- every option setters (`Transaction::setOption`,
  `Database::setOption`, `NetworkOptions::set*`) and the tenant/server
  identifiers — when the byte string would not fit in libfdb_c's 32-bit
  `int` length parameter.

The exception is thrown at the call site rather than on `commit()`, so
applications get a stack trace at the offending line with the actual length
that failed validation. `transact()` does not retry these exceptions —
they are treated as programmer errors, not transient conflicts.

See `KeyValueLimits` for the precise constants and the FFI 32-bit guard.
