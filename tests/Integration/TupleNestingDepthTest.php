<?php

declare(strict_types=1);

namespace CrazyGoat\FoundationDB\Tests\Integration;

use CrazyGoat\FoundationDB\Tuple\Tuple;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Integration tests for the explicit nesting-depth bound on the
 * tuple layer (issue #39).
 *
 * These tests run against a live 5-node FoundationDB cluster (see
 * `.github/workflows/ci.yml`). They confirm that:
 *
 *   - a tree packed at the wire-boundary round-trips through the
 *     cluster unchanged, so the limit does not break legitimate
 *     deeply-nested user data;
 *   - a deliberately oversized payload (the original 300 KB
 *     \x05-only PoC) is rejected with \InvalidArgumentException the
 *     moment it is read back into PHP, instead of bottlenecking
 *     the application on a runaway encoder/decoder.
 */
final class TupleNestingDepthTest extends TestCase
{
    use DatabaseCleanupTrait;

    #[Test]
    public function roundtripsAtBoundaryUnderRealCluster(): void
    {
        // MAX_NESTING_DEPTH − 1 wrappers around `0` produces exactly
        // MAX wire bytes and exercises the entire encode/decode
        // pipeline through libfdb_c.
        $wraps = Tuple::MAX_NESTING_DEPTH - 1;

        $key = 'tuple_nesting/max';
        $tree = $this->buildNestedArray($wraps, 0);
        /** @phpstan-ignore argument.type */
        $this->getDatabase()->set($key, Tuple::pack($tree));
        $recovered = Tuple::unpack((string) $this->getDatabase()->get($key));

        self::assertCount(1, $recovered);
        self::assertSame(
            $wraps,
            $this->arrayLayersOf($recovered[0]),
        );
        // Walk down to the leaf and confirm the integer 0 round-
        // tripped exactly.
        $cursor = $recovered[0];
        for ($i = 0; $i < $wraps; $i++) {
            self::assertIsArray($cursor);
            $cursor = $cursor[0];
        }
        self::assertSame(0, $cursor);
    }

    #[Test]
    public function readsPoCMaliciousBufferViaClusterWithoutCrashing(): void
    {
        // The original PoC from issue #39: hundreds of kilobytes of
        // TYPE_NESTED bytes would drive the call stack until the
        // process aborted. The fix must reject the same buffer
        // deterministically when the application tries to read it
        // back from a live cluster, in well under a second.
        $key = 'tuple_nesting/poc';
        $payload = str_repeat("\x05", 300_000);
        $this->getDatabase()->set($key, $payload);

        $raw = (string) $this->getDatabase()->get($key);
        self::assertSame($payload, $raw, 'FDB returns the literal bytes we wrote');

        $start = hrtime(true);
        try {
            Tuple::unpack($raw);
            self::fail('Expected \\InvalidArgumentException was not thrown');
        } catch (\InvalidArgumentException $e) {
            self::assertStringContainsString('nesting depth', $e->getMessage());
            $elapsedMs = (hrtime(true) - $start) / 1_000_000;
            self::assertLessThan(
                1000.0,
                $elapsedMs,
                'unpack() of an oversized buffer must reject in well under 1s',
            );
        }
    }

    /**
     * Build a PHP array nested `$wraps` array-layers deep around a
     * leaf value. The result has `$wraps + 1` array layers including
     * the `[$leaf]` slot.
     *
     *   `buildNestedArray(0, $x) -> [$x]`
     *   `buildNestedArray(1, $x) -> [[$x]]`
     *   `buildNestedArray(3, $x) -> [[[[$x]]]]`
     *
     * @return list<mixed>
     */
    private function buildNestedArray(int $wraps, mixed $leaf): array
    {
        $tree = [$leaf];
        for ($i = 0; $i < $wraps; $i++) {
            $tree = [$tree];
        }

        return $tree;
    }

    /**
     * Count array layers from $value down to the first non-array child.
     */
    private function arrayLayersOf(mixed $value): int
    {
        if (!is_array($value)) {
            return 0;
        }
        return 1 + $this->arrayLayersOf($value[0] ?? null);
    }
}
