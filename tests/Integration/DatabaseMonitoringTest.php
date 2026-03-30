<?php

declare(strict_types=1);

namespace CrazyGoat\FoundationDB\Tests\Integration;

use CrazyGoat\FoundationDB\Database;
use CrazyGoat\FoundationDB\FoundationDB;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class DatabaseMonitoringTest extends TestCase
{
    private static bool $initialized = false;

    private static Database $db;

    protected function setUp(): void
    {
        if (!self::$initialized) {
            FoundationDB::reset();
            FoundationDB::apiVersion(730);
            self::$db = FoundationDB::open();
            self::$initialized = true;
        }
    }

    #[Test]
    public function getMainThreadBusynessReturnsNonNegativeValue(): void
    {
        $busyness = self::$db->getMainThreadBusyness();

        self::assertGreaterThanOrEqual(0.0, $busyness);
    }

    #[Test]
    public function getClientStatusReturnsValidJson(): void
    {
        $status = self::$db->getClientStatus();

        self::assertNotEmpty($status);
        $decoded = json_decode($status, true);
        self::assertIsArray($decoded);
    }
}
