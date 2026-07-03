<?php

declare(strict_types=1);

namespace CrazyGoat\FoundationDB;

use CrazyGoat\FoundationDB\Enum\StreamingMode;

final readonly class RangeOptions
{
    /**
     * @param ?int $limit Maximum number of rows to return.
     *                     - null: unlimited (all matching rows)
     *                     - 0:    no rows (empty result)
     *                     - >0:   at most N rows
     * @param bool $reverse If true, results are returned in reverse key order.
     * @param StreamingMode $mode Controls server-side batch sizing strategy.
     */
    public function __construct(
        public ?int $limit = null,
        public bool $reverse = false,
        public StreamingMode $mode = StreamingMode::Iterator,
    ) {
    }
}
