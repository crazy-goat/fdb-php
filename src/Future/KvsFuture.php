<?php

declare(strict_types=1);

namespace CrazyGoat\FoundationDB\Future;

/**
 * A pending (or already resolved) batch of key-value pairs that can be
 * resolved with await().
 *
 * Implemented by FutureKeyValueArray (a real FDB future backed by
 * `fdb_transaction_get_range()`) and by FutureKvResult (an already-resolved
 * value, e.g. from unit-test stubs). RangeResult::paginate() accepts either,
 * which lets callers hand in un-awaited futures so that the next page can be
 * prefetched while the current one is being consumed.
 */
interface KvsFuture
{
    public function await(): FutureKvResult;
}
