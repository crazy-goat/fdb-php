<?php

declare(strict_types=1);

namespace CrazyGoat\FoundationDB\Tests\Unit\Tuple;

use CrazyGoat\FoundationDB\Tuple\Tuple;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Boundary integer encodings must be byte-identical to the canonical
 * (Python/Java) tuple layer, as verified by the upstream binding tester
 * (issue #96): the fixed-width int codes only cover values with a
 * magnitude below 2^64-1; 2^64-1 itself and beyond use the big-int forms.
 */
final class TupleBoundaryIntTest extends TestCase
{
    /** @return iterable<string, array{0: string, 1: string}> */
    public static function boundaryProvider(): iterable
    {
        $twoPow64MinusOne = '18446744073709551615'; // 2^64-1

        yield '2^64-1 uses POS_BIGINT' => [$twoPow64MinusOne, '1d08ffffffffffffffff'];
        yield '-(2^64-1) uses NEG_BIGINT' => ['-' . $twoPow64MinusOne, '0bf70000000000000000'];
        yield '2^63-1 uses fixed-width positive' => ['9223372036854775807', '1c7fffffffffffffff'];
        yield '-2^63 uses fixed-width negative' => ['-9223372036854775808', '0c7fffffffffffffff'];
        yield '2^63 uses fixed-width positive' => ['9223372036854775808', '1c8000000000000000'];
        yield '-2^63-1 uses fixed-width negative' => ['-9223372036854775809', '0c7ffffffffffffffe'];
        yield '-(2^64-2) uses fixed-width negative' => ['-18446744073709551614', '0c0000000000000001'];
    }

    #[Test]
    #[DataProvider('boundaryProvider')]
    public function encodesBoundaryIntegersCanonically(string $value, string $expectedHex): void
    {
        $packed = Tuple::pack([gmp_init($value)]);

        $hex = unpack('H*', $packed);

        self::assertNotFalse($hex);
        self::assertSame($expectedHex, $hex[1]);
    }

    #[Test]
    #[DataProvider('boundaryProvider')]
    public function decodesBoundaryIntegersRoundTrip(string $value, string $expectedHex): void
    {
        $packed = Tuple::pack([gmp_init($value)]);
        $unpacked = Tuple::unpack($packed);
        $element = $unpacked[0];

        self::assertSame(
            gmp_strval(gmp_init($value)),
            $element instanceof \GMP ? gmp_strval($element) : (is_int($element) ? (string) $element : 'unexpected'),
        );
    }

    #[Test]
    public function encodesMaxSmallPositiveInt(): void
    {
        $hex = unpack('H*', Tuple::pack([gmp_init('18446744073709551614')]));
        self::assertNotFalse($hex);
        self::assertSame('1cfffffffffffffffe', $hex[1]);
    }

    #[Test]
    public function encodesMaxSmallNegativeInt(): void
    {
        $hex = unpack('H*', Tuple::pack([gmp_init('-18446744073709551614')]));
        self::assertNotFalse($hex);
        self::assertSame('0c0000000000000001', $hex[1]);
    }
}
