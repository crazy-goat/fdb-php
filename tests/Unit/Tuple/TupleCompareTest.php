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

final class TupleCompareTest extends TestCase
{
    #[Test]
    public function compareEmptyTuplesReturnsZero(): void
    {
        self::assertSame(0, Tuple::compare([], []));
    }

    #[Test]
    public function compareIdenticalSingleElementTuplesReturnsZero(): void
    {
        self::assertSame(0, Tuple::compare([1], [1]));
    }

    #[Test]
    public function compareIdenticalMultiElementTuplesReturnsZero(): void
    {
        self::assertSame(0, Tuple::compare([1, 'hello', null], [1, 'hello', null]));
    }

    #[Test]
    public function compareShorterTupleIsLessThanLongerWithSamePrefix(): void
    {
        self::assertSame(-1, Tuple::compare([1], [1, 2]));
    }

    #[Test]
    public function compareLongerTupleIsGreaterThanShorterWithSamePrefix(): void
    {
        self::assertSame(1, Tuple::compare([1, 2], [1]));
    }

    #[Test]
    public function compareEmptyTupleIsLessThanNonEmpty(): void
    {
        self::assertSame(-1, Tuple::compare([], [null]));
    }

    #[Test]
    public function compareNonEmptyTupleIsGreaterThanEmpty(): void
    {
        self::assertSame(1, Tuple::compare([null], []));
    }

    #[Test]
    public function comparePositiveIntegers(): void
    {
        self::assertSame(-1, Tuple::compare([1], [2]));
        self::assertSame(1, Tuple::compare([2], [1]));
    }

    #[Test]
    public function compareNegativeIntegers(): void
    {
        self::assertSame(-1, Tuple::compare([-100], [-1]));
        self::assertSame(1, Tuple::compare([-1], [-100]));
    }

    #[Test]
    public function compareNegativeToPositiveInteger(): void
    {
        self::assertSame(-1, Tuple::compare([-1], [0]));
        self::assertSame(-1, Tuple::compare([-1], [1]));
    }

    #[Test]
    public function compareStrings(): void
    {
        self::assertSame(-1, Tuple::compare(['a'], ['b']));
        self::assertSame(1, Tuple::compare(['b'], ['a']));
        self::assertSame(0, Tuple::compare(['hello'], ['hello']));
    }

    #[Test]
    public function compareEmptyStringBeforeNonEmpty(): void
    {
        self::assertSame(-1, Tuple::compare([''], ['a']));
    }

    #[Test]
    public function compareBytes(): void
    {
        self::assertSame(-1, Tuple::compare([new Bytes('a')], [new Bytes('b')]));
        self::assertSame(1, Tuple::compare([new Bytes('b')], [new Bytes('a')]));
        self::assertSame(0, Tuple::compare([new Bytes('hello')], [new Bytes('hello')]));
    }

    #[Test]
    public function compareEmptyBytesBeforeNonEmpty(): void
    {
        self::assertSame(-1, Tuple::compare([new Bytes('')], [new Bytes('a')]));
    }

    #[Test]
    public function compareBooleans(): void
    {
        self::assertSame(-1, Tuple::compare([false], [true]));
        self::assertSame(1, Tuple::compare([true], [false]));
        self::assertSame(0, Tuple::compare([true], [true]));
        self::assertSame(0, Tuple::compare([false], [false]));
    }

    #[Test]
    public function compareDoubleFloats(): void
    {
        self::assertSame(-1, Tuple::compare([1.0], [2.0]));
        self::assertSame(1, Tuple::compare([2.0], [1.0]));
        self::assertSame(0, Tuple::compare([1.5], [1.5]));
    }

    #[Test]
    public function compareNegativeDoubleFloats(): void
    {
        self::assertSame(-1, Tuple::compare([-2.0], [-1.0]));
        self::assertSame(1, Tuple::compare([-1.0], [-2.0]));
    }

    #[Test]
    public function compareDoubleNegativeZeroBeforePositiveZero(): void
    {
        self::assertSame(-1, Tuple::compare([-0.0], [0.0]));
    }

    #[Test]
    public function compareDoubleNanOrdering(): void
    {
        self::assertSame(0, Tuple::compare([NAN], [NAN]));
    }

