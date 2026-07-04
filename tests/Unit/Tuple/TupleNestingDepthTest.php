<?php

declare(strict_types=1);

namespace CrazyGoat\FoundationDB\Tests\Unit\Tuple;

use CrazyGoat\FoundationDB\Tuple\Tuple;
use CrazyGoat\FoundationDB\Tuple\Versionstamp;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Tests for the explicit nesting-depth bound introduced for issue #39.
 *
 * Before the fix, the decoder and encoder recursed without limit on
 * nested tuples (`TYPE_NESTED` on the wire and PHP arrays in the API).
 * A stored value consisting of hundreds of kilobytes of `0x05` bytes
 * could exhaust the call stack and abort the process — a denial-of-
 * service vector when an application unpacks data produced by a
 * less-trusted writer.
 *
 * The fix introduces `Tuple::MAX_NESTING_DEPTH = 100`, enforced
 * symmetrically on the encode and decode paths of `encode/decodeElement`
 * and `encode/decodeNestedTuple`, plus the encode-side helpers
 * `findVersionstampOffset`, `countVersionstamps` and
 * `elementHasIncompleteVersionstamp`.
 *
 * The guard is inclusive: a payload whose deepest recursion reaches
 * exactly `MAX_NESTING_DEPTH` is accepted, and a payload that would
 * recurse to `MAX_NESTING_DEPTH + 1` is rejected with
 * `\InvalidArgumentException` before the offending call. The exact
 * depth accounting varies per public method (PHP-array input vs. wire
 * bytes vs. internal walker); the boundary in each test is calibrated
 * to the wire-format symmetry so pack→unpack is balanced and the
 * guard fires exactly at the limit.
 */
final class TupleNestingDepthTest extends TestCase
{
    #[Test]
    public function maxNestingDepthConstantIsPublicAndReasonable(): void
    {
        // Must be exposed for callers to assert against.
        self::assertSame(100, Tuple::MAX_NESTING_DEPTH);
    }

    #[Test]
    public function unpackAcceptsPayloadAtExactlyMaximumDepth(): void
    {
        // A sequence of MAX_NESTING_DEPTH `\x05` bytes followed by the
        // same number of `\x00` terminators reaches exactly
        // MAX_NESTING_DEPTH nested-tuple layers on the wire. The
        // decoder must accept it.
        $data = str_repeat("\x05", Tuple::MAX_NESTING_DEPTH)
              . str_repeat("\x00", Tuple::MAX_NESTING_DEPTH);

        $result = Tuple::unpack($data);
        self::assertCount(1, $result);
        self::assertSame(Tuple::MAX_NESTING_DEPTH, $this->arrayLayersOf($result[0]));
    }

    #[Test]
    public function unpackRejectsPayloadAtMaximumDepthPlusOne(): void
    {
        // One more `\x05\x00` pair — the next recursion step is
        // forbidden and must throw \InvalidArgumentException, NOT
        // consume the stack.
        $data = str_repeat("\x05", Tuple::MAX_NESTING_DEPTH + 1)
              . str_repeat("\x00", Tuple::MAX_NESTING_DEPTH + 1);

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('nesting depth');
        Tuple::unpack($data);
    }

    #[Test]
    public function unpackRejectsPoCMaliciousBufferInsteadOfCrashing(): void
    {
        // The original PoC in the issue: hundreds of kilobytes of
        // TYPE_NESTED bytes would drive the call stack until the
        // process aborts. The fix must reject the same buffer
        // deterministically in milliseconds.
        $data = str_repeat("\x05", 300_000);

        $start = hrtime(true);
        try {
            Tuple::unpack($data);
            self::fail('Expected \\InvalidArgumentException was not thrown');
        } catch (\InvalidArgumentException $e) {
            self::assertStringContainsString('nesting depth', $e->getMessage());
            $elapsedMs = (hrtime(true) - $start) / 1_000_000;
            self::assertLessThan(1000.0, $elapsedMs, 'unpack() must reject in well under 1s');
        }
    }

    #[Test]
    public function packAcceptsPhpArrayAtExactlyMaximumDepth(): void
    {
        // pack() with MAX − 1 inner wrappers produces MAX \x05 wire
        // bytes in total — the deepest `[0]` becomes
        // `\x05\x14\x00` (a nested tuple containing a single integer).
        // At exactly this depth pack() does NOT throw and unpack()
        // round-trips the byte sequence into the same shape.
        $wraps = Tuple::MAX_NESTING_DEPTH - 1;
        $tree = $this->nestedArrayWithDepth($wraps, 0);
        /** @phpstan-ignore argument.type */
        $packed = Tuple::pack($tree);
        self::assertNotSame('', $packed);
        $decoded = Tuple::unpack($packed);
        self::assertCount(1, $decoded);
        self::assertSame(
            $wraps,
            $this->arrayLayersOf($decoded[0]),
        );
    }

    #[Test]
    public function packRejectsPhpArrayAtMaximumDepthPlusOne(): void
    {
        // One additional wrapper beyond the symmetric boundary — must
        // throw on the encoder side. The leaf array of `[value]`
        // pushes the recursion depth over MAX while encoding it.
        $input = $this->nestedArrayWithDepth(Tuple::MAX_NESTING_DEPTH + 1, 0);

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('nesting depth');
        /** @phpstan-ignore argument.type */
        Tuple::pack($input);
    }

    #[Test]
    public function packRoundtripsAtBoundaryThroughUnpack(): void
    {
        // Sanity: pack→unpack round-trip must work for the maximal
        // accepted structure on both sides of the wire boundary.
        $wraps = Tuple::MAX_NESTING_DEPTH - 1;
        $tree = $this->nestedArrayWithDepth($wraps, 0);
        /** @phpstan-ignore argument.type */
        $packed = Tuple::pack($tree);
        $decoded = Tuple::unpack($packed);
        $cursor = $decoded[0];
        for ($i = 0; $i < $wraps; $i++) {
            self::assertIsArray($cursor);
            $cursor = $cursor[0];
        }
        self::assertSame(0, $cursor);
    }

