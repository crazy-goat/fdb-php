<?php

declare(strict_types=1);

namespace CrazyGoat\FoundationDB\Tests\Unit;

use CrazyGoat\FoundationDB\FoundationDB;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for the bounded database cache in `FoundationDB`.
 *
 * These exercise the pure cache logic (LRU ordering, capacity bound,
 * configuration validation) via reflection so no live FoundationDB
 * cluster or FFI is required. The wiring of `open()` /
 * `openWithConnectionString()` against the native client is covered by
 * `tests/Integration/ConnectionStringTest.php`.
 *
 * @internal
 */
final class FoundationDBDatabaseCacheTest extends TestCase
{
    /** @var array<int, object> */
    private static array $dummy = [];

    private function dummy(int $i): object
    {
        return self::$dummy[$i] ??= new \stdClass();
    }

    /**
     * @return \ReflectionClass<FoundationDB>
     */
    private function reflection(): \ReflectionClass
    {
        return new \ReflectionClass(FoundationDB::class);
    }

    /**
     * @param array<string, object> $databases
     * @param array<string, int>    $access
     */
    private function populate(array $databases, array $access): void
    {
        $r = $this->reflection();
        $r->getProperty('databases')->setValue(null, $databases);
        $r->getProperty('dbAccess')->setValue(null, $access);
    }

    /**
     * @return array<string, object>
     */
    private function databases(): array
    {
        $value = $this->reflection()->getProperty('databases')->getValue();

        /** @var array<string, object> $value */
        return $value;
    }

    protected function setUp(): void
    {
        FoundationDB::reset();
        // reset() restores the default bound of 8.
        self::assertSame(8, FoundationDB::getMaxDatabases());
    }

    protected function tearDown(): void
    {
        FoundationDB::reset();
        self::$dummy = [];
    }

    #[Test]
    public function defaultMaxDatabasesIsEight(): void
    {
        self::assertSame(8, FoundationDB::getMaxDatabases());
    }

    #[Test]
    public function setMaxDatabasesConfiguresBound(): void
    {
        FoundationDB::setMaxDatabases(3);
        self::assertSame(3, FoundationDB::getMaxDatabases());
    }

    #[Test]
    public function setMaxDatabasesRejectsZeroAndNegative(): void
    {
        foreach ([0, -1, -5] as $invalid) {
            try {
                FoundationDB::setMaxDatabases($invalid);
                self::fail('Expected InvalidArgumentException for limit ' . $invalid);
            } catch (\InvalidArgumentException) {
                // expected
            }
        }

        // The bound must be untouched after a rejected call.
        self::assertSame(8, FoundationDB::getMaxDatabases());
    }

    #[Test]
    public function trimEvictsLeastRecentlyUsedEntry(): void
    {
        // Access order: k1 oldest (access 1), k3 newest (access 3).
        $this->populate([
            'k1' => $this->dummy(1),
            'k2' => $this->dummy(2),
            'k3' => $this->dummy(3),
        ], [
            'k1' => 1,
            'k2' => 2,
            'k3' => 3,
        ]);

        FoundationDB::setMaxDatabases(2);

        $remaining = $this->databases();
        self::assertCount(2, $remaining);
        self::assertArrayNotHasKey('k1', $remaining);
        self::assertArrayHasKey('k2', $remaining);
        self::assertArrayHasKey('k3', $remaining);
    }

    #[Test]
    public function trimRemovesEnoughWhenShrunkToOne(): void
    {
        $this->populate([
            'a' => $this->dummy(1),
            'b' => $this->dummy(2),
            'c' => $this->dummy(3),
            'd' => $this->dummy(4),
        ], [
            'a' => 1,
            'b' => 2,
            'c' => 3,
            'd' => 4,
        ]);

        FoundationDB::setMaxDatabases(1);

        $remaining = $this->databases();
        self::assertCount(1, $remaining);
        // 'd' was most recently used, so it survives.
        self::assertArrayHasKey('d', $remaining);
    }

    #[Test]
    public function trimLeavesCacheUntouchedWhenWithinBound(): void
    {
        $this->populate([
            'a' => $this->dummy(1),
            'b' => $this->dummy(2),
        ], [
            'a' => 1,
            'b' => 2,
        ]);

        FoundationDB::setMaxDatabases(8);

        self::assertCount(2, $this->databases());
    }

    #[Test]
    public function trimIsIdempotentUnderRepeatedCalls(): void
    {
        $this->populate([
            'a' => $this->dummy(1),
            'b' => $this->dummy(2),
            'c' => $this->dummy(3),
        ], [
            'a' => 1,
            'b' => 2,
            'c' => 3,
        ]);

        FoundationDB::setMaxDatabases(2);
        $this->reflection()->getMethod("trimDatabaseCache")->invoke(null);
        $this->reflection()->getMethod("trimDatabaseCache")->invoke(null);

        self::assertCount(2, $this->databases());
    }

    #[Test]
    public function resetRestoresDefaultBoundAndClearsCache(): void
    {
        FoundationDB::setMaxDatabases(2);
        $this->populate(['k' => $this->dummy(1)], ['k' => 1]);

        FoundationDB::reset();

        self::assertSame(8, FoundationDB::getMaxDatabases());
        self::assertSame([], $this->databases());
    }
}
