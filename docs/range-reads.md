# Range Reads

**Namespace:** `CrazyGoat\FoundationDB`

## Overview

Range reads retrieve multiple key-value pairs. Two modes: lazy (Generator-based, memory-efficient) and eager (load all into array).

## Lazy Range Reads

Generator-based iteration:

```php
use CrazyGoat\FoundationDB\Transaction;

$db->transact(function (Transaction $tr) {
    // Iterate lazily — fetches in batches automatically
    foreach ($tr->getRangeStartsWith('users/') as $kv) {
        echo "{$kv->key} = {$kv->value}\n";
    }
    
    // With explicit begin/end
    foreach ($tr->getRange('a', 'z') as $kv) {
        // ...
    }
});
```

- Returns `RangeResult` which implements `IteratorAggregate<int, KeyValue>`
- Fetches data in batches internally (automatic pagination)
- Memory-efficient for large ranges

## Eager Range Reads

Load all results into array:

```php
$db->transact(function (Transaction $tr) {
    // Eager fetch — all results in memory
    $results = $tr->getRangeAll('a', 'z');
    // $results is list<KeyValue>
    
    $results = $tr->getRangeAllStartsWith('users/');
    
    echo count($results) . " results\n";
});
```

- Uses `StreamingMode::WantAll` internally for optimal batch size
- Returns `list<KeyValue>` directly
- Use when you need all results or need to count them

## Converting Lazy to Eager

```php
$rangeResult = $tr->getRangeStartsWith('users/');
$array = $rangeResult->toArray(); // list<KeyValue>
```

## RangeOptions

```php
use CrazyGoat\FoundationDB\RangeOptions;
use CrazyGoat\FoundationDB\Enum\StreamingMode;

$options = new RangeOptions(
    limit: 100,           // max results (null = unlimited, 0 = empty result)
    reverse: true,        // reverse order
    mode: StreamingMode::WantAll,  // streaming mode
);

foreach ($tr->getRangeStartsWith('users/', $options) as $kv) {
    // Last 100 users in reverse order
}
```

## StreamingMode Enum

| Mode | Value | Description |
|------|-------|-------------|
| `WantAll` | -2 | Client wants all data; server sends large batches |
| `Iterator` | -1 | Default; server adjusts batch size based on iteration |
| `Exact` | 0 | Batch size equals limit |
| `Small` | 1 | Small batches |
| `Medium` | 2 | Medium batches |
| `Large` | 3 | Large batches |
| `Serial` | 4 | One batch at a time |

## KeySelector

Precise range boundaries:

```php
use CrazyGoat\FoundationDB\KeySelector;

// Named constructors
$begin = KeySelector::firstGreaterOrEqual('users/a');
$end = KeySelector::firstGreaterThan('users/z');

// Other selectors
KeySelector::lastLessThan('key');
KeySelector::lastLessOrEqual('key');

// Offset arithmetic
$selector = KeySelector::firstGreaterOrEqual('key')->add(5);
$selector = KeySelector::lastLessThan('key')->subtract(2);

// Use with getRange
foreach ($tr->getRange($begin, $end) as $kv) {
    // ...
}
```

- Properties: `$selector->key`, `$selector->orEqual`, `$selector->offset`

## Database-Level Range Reads

Convenience methods (auto-transact):

```php
// These wrap in transactions automatically
$results = $db->getRange('a', 'z');           // list<KeyValue>
$results = $db->getRangeStartsWith('users/'); // list<KeyValue>
$results = $db->getRangeAll('a', 'z');         // list<KeyValue>
$results = $db->getRangeAllStartsWith('users/'); // list<KeyValue>
```

Note: Database-level methods always return arrays (not lazy iterators).

## Range Size Estimation

```php
$db->transact(function (Transaction $tr) {
    $bytes = $tr->getEstimatedRangeSizeBytes('a', 'z')->await();
    echo "Estimated size: {$bytes} bytes\n";
    
    $splitPoints = $tr->getRangeSplitPoints('a', 'z', 1_000_000)->await();
    // Split range into ~1MB chunks
});
```

## KeyValue Object

- `$kv->key` — string (readonly)
- `$kv->value` — string (readonly)
