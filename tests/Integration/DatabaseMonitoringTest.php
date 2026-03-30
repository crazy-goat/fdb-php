<?php

declare(strict_types=1);

namespace CrazyGoat\FoundationDB\Tests\Integration;

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
}
