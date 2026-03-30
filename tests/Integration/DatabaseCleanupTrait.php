<?php

declare(strict_types=1);

namespace CrazyGoat\FoundationDB\Tests\Integration;

use CrazyGoat\FoundationDB\Database;
use CrazyGoat\FoundationDB\FoundationDB;

trait DatabaseCleanupTrait
{
    private static bool $dbInitialized = false;

    private static Database $database;

    protected function getDatabase(): Database
    {
        if (!self::$dbInitialized) {
            FoundationDB::reset();
            FoundationDB::apiVersion(730);
            self::$database = FoundationDB::open();
            self::$dbInitialized = true;
        }

        return self::$database;
    }

    protected function setUp(): void
    {
        $db = $this->getDatabase();

        // Clear all data from database before each test
        $db->clearAll();
    }

    protected function tearDown(): void
    {
        // Optional: clear after test as well to ensure clean state
        // This helps if test fails in the middle
        try {
            $this->getDatabase()->clearAll();
        } catch (\Exception) {
            // Ignore cleanup errors in tearDown
        }
    }
}
