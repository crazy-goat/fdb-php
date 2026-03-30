<?php

declare(strict_types=1);

namespace CrazyGoat\FoundationDB\Tuple;

final readonly class Bytes
{
    public function __construct(
        public string $data,
    ) {
    }
}
