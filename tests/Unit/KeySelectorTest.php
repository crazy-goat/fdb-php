<?php

declare(strict_types=1);

namespace CrazyGoat\FoundationDB\Tests\Unit;

use CrazyGoat\FoundationDB\KeySelector;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class KeySelectorTest extends TestCase
{
    #[Test]
    public function lastLessThanCreatesCorrectSelector(): void
    {
        $selector = KeySelector::lastLessThan('abc');

        self::assertSame('abc', $selector->key);
        self::assertFalse($selector->orEqual);
        self::assertSame(0, $selector->offset);
    }

    #[Test]
    public function lastLessOrEqualCreatesCorrectSelector(): void
    {
        $selector = KeySelector::lastLessOrEqual('abc');

        self::assertSame('abc', $selector->key);
        self::assertTrue($selector->orEqual);
        self::assertSame(0, $selector->offset);
    }

    #[Test]
    public function firstGreaterThanCreatesCorrectSelector(): void
    {
        $selector = KeySelector::firstGreaterThan('abc');

        self::assertSame('abc', $selector->key);
        self::assertTrue($selector->orEqual);
        self::assertSame(1, $selector->offset);
    }

    #[Test]
    public function firstGreaterOrEqualCreatesCorrectSelector(): void
    {
        $selector = KeySelector::firstGreaterOrEqual('abc');

        self::assertSame('abc', $selector->key);
        self::assertFalse($selector->orEqual);
        self::assertSame(1, $selector->offset);
    }

    #[Test]
    public function addReturnsNewInstanceWithAdjustedOffset(): void
    {
        $original = KeySelector::firstGreaterOrEqual('key');
        $adjusted = $original->add(5);

        self::assertSame('key', $adjusted->key);
        self::assertFalse($adjusted->orEqual);
        self::assertSame(6, $adjusted->offset);
        self::assertNotSame($original, $adjusted);
    }

    #[Test]
    public function subtractReturnsNewInstanceWithAdjustedOffset(): void
    {
        $original = KeySelector::firstGreaterThan('key');
        $adjusted = $original->subtract(3);

        self::assertSame('key', $adjusted->key);
        self::assertTrue($adjusted->orEqual);
        self::assertSame(-2, $adjusted->offset);
        self::assertNotSame($original, $adjusted);
    }

    #[Test]
    public function originalInstanceIsNotModifiedByAdd(): void
    {
        $original = KeySelector::lastLessThan('key');
        $original->add(10);

        self::assertSame('key', $original->key);
        self::assertFalse($original->orEqual);
        self::assertSame(0, $original->offset);
    }

    #[Test]
    public function originalInstanceIsNotModifiedBySubtract(): void
    {
        $original = KeySelector::lastLessOrEqual('key');
        $original->subtract(5);

        self::assertSame('key', $original->key);
        self::assertTrue($original->orEqual);
        self::assertSame(0, $original->offset);
    }
}
