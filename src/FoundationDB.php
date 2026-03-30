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
    public static function reset(): void
    {
        self::$apiVersion = null;
        self::$databases = [];
    }
}
