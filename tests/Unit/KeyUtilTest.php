<?php

declare(strict_types=1);

namespace CrazyGoat\FoundationDB\Tests\Unit;

use CrazyGoat\FoundationDB\KeyUtil;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class KeyUtilTest extends TestCase
{
    #[Test]
    public function printableWithEmptyString(): void
    {
        self::assertSame('', KeyUtil::printable(''));
    }

    #[Test]
    public function printableWithPureAscii(): void
    {
        self::assertSame('hello world', KeyUtil::printable('hello world'));
    }

    #[Test]
    public function printableWithAllPrintableAscii(): void
    {
        $printable = '';
        $expected = '';

        for ($i = 32; $i < 127; $i++) {
            if ($i === 0x5C) {
                continue;
            }
            $printable .= chr($i);
            $expected .= chr($i);
        }

        self::assertSame($expected, KeyUtil::printable($printable));
    }

    #[Test]
    public function printableEscapesBackslash(): void
    {
        self::assertSame('a\\\\b', KeyUtil::printable('a\\b'));
    }

    #[Test]
    public function printableEscapesNullByte(): void
    {
        self::assertSame('\\x00', KeyUtil::printable("\x00"));
    }

    #[Test]
    public function printableEscapesNonPrintableBytes(): void
    {
        self::assertSame('\\x01\\x1f', KeyUtil::printable("\x01\x1F"));
    }

    #[Test]
    public function printableEscapesHighBytes(): void
    {
        self::assertSame('\\x7f\\x80\\xff', KeyUtil::printable("\x7F\x80\xFF"));
    }

    #[Test]
    public function printableWithMixedContent(): void
    {
        self::assertSame('hello\\x00world\\xff', KeyUtil::printable("hello\x00world\xFF"));
    }

    #[Test]
    public function printableWithBinaryKey(): void
    {
        $key = "\x02myapp\x00\x02users\x00\x15\x2A";
        self::assertSame('\\x02myapp\\x00\\x02users\\x00\\x15*', KeyUtil::printable($key));
    }

    #[Test]
    public function printableWithOnlyBackslashes(): void
    {
        self::assertSame('\\\\\\\\\\\\', KeyUtil::printable('\\\\\\'));
    }

    #[Test]
    public function printableWithDeleteCharacter(): void
    {
        self::assertSame('\\x7f', KeyUtil::printable("\x7F"));
    }

    #[Test]
    public function printableWithSpaceCharacter(): void
    {
        self::assertSame(' ', KeyUtil::printable(' '));
    }

    #[Test]
    public function printableWithTildeCharacter(): void
    {
        self::assertSame('~', KeyUtil::printable('~'));
    }

    #[Test]
    #[DataProvider('prefixRangeProvider')]
    public function prefixRangeReturnsCorrectPair(string $prefix, string $expectedBegin, string $expectedEnd): void
    {
        $result = KeyUtil::prefixRange($prefix);

        self::assertSame($expectedBegin, $result[0]);
        self::assertSame($expectedEnd, $result[1]);
    }

    /**
     * @return iterable<string, array{string, string, string}>
     */
    public static function prefixRangeProvider(): iterable
    {
        yield 'simple prefix' => ['abc', 'abc', 'abd'];
        yield 'single byte' => ['a', 'a', 'b'];
        yield 'binary prefix' => ["\x01\x02", "\x01\x02", "\x01\x03"];
        yield 'trailing 0xFF trimmed' => ["a\xFF", "a\xFF", "b"];
    }

    #[Test]
    public function prefixRangeThrowsOnEmptyPrefix(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Cannot compute prefix range');

        KeyUtil::prefixRange('');
    }

    #[Test]
    public function prefixRangeThrowsOnAllFfBytes(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Cannot compute prefix range');

        KeyUtil::prefixRange("\xFF\xFF\xFF");
    }

    #[Test]
    public function prefixRangePreservesOriginalPrefix(): void
    {
        $prefix = "myprefix";
        $result = KeyUtil::prefixRange($prefix);

        self::assertSame($prefix, $result[0]);
    }

    #[Test]
    public function strincWithSimpleKey(): void
    {
        self::assertSame('abd', KeyUtil::strinc('abc'));
    }

    #[Test]
    public function strincWithTrailingFf(): void
    {
        self::assertSame('b', KeyUtil::strinc("a\xFF"));
    }

    #[Test]
    public function strincWithAllFfReturnsNull(): void
    {
        self::assertNull(KeyUtil::strinc("\xFF\xFF"));
    }

    #[Test]
    public function strincWithEmptyStringReturnsNull(): void
    {
        self::assertNull(KeyUtil::strinc(''));
    }
}
