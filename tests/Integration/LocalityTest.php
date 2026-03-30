<?php

declare(strict_types=1);

namespace CrazyGoat\FoundationDB\Tests\Integration;

use CrazyGoat\FoundationDB\Locality;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class LocalityTest extends TestCase
{
    use DatabaseCleanupTrait;

    #[Test]
    public function getBoundaryKeysReturnsNonEmptyArray(): void
    {
        $this->getDatabase()->set('test/locality/a', 'value');

        $boundaries = Locality::getBoundaryKeys($this->getDatabase(), '', "\xFF");

        self::assertNotEmpty($boundaries);
    }

    #[Test]
    public function getBoundaryKeysContainsOnlyStrings(): void
    {
        $boundaries = Locality::getBoundaryKeys($this->getDatabase(), '', "\xFF");

        self::assertNotEmpty($boundaries);
        self::assertContainsOnly('string', $boundaries);
    }

    #[Test]
    public function getBoundaryKeysWithNarrowRange(): void
    {
        for ($i = 0; $i < 5; $i++) {
            $this->getDatabase()->set("test/locality/narrow/{$i}", str_repeat('x', 100));
        }

        $boundaries = Locality::getBoundaryKeys($this->getDatabase(), 'test/locality/narrow/', 'test/locality/narrow0');

        self::assertGreaterThanOrEqual(0, count($boundaries));
    }

    #[Test]
    public function getBoundaryKeysWithEmptyRangeReturnsEmptyArray(): void
    {
        $boundaries = Locality::getBoundaryKeys($this->getDatabase(), "\xFE", "\xFE");

        self::assertSame([], $boundaries);
    }

    #[Test]
    public function getBoundaryKeysAreSorted(): void
    {
        $boundaries = Locality::getBoundaryKeys($this->getDatabase(), '', "\xFF");

        self::assertGreaterThanOrEqual(1, count($boundaries));

        $count = count($boundaries);

        for ($i = 1; $i < $count; $i++) {
            self::assertGreaterThan(
                $boundaries[$i - 1],
                $boundaries[$i],
                'Boundary keys should be in ascending order',
            );
        }
    }
}