    #[Test]
    public function compareDoubleInfinityOrdering(): void
    {
        self::assertSame(-1, Tuple::compare([-INF], [0.0]));
        self::assertSame(-1, Tuple::compare([0.0], [INF]));
        self::assertSame(-1, Tuple::compare([-INF], [INF]));
    }

    #[Test]
    public function compareSingleFloats(): void
    {
        self::assertSame(-1, Tuple::compare([new SingleFloat(1.0)], [new SingleFloat(2.0)]));
        self::assertSame(1, Tuple::compare([new SingleFloat(2.0)], [new SingleFloat(1.0)]));
        self::assertSame(0, Tuple::compare([new SingleFloat(1.5)], [new SingleFloat(1.5)]));
    }

    #[Test]
    public function compareUuids(): void
    {
        $uuid1 = new Uuid(str_repeat("\x01", 16));
        $uuid2 = new Uuid(str_repeat("\x02", 16));
        self::assertSame(-1, Tuple::compare([$uuid1], [$uuid2]));
        self::assertSame(1, Tuple::compare([$uuid2], [$uuid1]));
        self::assertSame(0, Tuple::compare([$uuid1], [$uuid1]));
    }

    #[Test]
    public function compareVersionstamps(): void
    {
        $vs1 = new Versionstamp(str_repeat("\x01", 10), 0);
        $vs2 = new Versionstamp(str_repeat("\x02", 10), 0);
        self::assertSame(-1, Tuple::compare([$vs1], [$vs2]));
        self::assertSame(1, Tuple::compare([$vs2], [$vs1]));
        self::assertSame(0, Tuple::compare([$vs1], [$vs1]));
    }

    #[Test]
    public function compareVersionstampsByUserVersion(): void
    {
        $vs1 = new Versionstamp(str_repeat("\x01", 10), 0);
        $vs2 = new Versionstamp(str_repeat("\x01", 10), 1);
        self::assertSame(-1, Tuple::compare([$vs1], [$vs2]));
    }

    #[Test]
    public function compareNestedTuples(): void
    {
        self::assertSame(-1, Tuple::compare([[1, 2]], [[1, 3]]));
        self::assertSame(1, Tuple::compare([[1, 3]], [[1, 2]]));
        self::assertSame(0, Tuple::compare([[1, 2]], [[1, 2]]));
    }

    #[Test]
    public function compareNestedTuplesShorterBeforeLonger(): void
    {
        self::assertSame(-1, Tuple::compare([[1]], [[1, 2]]));
        self::assertSame(1, Tuple::compare([[1, 2]], [[1]]));
    }

    #[Test]
    public function compareNullElements(): void
    {
        self::assertSame(0, Tuple::compare([null], [null]));
    }

    /**
     * @param list<null|bool|int|float|string|\GMP|Bytes|SingleFloat|Uuid|Versionstamp|list<mixed>> $lesser
     * @param list<null|bool|int|float|string|\GMP|Bytes|SingleFloat|Uuid|Versionstamp|list<mixed>> $greater
     */
    #[Test]
    #[DataProvider('typeOrderingProvider')]
    public function compareTypeCodeOrdering(array $lesser, array $greater, string $description): void
    {
        self::assertSame(-1, Tuple::compare($lesser, $greater), "$description: expected lesser < greater");
        self::assertSame(1, Tuple::compare($greater, $lesser), "$description: expected greater > lesser");
    }

    /**
     * @return iterable<string, array{
     *     list<null|bool|int|float|string|\GMP|Bytes|SingleFloat|Uuid|Versionstamp|list<mixed>>,
     *     list<null|bool|int|float|string|\GMP|Bytes|SingleFloat|Uuid|Versionstamp|list<mixed>>,
     *     string,
     * }>
     */
    public static function typeOrderingProvider(): iterable
    {
        yield 'null < bytes' => [[null], [new Bytes('a')], 'null < bytes'];
        yield 'bytes < string' => [[new Bytes('a')], ['a'], 'bytes < string'];
        yield 'string < nested' => [['z'], [[]], 'string < nested'];
        yield 'nested < int zero' => [[[]], [0], 'nested < int zero'];
        yield 'int < single float' => [[100], [new SingleFloat(0.0)], 'int < single float'];
        yield 'single float < double' => [[new SingleFloat(999.0)], [0.0], 'single float < double'];
        yield 'double < false' => [[999.0], [false], 'double < false'];
        yield 'false < true' => [[false], [true], 'false < true'];
        yield 'true < uuid' => [[true], [new Uuid(str_repeat("\x00", 16))], 'true < uuid'];
        yield 'uuid < versionstamp' => [
            [new Uuid(str_repeat("\xFF", 16))],
            [new Versionstamp(str_repeat("\x00", 10), 0)],
            'uuid < versionstamp',
        ];
    }

