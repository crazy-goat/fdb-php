<?php

declare(strict_types=1);

namespace CrazyGoat\FoundationDB;

use CrazyGoat\FoundationDB\Option\NetworkOptions;
use FFI;

final class FoundationDB
{
    private const HEADER_VERSION = 730;

    private static ?int $apiVersion = null;

    /** @var array<string, Database> */
    private static array $databases = [];

    /**
     * Default maximum number of `on_error()` retry attempts for the
     * convenience retry loops on `Database::transact()`,
     * `Database::readTransact()`, and the four `watch` helpers.
     *
     * `0` means "unlimited" — i.e. preserve the historical
     * `while (true)` semantics that rely entirely on FDB's
     * `fdb_transaction_on_error()` to eventually bubble a
     * non-retryable error back to PHP. The default is `0`. Set a
     * positive integer via `defaultTransactionRetryLimit()` to opt
     * into a deterministic upper bound; when the ceiling is
     * reached, the loop throws
     * `TransactionRetryLimitExceededException` synchronously,
     * regardless of whether FDB still classifies the current error
     * as retryable.
     *
     * Mirrors the default FDB Java binding's `Transaction.RETRY_LIMIT`
     * default (no built-in ceiling in the C client); we expose the
     * knob explicitly because the C client cannot.
     */
    private static int $defaultTransactionRetryLimit = 0;

    /**
     * Default per-transaction wall-clock ceiling for the same set
     * of convenience retry loops, in fractional seconds. `0.0`
     * means "unlimited" (the default). Setting via
     * `defaultTransactionTimeoutSeconds()` is opt-in: at every
     * `on_error().await()` boundary the loops check elapsed time
     * and, when the configured wall-clock budget is exhausted,
     * throw `TransactionRetryLimitExceededException` even if the
     * retry-count ceiling would still allow another attempt.
     */
    private static float $defaultTransactionTimeoutSeconds = 0.0;

    private function __construct()
    {
    }

    private const ERROR_API_VERSION_ALREADY_SET = 2201;

    public static function apiVersion(int $version): void
    {
        if (self::$apiVersion !== null) {
            throw new \LogicException(
                'API version already set to ' . self::$apiVersion . '. It can only be set once per process.',
            );
        }

        $client = NativeClient::getInstance();
        /** @var int $errorCode */
        $errorCode = $client->fdb->fdb_select_api_version_impl($version, self::HEADER_VERSION);

        if ($errorCode !== 0 && $errorCode !== self::ERROR_API_VERSION_ALREADY_SET) {
            $client->checkError($errorCode);
        }

        self::$apiVersion = $version;
    }

    public static function networkOptions(): NetworkOptions
    {
        return new NetworkOptions(NativeClient::getInstance());
    }

    public static function getApiVersion(): ?int
    {
        return self::$apiVersion;
    }

    public static function getMaxApiVersion(): int
    {
        return NativeClient::getInstance()->fdb->fdb_get_max_api_version();
    }

    /**
     * Configure the default per-transaction retry-attempt ceiling used
     * by `Database::transact()`, `Database::readTransact()`,
     * `Database::watch()`, `Database::getAndWatch()`,
     * `Database::setAndWatch()`, and `Database::clearAndWatch()`.
     *
     * - `$limit = 0` (default) preserves the historical unbounded
     *   `while (true)` behaviour: the loops rely entirely on FDB's
     *   `fdb_transaction_on_error()` to eventually bubble a
     *   non-retryable error back to PHP. A persistently conflicting
     *   workload can therefore spin indefinitely under the default.
     * - `$limit > 0` sets an explicit upper bound. After `$limit`
     *   on-error retry attempts, the loop throws
     *   `TransactionRetryLimitExceededException` synchronously, with
     *   the actual attempt count and elapsed wall-clock seconds.
     * - `$limit < 0` is rejected at configuration time with
     *   `\InvalidArgumentException`, so a typo can't silently
     *   disable the ceiling.
     *
     * The setting is process-wide (static); it is intentionally
     * not per-`Database` because, like `apiVersion()`, it represents
     * library-level policy that the process opts into once. Reset to
     * 0 via `FoundationDB::reset()` (test-only and process-shutdown
     * convenience).
     */
    public static function defaultTransactionRetryLimit(int $limit): void
    {
        if ($limit < 0) {
            throw new \InvalidArgumentException(
                'defaultTransactionRetryLimit must be >= 0; use 0 for unbounded. Got: ' . $limit,
            );
        }

        self::$defaultTransactionRetryLimit = $limit;
    }

