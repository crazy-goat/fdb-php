<?php

declare(strict_types=1);

namespace CrazyGoat\FoundationDB;

final readonly class KeySelector
{
    public function __construct(
        public string $key,
        public bool $orEqual,
        public int $offset,
    ) {
    }

    public static function lastLessThan(string $key): self
    {
        return new self($key, false, 0);
    }

    public static function lastLessOrEqual(string $key): self
    {
        return new self($key, true, 0);
    }

    public static function firstGreaterThan(string $key): self
    {
        return new self($key, true, 1);
    }

    public static function firstGreaterOrEqual(string $key): self
    {
        return new self($key, false, 1);
    }

    public function add(int $offset): self
    {
        return new self($this->key, $this->orEqual, $this->offset + $offset);
    }

    public function subtract(int $offset): self
    {
        return new self($this->key, $this->orEqual, $this->offset - $offset);
    }
}
