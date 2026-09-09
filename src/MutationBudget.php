<?php

declare(strict_types=1);

namespace CrazyGoat\FoundationDB;

/**
 * Mutation-budget accounting shared by the batch-write helpers
 * (`Transaction::setBatch()` / `Database::setBatch()`).
 *
 * FoundationDB enforces a hard limit of 10,000,000 bytes on the total size
 * of a committed transaction (keys + values + overhead). The native client
 * rejects oversized commits with an opaque server-side error at commit time,
 * *after* the application has already queued its mutations. Pre-flight
 * accounting at the PHP trust boundary surfaces the overflow at the call
 * site instead, as a named `BatchTooLargeException`.
 *
 * Split mode (`Database::setBatch(..., split: true)`) groups entries under
 * {@see self::SPLIT_TARGET_BYTES} — a deliberately conservative margin below
 * the hard limit so that key/tuple overhead, conflict-range bookkeeping and
 * the read-write sets of the surrounding transaction cannot push a group
 * over the server-side limit during commit.
 *
 * This type intentionally knows nothing about the chunked-values key space
 * (see issue #115): it is pure byte accounting, shared by every multi-key
 * write helper.
 *
 * @internal Shared infrastructure; not part of the public API.
 */
final class MutationBudget
{
    /**
     * FoundationDB's server-side per-transaction mutation budget
     * (https://apple.github.io/foundationdb/known-limitations.html).
     */
    public const TRANSACTION_MUTATION_LIMIT = 10000000;

    /**
     * Target group size when a batch is split across multiple transactions.
     *
     * Comfortably below the hard limit so that per-key overhead (conflict
     * ranges, commit bookkeeping) can never push a group past the server-side
     * check. Each individual entry is bounded by `KeyValueLimits::MAX_KEY_SIZE
     * + MAX_VALUE_SIZE` (20 kB), so a group can only overshoot the target by a
     * single entry and always stays far below the hard limit.
     */
    public const SPLIT_TARGET_BYTES = 8000000;

    private function __construct()
    {
    }

    /**
     * Assert that the measured batch fits inside a single transaction's
     * mutation budget.
     *
     * @throws BatchTooLargeException when `$totalBytes` exceeds the budget
     */
    public static function assertWithinTransactionLimit(int $totalBytes): void
    {
        if ($totalBytes > self::TRANSACTION_MUTATION_LIMIT) {
            throw new BatchTooLargeException($totalBytes, self::TRANSACTION_MUTATION_LIMIT);
        }
    }

    /**
     * Byte size of a single `[key, value]` batch entry, with the key resolved
     * to its final binary form (`KeyConvertible` keys are packed via
     * `asFoundationDbKey()`).
     *
     * @param array{0: string|KeyConvertible, 1: string} $pair
     */
    public static function entrySize(array $pair): int
    {
        [$key, $value] = $pair;

        return strlen(self::resolveKeyForSizing($key)) + strlen($value);
    }

    private static function resolveKeyForSizing(string|KeyConvertible $key): string
    {
        return $key instanceof KeyConvertible ? $key->asFoundationDbKey() : $key;
    }
}
