<?php

declare(strict_types=1);

namespace CrazyGoat\FoundationDB\Future;

/**
 * Result of a mapped range read batch: the mapped index rows plus the
 * pagination hint.
 */
final readonly class MappedRangeResult
{
    /**
     * @param list<\CrazyGoat\FoundationDB\MappedKeyValue> $entries
     * @param int<0, max> $count
     */
    public function __construct(
        public array $entries,
        public int $count,
        public bool $more,
    ) {
    }
}
