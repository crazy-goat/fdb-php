# Atomic Operations

**Namespace:** `CrazyGoat\FoundationDB`

## Overview

Atomic operations modify values without reading them first, avoiding read-write conflicts. They're performed as part of a transaction but don't require a read conflict range.

## Integer Operations

The `add()`, `max()`, `min()`, `bitAnd()`, `bitOr()`, and `bitXor()` methods accept `int` parameters directly. The library handles the little-endian encoding internally.

| Method | Param Type | Description |
|--------|-----------|-------------|
| `add($key, int $param)` | `int` | Adds the integer to the stored value |
| `max($key, int $param)` | `int` | Keeps the larger value |
| `min($key, int $param)` | `int` | Keeps the smaller value |
| `bitAnd($key, int $param)` | `int` | Bitwise AND |
| `bitOr($key, int $param)` | `int` | Bitwise OR |
| `bitXor($key, int $param)` | `int` | Bitwise XOR |

## Byte Operations

These operate on raw byte strings and keep `string` parameters:

| Method | Param Type | Description |
|--------|-----------|-------------|
| `byteMax($key, string $param)` | `string` | Byte-string maximum |
| `byteMin($key, string $param)` | `string` | Byte-string minimum |
| `compareAndClear($key, string $param)` | `string` | Clears key if current value equals param |
| `setVersionstampedKey($key, string $value)` | `string` | Sets key with versionstamp |
| `setVersionstampedValue($key, string $param)` | `string` | Sets value with versionstamp |

## Counters

The most common use case — no `pack()` / `unpack()` needed:

```php
use CrazyGoat\FoundationDB\Transaction;

// Increment a counter
$db->add('page_views', 1);

// Increment by 10
$db->add('page_views', 10);

// Read the counter — returns int directly
$count = $db->getInt('page_views');
echo "Page views: {$count}\n"; // 11

// Inside a transaction
$db->transact(function (Transaction $tr) {
    $tr->add('page_views', 1);
    $count = $tr->getInt('page_views');
});
```

## Reading Integer Values

Use `getInt()` to read values stored by integer atomic operations:

```php
// Database level
$value = $db->getInt('counter'); // ?int — null if key doesn't exist

// Transaction level
$db->transact(function (Transaction $tr) {
    $value = $tr->getInt('counter'); // ?int
});

// Snapshot level
$db->readTransact(function ($snap) {
    $value = $snap->getInt('counter'); // ?int
});
```

`getInt()` decodes the stored bytes as an unsigned 64-bit little-endian integer. It returns `null` if the key does not exist. If the stored value is shorter than 8 bytes, it is zero-padded. If the stored value is longer than 8 bytes, `RuntimeException` is thrown to avoid silently truncating the input — callers should treat the key as non-integer data in that case.

## Bitwise Operations

```php
// Set specific bits
$db->bitOr('permissions', 0b00001100);

// Clear specific bits
$db->bitAnd('permissions', 0b11110011);

// Toggle a flag
$db->bitXor('flags', 0b00000001);

// Read the result
$flags = $db->getInt('flags');
```

## Max and Min

```php
// Track high score — only updates if new value is larger
$db->max('high_score', 999);

// Track minimum — only updates if new value is smaller
$db->min('response_time_ms', 42);
```

## Compare and Clear

Atomically clears a key only if its current value matches the expected value:

```php
$db->compareAndClear('temp_lock', 'expected_value');
```

Note: `compareAndClear()` uses `string` parameters since it compares raw byte values.

## Generic atomicOp()

For cases where you need raw byte-level control or a mutation type dynamically:

```php
use CrazyGoat\FoundationDB\Enum\MutationType;

$tr->atomicOp(MutationType::Add, 'counter', pack('P', 1));
```

The low-level `atomicOp()` always takes `string` parameters.

## Database-Level Convenience Methods

All integer atomic operations are available directly on the Database:

```php
$db->add('counter', 1);
$db->max('high_score', 999);
$db->min('lowest', 1);
$db->bitOr('flags', 0b00000001);
$db->bitAnd('flags', 0b11111110);
$db->bitXor('flags', 0b00000001);
$db->getInt('counter'); // ?int
```

## Size Limits

Atomic ops inherit the same key / value limits as regular writes:

- key ≤ 10,000 bytes,
- value / param ≤ 100,000 bytes (for `byteMax`, `byteMin`, `compareAndClear`,
  `setVersionstampedKey`, `setVersionstampedValue`, and `atomicOp()` custom
  byte params).

Exceeding the limit throws `\InvalidArgumentException` immediately at the
atomic-op call site, before the FFI call, with the actual length that failed.
See [transactions.md → Size Limits](transactions.md#key--value-size-limits)
for the full table and the defensive 32-bit FFI guard.

## Versionstamped Operations

Versionstamped operations embed the commit version into keys or values:

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
