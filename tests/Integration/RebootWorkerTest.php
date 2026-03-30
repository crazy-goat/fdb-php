<?php

declare(strict_types=1);

namespace CrazyGoat\FoundationDB\Tests\Integration;

use CrazyGoat\FoundationDB\Database;
use CrazyGoat\FoundationDB\FoundationDB;
use CrazyGoat\FoundationDB\RebootWorkerException;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Tests for Database::rebootWorker()
 *
 * Note: These tests verify the method exists and handles errors correctly.
 * Actual reboot tests are not practical on single-node clusters
 * as they would disrupt the database operation.
 */
final class RebootWorkerTest extends TestCase
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
    public function rebootWorkerMethodExists(): void
    {
        self::assertTrue(method_exists(self::$db, 'rebootWorker'));
    }

    #[Test]
    public function rebootWorkerExceptionHasCorrectStructure(): void
    {
        $exception = new RebootWorkerException('test-address:1234');

        self::assertSame('test-address:1234', $exception->address);
        self::assertSame('Failed to reboot worker', $exception->getMessage());
    }

    #[Test]
    public function rebootWorkerWithInvalidAddressThrowsException(): void
    {
        $this->expectException(RebootWorkerException::class);

        // Invalid address should fail immediately without affecting cluster
        self::$db->rebootWorker('192.0.2.1:99999');
    }

    #[Test]
    public function rebootWorkerWithCheckFileParameter(): void
    {
        $this->expectException(RebootWorkerException::class);

        // Non-existent address with checkFile=true should fail
        self::$db->rebootWorker('192.0.2.1:99999', checkFile: true);
    }

    #[Test]
    public function rebootWorkerWithSuspendDurationParameter(): void
    {
        $this->expectException(RebootWorkerException::class);

        // Non-existent address with suspendDuration should fail
        self::$db->rebootWorker('192.0.2.1:99999', suspendDuration: 5);
    }
}
