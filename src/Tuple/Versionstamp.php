<?php

declare(strict_types=1);

namespace CrazyGoat\FoundationDB\Tuple;

final class Versionstamp
{
    public function __construct(
        public readonly string $trVersion,
        public readonly int $userVersion,
    ) {
        if (strlen($this->trVersion) !== 10) {
            throw new \InvalidArgumentException(
                'Transaction version must be exactly 10 bytes, got ' . strlen($this->trVersion)
            );
        }

        if ($this->userVersion < 0 || $this->userVersion > 65535) {
            throw new \InvalidArgumentException(
                'User version must be between 0 and 65535, got ' . $this->userVersion
            );
        }
    }

    public static function incomplete(int $userVersion = 0): self
    {
        return new self(str_repeat("\xFF", 10), $userVersion);
    }

    public function isComplete(): bool
    {
        return $this->trVersion !== str_repeat("\xFF", 10);
    }
}
