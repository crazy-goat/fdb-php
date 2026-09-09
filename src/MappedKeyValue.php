<?php

declare(strict_types=1);

namespace CrazyGoat\FoundationDB;

/**
 * A single row of a mapped range read: one index key/value together with the
 * records fetched for it by the mapper (single round trip to the storage
 * servers).
 */
final readonly class MappedKeyValue
{
    /**
     * @param string $key The index key.
     * @param string $value The index value.
     * @param list<KeyValue> $results The records mapped from this index row.
     */
    public function __construct(
        public string $key,
        public string $value,
        public array $results,
    ) {
    }
}
