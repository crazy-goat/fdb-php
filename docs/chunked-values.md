# Chunked values

FoundationDB enforces a hard limit of **100,000 bytes per value**. Chunked
values lift that ceiling for a *single logical value* by splitting it into
ordered chunks under a dedicated, `\x00`-namespaced sub-key space derived
from the base key. The layout is owned by the `@internal
Chunk\ChunkKeyCodec`:

- metadata key: `$key . "\x00" . "\x00"` — sorts before every chunk
- chunk keys:   `$key . "\x00" . Tuple::pack([$generation, $index])`
- namespace:    `[$key . "\x00", $key . "\x01")`

The `\x00` separator sorts before every tuple type byte, so the namespace
can never collide with user text suffixes (`$key . "abc"` stays outside) and
is unreachable through the public `Tuple` / `Subspace` / `Directory` API.
The metadata record carries a magic signature (`FDBCK1`), the total length,
the chunk count and the active generation — so foreign or tampered data is
detected loudly instead of silently mis-read.

## Writing

```php
// Atomic mode (default): everything in one transaction, capped at 8 MB
$db->setValueChunked('cache/page/home', $html);

// Non-atomic mode: no size cap, written across multiple transactions
$db->setValueChunked('cache/page/huge', $enormousHtml, atomic: false);

// Custom chunk size (1..100,000 bytes)
$db->setValueChunked('cache/blobs', $data, 32768);

// Transaction level (atomic only — it queues into the current transaction)
$db->transact(function ($tr): void {
    $tr->setValueChunked('cache/page/home', $html);
});
```

### Atomic mode (default)

The namespace clear, all chunk writes and the metadata record happen inside
one transaction, so readers see either the whole old or the whole new value
— never a mixture, never a stale tail from a previous larger value. Because
all mutations share the 10,000,000 B per-transaction budget, the assembled
value is capped at 8,000,000 B (`MutationBudget::SPLIT_TARGET_BYTES`);
`ChunkedValueTooLargeException` (public readonly `valueSize` / `maxSize`) is
thrown at the call site, before any mutation is queued.

### Non-atomic mode (`atomic: false`)

Removes the size cap by splitting the write across multiple transactions
using **generations**:

1. chunks of the new value are written under `generation + 1` in
   budget-sized groups, each group committed in its own retried transaction;
2. a final micro-transaction atomically swaps the metadata record to the new
   generation and clears everything below it (the previous generation plus
   any orphaned chunks from interrupted attempts).

Readers always see either the whole old or the whole new value, because
`getValueChunked()` selects chunks by the generation stored in the metadata.
A crash between the chunk transactions and the metadata swap leaves only
orphaned chunks — reads are unaffected, and the next non-atomic write to the
key cleans them up. Two concurrent non-atomic writers to the same key
conflict on their chunk ranges, so one retries.

## Reading

```php
$value = $db->transact(fn ($tr) => $tr->getValueChunked('cache/page/home'));
```

- key holds no chunked value → `null`
- key holds an empty chunked value → `""` (not `null`)
- metadata malformed or chunks missing / wrong total length →
  `ChunkedValueCorruptedException` (loud failure, never garbage)

Available on `Transaction`, `Tenant` transactions and snapshots
(`$tr->snapshot()->getValueChunked(...)` — note that snapshot reads create
no read-conflict ranges).

## Deleting

```php
$db->deleteValueChunked('cache/page/home');   // or $tr->deleteValueChunked(...)
```

One range clear removes the metadata record, all chunks and all generations
at once. Deleting a key that holds no chunked value is a no-op.

## Semantics and caveats

- **Mixing plain `set()` and chunked values on the same base key is
  unsupported.** The chunk namespace is separate, so the two representations
  can silently diverge; `getValueChunked()` reads only the namespace, and a
  raw `set()` under the base key is left as orphaned bytes.
- `watch()` and atomic operations do not observe chunked values (they
  operate on single keys, not on the namespace).
- TTL / expiration is deliberately out of scope for this layer — cache
  adapters implement it above chunked values.
- `setBatch()` (#116) writes **many small values**; chunked values handle
  **one large value**. The two share the `@internal MutationBudget` byte
  accounting only.
