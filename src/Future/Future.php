<?php

declare(strict_types=1);

namespace CrazyGoat\FoundationDB\Future;

use CrazyGoat\FoundationDB\NativeClient;
use FFI\CData;

abstract class Future
{
    protected bool $resolved = false;

    public function __construct(
        protected CData $fpointer,
        protected readonly NativeClient $client,
    ) {
    }

    public function isReady(): bool
    {
        return (bool) $this->client->fdb->fdb_future_is_ready($this->fpointer);
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
        $this->client->checkError(
            $this->client->fdb->fdb_future_get_error($this->fpointer),
        );
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
