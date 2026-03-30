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

## Other Exceptions

### DirectoryException

Extends `RuntimeException` — directory layer errors.

### RebootWorkerException

Extends `RuntimeException` — worker reboot failures.

- `$e->address` — the worker address (readonly)

### LogicException

Programming errors (e.g., using closed database, using partition as subspace).

### InvalidArgumentException

Invalid parameters (e.g., empty directory path).
