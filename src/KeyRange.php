<?php

declare(strict_types=1);

namespace CrazyGoat\FoundationDB;

/**
 * A contiguous key range [begin, end) as returned by the blob granule
 * range-management APIs (`listBlobbifiedRanges()`, `getBlobGranuleRanges()`,
 * `blobbifyRange()`).
 */
final readonly class KeyRange
{
    public function __construct(
        public string $begin,
        public string $end,
    ) {
    }

    public function toString(): string
    {
        return '[' . KeyUtil::printable($this->begin) . ', ' . KeyUtil::printable($this->end) . ')';
    }
}
