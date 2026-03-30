# Atomic Operations

**Namespace:** `CrazyGoat\FoundationDB`

## Overview

Atomic operations modify values without reading them first, avoiding read-write conflicts. They're performed as part of a transaction but don't require a read conflict range.

## Available Operations

| Method | MutationType | Description |
|--------|-------------|-------------|
| `add($key, $param)` | Add | Interprets both values as little-endian integers and adds them |
| `bitAnd($key, $param)` | BitAnd | Bitwise AND |
| `bitOr($key, $param)` | BitOr | Bitwise OR |
| `bitXor($key, $param)` | BitXor | Bitwise XOR |
| `max($key, $param)` | Max | Keeps the lexicographically greater value |
| `min($key, $param)` | Min | Keeps the lexicographically lesser value |
| `byteMax($key, $param)` | ByteMax | Byte-string maximum |
| `byteMin($key, $param)` | ByteMin | Byte-string minimum |
| `compareAndClear($key, $param)` | CompareAndClear | Clears key if current value equals param |
| `setVersionstampedKey($key, $value)` | SetVersionstampedKey | Sets key with versionstamp |
| `setVersionstampedValue($key, $param)` | SetVersionstampedValue | Sets value with versionstamp |

## Counters

The most common use case for atomic operations is implementing counters:

```php
use CrazyGoat\FoundationDB\Transaction;

// Increment a counter by 1
$db->transact(function (Transaction $tr) {
    $tr->add('page_views', pack('P', 1)); // P = unsigned 64-bit LE
});

// Increment by 10
$db->transact(function (Transaction $tr) {
    $tr->add('page_views', pack('P', 10));
});

// Read the counter
$db->transact(function (Transaction $tr) {
    $raw = $tr->get('page_views')->await();
    $count = unpack('P', $raw)[1];
    echo "Page views: {$count}\n";
});
```

**Note:** `pack('P', $n)` creates an unsigned 64-bit little-endian integer.

## Bitwise Operations

```php
$db->transact(function (Transaction $tr) {
    // Toggle a flag
    $tr->bitXor('flags', pack('C', 0b00000001));
    
    // Set specific bits
    $tr->bitOr('permissions', pack('C', 0b00001100));
    
    // Clear specific bits
    $tr->bitAnd('permissions', pack('C', 0b11110011));
});
```

## Compare and Clear

```php
$db->transact(function (Transaction $tr) {
    // Clear key only if its value is the zero sentinel
    $tr->compareAndClear('temp_lock', pack('P', 0));
});
```

## Generic atomicOp()

For cases where you need to use a mutation type dynamically:

```php
use CrazyGoat\FoundationDB\Enum\MutationType;

$tr->atomicOp(MutationType::Add, 'counter', pack('P', 1));
```

## Database-Level Convenience Methods

The Database class provides convenience methods that automatically wrap operations in a transaction:

```php
$db->add('counter', pack('P', 1));
$db->bitXor('flag', pack('C', 1));
$db->compareAndClear('temp', pack('P', 0));
// Also: bitAnd, bitOr, max, min
```

## Versionstamped Operations

Versionstamped operations allow you to embed the commit version into keys or values:

```php
$db->transact(function (Transaction $tr) {
    // Key with versionstamp placeholder
    $tr->setVersionstampedKey($keyWithPlaceholder, 'value');
    
    // Value with versionstamp placeholder
    $tr->setVersionstampedValue('key', $valueWithPlaceholder);
    
    // Get the versionstamp after commit
    $vs = $tr->getVersionstamp(); // FutureKey, await after commit
});
```
