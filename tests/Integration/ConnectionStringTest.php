<?php

declare(strict_types=1);

namespace CrazyGoat\FoundationDB\Tests\Integration;

use CrazyGoat\FoundationDB\Database;
use CrazyGoat\FoundationDB\FoundationDB;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class ConnectionStringTest extends TestCase
{
    private static bool $initialized = false;

    private static Database $db;

    private static string $connectionString;

    protected function setUp(): void
    {
        if (!self::$initialized) {
            $clusterFile = getenv('FDB_CLUSTER_FILE');
            if ($clusterFile === false) {
                self::markTestSkipped('FDB_CLUSTER_FILE environment variable not set');
            }

            $contents = file_get_contents($clusterFile);
            if ($contents === false) {
                self::markTestSkipped('Cannot read cluster file: ' . $clusterFile);
            }

            self::$connectionString = trim($contents);

            FoundationDB::reset();
            FoundationDB::apiVersion(730);
            self::$db = FoundationDB::openWithConnectionString(self::$connectionString);
            self::$initialized = true;
        }

        self::$db->clearRangeStartsWith('test/connstr/');
    }

    #[Test]
    public function openWithConnectionStringReturnsDatabase(): void
    {
        self::assertInstanceOf(Database::class, self::$db);
    }

    #[Test]
    public function canPerformBasicCrudViaConnectionString(): void
    {
        self::$db->set('test/connstr/key1', 'value1');

        $value = self::$db->get('test/connstr/key1');
        self::assertSame('value1', $value);
    }

    #[Test]
    public function openWithConnectionStringReturnsCachedInstance(): void
    {
        $db2 = FoundationDB::openWithConnectionString(self::$connectionString);
        self::assertSame(self::$db, $db2);
    }

    #[Test]
    public function openWithConnectionStringThrowsWithoutApiVersion(): void
    {
        $currentVersion = FoundationDB::getApiVersion();
        FoundationDB::reset();

        try {
            $this->expectException(\LogicException::class);
            $this->expectExceptionMessage('API version must be set');
            FoundationDB::openWithConnectionString(self::$connectionString);
        } finally {
            if ($currentVersion !== null) {
                FoundationDB::apiVersion($currentVersion);
                FoundationDB::openWithConnectionString(self::$connectionString);
            }
        }
    }
}
