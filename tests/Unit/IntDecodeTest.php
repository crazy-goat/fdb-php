<?php

declare(strict_types=1);

namespace CrazyGoat\FoundationDB\Tests\Unit;

use CrazyGoat\FoundationDB\Directory\HighContentionAllocator;
use CrazyGoat\FoundationDB\ReadTransaction;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class IntDecodeTest extends TestCase
{
    /**
     * Invoke ReadTransaction::decodeLittleEndianInt via reflection.
     */
    private function callDecoder(string $raw): int
    {
        $method = new \ReflectionMethod(ReadTransaction::class, 'decodeLittleEndianInt');
        /** @var int $result */
        $result = $method->invoke(null, $raw);

        return $result;
    }

    /**
     * Invoke HighContentionAllocator::decodeCount via reflection on a no-op instance.
     */
    private function callDecodeCount(string $raw): int
    {
        // Use newInstanceWithoutConstructor so we don't need FFI state for a pure-decode call.
        $reflection = new \ReflectionClass(HighContentionAllocator::class);
        /** @var HighContentionAllocator $instance */
        $instance = $reflection->newInstanceWithoutConstructor();
        $method = $reflection->getMethod('decodeCount');

        /** @var int $result */
        $result = $method->invoke($instance, $raw);

        return $result;
    }

    // -- ReadTransaction::decodeLittleEndianInt --------------------------------

    #[Test]
    public function decoderAcceptsExactlyEightBytes(): void
    {
        // 0x0102030405060708 little-endian
        $value = $this->callDecoder("\x08\x07\x06\x05\x04\x03\x02\x01");

        // PHP integer is 64-bit on supported platforms; ensure exact value.
        self::assertSame(0x0102030405060708, $value);
    }

    #[Test]
    public function decoderPadsShortValuesWithTrailingZeros(): void
    {
        // 1 byte: 0x42 -> 0x0000000000000042
        self::assertSame(0x42, $this->callDecoder("\x42"));
    }

    #[Test]
    public function decoderReadsLeadingByteRegardlessOfPadding(): void
    {
        // 4 bytes with low byte 0x01, padded to 8 bytes -> 1
        $littleEndianOne = "\x01\x00\x00\x00";
        self::assertSame(1, $this->callDecoder($littleEndianOne));

        // A 1-byte value 0x09 -> 9
        self::assertSame(9, $this->callDecoder("\x09"));
    }

    #[Test]
    public function decoderAcceptsEmptyStringAsZero(): void
    {
        self::assertSame(0, $this->callDecoder(''));
    }

    #[Test]
    public function decoderAcceptsMaxUint64(): void
    {
        // 0xFFFFFFFFFFFFFFFF -> 2^64 - 1
        self::assertSame(-1, $this->callDecoder(str_repeat("\xff", 8))); // PHP int is signed
    }

    #[Test]
    public function decoderRejectsValueLongerThanEightBytes(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('stored value is 9 bytes');

        $this->callDecoder(str_repeat("\x00", 9));
    }

    #[Test]
    public function decoderRejectsMuchLongerValue(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('stored value is 100 bytes');

        $this->callDecoder(str_repeat("\x01", 100));
    }

    // -- HighContentionAllocator::decodeCount ---------------------------------

    #[Test]
    public function decodeCountHandlesExactlyEightBytes(): void
    {
        $value = $this->callDecodeCount("\x05\x00\x00\x00\x00\x00\x00\x00");

        self::assertSame(5, $value);
    }

    #[Test]
    public function decodeCountPadsShortValues(): void
    {
        // "raw 4 bytes" -> padded
        self::assertSame(1, $this->callDecodeCount("\x01"));
    }

    #[Test]
    public function decodeCountAcceptsEmptyStringAsZero(): void
    {
        self::assertSame(0, $this->callDecodeCount(''));
    }

    #[Test]
    public function decodeCountRejectsValueLongerThanEightBytes(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('stored value is 12 bytes');

        $this->callDecodeCount(str_repeat("\x00", 12));
    }
}
