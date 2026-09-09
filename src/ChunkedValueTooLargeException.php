<?php

declare(strict_types=1);

namespace CrazyGoat\FoundationDB;

/**
 * Thrown when a value exceeds the per-transaction cap of the atomic chunked
 * write mode (`Transaction::setValueChunked()` / `Database::setValueChunked()
 * with atomic: true`).
 *
 * The atomic mode must write all chunks plus the metadata record inside a
 * single transaction, so the assembled value is capped (with a safety margin
 * below FoundationDB's 10,000,000 B mutation budget, mirroring
 * `MutationBudget::SPLIT_TARGET_BYTES`). The exception is raised synchronously
 * at the call site, before any mutation is queued.
 *
 * Values above the cap can still be written with
 * `Database::setValueChunked(..., atomic: false)`, which splits the write
 * across multiple transactions (per-chunk atomicity, atomic metadata swap).
 */
final class ChunkedValueTooLargeException extends \RuntimeException
{
    public function __construct(
        public readonly int $valueSize,
        public readonly int $maxSize,
    ) {
        parent::__construct(sprintf(
            'Chunked value exceeds the single-transaction cap: %d bytes (limit is %d bytes). '
            . 'Use Database::setValueChunked(..., atomic: false) to split the write across '
            . 'multiple transactions.',
            $valueSize,
            $maxSize,
        ));
    }
}
