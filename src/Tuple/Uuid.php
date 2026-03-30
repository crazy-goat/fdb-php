<?php

declare(strict_types=1);

namespace CrazyGoat\FoundationDB\Tuple;

final readonly class Uuid
{
    public function __construct(
        public string $bytes,
    ) {
        if (strlen($this->bytes) !== 16) {
            throw new \InvalidArgumentException(
                'UUID must be exactly 16 bytes, got ' . strlen($this->bytes)
            );
        }
    }
}
