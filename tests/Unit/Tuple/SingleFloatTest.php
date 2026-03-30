<?php

declare(strict_types=1);

namespace CrazyGoat\FoundationDB\Tests\Unit\Tuple;

use CrazyGoat\FoundationDB\Tuple\SingleFloat;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class SingleFloatTest extends TestCase
{
    #[Test]
    public function constructorAcceptsZero(): void
    {
        $float = new SingleFloat(0.0);
        self::assertSame(0.0, $float->value);
    }

    #[Test]
    public function constructorAcceptsPositiveValue(): void
    {
        $float = new SingleFloat(1.5);
        self::assertSame(1.5, $float->value);
    }

    #[Test]
    public function constructorAcceptsNegativeValue(): void
    {
        $float = new SingleFloat(-1.5);
        self::assertSame(-1.5, $float->value);
    }

    #[Test]
    public function constructorAcceptsInfinity(): void
    {
        $float = new SingleFloat(INF);
        self::assertSame(INF, $float->value);
    }

    #[Test]
    public function constructorAcceptsNegativeInfinity(): void
    {
        $float = new SingleFloat(-INF);
        self::assertSame(-INF, $float->value);
    }

    #[Test]
    public function constructorAcceptsNan(): void
    {
        $float = new SingleFloat(NAN);
        self::assertNan($float->value);
    }

    #[Test]
    public function isReadonly(): void
    {
        $float = new SingleFloat(1.0);
        $reflection = new \ReflectionClass($float);
        self::assertTrue($reflection->isReadOnly());
    }
}