    #[Test]
    public function hasIncompleteVersionstampRejectsDeeplyNestedPhpArray(): void
    {
        // `hasIncompleteVersionstamp()` walks the PHP structure
        // without emitting a wire wrapper for the leaf `[scalar]`, so
        // its boundary is exactly MAX_NESTING_DEPTH + 1 wrappers (one
        // more than pack because the leaf encoding step is skipped).
        // At MAX + 2 wrappers with a Versionstamp at the deepest
        // slot, the guard fires from the very next recursion step.
        $vs = Versionstamp::incomplete();
        $deep = $this->nestedArrayWithDepth(Tuple::MAX_NESTING_DEPTH + 2, $vs);

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('nesting depth');
        /** @phpstan-ignore argument.type */
        Tuple::hasIncompleteVersionstamp($deep);
    }

    #[Test]
    public function packWithVersionstampRejectsDeeplyNestedPhpArray(): void
    {
        // packWithVersionstamp() internally calls the encoder
        // (encodeElement + findVersionstampOffset) for each element
        // and the versionstamp counter, mirroring pack()'s boundary.
        // MAX + 1 wrappers must throw.
        $deep = $this->nestedArrayWithDepth(
            Tuple::MAX_NESTING_DEPTH + 1,
            Versionstamp::incomplete(7),
        );

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('nesting depth');
        /** @phpstan-ignore argument.type */
        Tuple::packWithVersionstamp($deep);
    }

    #[Test]
    public function hasIncompleteVersionstampAllowsBoundaryDepth(): void
    {
        // Mirror image of the rejection test — at the limit the
        // walker still returns the expected boolean instead of
        // throwing.
        $deep = $this->nestedArrayWithDepth(
            Tuple::MAX_NESTING_DEPTH + 1,
            Versionstamp::incomplete(),
        );

        /** @phpstan-ignore argument.type */
        self::assertTrue(Tuple::hasIncompleteVersionstamp($deep));
    }

    #[Test]
    public function shallowNestingRoundtripsAndIsNotMisclassified(): void
    {
        // Sanity: a small/deep-but-legitimate tuple still works
        // without hitting the guard. `pack()` interprets its argument
        // as the list of top-level tuple elements; the implicit
        // outermost `[ ]` is NOT encoded. So a literal
        // `['a', [1, 2]]` round-trips into the same shape even though
        // a naive reading would treat the outer `[ ]` as another
        // nested-tuple layer.
        $input = ['a', [1, 2]];
        $packed = Tuple::pack($input);
        $decoded = Tuple::unpack($packed);

        self::assertSame($input, $decoded);
    }

    #[Test]
    public function encodeSideHelperThrowsOnDeepArraysEvenWithoutVersionstamps(): void
    {
        // `findVersionstampOffset` and `countVersionstamps` recurse
        // through nested PHP arrays WITHOUT looking at the element
        // typecode. Even with a payload that contains no Versionstamp
        // at all, an input tree at MAX + 2 wrappers must trip the
        // guard so a malicious caller cannot bypass the limit by
        // handing in arrays made entirely of scalars.
        $deep = $this->nestedArrayWithDepth(Tuple::MAX_NESTING_DEPTH + 2, 0);

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('nesting depth');
        // countVersionstamps() doesn't reference the leaf; the guard
        // still fires while walking the structure.
        $count = 0;
        $reflect = new \ReflectionClass(Tuple::class);
        $reflect->getMethod('countVersionstamps')->getClosure()($deep, $count, 0);
    }

    #[Test]
    public function decodeNestedTupleHelperThrowsAtEntryBeyondLimit(): void
    {
        // The wire payload has MAX + 1 `\x05` bytes (one more than
        // the limit). Direct `decodeNestedTuple()` entry must throw
        // even at `$depth = MAX` — a future maintainer forgetting to
        // forward the depth parameter (or accidentally starting
        // from `$depth = 0` at every entry site) would let this slip
        // past the guard.
        $data = str_repeat("\x05", Tuple::MAX_NESTING_DEPTH + 1)
              . str_repeat("\x00", Tuple::MAX_NESTING_DEPTH + 1);

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('nesting depth');
        $reflect = new \ReflectionClass(Tuple::class);
        $reflect->getMethod('decodeNestedTuple')->getClosure()(
            $data,
            0,
            strlen($data),
            Tuple::MAX_NESTING_DEPTH,
        );
    }

    /**
     * Build a PHP array nested `$wraps` array-layers deep around a
     * leaf value. The result has `$wraps + 1` array layers including
     * the `[leaf]` slot.
     *
     *   `nestedArrayWithDepth(0, $x) -> [$x]`
     *   `nestedArrayWithDepth(1, $x) -> [[$x]]`
     *   `nestedArrayWithDepth(3, $x) -> [[[[$x]]]]`
     *
     * @return list<mixed>
     */
    private function nestedArrayWithDepth(int $wraps, mixed $leaf): array
    {
        $tree = [$leaf];
        for ($i = 0; $i < $wraps; $i++) {
            $tree = [$tree];
        }

        return $tree;
    }

    /**
     * Count array layers from $value down to the first non-array child.
     * `arrayLayersOf(0) === 0`, `arrayLayersOf([0]) === 1`,
     * `arrayLayersOf([[0]]) === 2`.
     */
    private function arrayLayersOf(mixed $value): int
    {
        if (!is_array($value)) {
            return 0;
        }
        return 1 + $this->arrayLayersOf($value[0] ?? null);
    }
}
