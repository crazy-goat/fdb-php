<?php

declare(strict_types=1);

namespace CrazyGoat\FoundationDB;

use FFI;
use FFI\CData;

/**
 * Builds the native `FDBReadBlobGranuleContext` for
 * `fdb_transaction_read_blob_granules` out of a PHP `BlobGranuleLoader`.
 *
 * The PHP callback methods are cast to C function pointers; this object
 * keeps references to them (and to any in-flight data buffers) so neither
 * the callbacks nor the buffers are garbage collected while FDB may still
 * call into them.
 */
final class BlobGranuleReadContext
{
    private readonly CData $context;

    /** @var array<int, CData> In-flight buffers keyed by load id. */
    private array $buffers = [];

    public function __construct(
        private readonly NativeClient $client,
        private readonly BlobGranuleLoader $loader,
        int $granuleParallelism = 1,
    ) {
        $context = $this->client->fdb->new('FDBReadBlobGranuleContext');
        $context->user_context = null;
        $context->start_load_f = $this->startLoad(...);
        $context->get_load_f = $this->getLoad(...);
        $context->free_load_f = $this->freeLoad(...);
        $context->debug_no_materialize = 0;
        $context->granule_parallelism = $granuleParallelism;
        $this->context = $context;
    }

    /** C callback: fdb_c.h start_load_f. */
    private function startLoad(
        string $filename,
        int $filenameLength,
        int $offset,
        int $length,
        int $fullFileLength,
        ?CData $userContext,
    ): int {
        return $this->loader->startLoad(
            FFI::string($filename, $filenameLength),
            $offset,
            $length,
            $fullFileLength,
        );
    }

    /** C callback: fdb_c.h get_load_f. */
    private function getLoad(int $loadId, ?CData $userContext): ?CData
    {
        $data = $this->loader->getLoad($loadId);
        if ($data === '') {
            return null;
        }

        $buffer = $this->client->fdb->new('uint8_t[' . strlen($data) . ']');
        FFI::memcpy($buffer, $data, strlen($data));
        $this->buffers[$loadId] = $buffer;

        return FFI::cast('uint8_t*', $buffer);
    }

    /** C callback: fdb_c.h free_load_f. */
    private function freeLoad(int $loadId, ?CData $userContext): void
    {
        $this->loader->freeLoad($loadId);
        unset($this->buffers[$loadId]);
    }

    /**
     * Disable materialization (only issue the request to the blob workers —
     * useful for testing). Must be called before the context is passed to
     * `readBlobGranules()`.
     */
    public function setDebugNoMaterialize(bool $debugNoMaterialize = true): void
    {
        $this->context->debug_no_materialize = $debugNoMaterialize ? 1 : 0;
    }

    public function toCData(): CData
    {
        return $this->context;
    }
}