    public static function getDefaultTransactionRetryLimit(): int
    {
        return self::$defaultTransactionRetryLimit;
    }

    /**
     * Configure the default per-transaction wall-clock ceiling used
     * by the same set of convenience retry loops. `$seconds = 0.0`
     * (default) means "unlimited"; a positive value sets an
     * explicit upper bound. Negative values are rejected with
     * `\InvalidArgumentException`.
     *
     * When both a retry-count limit and a wall-clock limit are
     * configured, whichever ceiling is hit first throws
     * `TransactionRetryLimitExceededException`.
     */
    public static function defaultTransactionTimeoutSeconds(float $seconds): void
    {
        if ($seconds < 0.0) {
            throw new \InvalidArgumentException(
                'defaultTransactionTimeoutSeconds must be >= 0.0; use 0.0 for unbounded. Got: '
                . var_export($seconds, true),
            );
        }

        self::$defaultTransactionTimeoutSeconds = $seconds;
    }

    public static function getDefaultTransactionTimeoutSeconds(): float
    {
        return self::$defaultTransactionTimeoutSeconds;
    }

    public static function open(?string $clusterFile = null): Database
    {
        if (self::$apiVersion === null) {
            throw new \LogicException(
                'API version must be set before opening a database. Call FoundationDB::apiVersion() first.',
            );
        }

        $envFile = getenv('FDB_CLUSTER_FILE');
        $resolvedFile = $clusterFile ?? ($envFile !== false ? $envFile : null);

        $cacheKey = $resolvedFile ?? '__default__';

        if (isset(self::$databases[$cacheKey])) {
            return self::$databases[$cacheKey];
        }

        $client = NativeClient::getInstance();
        $client->ensureNetwork();

        $dbPointer = $client->fdb->new('FDBDatabase*');
        $client->checkError(
            $client->fdb->fdb_create_database($resolvedFile, FFI::addr($dbPointer)),
        );

        $database = new Database($dbPointer, $client);
        self::$databases[$cacheKey] = $database;

        return $database;
    }

    public static function openWithConnectionString(string $connectionString): Database
    {
        if (self::$apiVersion === null) {
            throw new \LogicException(
                'API version must be set before opening a database. Call FoundationDB::apiVersion() first.',
            );
        }

        $cacheKey = 'conn:' . $connectionString;

        if (isset(self::$databases[$cacheKey])) {
            return self::$databases[$cacheKey];
        }

        $client = NativeClient::getInstance();
        $client->ensureNetwork();

        $dbPointer = $client->fdb->new('FDBDatabase*');
        $client->checkError(
            $client->fdb->fdb_create_database_from_connection_string($connectionString, FFI::addr($dbPointer)),
        );

        $database = new Database($dbPointer, $client);
        self::$databases[$cacheKey] = $database;

        return $database;
    }

    /** @internal */
    public static function removeDatabase(Database $database): void
    {
        foreach (self::$databases as $key => $cached) {
            if ($cached === $database) {
                unset(self::$databases[$key]);
            }
        }
    }

    /** @internal */
    public static function closeAllDatabases(): void
    {
        foreach (self::$databases as $database) {
            $database->close();
        }
        self::$databases = [];
    }

    /** @internal */
    public static function reset(): void
    {
        self::$apiVersion = null;
        self::$databases = [];
        self::$defaultTransactionRetryLimit = 0;
        self::$defaultTransactionTimeoutSeconds = 0.0;
    }
}
