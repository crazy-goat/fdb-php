<?php

declare(strict_types=1);

namespace CrazyGoat\FoundationDB\Tests\Unit\Tuple;

use CrazyGoat\FoundationDB\Tuple\Bytes;
use CrazyGoat\FoundationDB\Tuple\SingleFloat;
use CrazyGoat\FoundationDB\Tuple\Tuple;
use CrazyGoat\FoundationDB\Tuple\Uuid;
use CrazyGoat\FoundationDB\Tuple\Versionstamp;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class TupleTest extends TestCase
{
    #[Test]
    public function packEmptyTupleReturnsEmptyString(): void
    {
        self::assertSame('', Tuple::pack([]));
    }

    #[Test]
    public function packEncodesNullAsSingleZeroByte(): void
    {
        self::assertSame("\x00", Tuple::pack([null]));
    }

    #[Test]
    public function unpackNullByte(): void
    {
        $result = Tuple::unpack("\x00");
        self::assertCount(1, $result);
        self::assertNull($result[0]);
    }

    #[Test]
    public function packEncodesEmptyBytesWithTypeAndTerminator(): void
    {
        self::assertSame("\x01\x00", Tuple::pack([new Bytes('')]));
    }

    #[Test]
    public function packEncodesBytesHello(): void
    {
        self::assertSame("\x01hello\x00", Tuple::pack([new Bytes('hello')]));
    }

    #[Test]
    public function packEncodesBytesWithNullBytesEscaped(): void
    {
        $input = new Bytes("he\x00lo");
        $packed = Tuple::pack([$input]);
        self::assertSame("\x01he\x00\xFFlo\x00", $packed);
    }

    #[Test]
    public function unpackBytesReturnsCorrectData(): void
    {
        $result = Tuple::unpack("\x01hello\x00");
        self::assertCount(1, $result);
        self::assertInstanceOf(Bytes::class, $result[0]);
        self::assertSame('hello', $result[0]->data);
    }

    #[Test]
    public function unpackBytesWithNullBytesUnescapes(): void
    {
        $result = Tuple::unpack("\x01he\x00\xFFlo\x00");
        self::assertCount(1, $result);
        self::assertInstanceOf(Bytes::class, $result[0]);
        self::assertSame("he\x00lo", $result[0]->data);
    }

    #[Test]
    public function packEncodesStringHello(): void
    {
        self::assertSame("\x02hello\x00", Tuple::pack(['hello']));
    }

    #[Test]
    public function packEncodesEmptyString(): void
    {
        self::assertSame("\x02\x00", Tuple::pack(['']));
    }

    #[Test]
    public function packEncodesStringWithNullBytesEscaped(): void
    {
        $packed = Tuple::pack(["he\x00lo"]);
        self::assertSame("\x02he\x00\xFFlo\x00", $packed);
    }

    #[Test]
    public function packEncodesUtf8String(): void
    {
        $packed = Tuple::pack(['日本語']);
        $result = Tuple::unpack($packed);
        self::assertSame('日本語', $result[0]);
    }

    #[Test]
    public function unpackStringReturnsCorrectValue(): void
    {
        $result = Tuple::unpack("\x02hello\x00");
        self::assertCount(1, $result);
        self::assertIsString($result[0]);
        self::assertSame('hello', $result[0]);
    }

    #[Test]
    public function packEncodesIntegerZero(): void
    {
        self::assertSame("\x14", Tuple::pack([0]));
    }

    #[Test]
    public function packEncodesIntegerOne(): void
    {
        self::assertSame("\x15\x01", Tuple::pack([1]));
    }

    #[Test]
    public function packEncodesIntegerNegativeOne(): void
    {
        self::assertSame("\x13\xFE", Tuple::pack([-1]));
    }

    #[Test]
    public function packEncodesInteger255(): void
    {
        self::assertSame("\x15\xFF", Tuple::pack([255]));
    }

    #[Test]
    public function packEncodesInteger256(): void
    {
        self::assertSame("\x16\x01\x00", Tuple::pack([256]));
    }

    #[Test]
    public function packEncodesIntegerNegative256(): void
    {
        $packed = Tuple::pack([-256]);
        self::assertSame("\x12\xFE\xFF", $packed);
    }

    #[Test]
    public function packEncodesInteger65535(): void
    {
        self::assertSame("\x16\xFF\xFF", Tuple::pack([65535]));
    }

    #[Test]
    public function packEncodesInteger65536(): void
    {
        self::assertSame("\x17\x01\x00\x00", Tuple::pack([65536]));
    }

    #[Test]
    public function roundtripIntegerPhpIntMax(): void
    {
        $result = Tuple::unpack(Tuple::pack([PHP_INT_MAX]));
        self::assertSame(PHP_INT_MAX, $result[0]);
    }

    #[Test]
    public function roundtripIntegerPhpIntMin(): void
    {
        $result = Tuple::unpack(Tuple::pack([PHP_INT_MIN]));
        self::assertSame(PHP_INT_MIN, $result[0]);
    }

    #[Test]
    public function roundtripLargePositiveInteger(): void
    {
        $value = 1_000_000_000;
        $result = Tuple::unpack(Tuple::pack([$value]));
        self::assertSame($value, $result[0]);
    }

    #[Test]
    public function roundtripLargeNegativeInteger(): void
    {
        $value = -1_000_000_000;
        $result = Tuple::unpack(Tuple::pack([$value]));
        self::assertSame($value, $result[0]);
    }

    #[Test]
    public function unpackIntegerZero(): void
    {
        $result = Tuple::unpack("\x14");
        self::assertSame(0, $result[0]);
    }

    #[Test]
    public function unpackPositiveInteger(): void
    {
        $result = Tuple::unpack("\x15\x01");
        self::assertSame(1, $result[0]);
    }

    #[Test]
    public function unpackNegativeInteger(): void
    {
        $result = Tuple::unpack("\x13\xFE");
        self::assertSame(-1, $result[0]);
    }

    #[Test]
    public function packEncodesDoubleZero(): void
    {
        $packed = Tuple::pack([0.0]);
        $result = Tuple::unpack($packed);
        self::assertSame(0.0, $result[0]);
        self::assertSame('+', sprintf('%+.1f', $result[0])[0]);
    }

    #[Test]
    public function packEncodesNegativeZero(): void
    {
        $packed = Tuple::pack([-0.0]);
        $result = Tuple::unpack($packed);
        self::assertSame(0.0, $result[0]);
    }

    #[Test]
    public function roundtripDoublePositive(): void
    {
        $result = Tuple::unpack(Tuple::pack([1.5]));
        self::assertSame(1.5, $result[0]);
    }

    #[Test]
    public function roundtripDoubleNegative(): void
    {
        $result = Tuple::unpack(Tuple::pack([-1.5]));
        self::assertSame(-1.5, $result[0]);
    }

    #[Test]
    public function roundtripDoubleInfinity(): void
    {
        $result = Tuple::unpack(Tuple::pack([INF]));
        self::assertSame(INF, $result[0]);
    }

    #[Test]
    public function roundtripDoubleNegativeInfinity(): void
    {
        $result = Tuple::unpack(Tuple::pack([-INF]));
        self::assertSame(-INF, $result[0]);
    }

    #[Test]
    public function roundtripDoubleNan(): void
    {
        $result = Tuple::unpack(Tuple::pack([NAN]));
        self::assertNan($result[0]);
    }

    #[Test]
    public function roundtripSingleFloatZero(): void
    {
        $result = Tuple::unpack(Tuple::pack([new SingleFloat(0.0)]));
        self::assertInstanceOf(SingleFloat::class, $result[0]);
        self::assertSame(0.0, $result[0]->value);
    }

    #[Test]
    public function roundtripSingleFloatPositive(): void
    {
        $result = Tuple::unpack(Tuple::pack([new SingleFloat(1.5)]));
        self::assertInstanceOf(SingleFloat::class, $result[0]);
        self::assertSame(1.5, $result[0]->value);
    }

    #[Test]
    public function roundtripSingleFloatNegative(): void
    {
        $result = Tuple::unpack(Tuple::pack([new SingleFloat(-1.5)]));
        self::assertInstanceOf(SingleFloat::class, $result[0]);
        self::assertSame(-1.5, $result[0]->value);
    }

    #[Test]
    public function roundtripSingleFloatInfinity(): void
    {
        $result = Tuple::unpack(Tuple::pack([new SingleFloat(INF)]));
        self::assertInstanceOf(SingleFloat::class, $result[0]);
        self::assertSame(INF, $result[0]->value);
    }

    #[Test]
    public function roundtripSingleFloatNegativeInfinity(): void
    {
        $result = Tuple::unpack(Tuple::pack([new SingleFloat(-INF)]));
        self::assertInstanceOf(SingleFloat::class, $result[0]);
        self::assertSame(-INF, $result[0]->value);
    }

    #[Test]
    public function roundtripSingleFloatNan(): void
    {
        $result = Tuple::unpack(Tuple::pack([new SingleFloat(NAN)]));
        self::assertInstanceOf(SingleFloat::class, $result[0]);
        self::assertNan($result[0]->value);
    }

    #[Test]
    public function packEncodesTrueCorrectly(): void
    {
        self::assertSame("\x27", Tuple::pack([true]));
    }

    #[Test]
    public function packEncodesFalseCorrectly(): void
    {
        self::assertSame("\x26", Tuple::pack([false]));
    }

    #[Test]
    public function unpackTrueReturnsTrue(): void
    {
        $result = Tuple::unpack("\x27");
        self::assertTrue($result[0]);
    }

    #[Test]
    public function unpackFalseReturnsFalse(): void
    {
        $result = Tuple::unpack("\x26");
        self::assertFalse($result[0]);
    }

    #[Test]
    public function roundtripUuid(): void
    {
        $bytes = random_bytes(16);
        $uuid = new Uuid($bytes);
        $result = Tuple::unpack(Tuple::pack([$uuid]));
        self::assertInstanceOf(Uuid::class, $result[0]);
        self::assertSame($bytes, $result[0]->bytes);
    }

    #[Test]
    public function packEncodesUuidWithTypePrefix(): void
    {
        $bytes = str_repeat("\xAB", 16);
        $packed = Tuple::pack([new Uuid($bytes)]);
        self::assertSame("\x30" . $bytes, $packed);
    }

    #[Test]
    public function roundtripVersionstamp(): void
    {
        $trVersion = str_repeat("\x01", 10);
        $vs = new Versionstamp($trVersion, 42);
        $result = Tuple::unpack(Tuple::pack([$vs]));
        self::assertInstanceOf(Versionstamp::class, $result[0]);
        self::assertSame($trVersion, $result[0]->trVersion);
        self::assertSame(42, $result[0]->userVersion);
    }

    #[Test]
    public function packEncodesVersionstampWithTypePrefix(): void
    {
        $trVersion = str_repeat("\x00", 10);
        $vs = new Versionstamp($trVersion, 0);
        $packed = Tuple::pack([$vs]);
        self::assertSame("\x33" . $trVersion . "\x00\x00", $packed);
    }

    #[Test]
    public function packEncodesEmptyNestedTuple(): void
    {
        self::assertSame("\x05\x00", Tuple::pack([[]]));
    }

    #[Test]
    public function packEncodesNestedTupleWithElements(): void
    {
        self::assertSame("\x05\x15\x01\x15\x02\x00", Tuple::pack([[1, 2]]));
    }

    #[Test]
    public function packEncodesNullInsideNestedTuple(): void
    {
        self::assertSame("\x05\x00\xFF\x00", Tuple::pack([[null]]));
    }

    #[Test]
    public function unpackNestedTupleReturnsArray(): void
    {
        $result = Tuple::unpack("\x05\x15\x01\x15\x02\x00");
        self::assertCount(1, $result);
        self::assertIsArray($result[0]);
        self::assertSame([1, 2], $result[0]);
    }

    #[Test]
    public function unpackNestedTupleWithNull(): void
    {
        $result = Tuple::unpack("\x05\x00\xFF\x00");
        self::assertCount(1, $result);
        self::assertIsArray($result[0]);
        self::assertCount(1, $result[0]);
        self::assertNull($result[0][0]);
    }

    #[Test]
    public function roundtripDeeplyNestedTuple(): void
    {
        $input = [[1, [2, 3]]];
        $result = Tuple::unpack(Tuple::pack($input));
        self::assertIsArray($result[0]);
        $outer = $result[0];
        self::assertSame(1, $outer[0]);
        self::assertIsArray($outer[1]);
        $inner = $outer[1];
        self::assertSame(2, $inner[0]);
        self::assertSame(3, $inner[1]);
    }

    #[Test]
    public function packMultipleElementsInOneTuple(): void
    {
        $packed = Tuple::pack([1, 'hello', null, true]);
        $result = Tuple::unpack($packed);
        self::assertCount(4, $result);
        self::assertSame(1, $result[0]);
        self::assertSame('hello', $result[1]);
        self::assertNull($result[2]);
        self::assertTrue($result[3]);
    }

    #[Test]
    public function packWithPrefixPrependsPrefix(): void
    {
        $packed = Tuple::pack([1], 'prefix');
        self::assertStringStartsWith('prefix', $packed);
        $result = Tuple::unpack($packed, strlen('prefix'));
        self::assertSame(1, $result[0]);
    }

    #[Test]
    public function unpackWithPrefixLengthSkipsPrefix(): void
    {
        $packed = "ABC" . Tuple::pack([42]);
        $result = Tuple::unpack($packed, 3);
        self::assertCount(1, $result);
        self::assertSame(42, $result[0]);
    }

    #[Test]
    public function rangeReturnsBeginAndEndKeys(): void
    {
        $range = Tuple::range([1, 2]);
        self::assertCount(2, $range);
        $packed = Tuple::pack([1, 2]);
        self::assertSame($packed . "\x00", $range[0]);
        self::assertSame($packed . "\xFF", $range[1]);
    }

    #[Test]
    public function rangeEmptyTupleReturnsZeroAndFfByte(): void
    {
        $range = Tuple::range([]);
        self::assertSame("\x00", $range[0]);
        self::assertSame("\xFF", $range[1]);
    }

    #[Test]
    public function rangeBeginIsLessThanEnd(): void
    {
        $range = Tuple::range(['test']);
        self::assertTrue($range[0] < $range[1]);
    }

    #[Test]
    #[DataProvider('roundtripProvider')]
    public function roundtripForAllTypes(mixed $input, string $description): void
    {
        $packed = Tuple::pack([$input]);
        $result = Tuple::unpack($packed);
        self::assertCount(1, $result);

        if ($input instanceof Bytes) {
            self::assertInstanceOf(Bytes::class, $result[0]);
            self::assertSame($input->data, $result[0]->data);
        } elseif ($input instanceof SingleFloat) {
            self::assertInstanceOf(SingleFloat::class, $result[0]);
            if (is_nan($input->value)) {
                self::assertNan($result[0]->value);
            } else {
                self::assertSame($input->value, $result[0]->value);
            }
        } elseif ($input instanceof Uuid) {
            self::assertInstanceOf(Uuid::class, $result[0]);
            self::assertSame($input->bytes, $result[0]->bytes);
        } elseif ($input instanceof Versionstamp) {
            self::assertInstanceOf(Versionstamp::class, $result[0]);
            self::assertSame($input->trVersion, $result[0]->trVersion);
            self::assertSame($input->userVersion, $result[0]->userVersion);
        } elseif (is_float($input) && is_nan($input)) {
            self::assertNan($result[0]);
        } elseif (is_array($input)) {
            self::assertIsArray($result[0]);
        } else {
            self::assertSame($input, $result[0]);
        }
    }

    /**
     * @return iterable<string, array{mixed, string}>
     */
    public static function roundtripProvider(): iterable
    {
        yield 'null' => [null, 'null'];
        yield 'true' => [true, 'true'];
        yield 'false' => [false, 'false'];
        yield 'int 0' => [0, 'int 0'];
        yield 'int 1' => [1, 'int 1'];
        yield 'int -1' => [-1, 'int -1'];
        yield 'int 255' => [255, 'int 255'];
        yield 'int 256' => [256, 'int 256'];
        yield 'int -256' => [-256, 'int -256'];
        yield 'int 65535' => [65535, 'int 65535'];
        yield 'int 65536' => [65536, 'int 65536'];
        yield 'int PHP_INT_MAX' => [PHP_INT_MAX, 'int PHP_INT_MAX'];
        yield 'int PHP_INT_MIN' => [PHP_INT_MIN, 'int PHP_INT_MIN'];
        yield 'int 1 billion' => [1_000_000_000, 'int 1 billion'];
        yield 'int -1 billion' => [-1_000_000_000, 'int -1 billion'];
        yield 'float 0.0' => [0.0, 'float 0.0'];
        yield 'float 1.5' => [1.5, 'float 1.5'];
        yield 'float -1.5' => [-1.5, 'float -1.5'];
        yield 'float INF' => [INF, 'float INF'];
        yield 'float -INF' => [-INF, 'float -INF'];
        yield 'float NAN' => [NAN, 'float NAN'];
        yield 'empty string' => ['', 'empty string'];
        yield 'string hello' => ['hello', 'string hello'];
        yield 'string with null byte' => ["he\x00lo", 'string with null byte'];
        yield 'utf8 string' => ['日本語', 'utf8 string'];
        yield 'empty bytes' => [new Bytes(''), 'empty bytes'];
        yield 'bytes hello' => [new Bytes('hello'), 'bytes hello'];
        yield 'bytes with null' => [new Bytes("\x00\x01\x02"), 'bytes with null'];
        yield 'single float 1.5' => [new SingleFloat(1.5), 'single float 1.5'];
        yield 'single float -1.5' => [new SingleFloat(-1.5), 'single float -1.5'];
        yield 'single float NAN' => [new SingleFloat(NAN), 'single float NAN'];
        yield 'uuid' => [new Uuid(str_repeat("\xAB", 16)), 'uuid'];
        yield 'versionstamp' => [new Versionstamp(str_repeat("\x01", 10), 100), 'versionstamp'];
        yield 'empty nested' => [[], 'empty nested tuple'];
        yield 'nested with values' => [[1, 'a'], 'nested with values'];
        yield 'nested with null' => [[null], 'nested with null'];
    }

    #[Test]
    public function sortOrderPositiveIntegers(): void
    {
        self::assertTrue(Tuple::pack([1]) < Tuple::pack([2]));
    }

    #[Test]
    public function sortOrderNegativeToZero(): void
    {
        self::assertTrue(Tuple::pack([-1]) < Tuple::pack([0]));
    }

    #[Test]
    public function sortOrderStrings(): void
    {
        self::assertTrue(Tuple::pack(['a']) < Tuple::pack(['b']));
    }

    #[Test]
    public function sortOrderNullBeforeIntegers(): void
    {
        self::assertTrue(Tuple::pack([null]) < Tuple::pack([0]));
    }

    #[Test]
    public function sortOrderBytesBeforeStrings(): void
    {
        self::assertTrue(Tuple::pack([new Bytes('a')]) < Tuple::pack(['a']));
    }

    #[Test]
    public function sortOrderFalseBeforeTrue(): void
    {
        self::assertTrue(Tuple::pack([false]) < Tuple::pack([true]));
    }

    #[Test]
    public function sortOrderNegativeIntegersPreserved(): void
    {
        self::assertTrue(Tuple::pack([-100]) < Tuple::pack([-1]));
    }

    #[Test]
    public function sortOrderLargeIntegers(): void
    {
        self::assertTrue(Tuple::pack([1000]) < Tuple::pack([1001]));
    }

    #[Test]
    public function sortOrderDoubleFloats(): void
    {
        self::assertTrue(Tuple::pack([1.0]) < Tuple::pack([2.0]));
    }

    #[Test]
    public function sortOrderNegativeDoubleFloats(): void
    {
        self::assertTrue(Tuple::pack([-2.0]) < Tuple::pack([-1.0]));
    }

    #[Test]
    public function crossLanguagePackEmptyTuple(): void
    {
        self::assertSame('', Tuple::pack([]));
    }

    #[Test]
    public function crossLanguagePackNull(): void
    {
        self::assertSame("\x00", Tuple::pack([null]));
    }

    #[Test]
    public function crossLanguagePackEmptyBytes(): void
    {
        self::assertSame("\x01\x00", Tuple::pack([new Bytes('')]));
    }

    #[Test]
    public function crossLanguagePackBytesHello(): void
    {
        self::assertSame("\x01hello\x00", Tuple::pack([new Bytes('hello')]));
    }

    #[Test]
    public function crossLanguagePackStringHello(): void
    {
        self::assertSame("\x02hello\x00", Tuple::pack(['hello']));
    }

    #[Test]
    public function crossLanguagePackIntZero(): void
    {
        self::assertSame("\x14", Tuple::pack([0]));
    }

    #[Test]
    public function crossLanguagePackIntOne(): void
    {
        self::assertSame("\x15\x01", Tuple::pack([1]));
    }

    #[Test]
    public function crossLanguagePackIntNegativeOne(): void
    {
        self::assertSame("\x13\xFE", Tuple::pack([-1]));
    }

    #[Test]
    public function crossLanguagePackInt255(): void
    {
        self::assertSame("\x15\xFF", Tuple::pack([255]));
    }

    #[Test]
    public function crossLanguagePackInt256(): void
    {
        self::assertSame("\x16\x01\x00", Tuple::pack([256]));
    }

    #[Test]
    public function crossLanguagePackInt65535(): void
    {
        self::assertSame("\x16\xFF\xFF", Tuple::pack([65535]));
    }

    #[Test]
    public function crossLanguagePackInt65536(): void
    {
        self::assertSame("\x17\x01\x00\x00", Tuple::pack([65536]));
    }

    #[Test]
    public function crossLanguagePackTrue(): void
    {
        self::assertSame("\x27", Tuple::pack([true]));
    }

    #[Test]
    public function crossLanguagePackFalse(): void
    {
        self::assertSame("\x26", Tuple::pack([false]));
    }

    #[Test]
    public function crossLanguagePackEmptyNestedTuple(): void
    {
        self::assertSame("\x05\x00", Tuple::pack([[]]));
    }

    #[Test]
    public function crossLanguagePackNestedTupleWithElements(): void
    {
        self::assertSame("\x05\x15\x01\x15\x02\x00", Tuple::pack([[1, 2]]));
    }

    #[Test]
    public function crossLanguagePackNestedTupleWithNull(): void
    {
        self::assertSame("\x05\x00\xFF\x00", Tuple::pack([[null]]));
    }

    #[Test]
    public function packWithVersionstampAppendsOffset(): void
    {
        $vs = Versionstamp::incomplete();
        $packed = Tuple::packWithVersionstamp([$vs]);
        $expectedBody = "\x33" . str_repeat("\xFF", 10) . "\x00\x00";
        $expectedOffset = pack('V', 1);
        self::assertSame($expectedBody . $expectedOffset, $packed);
    }

    #[Test]
    public function packWithVersionstampWithPrefix(): void
    {
        $vs = Versionstamp::incomplete();
        $packed = Tuple::packWithVersionstamp([$vs], 'PRE');
        $expectedBody = 'PRE' . "\x33" . str_repeat("\xFF", 10) . "\x00\x00";
        $expectedOffset = pack('V', 4);
        self::assertSame($expectedBody . $expectedOffset, $packed);
    }

    #[Test]
    public function packWithVersionstampOffsetAccountsForPrecedingElements(): void
    {
        $vs = Versionstamp::incomplete();
        $packed = Tuple::packWithVersionstamp([1, $vs]);
        $intEncoded = "\x15\x01";
        $vsEncoded = "\x33" . str_repeat("\xFF", 10) . "\x00\x00";
        $expectedOffset = pack('V', strlen($intEncoded) + 1);
        self::assertSame($intEncoded . $vsEncoded . $expectedOffset, $packed);
    }

    #[Test]
    public function packWithVersionstampThrowsOnZeroVersionstamps(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('found 0');
        Tuple::packWithVersionstamp([1, 2, 3]);
    }

    #[Test]
    public function packWithVersionstampThrowsOnMultipleVersionstamps(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('found 2');
        Tuple::packWithVersionstamp([
            Versionstamp::incomplete(),
            Versionstamp::incomplete(),
        ]);
    }

    #[Test]
    public function packWithVersionstampCountsNestedVersionstamps(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('found 2');
        Tuple::packWithVersionstamp([
            Versionstamp::incomplete(),
            [Versionstamp::incomplete()],
        ]);
    }

    #[Test]
    public function packThrowsOnUnsupportedType(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Unsupported tuple element type');
        /** @phpstan-ignore argument.type */
        Tuple::pack([new \stdClass()]);
    }

    #[Test]
    public function unpackThrowsOnUnknownTypeCode(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Unknown type code');
        Tuple::unpack("\x03");
    }

    #[Test]
    public function unpackThrowsOnTruncatedPositiveInteger(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Truncated');
        Tuple::unpack("\x16\x01");
    }

    #[Test]
    public function unpackThrowsOnTruncatedNegativeInteger(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Truncated');
        Tuple::unpack("\x12\x01");
    }

    #[Test]
    public function unpackThrowsOnTruncatedDouble(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Truncated');
        Tuple::unpack("\x21\x01\x02\x03");
    }

    #[Test]
    public function unpackThrowsOnTruncatedSingleFloat(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Truncated');
        Tuple::unpack("\x20\x01\x02");
    }

    #[Test]
    public function unpackThrowsOnTruncatedUuid(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Truncated');
        Tuple::unpack("\x30" . str_repeat("\x01", 10));
    }

    #[Test]
    public function unpackThrowsOnTruncatedVersionstamp(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Truncated');
        Tuple::unpack("\x33" . str_repeat("\x01", 5));
    }

    #[Test]
    public function unpackThrowsOnUnterminatedString(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Unterminated');
        Tuple::unpack("\x02hello");
    }

    #[Test]
    public function unpackThrowsOnUnterminatedBytes(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Unterminated');
        Tuple::unpack("\x01hello");
    }

    #[Test]
    public function unpackThrowsOnUnterminatedNestedTuple(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Unterminated');
        Tuple::unpack("\x05\x15\x01");
    }

    #[Test]
    public function unpackEmptyStringReturnsEmptyArray(): void
    {
        self::assertSame([], Tuple::unpack(''));
    }

    #[Test]
    public function roundtripMixedTuplePreservesAllTypes(): void
    {
        $uuid = new Uuid(str_repeat("\xCD", 16));
        $vs = new Versionstamp(str_repeat("\x02", 10), 7);
        $elements = [
            null,
            true,
            false,
            0,
            42,
            -42,
            'hello',
            new Bytes('world'),
            1.5,
            new SingleFloat(2.5),
            $uuid,
            $vs,
            [1, null, 'nested'],
        ];

        $packed = Tuple::pack($elements);
        $result = Tuple::unpack($packed);

        self::assertCount(13, $result);
        self::assertNull($result[0]);
        self::assertTrue($result[1]);
        self::assertFalse($result[2]);
        self::assertSame(0, $result[3]);
        self::assertSame(42, $result[4]);
        self::assertSame(-42, $result[5]);
        self::assertSame('hello', $result[6]);
        self::assertInstanceOf(Bytes::class, $result[7]);
        self::assertSame('world', $result[7]->data);
        self::assertSame(1.5, $result[8]);
        self::assertInstanceOf(SingleFloat::class, $result[9]);
        self::assertEqualsWithDelta(2.5, $result[9]->value, 0.01);
        self::assertInstanceOf(Uuid::class, $result[10]);
        self::assertSame($uuid->bytes, $result[10]->bytes);
        self::assertInstanceOf(Versionstamp::class, $result[11]);
        self::assertSame($vs->trVersion, $result[11]->trVersion);
        self::assertSame($vs->userVersion, $result[11]->userVersion);
        self::assertIsArray($result[12]);
        self::assertSame(1, $result[12][0]);
        self::assertNull($result[12][1]);
        self::assertSame('nested', $result[12][2]);
    }

    #[Test]
    public function packEncodesIntegerNegative255(): void
    {
        $packed = Tuple::pack([-255]);
        $result = Tuple::unpack($packed);
        self::assertSame(-255, $result[0]);
    }

    #[Test]
    public function roundtripIntegerBoundaries(): void
    {
        $boundaries = [
            0xFF, -0xFF,
            0xFFFF, -0xFFFF,
            0xFFFFFF, -0xFFFFFF,
            0xFFFFFFFF, -0xFFFFFFFF,
            0xFFFFFFFFFF, -0xFFFFFFFFFF,
            0xFFFFFFFFFFFF, -0xFFFFFFFFFFFF,
            0xFFFFFFFFFFFFFF, -0xFFFFFFFFFFFFFF,
        ];

        foreach ($boundaries as $value) {
            $result = Tuple::unpack(Tuple::pack([$value]));
            self::assertSame($value, $result[0], "Failed roundtrip for integer: $value");
        }
    }

    #[Test]
    public function sortOrderIntegerByteLengthBoundaries(): void
    {
        self::assertTrue(Tuple::pack([255]) < Tuple::pack([256]));
        self::assertTrue(Tuple::pack([65535]) < Tuple::pack([65536]));
        self::assertTrue(Tuple::pack([-256]) < Tuple::pack([-255]));
    }

    #[Test]
    public function packWithVersionstampInNestedTuple(): void
    {
        $vs = Versionstamp::incomplete();
        $packed = Tuple::packWithVersionstamp([[1, $vs]]);
        self::assertNotEmpty($packed);
        $lastFourBytes = substr($packed, -4);
        $offset = unpack('V', $lastFourBytes);
        self::assertNotFalse($offset);
        self::assertGreaterThan(0, $offset[1]);
    }

    #[Test]
    public function crossLanguageUnpackKnownBytes(): void
    {
        $result = Tuple::unpack("\x14");
        self::assertSame(0, $result[0]);

        $result = Tuple::unpack("\x15\x01");
        self::assertSame(1, $result[0]);

        $result = Tuple::unpack("\x13\xFE");
        self::assertSame(-1, $result[0]);

        $result = Tuple::unpack("\x02hello\x00");
        self::assertSame('hello', $result[0]);

        $result = Tuple::unpack("\x01hello\x00");
        self::assertInstanceOf(Bytes::class, $result[0]);
        self::assertSame('hello', $result[0]->data);
    }

    #[Test]
    public function packMultipleNullsInSequence(): void
    {
        $packed = Tuple::pack([null, null, null]);
        self::assertSame("\x00\x00\x00", $packed);
        $result = Tuple::unpack($packed);
        self::assertCount(3, $result);
        self::assertNull($result[0]);
        self::assertNull($result[1]);
        self::assertNull($result[2]);
    }

    #[Test]
    public function sortOrderEmptyStringBeforeNonEmpty(): void
    {
        self::assertTrue(Tuple::pack(['']) < Tuple::pack(['a']));
    }

    #[Test]
    public function sortOrderEmptyBytesBeforeNonEmpty(): void
    {
        self::assertTrue(Tuple::pack([new Bytes('')]) < Tuple::pack([new Bytes('a')]));
    }

    #[Test]
    public function roundtripStringWithMultipleNullBytes(): void
    {
        $input = "\x00\x00\x00";
        $result = Tuple::unpack(Tuple::pack([$input]));
        self::assertSame($input, $result[0]);
    }

    #[Test]
    public function roundtripBytesWithMultipleNullBytes(): void
    {
        $input = new Bytes("\x00\x00\x00");
        $result = Tuple::unpack(Tuple::pack([$input]));
        self::assertInstanceOf(Bytes::class, $result[0]);
        self::assertSame($input->data, $result[0]->data);
    }

    #[Test]
    public function doubleFloatEncodingProducesNineBytes(): void
    {
        $packed = Tuple::pack([1.5]);
        self::assertSame(9, strlen($packed));
        self::assertSame("\x21", $packed[0]);
    }

    #[Test]
    public function singleFloatEncodingProducesFiveBytes(): void
    {
        $packed = Tuple::pack([new SingleFloat(1.5)]);
        self::assertSame(5, strlen($packed));
        self::assertSame("\x20", $packed[0]);
    }

    #[Test]
    public function nestedTupleWithMixedTypes(): void
    {
        $input = [['hello', 42, null, true]];
        $result = Tuple::unpack(Tuple::pack($input));
        self::assertIsArray($result[0]);
        self::assertSame('hello', $result[0][0]);
        self::assertSame(42, $result[0][1]);
        self::assertNull($result[0][2]);
        self::assertTrue($result[0][3]);
    }

    #[Test]
    public function packWithVersionstampCompleteVersionstamp(): void
    {
        $vs = new Versionstamp(str_repeat("\x01", 10), 0);
        $packed = Tuple::packWithVersionstamp([$vs]);
        self::assertNotEmpty($packed);
    }
}
