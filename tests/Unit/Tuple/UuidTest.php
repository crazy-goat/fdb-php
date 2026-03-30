<?php

declare(strict_types=1);

namespace CrazyGoat\FoundationDB\Tests\Unit\Tuple;

use CrazyGoat\FoundationDB\Tuple\Uuid;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class UuidTest extends TestCase
{
    #[Test]
    public function constructorAccepts16Bytes(): void
    {
        $bytes = str_repeat("\x01", 16);
        $uuid = new Uuid($bytes);
        self::assertSame($bytes, $uuid->bytes);
    }

    #[Test]
    public function constructorAcceptsAllZeroBytes(): void
    {
        $bytes = str_repeat("\x00", 16);
        $uuid = new Uuid($bytes);
        self::assertSame($bytes, $uuid->bytes);
    }

    #[Test]
    public function constructorAcceptsAllMaxBytes(): void
    {
        $bytes = str_repeat("\xFF", 16);
        $uuid = new Uuid($bytes);
        self::assertSame($bytes, $uuid->bytes);
    }

    #[Test]
    public function constructorThrowsOnEmptyString(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('UUID must be exactly 16 bytes, got 0');
        new Uuid('');
    }

    #[Test]
    public function constructorThrowsOnTooFewBytes(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('UUID must be exactly 16 bytes, got 15');
        new Uuid(str_repeat("\x01", 15));
    }

    #[Test]
    public function constructorThrowsOnTooManyBytes(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('UUID must be exactly 16 bytes, got 17');
        new Uuid(str_repeat("\x01", 17));
    }

    #[Test]
    public function isReadonly(): void
    {
        $uuid = new Uuid(str_repeat("\x00", 16));
        $reflection = new \ReflectionClass($uuid);
        self::assertTrue($reflection->isReadOnly());
    }
}
