<?php

declare(strict_types=1);

namespace CrazyGoat\FoundationDB;

use CrazyGoat\FoundationDB\Enum\StreamingMode;

final readonly class RangeOptions
{
    public function __construct(
        public ?int $limit = null,
        public bool $reverse = false,
        public StreamingMode $mode = StreamingMode::Iterator,
    ) {
    }
}
