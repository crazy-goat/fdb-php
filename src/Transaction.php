<?php

declare(strict_types=1);

namespace CrazyGoat\FoundationDB;

use FFI;
use FFI\CData;

final class Transaction
{
    public function __construct(
        private readonly CData $tpointer,
        private readonly Database $db,
        private readonly NativeClient $client,
    ) {
    }

    public function set(string $key, string $value): void
    {
        $this->client->fdb->fdb_transaction_set(
            $this->tpointer,
            $key,
            strlen($key),
            $value,
            strlen($value),
        );
    }

    public function get(string $key): ?string
    {
        $future = $this->client->fdb->fdb_transaction_get(
            $this->tpointer,
            $key,
            strlen($key),
            0,
        );

        $this->client->checkError(
            $this->client->fdb->fdb_future_block_until_ready($future),
        );
        $this->client->checkError(
            $this->client->fdb->fdb_future_get_error($future),
        );

        $present = $this->client->fdb->new('fdb_bool_t');
        $valuePtr = $this->client->fdb->new('char*');
        $valueLength = $this->client->fdb->new('int');

        $this->client->checkError(
            $this->client->fdb->fdb_future_get_value(
                $future,
                FFI::addr($present),
                FFI::addr($valuePtr),
                FFI::addr($valueLength),
            ),
        );

        if ($present->cdata === 0) {
            $this->client->fdb->fdb_future_destroy($future);
            return null;
        }

        $result = FFI::string($valuePtr, $valueLength->cdata);
        $this->client->fdb->fdb_future_destroy($future);

        return $result;
    }

    public function clear(string $key): void
    {
        $this->client->fdb->fdb_transaction_clear(
            $this->tpointer,
            $key,
            strlen($key),
        );
    }

    public function commit(): void
    {
        $future = $this->client->fdb->fdb_transaction_commit($this->tpointer);

        $this->client->checkError(
            $this->client->fdb->fdb_future_block_until_ready($future),
        );
        $this->client->checkError(
            $this->client->fdb->fdb_future_get_error($future),
        );

        $this->client->fdb->fdb_future_destroy($future);
    }

    public function reset(): void
    {
        $this->client->fdb->fdb_transaction_reset($this->tpointer);
    }

    public function cancel(): void
    {
        $this->client->fdb->fdb_transaction_cancel($this->tpointer);
    }

    public function onError(int $code): void
    {
        $future = $this->client->fdb->fdb_transaction_on_error($this->tpointer, $code);

        $this->client->checkError(
            $this->client->fdb->fdb_future_block_until_ready($future),
        );
        $this->client->checkError(
            $this->client->fdb->fdb_future_get_error($future),
        );

        $this->client->fdb->fdb_future_destroy($future);
    }

    /** @internal */
    public function getPointer(): CData
    {
        return $this->tpointer;
    }

    public function __destruct()
    {
        $this->client->fdb->fdb_transaction_destroy($this->tpointer);
    }
}
