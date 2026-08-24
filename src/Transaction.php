<?php

declare(strict_types=1);

namespace CrazyGoat\FoundationDB;

use CrazyGoat\FoundationDB\Enum\ConflictRangeType;
use CrazyGoat\FoundationDB\Enum\MutationType;
use CrazyGoat\FoundationDB\Future\FutureInt64;
use CrazyGoat\FoundationDB\Future\FutureKey;
use CrazyGoat\FoundationDB\Future\FutureVoid;
use CrazyGoat\FoundationDB\Option\TransactionOptions;
use FFI;
use FFI\CData;

final class Transaction extends ReadTransaction implements Transactor
{
    public function __construct(
        CData $tpointer,
        Database $db,
        NativeClient $client,
    ) {
        parent::__construct($tpointer, $db, $client, false);
    }

    public function set(string|KeyConvertible $key, string $value): void
    {
        $resolvedKey = $this->resolveKey($key);
        $keyLength = KeyValueLimits::assertValidKey($resolvedKey);
        $valueLength = KeyValueLimits::assertValidValue($value);

        $this->client->fdb->fdb_transaction_set(
            $this->tpointer,
            $resolvedKey,
            $keyLength,
            $value,
            $valueLength,
        );
    }

    public function clear(string|KeyConvertible $key): void
    {
        $resolvedKey = $this->resolveKey($key);
        $keyLength = KeyValueLimits::assertValidKey($resolvedKey);

        $this->client->fdb->fdb_transaction_clear(
            $this->tpointer,
            $resolvedKey,
            $keyLength,
        );
    }

    public function clearRange(string $begin, string $end): void
    {
        $beginLength = KeyValueLimits::assertValidRangeEndpoint($begin);
        $endLength = KeyValueLimits::assertValidRangeEndpoint($end);

        $this->client->fdb->fdb_transaction_clear_range(
            $this->tpointer,
            $begin,
            $beginLength,
            $end,
            $endLength,
        );
    }

    public function clearRangeStartsWith(string $prefix): void
    {
        $end = KeyUtil::strinc($prefix);

        if ($end === null) {
            return;
        }

        $this->clearRange($prefix, $end);
    }

    public function commit(): FutureVoid
    {
        return new FutureVoid(
            $this->client->fdb->fdb_transaction_commit($this->tpointer),
            $this->client,
        );
    }

    public function onError(int $code): FutureVoid
    {
        return new FutureVoid(
            $this->client->fdb->fdb_transaction_on_error($this->tpointer, $code),
            $this->client,
        );
    }

    public function reset(): void
    {
        $this->client->fdb->fdb_transaction_reset($this->tpointer);
    }

    public function cancel(): void
    {
        $this->client->fdb->fdb_transaction_cancel($this->tpointer);
    }

    public function setReadVersion(int $version): void
    {
        $this->client->fdb->fdb_transaction_set_read_version($this->tpointer, $version);
    }

    public function getCommittedVersion(): int
    {
        $out = $this->client->fdb->new('int64_t');
        $this->client->checkError(
            $this->client->fdb->fdb_transaction_get_committed_version($this->tpointer, FFI::addr($out)),
        );

        return $out->cdata;
    }

    public function getApproximateSize(): FutureInt64
    {
        return new FutureInt64(
            $this->client->fdb->fdb_transaction_get_approximate_size($this->tpointer),
            $this->client,
        );
    }

    public function getVersionstamp(): FutureKey
    {
        return new FutureKey(
            $this->client->fdb->fdb_transaction_get_versionstamp($this->tpointer),
            $this->client,
        );
    }

    public function watch(string $key): FutureVoid
    {
        $keyLength = KeyValueLimits::assertValidKey($key);

        return new FutureVoid(
            $this->client->fdb->fdb_transaction_watch(
                $this->tpointer,
                $key,
                $keyLength,
            ),
            $this->client,
        );
    }

    public function atomicOp(MutationType $type, string $key, string $param): void
    {
        $keyLength = KeyValueLimits::assertValidKey($key);
        $paramLength = KeyValueLimits::assertValidValue($param);

        $this->client->fdb->fdb_transaction_atomic_op(
            $this->tpointer,
            $key,
            $keyLength,
            $param,
            $paramLength,
            $type->value,
        );
    }

    public function add(string $key, int $param): void
    {
        $this->atomicOp(MutationType::Add, $key, pack('P', $param));
    }

    public function bitAnd(string $key, int $param): void
    {
        $this->atomicOp(MutationType::BitAnd, $key, pack('P', $param));
    }

    public function bitOr(string $key, int $param): void
    {
        $this->atomicOp(MutationType::BitOr, $key, pack('P', $param));
    }

    public function bitXor(string $key, int $param): void
    {
        $this->atomicOp(MutationType::BitXor, $key, pack('P', $param));
    }

