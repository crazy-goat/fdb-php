<?php

declare(strict_types=1);

namespace CrazyGoat\FoundationDB;

use CrazyGoat\FoundationDB\Future\FutureVoid;
use CrazyGoat\FoundationDB\Option\DatabaseOptions;
use FFI;
use FFI\CData;

final class Database implements Transactor, ReadTransactor
{
    /**
     * Sentinel version for blob granule APIs meaning "the latest version" —
     * accepted by `verifyBlobRange()` and `flushBlobRange()`.
     */
    public const LATEST_VERSION = -2;

    private bool $closed = false;

    public function __construct(
        private readonly CData $dpointer,
        private readonly NativeClient $client,
    ) {
    }

    public function close(): void
    {
        if ($this->closed) {
            return;
        }

        $this->client->fdb->fdb_database_destroy($this->dpointer);
        $this->closed = true;
        FoundationDB::removeDatabase($this);
    }

    public function createTransaction(): Transaction
    {
        $this->ensureOpen();

        $trPointer = $this->client->fdb->new('FDBTransaction*');
        $this->client->checkError(
            $this->client->fdb->fdb_database_create_transaction($this->dpointer, FFI::addr($trPointer)),
        );

        return new Transaction($trPointer, $this, $this->client);
    }

    public function openTenant(string $name): Tenant
    {
        $this->ensureOpen();

        $nameLength = KeyValueLimits::assertValidFfiLength($name, 'Tenant name');

        $tpointer = $this->client->fdb->new('FDBTenant*');
        $this->client->checkError(
            $this->client->fdb->fdb_database_open_tenant(
                $this->dpointer,
                $name,
                $nameLength,
                FFI::addr($tpointer),
            ),
        );

        return new Tenant($tpointer, $this, $this->client);
    }

    /**
     * @template T
     *
     * Execute `$fn` inside a writable transaction and commit,
     * retrying on retryable `FDBException`s. The retry loop is
     * bounded by the process-wide `FoundationDB::defaultTransactionRetryLimit()`
     * and `FoundationDB::defaultTransactionTimeoutSeconds()`.
     *
     * When neither is set (the default), the loop is unbounded and
     * relies entirely on `fdb_transaction_on_error()` to eventually
     * surface a non-retryable error. When either ceiling is reached,
     * `TransactionRetryLimitExceededException` is thrown
     * synchronously — see issue #52.
     *
     * @param callable(Transaction): T $fn
     *
     * @return T
     */
    public function transact(callable $fn): mixed
    {
        return $this->runWithRetry(
            function (Transaction $tr) use ($fn): mixed {
                $result = $fn($tr);
                $tr->commit()->await();

                return $result;
            },
        );
    }

