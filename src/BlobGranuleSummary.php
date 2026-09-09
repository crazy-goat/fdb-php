<?php

declare(strict_types=1);

namespace CrazyGoat\FoundationDB;

/**
 * A single granule summary as returned by
 * `ReadTransaction::summarizeBlobGranules()` (backed by
 * `fdb_future_get_granule_summary_array`).
 */
final readonly class BlobGranuleSummary
{
    public function __construct(
        public KeyRange $keyRange,
        public int $snapshotVersion,
        public int $snapshotSize,
        public int $deltaVersion,
        public int $deltaSize,
    ) {
    }
}
