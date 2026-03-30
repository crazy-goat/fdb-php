<?php

declare(strict_types=1);

namespace CrazyGoat\FoundationDB\Tests\Integration;

use CrazyGoat\FoundationDB\Database;
use CrazyGoat\FoundationDB\FoundationDB;
use CrazyGoat\FoundationDB\Locality;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class LocalityTest extends TestCase
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
    public function getBoundaryKeysReturnsNonEmptyArray(): void
    {
        self::$db->set('test/locality/a', 'value');

        $boundaries = Locality::getBoundaryKeys(self::$db, '', "\xFF");

        self::assertNotEmpty($boundaries);
    }

    #[Test]
    public function getBoundaryKeysContainsOnlyStrings(): void
    {
        $boundaries = Locality::getBoundaryKeys(self::$db, '', "\xFF");

        self::assertNotEmpty($boundaries);
        self::assertContainsOnly('string', $boundaries);
    }

    #[Test]
    public function getBoundaryKeysWithNarrowRange(): void
    {
        for ($i = 0; $i < 5; $i++) {
            self::$db->set("test/locality/narrow/{$i}", str_repeat('x', 100));
        }

        $boundaries = Locality::getBoundaryKeys(self::$db, 'test/locality/narrow/', 'test/locality/narrow0');

        self::assertGreaterThanOrEqual(0, count($boundaries));
    }

    #[Test]
    public function getBoundaryKeysWithEmptyRangeReturnsEmptyArray(): void
    {
        $boundaries = Locality::getBoundaryKeys(self::$db, "\xFE", "\xFE");

        self::assertSame([], $boundaries);
    }

    #[Test]
    public function getBoundaryKeysAreSorted(): void
    {
        $boundaries = Locality::getBoundaryKeys(self::$db, '', "\xFF");

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
