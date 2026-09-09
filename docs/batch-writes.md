# Batch writes

Multi-key write helpers built on top of a single transaction primitive, with
up-front accounting of FoundationDB's per-transaction [mutation budget]
(https://apple.github.io/foundationdb/known-limitations.html) of 10,000,000
bytes (keys + values + overhead).

## `Transaction::setBatch()`

Queues multiple writes into the current transaction:

```php
use CrazyGoat\FoundationDB\KeyConvertible;
use CrazyGoat\FoundationDB\Subspace;

$db->transact(function ($tr) use ($subspace): void {
    $tr->setBatch([
        ['users/alice', $payloadA],
        ['users/bob', $payloadB],
        [$subspace->pack(['stats', 7]), $stats],   // KeyConvertible supported
    ]);
});
```

- Entries are `[key, value]` pairs (list form, so `KeyConvertible` keys are
  supported). Keys and values are validated individually exactly like
  `set()` (key ≤ 10,000 B, value ≤ 100,000 B).
- The total batch size is computed **before any mutation is queued**. An
  oversized batch throws `CrazyGoat\FoundationDB\BatchTooLargeException`
  (public readonly fields: `$batchSize`, `$maxBatchSize`) at the call site,
  instead of an opaque server-side error surfacing at `commit()` after the
  mutations were already queued. Nothing is written.
- The writes are **blind** (no reads), so two concurrent `setBatch()` calls
  do not conflict with each other — the last commit wins. Conflict detection
  for read-modify-write patterns comes from the reads performed in the same
  transaction.
- If a key appears multiple times, the last entry wins; ordering within the
  batch is not guaranteed.

Typical read-modify-write flow:

```php
$db->transact(function ($tr): void {
    $updates = [];
    foreach ($keys as $key) {
        $updates[] = [$key, $tr->get($key)->await() . '-appended'];
    }
    $tr->setBatch($updates);
});
```

## `Database::setBatch()`

Convenience wrapper that runs the batch inside a retried transaction
(`transact()`), so retryable FDB errors are retried with the standard
`onError()` backoff, bounded by the process-wide retry-limit / timeout
settings:

```php
$db->setBatch([
    ['cache/alpha', '1'],
    ['cache/beta', '2'],
]);
```

### Split mode

```php
$db->setBatch($hugeBatch, split: true);
```

Groups the batch into chunks of at most `MutationBudget::SPLIT_TARGET_BYTES`
(8,000,000 B) and commits each group in its own retried transaction. This
removes the size ceiling, at a documented cost:

- each **individual key write** stays atomic,
- there is **no cross-key snapshot consistency** — while the batch is in
  flight, readers may see a mixture of old and new keys,
- there is **no all-or-nothing commit** — a failure mid-batch leaves part of
  the keys updated.

This is deliberately not symmetric with the chunked-values mode (#115),
where a metadata swap hides the multi-transaction split behind an atomic
pointer update: a batch of *independent keys* has no such pointer to swap.
Split mode therefore suits bulk loads and independent key refreshes, but not
keys that must stay mutually consistent (e.g. data + index pairs).

Split mode accepts any `iterable`, including generators — entries are
consumed lazily and grouped on the fly, so oversized batches do not need to
be materialized twice in memory.

## Relationship to chunked values (#115)

Complementary features: `setBatch()` writes **many small values**, while
`setValueChunked()` (issue #115) writes **one value larger than the 100 kB
per-value limit** by splitting it across ordered sub-keys. Framework cache
adapters typically use both — small entries via `setBatch()`, oversized
entries via chunked writes.

Both share the internal `MutationBudget` accounting; the chunked key-space
layout is private to the chunked layer and `setBatch()` performs no
`\x00`-prefix validation on user keys (keys remain arbitrary binary, with
the same freedom as plain `set()`).
