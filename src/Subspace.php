<?php

declare(strict_types=1);

namespace CrazyGoat\FoundationDB;

use CrazyGoat\FoundationDB\Tuple\Bytes;
use CrazyGoat\FoundationDB\Tuple\SingleFloat;
use CrazyGoat\FoundationDB\Tuple\Tuple;
use CrazyGoat\FoundationDB\Tuple\Uuid;
use CrazyGoat\FoundationDB\Tuple\Versionstamp;

class Subspace implements KeyConvertible
{
    public readonly string $rawPrefix;

    /**
     * @param list<null|bool|int|float|string|\GMP|Bytes|SingleFloat|Uuid|Versionstamp|list<mixed>> $prefixTuple
     */
    public function __construct(
        array $prefixTuple = [],
        string $rawPrefix = '',
    ) {
        $this->rawPrefix = $rawPrefix . ($prefixTuple !== [] ? Tuple::pack($prefixTuple) : '');
    }

    public function key(): string
    {
        return $this->rawPrefix;
    }

    /**
     * @param list<null|bool|int|float|string|\GMP|Bytes|SingleFloat|Uuid|Versionstamp|list<mixed>> $tuple
     */
    public function pack(array $tuple = []): string
    {
        return $this->rawPrefix . Tuple::pack($tuple);
    }

    /**
     * @return list<null|bool|int|float|string|\GMP|Bytes|SingleFloat|Uuid|Versionstamp|list<mixed>>
     */
    public function unpack(string $key): array
    {
        $prefixLength = strlen($this->rawPrefix);

        if (!str_starts_with($key, $this->rawPrefix)) {
            throw new \InvalidArgumentException(
                'Key does not start with the subspace prefix.',
            );
        }

        return Tuple::unpack($key, $prefixLength);
    }

    /**
     * @param list<null|bool|int|float|string|\GMP|Bytes|SingleFloat|Uuid|Versionstamp|list<mixed>> $tuple
     */
    public function packWithVersionstamp(array $tuple): string
    {
        $packed = Tuple::packWithVersionstamp($tuple);
        $prefixLength = strlen($this->rawPrefix);

        if ($prefixLength === 0) {
            return $packed;
        }

        $body = substr($packed, 0, -4);
        $offsetBytes = substr($packed, -4);
        $offsetData = unpack('V', $offsetBytes);

        if ($offsetData === false) {
            throw new \RuntimeException('Failed to read versionstamp offset');
        }

        /** @var int $originalOffset */
        $originalOffset = $offsetData[1];

        return $this->rawPrefix . $body . pack('V', $originalOffset + $prefixLength);
    }

    /**
     * @param list<null|bool|int|float|string|\GMP|Bytes|SingleFloat|Uuid|Versionstamp|list<mixed>> $tuple
     * @return array{string, string}
     */
    public function range(array $tuple = []): array
    {
        $packed = $this->pack($tuple);

        return [$packed . "\x00", $packed . "\xFF"];
    }

    public function contains(string $key): bool
    {
        return str_starts_with($key, $this->rawPrefix);
    }

    public function subspace(mixed $element): self
    {
        /** @var list<null|bool|int|float|string|\GMP|Bytes|SingleFloat|Uuid|Versionstamp|list<mixed>> $tuple */
        $tuple = [$element];

        return new self($tuple, $this->rawPrefix);
    }

    public function asFoundationDbKey(): string
    {
        return $this->key();
    }
}
