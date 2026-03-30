# Tuple Layer

**Namespace:** `CrazyGoat\FoundationDB\Tuple`

## Overview

The tuple layer provides a way to encode structured data into keys while preserving sort order. Binary-compatible with Python, Go, Java, and Ruby tuple layers.

## Basic Usage

```php
use CrazyGoat\FoundationDB\Tuple\Tuple;

$packed = Tuple::pack(['users', 42, 'name']);
$unpacked = Tuple::unpack($packed); // ['users', 42, 'name']
```

## Supported Types

| PHP Type | Tuple Type | Notes |
|----------|-----------|-------|
| `null` | Null | Encoded as 0x00 |
| `bool` | Boolean | true/false |
| `int` | Integer | 64-bit, variable-length encoding |
| `float` | Double | IEEE 754 double-precision |
| `string` | Unicode string | UTF-8, null-byte escaped |
| `\GMP` | Arbitrary integer | Requires ext-gmp, for integers > 64-bit |
| `Bytes` | Byte string | Raw bytes (not UTF-8 string) |
| `SingleFloat` | Single float | IEEE 754 single-precision |
| `Uuid` | UUID | 16-byte UUID |
| `Versionstamp` | Versionstamp | 10-byte transaction version + 2-byte user version |
| `array` (list) | Nested tuple | Recursive encoding |

## Sort Order Preservation

```php
// Binary comparison matches logical order
assert(Tuple::pack([1]) < Tuple::pack([2]));
assert(Tuple::pack(['a']) < Tuple::pack(['b']));
assert(Tuple::pack([1, 'a']) < Tuple::pack([1, 'b']));
```

## Tuple Comparison

```php
$cmp = Tuple::compare(['users', 1], ['users', 2]); // -1
$cmp = Tuple::compare(['a', 1], ['a', 1]);          // 0
```

## Range Queries

```php
[$begin, $end] = Tuple::range(['users']);
// All keys starting with ('users',) — use with getRange()
```

## Special Types

### Bytes

Raw byte strings (vs UTF-8 strings):

```php
use CrazyGoat\FoundationDB\Tuple\Bytes;

$packed = Tuple::pack([new Bytes("\x00\x01\x02")]);
```

### SingleFloat

32-bit float:

```php
use CrazyGoat\FoundationDB\Tuple\SingleFloat;

$packed = Tuple::pack([new SingleFloat(3.14)]);
```

### Uuid

16-byte UUID:

```php
use CrazyGoat\FoundationDB\Tuple\Uuid;

$packed = Tuple::pack([new Uuid(random_bytes(16))]);
```

### Nested Tuples

```php
$packed = Tuple::pack([['nested', 'tuple'], 'flat']);
```

## Versionstamps

```php
use CrazyGoat\FoundationDB\Tuple\Versionstamp;

// Incomplete versionstamp (filled in by FDB on commit)
$vs = Versionstamp::incomplete(0);
$packed = Tuple::packWithVersionstamp([$vs, 'data']);

// Check for incomplete versionstamps
Tuple::hasIncompleteVersionstamp([$vs]); // true

// Complete versionstamp
$vs = new Versionstamp($trVersion, 42);
$vs->isComplete(); // true
```

## Cross-Language Compatibility

The encoding is binary-compatible with official FDB tuple layers in Python, Go, Java, and Ruby.
