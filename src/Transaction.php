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
    private ?Snapshot $snapshotInstance = null;

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

        $this->client->fdb->fdb_transaction_set(
            $this->tpointer,
            $resolvedKey,
            strlen($resolvedKey),
            $value,
            strlen($value),
        );
    }

    public function clear(string|KeyConvertible $key): void
    {
        $resolvedKey = $this->resolveKey($key);

        $this->client->fdb->fdb_transaction_clear(
            $this->tpointer,
            $resolvedKey,
            strlen($resolvedKey),
        );
    }

    public function clearRange(string $begin, string $end): void
    {
        $this->client->fdb->fdb_transaction_clear_range(
            $this->tpointer,
            $begin,
            strlen($begin),
            $end,
            strlen($end),
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
        return new FutureVoid(
            $this->client->fdb->fdb_transaction_watch(
                $this->tpointer,
                $key,
                strlen($key),
            ),
            $this->client,
        );
    }

    public function atomicOp(MutationType $type, string $key, string $param): void
    {
        $this->client->fdb->fdb_transaction_atomic_op(
            $this->tpointer,
            $key,
            strlen($key),
            $param,
            strlen($param),
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
        $this->client->checkError(
            $this->client->fdb->fdb_transaction_add_conflict_range(
                $this->tpointer,
                $begin,
                strlen($begin),
                $end,
                strlen($end),
                ConflictRangeType::Read->value,
            ),
        );
    }

    public function addWriteConflictRange(string $begin, string $end): void
    {
        $this->client->checkError(
            $this->client->fdb->fdb_transaction_add_conflict_range(
                $this->tpointer,
                $begin,
                strlen($begin),
                $end,
                strlen($end),
                ConflictRangeType::Write->value,
            ),
        );
    }

    public function addReadConflictKey(string $key): void
    {
        $this->addReadConflictRange($key, $key . "\x00");
    }

    public function addWriteConflictKey(string $key): void
    {
        $this->addWriteConflictRange($key, $key . "\x00");
    }

    public function options(): TransactionOptions
    {
        return new TransactionOptions($this);
    }

    public function setOption(int $option, ?string $value = null): void
    {
        $this->client->checkError(
            $this->client->fdb->fdb_transaction_set_option(
                $this->tpointer,
                $option,
                $value,
                $value !== null ? strlen($value) : 0,
            ),
        );
    }

    public function snapshot(): Snapshot
    {
        return $this->snapshotInstance ??= new Snapshot($this->tpointer, $this->db, $this->client, $this);
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
