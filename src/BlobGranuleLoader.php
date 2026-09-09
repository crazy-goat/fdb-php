<?php

declare(strict_types=1);

namespace CrazyGoat\FoundationDB;

/**
 * Callback interface used by `ReadTransaction::readBlobGranules()` to fetch
 * blob granule file data. Mirrors the `FDBReadBlobGranuleContext` function
 * pointers of `fdb_c.h`.
 *
 * `startLoad()` is called (potentially many times, up to
 * `$granuleParallelism` outstanding loads) to begin reading a slice of a
 * granule file; it must return a caller-chosen unique load id. `getLoad()`
 * is then called with that id and must return the requested bytes — the
 * returned buffer must remain valid until `freeLoad()` is called for the
 * same id. `freeLoad()` releases the resources held for the id.
 */
interface BlobGranuleLoader
{
    /**
     * Begin loading `length` bytes of `filename` starting at `offset`.
     * `fullFileLength` is the total length of the file.
     *
     * @return int A unique load id, used for the subsequent getLoad()/freeLoad() calls.
     */
    public function startLoad(string $filename, int $offset, int $length, int $fullFileLength): int;

    /**
     * Return the bytes for the previously started load. The data must stay
     * valid until freeLoad() is called with the same load id.
     */
    public function getLoad(int $loadId): string;

    /**
     * Release the resources held for the load. Called exactly once per load id.
     */
    public function freeLoad(int $loadId): void;
}