    #[Test]
    public function compareMultiElementDiffersAtSecondPosition(): void
    {
        self::assertSame(-1, Tuple::compare([1, 'a'], [1, 'b']));
        self::assertSame(1, Tuple::compare([1, 'b'], [1, 'a']));
    }

    #[Test]
    public function compareMultiElementDiffersAtFirstPosition(): void
    {
        self::assertSame(-1, Tuple::compare([1, 'z'], [2, 'a']));
    }

    #[Test]
    public function compareIsAntiSymmetric(): void
    {
        $t1 = [1, 'hello'];
        $t2 = [1, 'world'];
        $forward = Tuple::compare($t1, $t2);
        $backward = Tuple::compare($t2, $t1);
        self::assertSame(-$forward, $backward);
    }

    #[Test]
    public function compareIsTransitive(): void
    {
        $t1 = [1];
        $t2 = [2];
        $t3 = [3];
        self::assertSame(-1, Tuple::compare($t1, $t2));
        self::assertSame(-1, Tuple::compare($t2, $t3));
        self::assertSame(-1, Tuple::compare($t1, $t3));
    }

    #[Test]
    public function compareLargeIntegers(): void
    {
        self::assertSame(-1, Tuple::compare([PHP_INT_MAX - 1], [PHP_INT_MAX]));
        self::assertSame(-1, Tuple::compare([PHP_INT_MIN], [PHP_INT_MIN + 1]));
    }

    #[Test]
    public function compareGmpIntegers(): void
    {
        if (!extension_loaded('gmp')) {
            self::markTestSkipped('GMP extension required');
        }

        $big1 = gmp_init('999999999999999999999999999999');
        $big2 = gmp_init('9999999999999999999999999999990');
        self::assertSame(-1, Tuple::compare([$big1], [$big2]));
        self::assertSame(1, Tuple::compare([$big2], [$big1]));
        self::assertSame(0, Tuple::compare([$big1], [$big1]));
    }

    #[Test]
    public function compareNumericLookingStrings(): void
    {
        // Packed bytes that look like numeric strings must NOT be coerced by PHP's <=>
        // strcmp() compares byte-by-byte, preserving correct ordering.
        // These bytes happen to form numeric-like strings when interpreted as ASCII.
        $a = "\x31\x32\x33"; // "123"
        $b = "\x32\x31\x30"; // "210"

        // Pack them as Bytes to preserve raw bytes, then compare
        $result = Tuple::compare([new Bytes($a)], [new Bytes($b)]);
        self::assertSame(-1, $result);

        // Also test strings that might look numeric
        $result2 = Tuple::compare(["123"], ["210"]);
        self::assertSame(-1, $result2);

        // Test equality for same numeric-looking string
        $result3 = Tuple::compare(["123"], ["123"]);
        self::assertSame(0, $result3);
    }

    #[Test]
    public function compareMatchesPackedByteOrdering(): void
    {
        $pairs = [
            [[1], [2]],
            [['a'], ['b']],
            [[null], [0]],
            [[false], [true]],
            [[-1], [0]],
            [[new Bytes('a')], ['a']],
        ];

        foreach ($pairs as [$t1, $t2]) {
            $packed1 = Tuple::pack($t1);
            $packed2 = Tuple::pack($t2);
            $byteComparison = $packed1 <=> $packed2;
            $tupleComparison = Tuple::compare($t1, $t2);
            self::assertSame(
                $byteComparison <=> 0,
                $tupleComparison,
                sprintf(
                    'Tuple::compare() should match packed byte ordering for %s vs %s',
                    json_encode($t1),
                    json_encode($t2),
                ),
            );
        }
    }
}
