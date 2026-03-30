<?php

declare(strict_types=1);

namespace CrazyGoat\FoundationDB;

use FFI;
use FFI\CData;

final readonly class Database
{
    public function __construct(
        private CData $dpointer,
        private NativeClient $client,
    ) {
    }

    public function createTransaction(): Transaction
    {
        $trPointer = $this->client->fdb->new('FDBTransaction*');
        $this->client->checkError(
            $this->client->fdb->fdb_database_create_transaction($this->dpointer, FFI::addr($trPointer)),
        );

        return new Transaction($trPointer, $this, $this->client);
    }

    public function setOption(int $option, ?string $value = null): void
    {
        $this->client->checkError(
            $this->client->fdb->fdb_database_set_option(
                $this->dpointer,
                $option,
                $value,
                $value !== null ? strlen($value) : 0,
            ),
        );
    }

    /** @internal */
    public function getPointer(): CData
    {
        return $this->dpointer;
    }

    /** @internal */
    public function getClient(): NativeClient
    {
        return $this->client;
    }

    public function __destruct()
    {
        $this->client->fdb->fdb_database_destroy($this->dpointer);
    }
}
