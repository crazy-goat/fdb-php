<?php

declare(strict_types=1);

namespace CrazyGoat\FoundationDB\Tuple;

final readonly class SingleFloat
{
    public function __construct(
        public float $value,
    ) {
    }
}
