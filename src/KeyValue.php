<?php

declare(strict_types=1);

namespace CrazyGoat\FoundationDB;

final readonly class KeyValue
{
    public function __construct(
        public string $key,
        public string $value,
    ) {
    }
}
