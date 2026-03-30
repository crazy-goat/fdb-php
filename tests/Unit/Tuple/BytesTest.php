<?php

declare(strict_types=1);

namespace CrazyGoat\FoundationDB\Tests\Unit\Tuple;

use CrazyGoat\FoundationDB\Tuple\Bytes;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class BytesTest extends TestCase
{
    #[Test]
    public function constructorAcceptsEmptyString(): void
    {
        $bytes = new Bytes('');
        self::assertSame('', $bytes->data);
    }

    #[Test]
    public function constructorAcceptsAsciiString(): void
    {
        $bytes = new Bytes('hello');
        self::assertSame('hello', $bytes->data);
    }

    #[Test]
    public function constructorAcceptsBinaryData(): void
    {
        $data = "\x00\x01\x02\xFF";
        $bytes = new Bytes($data);
        self::assertSame($data, $bytes->data);
    }

    #[Test]
    public function constructorAcceptsStringWithNullBytes(): void
    {
        $data = "hello\x00world";
        $bytes = new Bytes($data);
        self::assertSame($data, $bytes->data);
    }

    #[Test]
    public function isReadonly(): void
    {
        $bytes = new Bytes('test');
        $reflection = new \ReflectionClass($bytes);
        self::assertTrue($reflection->isReadOnly());
    }
}
