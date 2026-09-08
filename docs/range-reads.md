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

## Mapped Range Reads (single-round-trip index lookups)

`getMappedRange()` performs an index lookup and the corresponding record
fetches in a single round trip to the storage servers (backed by
`fdb_transaction_get_mapped_range`). Without it, an index-backed read requires
a `getRange()` over the index followed by N separate `get()` calls; with it,
every index row comes back together with its records in one response.

The index and the records must be laid out so that the mapper can address the
records from an index row. The mapper is a **packed tuple template** whose
elements are resolved per index row:

| Element      | Resolves to                                              |
|--------------|----------------------------------------------------------|
| `'literal'`  | Copied verbatim into the record key/value                |
| `{K[N]}`     | The N-th element (0-based) of the index key tuple        |
| `{V[N]}`     | The N-th element (0-based) of the index value tuple      |
| `{...}`      | Range descriptor — must be the **last** element          |

- Without `{...}`, the mapper describes a **point lookup**: exactly one
  record (the key built from the template) is fetched per index row. A
  missing record yields an empty `results` list.
- With `{...}` as the last element, the mapper describes a **range lookup**:
  every record whose key has the built key as a prefix is fetched per index
  row.
- Literal braces can be escaped by doubling them (`{{`, `}}`).

Example layout — index `('user', $userId) -> $name`, records under
`('records', $userId)` (multiple keys per user):

```php
use CrazyGoat\FoundationDB\Tuple\Tuple;

$tr = $db->createTransaction();
[$begin, $end] = Tuple::range(['user']); // index range

// mapper: ('records', {K[1]}, {...}) — every record under the
// ('records', $userId) prefix is fetched together with its index row
$mapper = Tuple::pack(['records', '{K[1]}', '{...}']);

$result = $tr->getMappedRange($begin, $end, $mapper)->await();

foreach ($result->entries as $mapped) {
    // $mapped->key / $mapped->value — the index row
    // $mapped->results            — list<KeyValue> fetched for this row
}

// Pagination: $result->more tells whether more index rows exist,
// $result->count the number of rows in this batch. Options (limit,
// reverse, streaming mode) are passed as a RangeOptions.
```

Notes:

- Mapped ranges are **not** supported on snapshot reads on current
  FoundationDB versions — call `getMappedRange()` on a plain `Transaction`
  (read-your-writes). Calling it on `Transaction::snapshot()` throws a
  `LogicException`.
- An invalid mapper (unparseable tuple, bad placeholder, or a `{...}`
  descriptor that is not the last element) surfaces as an `FDBException`.
- `getRange()`-style lazy pagination is not wrapped for mapped reads; use the
  `RangeOptions` limit together with `more` to page manually.

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