    /**
     * Read-only variant of `transact()`; uses the snapshot view so
     * the loop never commits and therefore cannot write to the
     * cluster. Bounded by the same retry-limit / timeout settings as
     * `transact()`.
     *
     * @template T
     *
     * @param callable(Snapshot): T $fn
     *
     * @return T
     */
    public function readTransact(callable $fn): mixed
    {
        $writeCall = (static fn (Transaction $tr) => $fn($tr->snapshot()));

        return $this->runWithRetry($writeCall);
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

    /**
     * Write multiple key/value pairs in batched transactions.
     *
     * Each entry is a `[key, value]` pair (list form, so `KeyConvertible`
     * keys are supported) — the same shape as `Transaction::setBatch()`.
     *
     * - `split: false` (default): the whole batch is committed inside a
     *   single retried transaction (`transact()`). The batch size is checked
     *   against FoundationDB's per-transaction mutation budget *before* any
     *   mutation is queued; an oversized batch throws
     *   `BatchTooLargeException` and nothing is written.
     * - `split: true`: the batch is grouped into chunks of at most
     *   `MutationBudget::SPLIT_TARGET_BYTES` and each group is committed in
     *   its own retried transaction. This removes the size ceiling, at a
     *   documented cost: each *individual key write* stays atomic, but there
     *   is no cross-key snapshot consistency and no all-or-nothing commit —
     *   a failure mid-batch leaves part of the keys updated, and readers
     *   may see a mixture of old and new keys while the batch is in
     *   flight. Suitable for bulk loads and independent key refreshes;
     *   not suitable for keys that must stay mutually consistent (e.g.
     *   data + index pairs).
     *
     * Retry behaviour: every group goes through `transact()`, so retryable
     * FDB errors are retried with the standard `onError()` backoff bounded
     * by the process-wide retry-limit / timeout settings.
     *
     * @param iterable<array{0: string|KeyConvertible, 1: string}> $pairs
     *
     * @throws \InvalidArgumentException when a key or value violates the
     *                                    FDB size limits
     * @throws BatchTooLargeException    when `split` is false and the batch
     *                                    exceeds the per-transaction
     *                                    mutation budget
     */
    public function setBatch(iterable $pairs, bool $split = false): void
    {
        if (!$split) {
            $this->transact(static function (Transaction $tr) use ($pairs): void {
                $tr->setBatch($pairs);
            });

            return;
        }

        $group = [];
        $groupBytes = 0;

        foreach ($pairs as $pair) {
            // Runtime guard, deliberately silenced against the docblock shape
            // (see Transaction::setBatch() for the rationale).
            if (!is_array($pair)) { // @phpstan-ignore function.alreadyNarrowedType
                throw new \InvalidArgumentException(
                    'Each setBatch() entry must be a [key, value] pair',
                );
            }

            if (count($pair) !== 2) { // @phpstan-ignore notIdentical.alwaysFalse
                throw new \InvalidArgumentException(
                    'Each setBatch() entry must be a [key, value] pair',
                );
            }

            $size = MutationBudget::entrySize($pair);

            if ($group !== [] && $groupBytes + $size > MutationBudget::SPLIT_TARGET_BYTES) {
                $this->setBatchGroup($group);
                $group = [];
                $groupBytes = 0;
            }

            $group[] = $pair;
            $groupBytes += $size;
        }

        if ($group !== []) {
            $this->setBatchGroup($group);
        }
    }

    /**
     * @param list<array{0: string|KeyConvertible, 1: string}> $group
     */
    private function setBatchGroup(array $group): void
    {
        $this->transact(static function (Transaction $tr) use ($group): void {
            $tr->setBatch($group);
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
     * Clear all data from the database.
     *
     * WARNING: This is a destructive operation that removes ALL keys from the database.
     * Use with caution, primarily intended for testing and administrative operations.
     */
    public function clearAll(): void
    {
        $this->transact(function (Transaction $tr): void {
            // Clear entire keyspace from "" (zero-length key) to \xFF
            $tr->clearRange("", "\xFF");
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

    /**
     * @return list<KeyValue>
     */
    public function getRangeAll(
        string|KeySelector $begin,
        string|KeySelector $end,
        ?RangeOptions $options = null,
    ): array {
        /** @var list<KeyValue> */
        return $this->transact(
            fn (Transaction $tr): array => $tr->getRangeAll($begin, $end, $options),
        );
    }

    /**
     * @return list<KeyValue>
     */
    public function getRangeAllStartsWith(
        string $prefix,
        ?RangeOptions $options = null,
    ): array {
        /** @var list<KeyValue> */
        return $this->transact(
            fn (Transaction $tr): array => $tr->getRangeAllStartsWith($prefix, $options),
        );
    }

    public function getKey(KeySelector $selector): string
    {
        /** @var string */
        return $this->transact(fn (Transaction $tr): string => $tr->getKey($selector)->await());
    }

    public function watch(string $key): FutureVoid
    {
        return $this->runWithRetry(
            function (Transaction $tr) use ($key): FutureVoid {
                $watchFuture = $tr->watch($key);
                $tr->commit()->await();

                return $watchFuture;
            },
        );
    }

    /**
     * @return array{?string, FutureVoid}
     */
    public function getAndWatch(string $key): array
    {
        return $this->runWithRetry(
            function (Transaction $tr) use ($key): array {
                $value = $tr->get($key)->await();
                $watchFuture = $tr->watch($key);
                $tr->commit()->await();

                return [$value, $watchFuture];
            },
        );
    }

    public function setAndWatch(string $key, string $value): FutureVoid
    {
        return $this->runWithRetry(
            function (Transaction $tr) use ($key, $value): FutureVoid {
                $tr->set($key, $value);
                $watchFuture = $tr->watch($key);
                $tr->commit()->await();

                return $watchFuture;
            },
        );
    }

    public function clearAndWatch(string $key): FutureVoid
    {
        return $this->runWithRetry(
            function (Transaction $tr) use ($key): FutureVoid {
                $tr->clear($key);
                $watchFuture = $tr->watch($key);
                $tr->commit()->await();

                return $watchFuture;
            },
        );
    }

    /**
     * Read a key and decode its value as a little-endian unsigned 64-bit integer.
     *
     * This is the counterpart to add(), max(), min() and other integer-based atomic operations.
     *
     * @return ?int null if the key does not exist
     */
    public function getInt(string|KeyConvertible $key): ?int
    {
        /** @var ?int */
        return $this->transact(fn (Transaction $tr): ?int => $tr->getInt($key));
    }

    public function add(string $key, int $param): void
    {
        $this->transact(function (Transaction $tr) use ($key, $param): void {
            $tr->add($key, $param);
        });
    }

    public function bitAnd(string $key, int $param): void
    {
        $this->transact(function (Transaction $tr) use ($key, $param): void {
            $tr->bitAnd($key, $param);
        });
    }

    public function bitOr(string $key, int $param): void
    {
        $this->transact(function (Transaction $tr) use ($key, $param): void {
            $tr->bitOr($key, $param);
        });
    }

    public function bitXor(string $key, int $param): void
    {
        $this->transact(function (Transaction $tr) use ($key, $param): void {
            $tr->bitXor($key, $param);
        });
    }

    public function max(string $key, int $param): void
    {
        $this->transact(function (Transaction $tr) use ($key, $param): void {
            $tr->max($key, $param);
        });
    }

    public function min(string $key, int $param): void
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

    public function byteMax(string $key, string $param): void
    {
        $this->transact(function (Transaction $tr) use ($key, $param): void {
            $tr->byteMax($key, $param);
        });
    }

    public function byteMin(string $key, string $param): void
    {
        $this->transact(function (Transaction $tr) use ($key, $param): void {
            $tr->byteMin($key, $param);
        });
    }

    public function appendIfFits(string $key, string $param): void
    {
        $this->transact(function (Transaction $tr) use ($key, $param): void {
            $tr->appendIfFits($key, $param);
        });
    }

    public function setVersionstampedKey(string $key, string $value): void
    {
        $this->transact(function (Transaction $tr) use ($key, $value): void {
            $tr->setVersionstampedKey($key, $value);
        });
    }

    public function setVersionstampedValue(string $key, string $param): void
    {
        $this->transact(function (Transaction $tr) use ($key, $param): void {
            $tr->setVersionstampedValue($key, $param);
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

    /**
     * Request that the given range be blobbified (materialized to blob
     * storage as blob granules). Backed by `fdb_database_blobbify_range`.
     *
     * Requires the cluster to have blob granules enabled
     * (`blob_granules_enabled=1`).
     */
    public function blobbifyRange(string $begin, string $end): void
    {
        $beginLength = KeyValueLimits::assertValidRangeEndpoint($begin);
        $endLength = KeyValueLimits::assertValidRangeEndpoint($end);

        $future = new FutureVoid(
            $this->client->fdb->fdb_database_blobbify_range(
                $this->dpointer,
                $begin,
                $beginLength,
                $end,
                $endLength,
            ),
            $this->client,
        );

        $future->await();
    }

    /**
     * Blocking variant of `blobbifyRange()` that only returns once the
     * blobbified range is durably committed. Backed by
     * `fdb_database_blobbify_range_blocking`.
     */
    public function blobbifyRangeBlocking(string $begin, string $end): void
    {
        $beginLength = KeyValueLimits::assertValidRangeEndpoint($begin);
        $endLength = KeyValueLimits::assertValidRangeEndpoint($end);

        $future = new FutureVoid(
            $this->client->fdb->fdb_database_blobbify_range_blocking(
                $this->dpointer,
                $begin,
                $beginLength,
                $end,
                $endLength,
            ),
            $this->client,
        );

        $future->await();
    }

    /**
     * Remove the blob manager's knowledge of the given blobbified range,
     * deleting the granule data it holds. Backed by
     * `fdb_database_unblobbify_range`.
     */
    public function unblobbifyRange(string $begin, string $end): void
    {
        $beginLength = KeyValueLimits::assertValidRangeEndpoint($begin);
        $endLength = KeyValueLimits::assertValidRangeEndpoint($end);

        $future = new FutureVoid(
            $this->client->fdb->fdb_database_unblobbify_range(
                $this->dpointer,
                $begin,
                $beginLength,
                $end,
                $endLength,
            ),
            $this->client,
        );

        $future->await();
    }

    /**
     * List the blobbified ranges within the given range. Backed by
     * `fdb_database_list_blobbified_ranges`.
     *
     * @param int $rangeLimit Maximum number of ranges to return (0 = unlimited).
     *
     * @return list<KeyRange>
     */
    public function listBlobbifiedRanges(string $begin, string $end, int $rangeLimit = 0): array
    {
        $beginLength = KeyValueLimits::assertValidRangeEndpoint($begin);
        $endLength = KeyValueLimits::assertValidRangeEndpoint($end);

        $future = new Future\FutureKeyRangeArray(
            $this->client->fdb->fdb_database_list_blobbified_ranges(
                $this->dpointer,
                $begin,
                $beginLength,
                $end,
                $endLength,
                $rangeLimit,
            ),
            $this->client,
        );

        return $future->await();
    }

    /**
     * Verify that the given range is blobbified at (or after) the given
     * version. Backed by `fdb_database_verify_blob_range`.
     *
     * @param int $version Version to verify; `self::LATEST_VERSION` (-2) for
     *                     the latest version.
     */
    public function verifyBlobRange(string $begin, string $end, int $version = self::LATEST_VERSION): bool
    {
        $beginLength = KeyValueLimits::assertValidRangeEndpoint($begin);
        $endLength = KeyValueLimits::assertValidRangeEndpoint($end);

        $future = new Future\FutureBool(
            $this->client->fdb->fdb_database_verify_blob_range(
                $this->dpointer,
                $begin,
                $beginLength,
                $end,
                $endLength,
                $version,
            ),
            $this->client,
        );

        return $future->await();
    }

    /**
     * Flush the given range to blob storage, optionally compacting the
     * granule files. Backed by `fdb_database_flush_blob_range`.
     *
     * @param int $version Version to flush at; `self::LATEST_VERSION` (-2) for
     *                     the latest version.
     */
    public function flushBlobRange(
        string $begin,
        string $end,
        bool $compact = false,
        int $version = self::LATEST_VERSION,
    ): void {
        $beginLength = KeyValueLimits::assertValidRangeEndpoint($begin);
        $endLength = KeyValueLimits::assertValidRangeEndpoint($end);

        $future = new FutureVoid(
            $this->client->fdb->fdb_database_flush_blob_range(
                $this->dpointer,
                $begin,
                $beginLength,
                $end,
                $endLength,
                $compact ? 1 : 0,
                $version,
            ),
            $this->client,
        );

        $future->await();
    }

    /**
     * Purge the blob granules for the given range: clears the data from
     * FDB's storage servers once it is safely in blob storage. Backed by
     * `fdb_database_purge_blob_granules`.
     *
     * Follow up with `waitPurgeGranulesComplete()` using the key returned
     * for tracking, or the range's end key.
     *
     * @param int $purgeVersion Version to purge to; `self::LATEST_VERSION` (-2) for
     *                          the latest version.
     */
    public function purgeBlobGranules(
        string $begin,
        string $end,
        bool $force = false,
        int $purgeVersion = self::LATEST_VERSION,
    ): void {
        $beginLength = KeyValueLimits::assertValidRangeEndpoint($begin);
        $endLength = KeyValueLimits::assertValidRangeEndpoint($end);

        $future = new FutureVoid(
            $this->client->fdb->fdb_database_purge_blob_granules(
                $this->dpointer,
                $begin,
                $beginLength,
                $end,
                $endLength,
                $purgeVersion,
                $force ? 1 : 0,
            ),
            $this->client,
        );

        $future->await();
    }

    /**
     * Block until all granules for the range started by
     * `purgeBlobGranules()` have been purged. Backed by
     * `fdb_database_wait_purge_granules_complete`.
     */
    public function waitPurgeGranulesComplete(string $purgeKey): void
    {
        $purgeKeyLength = KeyValueLimits::assertValidRangeEndpoint($purgeKey);

        $future = new FutureVoid(
            $this->client->fdb->fdb_database_wait_purge_granules_complete(
                $this->dpointer,
                $purgeKey,
                $purgeKeyLength,
            ),
            $this->client,
        );

        $future->await();
    }

    public function getMainThreadBusyness(): float
    {
        /** @var float $busyness */
        $busyness = $this->client->fdb->fdb_database_get_main_thread_busyness($this->dpointer);

        return $busyness;
    }

    /**
     * Get the protocol version spoken by the cluster this database is
     * connected to (backed by `fdb_database_get_server_protocol`).
     *
     * Lets a client detect the cluster's protocol version and therefore
     * whether the loaded client library can talk to it. Called with
     * `expected_version = 0`, meaning "no expectation" — the future resolves
     * to the protocol version currently in use by the cluster.
     */
    public function getServerProtocol(): int
    {
        $future = new Future\FutureUInt64(
            $this->client->fdb->fdb_database_get_server_protocol($this->dpointer, 0),
            $this->client,
        );

        return $future->await();
    }

    /**
     * Get the client status of the database.
     *
     * @param bool $asArray When true, returns the decoded status as an associative array,
     *                      matching the return type of AdminClient::getClusterStatus().
     * @return ($asArray is true ? array<string, mixed> : string)
     * @throws FDBException If status retrieval fails
     * @throws \JsonException If $asArray is true and the status JSON cannot be decoded
     */
    public function getClientStatus(bool $asArray = false): string|array
    {
        $future = new Future\FutureKey(
            $this->client->fdb->fdb_database_get_client_status($this->dpointer),
            $this->client,
        );

        $status = $future->await();

        if ($asArray) {
            /** @var array<string, mixed> $decoded */
            $decoded = json_decode($status, true, 512, JSON_THROW_ON_ERROR);

            return $decoded;
        }

        return $status;
    }

    /**
     * Reboots a FoundationDB worker process.
     *
     * @deprecated Use $database->admin()->rebootWorker() instead. This method will be removed in a future version.
     *
     * @param string $address The network address of the worker to reboot (e.g., "127.0.0.1:4500")
     * @param bool $checkFile If true, checks that a file exists at the specified path before rebooting
     * @param int $suspendDuration Duration in seconds to suspend the process (0 for immediate restart)
     *
     * @throws RebootWorkerException If the reboot operation fails
     *
     * @warning Do not close the Database immediately after calling this method, as the operation
     *          may still be in progress. Allow sufficient time for the operation to complete.
     */
    public function rebootWorker(string $address, bool $checkFile = false, int $suspendDuration = 0): void
    {
        $this->admin()->rebootWorker($address, $checkFile, $suspendDuration);
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

    /** @internal */
    public function getDatabasePointer(): \FFI\CData
    {
        return $this->dpointer;
    }

    /**
     * Get an AdminClient for cluster administration operations.
     *
     * @return AdminClient Client for administrative operations
     */
    public function admin(): AdminClient
    {
        return new AdminClient($this, $this->client);
    }

    public function __destruct()
    {
        if (!$this->closed) {
            $this->client->fdb->fdb_database_destroy($this->dpointer);
        }
    }

    private function ensureOpen(): void
    {
        if ($this->closed) {
            throw new \LogicException('Database has been closed');
        }
    }

    /**
     * Pure ceiling predicate used by `runWithRetry()`. Exposed as
     * `public static` with no FFI side-effects so it can be exercised
     * by unit tests without a live FoundationDB cluster.
     *
     * Returns `null` if the retry attempt is still within both
     * configured ceilings, or a fully populated
     * `TransactionRetryLimitExceededException` describing the
     * boundary that was crossed (which the caller is responsible for
     * `throw`ing). Walls-clock ceiling beats attempt ceiling when
     * both are configured and both are exceeded on the same attempt
     * — `elapsed` is checked first so the diagnostic reports the
     * boundary that will actually cause the application to stop.
     *
     * Conventions:
     *  - `$maxRetries === 0` means "no attempt ceiling configured".
     *  - `$maxSeconds === 0.0` means "no time ceiling configured".
     *  - `$retriesSoFar` is the number of `on_error().await()`
     *    retries that have already happened for the current
     *    transaction. The initial attempt is not counted. This
     *    matches the FDB Java binding's `Transaction.RETRY_LIMIT`
     *    semantics, where the limit bounds the number of retries,
     *    not the total number of attempts.
     *
     * @param int            $retriesSoFar Number of `on_error()` retries already consumed.
     * @param float          $elapsed      Wall-clock seconds since the loop started.
     * @param int            $maxRetries   Configured attempt ceiling (0 == unlimited).
     * @param float          $maxSeconds   Configured wall-clock ceiling in seconds (0.0 == unlimited).
     *
     * @return TransactionRetryLimitExceededException|null null if `runWithRetry` should keep going.
     */
    public static function checkRetryLimit(
        int $retriesSoFar,
        float $elapsed,
        int $maxRetries,
        float $maxSeconds,
    ): ?TransactionRetryLimitExceededException {
        if ($maxSeconds > 0.0 && $elapsed > $maxSeconds) {
            return new TransactionRetryLimitExceededException(
                attempts: $retriesSoFar,
                elapsedSeconds: $elapsed,
                message: sprintf(
                    'Transaction retry wall-clock limit exceeded after %d retry attempt%s '
                    . '(%.3fs elapsed, limit %.3fs). Configure both limits via '
                    . 'FoundationDB::defaultTransactionRetryLimit() / '
                    . 'FoundationDB::defaultTransactionTimeoutSeconds().',
                    $retriesSoFar,
                    $retriesSoFar === 1 ? '' : 's',
                    $elapsed,
                    $maxSeconds,
                ),
            );
        }

        if ($maxRetries > 0 && $retriesSoFar > $maxRetries) {
            return new TransactionRetryLimitExceededException(
                attempts: $retriesSoFar,
                elapsedSeconds: $elapsed,
                message: sprintf(
                    'Transaction retry attempt limit exceeded: %d retries '
                    . '(limit %d). Configure via '
                    . 'FoundationDB::defaultTransactionRetryLimit().',
                    $retriesSoFar,
                    $maxRetries,
                ),
            );
        }

        return null;
    }

    /**
     * Bounded retry loop shared by `transact()`, `readTransact()`,
     * and the four `watch*` helpers. Replaces the original
     * `while (true) { try { ...; commit; return } catch (FDBException) { onError } }`
     * pattern with an explicit two-dimensional budget — attempt count
     * and elapsed wall-clock seconds — that the application
     * optionally configures via `FoundationDB::defaultTransactionRetryLimit()`
     * and `FoundationDB::defaultTransactionTimeoutSeconds()`.
     *
     * Behaviour:
     *
     *  - The first attempt is `attempt = 1`. We only count attempts
     *    that needed an `on_error()` retry toward the ceiling — the
     *    initial attempt is free, matching the FDB Java binding's
     *    convention where `RETRY_LIMIT` bounds the number of retries
     *    after the initial try, not the total number of attempts.
     *  - After every `on_error().await()` we (a) increment the retry
     *    counter and (b) recompute elapsed time. If either configured
     *    ceiling is exceeded we throw
     *    `TransactionRetryLimitExceededException` synchronously via
     *    `checkRetryLimit()`.
     *  - When both ceilings are `0` (the default), the loop is
     *    unbounded — preserving the historical "FDB decides when to
     *    stop retrying" semantics for users who do not opt in.
     *  - Native FDB errors that are still retryable bubble through
     *    `on_error().await()` as before; the loop's only difference
     *    is that we now stop when the configured ceiling is
     *    reached.
     *
     * @template T
     *
     * @param callable(Transaction): T $fn
     *
     * @return T
     */
    private function runWithRetry(callable $fn): mixed
    {
        $maxRetries = FoundationDB::getDefaultTransactionRetryLimit();
        $maxSeconds = FoundationDB::getDefaultTransactionTimeoutSeconds();
        $deadlineTrackingEnabled = $maxRetries > 0 || $maxSeconds > 0.0;

        $tr = $this->createTransaction();
        $start = $deadlineTrackingEnabled ? microtime(true) : 0.0;
        $retries = 0;

        while (true) {
            try {
                return $fn($tr);
            } catch (FDBException $e) {
                if (!$deadlineTrackingEnabled) {
                    // Historical behaviour: rely entirely on FDB's
                    // on_error backoff. No local ceiling in effect.
                    $tr->onError($e->fdbCode)->await();
                    continue;
                }

                ++$retries;

                $elapsed = microtime(true) - $start;
                $limit = self::checkRetryLimit($retries, $elapsed, $maxRetries, $maxSeconds);
                if ($limit instanceof \CrazyGoat\FoundationDB\TransactionRetryLimitExceededException) {
                    throw $limit;
                }

                $tr->onError($e->fdbCode)->await();
            }
        }
    }
}
