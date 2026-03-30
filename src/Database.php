<?php

declare(strict_types=1);

namespace CrazyGoat\FoundationDB;

use FFI;
use FFI\CData;

final readonly class Database implements Transactor, ReadTransactor
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

    public function transact(callable $fn): mixed
    {
        $tr = $this->createTransaction();

        while (true) {
            try {
                $result = $fn($tr);
                $tr->commit()->await();

                return $result;
            } catch (FDBException $e) {
                $tr->onError($e->fdbCode)->await();
            }
        }
    }

    public function readTransact(callable $fn): mixed
    {
        $tr = $this->createTransaction();

        while (true) {
            try {
                return $fn($tr->snapshot());
            } catch (FDBException $e) {
                $tr->onError($e->fdbCode)->await();
            }
        }
    }

    public function get(string|KeyConvertible $key): ?string
    {
        /** @var ?string */
        return $this->transact(fn (Transaction $tr): ?string => $tr->get($key)->await());
    }

    public function set(string|KeyConvertible $key, string $value): void
    {
        $this->transact(function (Transaction $tr) use ($key, $value): void {
            $tr->set($key, $value);
        });
    }

    public function clear(string|KeyConvertible $key): void
    {
        $this->transact(function (Transaction $tr) use ($key): void {
            $tr->clear($key);
        });
    }

    public function clearRange(string $begin, string $end): void
    {
        $this->transact(function (Transaction $tr) use ($begin, $end): void {
            $tr->clearRange($begin, $end);
        });
    }

    public function clearRangeStartsWith(string $prefix): void
    {
        $this->transact(function (Transaction $tr) use ($prefix): void {
            $tr->clearRangeStartsWith($prefix);
        });
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
    public function getClient(): NativeClient
    {
        return $this->client;
    }

    public function __destruct()
    {
        $this->client->fdb->fdb_database_destroy($this->dpointer);
    }
}
