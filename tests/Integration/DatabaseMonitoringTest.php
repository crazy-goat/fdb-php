<?php

declare(strict_types=1);

namespace CrazyGoat\FoundationDB\Tests\Integration;

use CrazyGoat\FoundationDB\FoundationDB;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class DatabaseMonitoringTest extends TestCase
{
    use DatabaseCleanupTrait;

    #[Test]
    public function getMainThreadBusynessReturnsNonNegativeValue(): void
    {
        $busyness = $this->getDatabase()->getMainThreadBusyness();

        self::assertGreaterThanOrEqual(0.0, $busyness);
    }

    #[Test]
    public function getClientStatusReturnsValidJson(): void
    {
        $status = $this->getDatabase()->getClientStatus();

        self::assertNotEmpty($status);
        $decoded = json_decode($status, true);
        self::assertIsArray($decoded);
    }

    #[Test]
    public function getClientStatusCanReturnParsedArray(): void
    {
        $status = $this->getDatabase()->getClientStatus(asArray: true);

        self::assertNotEmpty($status);
    }

    #[Test]
    public function getClientVersionReturnsNonEmptyString(): void
    {
        $version = FoundationDB::getClientVersion();

        self::assertNotSame('', trim($version));
    }

    #[Test]
    public function getServerProtocolReturnsPositiveProtocolVersion(): void
    {
        // FDB protocol versions are large positive integers (e.g. 0x00F08044
        // family for 7.0+), so a successful resolution is non-zero.
        self::assertGreaterThan(0, $this->getDatabase()->getServerProtocol());
    }
}