    public function max(string $key, int $param): void
    {
        $this->atomicOp(MutationType::Max, $key, pack('P', $param));
    }

    public function min(string $key, int $param): void
    {
        $this->atomicOp(MutationType::Min, $key, pack('P', $param));
    }

    public function byteMax(string $key, string $param): void
    {
        $this->atomicOp(MutationType::ByteMax, $key, $param);
    }

    public function byteMin(string $key, string $param): void
    {
        $this->atomicOp(MutationType::ByteMin, $key, $param);
    }

    public function compareAndClear(string $key, string $param): void
    {
        $this->atomicOp(MutationType::CompareAndClear, $key, $param);
    }

    public function setVersionstampedKey(string $key, string $value): void
    {
        $this->atomicOp(MutationType::SetVersionstampedKey, $key, $value);
    }

    public function setVersionstampedValue(string $key, string $param): void
    {
        $this->atomicOp(MutationType::SetVersionstampedValue, $key, $param);
    }

    public function addReadConflictRange(string $begin, string $end): void
    {
        $beginLength = KeyValueLimits::assertValidRangeEndpoint($begin);
        $endLength = KeyValueLimits::assertValidRangeEndpoint($end);

        $this->client->checkError(
            $this->client->fdb->fdb_transaction_add_conflict_range(
                $this->tpointer,
                $begin,
                $beginLength,
                $end,
                $endLength,
                ConflictRangeType::Read->value,
            ),
        );
    }

    public function addWriteConflictRange(string $begin, string $end): void
    {
        $beginLength = KeyValueLimits::assertValidRangeEndpoint($begin);
        $endLength = KeyValueLimits::assertValidRangeEndpoint($end);

        $this->client->checkError(
            $this->client->fdb->fdb_transaction_add_conflict_range(
                $this->tpointer,
                $begin,
                $beginLength,
                $end,
                $endLength,
                ConflictRangeType::Write->value,
            ),
        );
    }

    public function addReadConflictKey(string $key): void
    {
        // Conflict keys are the [key, key + "\x00") range. Both endpoints must fit
        // within the FDB key limit; a key one byte short of the limit pushes the end
        // past it. Validate both endpoints up front so the application sees the
        // error at the call site instead of during commit.
        $beginLength = KeyValueLimits::assertValidRangeEndpoint($key);
        $endLength = KeyValueLimits::assertValidRangeEndpoint($key . "\x00");

        $this->client->checkError(
            $this->client->fdb->fdb_transaction_add_conflict_range(
                $this->tpointer,
                $key,
                $beginLength,
                $key . "\x00",
                $endLength,
                ConflictRangeType::Read->value,
            ),
        );
    }

    public function addWriteConflictKey(string $key): void
    {
        $beginLength = KeyValueLimits::assertValidRangeEndpoint($key);
        $endLength = KeyValueLimits::assertValidRangeEndpoint($key . "\x00");

        $this->client->checkError(
            $this->client->fdb->fdb_transaction_add_conflict_range(
                $this->tpointer,
                $key,
                $beginLength,
                $key . "\x00",
                $endLength,
                ConflictRangeType::Write->value,
            ),
        );
    }

    public function options(): TransactionOptions
    {
        return new TransactionOptions($this);
    }

    public function setOption(int $option, ?string $value = null): void
    {
        if ($value !== null) {
            $valueLength = KeyValueLimits::assertValidFfiLength($value, 'Transaction option value');
        } else {
            $valueLength = 0;
        }

        $this->client->checkError(
            $this->client->fdb->fdb_transaction_set_option(
                $this->tpointer,
                $option,
                $value,
                $valueLength,
            ),
        );
    }

    /**
     * Returns a fresh Snapshot sharing this transaction's native handle.
     *
     * The Snapshot is intentionally NOT cached on the Transaction: a cached
     * instance would hold a strong back-reference to $this, forming a
     * reference cycle (Transaction -> snapshotInstance -> parentTransaction)
     * whose refcount never reaches zero on scope exit. That defers
     * fdb_transaction_destroy() to the cycle collector (or process shutdown
     * with zend.enable_gc=0), leaking native handles in long-running workers.
     *
     * Without the cache, the Snapshot keeps its parent alive one-directionally,
     * and both objects are released deterministically when they go out of scope.
     *
     * @see \CrazyGoat\FoundationDB\Snapshot for the lifetime relationship.
     */
    public function snapshot(): Snapshot
    {
        return new Snapshot($this->tpointer, $this->db, $this->client, $this);
    }

    public function transact(callable $fn): mixed
    {
        return $fn($this);
    }

    public function __destruct()
    {
        $this->client->fdb->fdb_transaction_destroy($this->tpointer);
    }
}
