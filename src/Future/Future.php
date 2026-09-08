<?php

declare(strict_types=1);

namespace CrazyGoat\FoundationDB\Future;

use CrazyGoat\FoundationDB\NativeClient;
use FFI\CData;

abstract class Future
{
    protected bool $resolved = false;

    /**
     * Cached error state, captured in blockUntilReady() while the future's
     * memory is still valid. Null while the future has not been awaited yet.
     */
    protected ?bool $errorState = null;

    public function __construct(
        protected CData $fpointer,
        protected readonly NativeClient $client,
    ) {
    }

    public function isReady(): bool
    {
        return (bool) $this->client->fdb->fdb_future_is_ready($this->fpointer);
    }

    /**
     * Reports whether the future resolved to an error. Only meaningful once
     * the future is ready — call isReady() first or blockUntilReady() via
     * await(), and unlike await()/blockUntilReady() this does not throw the
     * error, it merely reports it.
     *
     * Note: this is deliberately NOT bound to fdb_future_is_error, which is
     * a removed-API stub in fdb_c.h for API versions >= 23 (calling it on a
     * modern libfdb_c aborts the process with "REMOVED FDB API FUNCTION").
     * The equivalent, safe source of truth is fdb_future_get_error(), which
     * returns the error code (0 when the future succeeded).
     */
    public function isError(): bool
    {
        if ($this->errorState !== null) {
            // The state was captured while the future's memory was still
            // valid; after releaseMemory() querying the handle is undefined.
            return $this->errorState;
        }

        return $this->client->fdb->fdb_future_get_error($this->fpointer) !== 0;
    }

    public function cancel(): void
    {
        $this->client->fdb->fdb_future_cancel($this->fpointer);
    }

    abstract public function await(): mixed;

    protected function blockUntilReady(): void
    {
        $this->client->checkError(
            $this->client->fdb->fdb_future_block_until_ready($this->fpointer),
        );
        // Capture the error code before releaseMemory() invalidates the
        // future's payload: fdb_future_get_error() must not be consulted
        // after the memory has been released.
        $errorCode = $this->client->fdb->fdb_future_get_error($this->fpointer);
        $this->errorState = $errorCode !== 0;
        if ($errorCode !== 0) {
            $this->client->checkError($errorCode);
        }
    }

    protected function releaseMemory(): void
    {
        $this->client->fdb->fdb_future_release_memory($this->fpointer);
    }

    public function __destruct()
    {
        $this->client->fdb->fdb_future_destroy($this->fpointer);
    }
}
