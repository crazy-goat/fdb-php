<?php

declare(strict_types=1);

namespace CrazyGoat\FoundationDB;

use CrazyGoat\FoundationDB\Future\FutureVoid;
use CrazyGoat\FoundationDB\Option\DatabaseOptions;
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

    /**
     * @return list<KeyValue>
     */
    public function getRange(
        string|KeySelector $begin,
        string|KeySelector $end,
        ?RangeOptions $options = null,
    ): array {
        /** @var list<KeyValue> */
        return $this->transact(
            fn (Transaction $tr): array => $tr->getRange($begin, $end, $options)->toArray(),
        );
    }

    /**
     * @return list<KeyValue>
     */
    public function getRangeStartsWith(
        string $prefix,
        ?RangeOptions $options = null,
    ): array {
        /** @var list<KeyValue> */
        return $this->transact(
            fn (Transaction $tr): array => $tr->getRangeStartsWith($prefix, $options)->toArray(),
        );
    }

    public function getKey(KeySelector $selector): string
    {
        /** @var string */
        return $this->transact(fn (Transaction $tr): string => $tr->getKey($selector)->await());
    }

    public function watch(string $key): FutureVoid
    {
        $tr = $this->createTransaction();

        while (true) {
            try {
                $watchFuture = $tr->watch($key);
                $tr->commit()->await();

                return $watchFuture;
            } catch (FDBException $e) {
                $tr->onError($e->fdbCode)->await();
            }
        }
    }

    /**
     * @return array{?string, FutureVoid}
     */
    public function getAndWatch(string $key): array
    {
        $tr = $this->createTransaction();

        while (true) {
            try {
                $value = $tr->get($key)->await();
                $watchFuture = $tr->watch($key);
                $tr->commit()->await();

                return [$value, $watchFuture];
            } catch (FDBException $e) {
                $tr->onError($e->fdbCode)->await();
            }
        }
    }

    public function setAndWatch(string $key, string $value): FutureVoid
    {
        $tr = $this->createTransaction();

        while (true) {
            try {
                $tr->set($key, $value);
                $watchFuture = $tr->watch($key);
                $tr->commit()->await();

                return $watchFuture;
            } catch (FDBException $e) {
                $tr->onError($e->fdbCode)->await();
            }
        }
    }

    public function clearAndWatch(string $key): FutureVoid
    {
        $tr = $this->createTransaction();

        while (true) {
            try {
                $tr->clear($key);
                $watchFuture = $tr->watch($key);
                $tr->commit()->await();

                return $watchFuture;
            } catch (FDBException $e) {
                $tr->onError($e->fdbCode)->await();
            }
        }
    }

    public function add(string $key, string $param): void
    {
        $this->transact(function (Transaction $tr) use ($key, $param): void {
            $tr->add($key, $param);
        });
    }

    public function bitAnd(string $key, string $param): void
    {
        $this->transact(function (Transaction $tr) use ($key, $param): void {
            $tr->bitAnd($key, $param);
        });
    }

    public function bitOr(string $key, string $param): void
    {
        $this->transact(function (Transaction $tr) use ($key, $param): void {
            $tr->bitOr($key, $param);
        });
    }

    public function bitXor(string $key, string $param): void
    {
        $this->transact(function (Transaction $tr) use ($key, $param): void {
            $tr->bitXor($key, $param);
        });
    }

    public function max(string $key, string $param): void
    {
        $this->transact(function (Transaction $tr) use ($key, $param): void {
            $tr->max($key, $param);
        });
    }

    public function min(string $key, string $param): void
    {
        $this->transact(function (Transaction $tr) use ($key, $param): void {
            $tr->min($key, $param);
        });
    }

    public function compareAndClear(string $key, string $param): void
    {
        $this->transact(function (Transaction $tr) use ($key, $param): void {
            $tr->compareAndClear($key, $param);
        });
    }

    public function getEstimatedRangeSizeBytes(string $begin, string $end): int
    {
        /** @var int */
        return $this->transact(
            fn (Transaction $tr): int => $tr->getEstimatedRangeSizeBytes($begin, $end)->await(),
        );
    }

    /**
     * @return list<string>
     */
    public function getRangeSplitPoints(string $begin, string $end, int $chunkSize): array
    {
        /** @var list<string> */
        return $this->transact(
            fn (Transaction $tr): array => $tr->getRangeSplitPoints($begin, $end, $chunkSize)->await(),
        );
    }

    public function options(): DatabaseOptions
    {
        return new DatabaseOptions($this);
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
