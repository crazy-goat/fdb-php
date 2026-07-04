<?php

declare(strict_types=1);

namespace CrazyGoat\FoundationDB\Tests\Unit;

use CrazyGoat\FoundationDB\Directory\DirectoryLayer;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;

/**
 * Unit tests for the explicit move-bounds validation that fixes the
 * silent-permissive / no-validation bug from issue #40: `move()` used to
 * accept any non-empty `oldPath`/`newPath` pair, including self-subtree
 * moves that create a cycle in the directory index and partition-layer
 * crossings that silently re-parent a prefix. The fix routes every
 * `move()` call through `validateMoveBounds()` (path-only checks) and
 * `assertSamePartitionLayer()` (layer-aware check) and lets both throw
 * `DirectoryException` with a printable rendering of the offending paths
 * instead of silently writing the broken state into the subdirs index.
 *
 * The validation logic is factored into static-friendly private helpers
 * (`validateMoveBounds()`, `assertSamePartitionLayer()`,
 * `printablePath()`, `printableSegment()`) so it can be exercised in pure
 * PHP without needing a live `Transaction` (which is bound to the native
 * FFI client and cannot be stubbed). The Transaction-bound happy/sad
 * paths are covered by integration tests in
 * `tests/Integration/DirectoryTest.php`.
 */
final class DirectoryMoveBoundsValidationTest extends TestCase
{
    /**
     * @param list<string> $oldPath
     * @param list<string> $newPath
     */
    private function validateBounds(array $oldPath, array $newPath): void
    {
        $method = new ReflectionMethod(DirectoryLayer::class, 'validateMoveBounds');
        $method->invoke(new DirectoryLayer(), $oldPath, $newPath);
    }

    /**
     * @param list<string> $oldPath
     * @param list<string> $newPath
     */
    private function assertSamePartitionLayer(
        string $oldLayer,
        string $newParentLayer,
        array $oldPath,
        array $newPath,
    ): void {
        $method = new ReflectionMethod(DirectoryLayer::class, 'assertSamePartitionLayer');
        $method->invoke(new DirectoryLayer(), $oldLayer, $newParentLayer, $oldPath, $newPath);
    }

    /**
     * @param list<string> $path
     */
    private function printablePath(array $path): string
    {
        $method = new ReflectionMethod(DirectoryLayer::class, 'printablePath');

        /** @var string */
        return $method->invoke(new DirectoryLayer(), $path);
    }

    private function printableSegment(string $value): string
    {
        $method = new ReflectionMethod(DirectoryLayer::class, 'printableSegment');

        /** @var string */
        return $method->invoke(new DirectoryLayer(), $value);
    }

    // -- printableSegment / printablePath boundaries --------------------------

    #[Test]
    public function printableSegmentRendersPrintableAsciiUnchanged(): void
    {
        self::assertSame('abc123', $this->printableSegment('abc123'));
    }

    #[Test]
    public function printableSegmentRendersSpaceAndTildeVerbatim(): void
    {
        self::assertSame(' ~', $this->printableSegment(' ~'));
    }

    #[Test]
    public function printableSegmentEscapesForwardSlashBecausePathsAreSlashJoined(): void
    {
        // A literal slash in a segment would be ambiguous when paths
        // are joined with "/" in printablePath(); escape it so the
        // segment boundary is round-trippable from the exception text.
        self::assertSame('a\\x2Fb', $this->printableSegment('a/b'));
    }

    #[Test]
    public function printableSegmentEscapesControlAndNullBytes(): void
    {
        self::assertSame('\\x00', $this->printableSegment("\x00"));
        self::assertSame('\\x01\\x1F', $this->printableSegment("\x01\x1F"));
    }

    #[Test]
    public function printableSegmentEscapesDelAndHighBytes(): void
    {
        self::assertSame('\\x7F', $this->printableSegment("\x7F"));
        self::assertSame('\\x80\\xFF', $this->printableSegment("\x80\xFF"));
    }

    #[Test]
    public function printablePathJoinsSegmentsWithSlash(): void
    {
        self::assertSame('a/b/c', $this->printablePath(['a', 'b', 'c']));
    }

    #[Test]
    public function printablePathEscapesEachSegmentIndependently(): void
    {
        // The middle segment contains a slash; printableSegment must
        // escape it so the segment boundary is unambiguous when paths
        // are joined with "/".
        self::assertSame('a/b\\x2Fc/c', $this->printablePath(['a', 'b/c', 'c']));
    }

    #[Test]
    public function printablePathRendersSingleSegment(): void
    {
        self::assertSame('root', $this->printablePath(['root']));
    }

    // -- validateMoveBounds: rejection paths ----------------------------------

    #[Test]
    public function validateBoundsRejectsIdenticalPaths(): void
    {
        $this->expectException(\CrazyGoat\FoundationDB\Directory\DirectoryException::class);
        $this->expectExceptionMessage('source and destination paths are identical (a/b)');

        $this->validateBounds(['a', 'b'], ['a', 'b']);
    }

    #[Test]
    public function validateBoundsRejectsIdenticalSingleSegmentPaths(): void
    {
        $this->expectException(\CrazyGoat\FoundationDB\Directory\DirectoryException::class);
        $this->expectExceptionMessage('source and destination paths are identical (root)');

        $this->validateBounds(['root'], ['root']);
    }

    #[Test]
    public function validateBoundsRejectsMoveIntoImmediateSubdirectory(): void
    {
        // ['a'] -> ['a','b'] creates a cycle: a now owns b, and b's content
        // lives under a. After the move, '/a/b' resolves to the old node,
        // and looking up '/a' from the subdirs index now points to a node
        // that is itself named 'a' — visiting 'a/b/c' is therefore a
        // candidate for a self-rewriting path.
        $this->expectException(\CrazyGoat\FoundationDB\Directory\DirectoryException::class);
        $this->expectExceptionMessage("destination path a/b is inside the source path's subtree a");

        $this->validateBounds(['a'], ['a', 'b']);
    }

    #[Test]
    public function validateBoundsRejectsMoveIntoDeeperSubdirectory(): void
    {
        $this->expectException(\CrazyGoat\FoundationDB\Directory\DirectoryException::class);
        $this->expectExceptionMessage("destination path a/b/c is inside the source path's subtree a/b");

        $this->validateBounds(['a', 'b'], ['a', 'b', 'c']);
    }

    #[Test]
    public function validateBoundsRejectsMoveIntoMultiLevelSubtree(): void
    {
        $this->expectException(\CrazyGoat\FoundationDB\Directory\DirectoryException::class);
        $this->expectExceptionMessage("destination path x/y/z is inside the source path's subtree x/y");

        $this->validateBounds(['x', 'y'], ['x', 'y', 'z']);
    }

    #[Test]
    public function validateBoundsRejectsWhenSourceIsPrefixOfShorterDestination(): void
    {
        // array_slice for len < oldPath is empty, so ['a','b'] -> ['a'] is
        // a rename-into-ancestor, not a self-subtree move. This must NOT
        // be flagged as a cycle (the destination parent ('a'-segment-0)
        // becomes a regular parent that didn't previously exist in oldPath).
        self::expectNotToPerformAssertions();
        $this->validateBounds(['a', 'b'], ['a']);
    }

    #[Test]
    public function validateBoundsRejectsSourceDeeperThanMaxDepth(): void
    {
        $this->expectException(\CrazyGoat\FoundationDB\Directory\DirectoryException::class);
        $this->expectExceptionMessage('source path exceeds maximum depth');

        // 65 segments — one past the documented MAX_MOVE_PATH_DEPTH (64).
        $path = [];
        for ($i = 0; $i < 65; $i++) {
            $path[] = 's' . $i;
        }
        $this->validateBounds($path, ['somewhere_else']);
    }

    #[Test]
    public function validateBoundsRejectsDestinationDeeperThanMaxDepth(): void
    {
        $this->expectException(\CrazyGoat\FoundationDB\Directory\DirectoryException::class);
        $this->expectExceptionMessage('destination path exceeds maximum depth');

        $path = [];
        for ($i = 0; $i < 65; $i++) {
            $path[] = 'd' . $i;
        }
        $this->validateBounds(['source'], $path);
    }

    #[Test]
    public function validateBoundsAcceptsExactlyMaxDepthOnEitherSide(): void
    {
        self::expectNotToPerformAssertions();

        $source = [];
        $destination = [];
        for ($i = 0; $i < 64; $i++) {
            $source[] = 'src' . $i;
            // Distinct path with a different first segment so it's
            // neither identical nor a prefix of the source.
            $destination[] = $i === 0 ? 'dst' : 'src' . $i;
        }

        $this->validateBounds($source, $destination);
    }

    #[Test]
    public function validateBoundsRejectsErrorMessagesWithBinarySegmentsPrintedSafely(): void
    {
        try {
            // A segment with a slash + control byte + non-ASCII byte
            // must be escaped, not echoed, so exception text is safe.
            $this->validateBounds(["naughty\x01/b"], ["naughty\x01/b", 'child']);
            self::fail('Expected DirectoryException was not thrown.');
        } catch (\CrazyGoat\FoundationDB\Directory\DirectoryException $e) {
            self::assertStringContainsString('naughty\\x01\\x2Fb/child', $e->getMessage());
            self::assertStringContainsString("inside the source path's subtree naughty\\x01\\x2Fb", $e->getMessage());
        }
    }

    // -- validateMoveBounds: accepted paths -----------------------------------

    #[Test]
    public function validateBoundsAcceptsSiblingMove(): void
    {
        self::expectNotToPerformAssertions();
        $this->validateBounds(['a', 'b'], ['a', 'c']);
    }

    #[Test]
    public function validateBoundsAcceptsRenameToSiblingAtRoot(): void
    {
        self::expectNotToPerformAssertions();
        $this->validateBounds(['old'], ['new']);
    }

    #[Test]
    public function validateBoundsAcceptsRenameIntoUncle(): void
    {
        // ['a','b'] -> ['x','y'] — neither is a prefix of the other; no cycle.
        self::expectNotToPerformAssertions();
        $this->validateBounds(['a', 'b'], ['x', 'y']);
    }

    #[Test]
    public function validateBoundsAcceptsMoveToParentAtDifferentDepthWhenNotEqualPrefix(): void
    {
        // ['a','b','c'] -> ['z'] is fine: 'z' is unrelated to oldPath.
        self::expectNotToPerformAssertions();
        $this->validateBounds(['a', 'b', 'c'], ['z']);
    }

    // -- assertSamePartitionLayer --------------------------------------------

    #[Test]
    public function assertSamePartitionLayerAcceptsEqualNonPartitionLayers(): void
    {
        self::expectNotToPerformAssertions();
        $this->assertSamePartitionLayer('', '', ['a'], ['b']);
    }

    #[Test]
    public function assertSamePartitionLayerAcceptsEqualPartitionLayers(): void
    {
        self::expectNotToPerformAssertions();
        $this->assertSamePartitionLayer('partition', 'partition', ['p', 'a'], ['p', 'b']);
    }

    #[Test]
    public function assertSamePartitionLayerAcceptsTopLevelToTopLevel(): void
    {
        self::expectNotToPerformAssertions();
        $this->assertSamePartitionLayer('', '', ['a'], ['a', 'b', 'c', 'd']);
    }

    #[Test]
    public function assertSamePartitionLayerRejectsMismatchWithPartitionLayerOnEitherSide(): void
    {
        $this->expectException(\CrazyGoat\FoundationDB\Directory\DirectoryException::class);
        // The message renders the source side as `(parent layer "X")`
        // and the destination side as `whose parent layer is "X"`.
        $this->expectExceptionMessageMatches(
            '/\(parent layer "partition"\).+whose parent layer is ""/',
        );

        $this->assertSamePartitionLayer('partition', '', ['a'], ['b']);
    }

    #[Test]
    public function assertSamePartitionLayerRejectsReverseMismatchToPartition(): void
    {
        $this->expectException(\CrazyGoat\FoundationDB\Directory\DirectoryException::class);
        $this->expectExceptionMessageMatches(
            '/\(parent layer ""\).+whose parent layer is "partition"/',
        );

        $this->assertSamePartitionLayer('', 'partition', ['a', 'b'], ['p', 'x']);
    }

    #[Test]
    public function assertSamePartitionLayerDistinguishesCustomLayers(): void
    {
        $this->expectException(\CrazyGoat\FoundationDB\Directory\DirectoryException::class);
        $this->expectExceptionMessageMatches(
            '/\(parent layer "alpha"\).+whose parent layer is "beta"/',
        );

        $this->assertSamePartitionLayer('alpha', 'beta', ['app', 'a'], ['app', 'b']);
    }

    #[Test]
    public function assertSamePartitionLayerErrorRendersPathsAndLayersSafely(): void
    {
        try {
            $this->assertSamePartitionLayer(
                'partition',
                '',
                ["weird\x01/seg"],
                ['plain', 'dest'],
            );
            self::fail('Expected DirectoryException was not thrown.');
        } catch (\CrazyGoat\FoundationDB\Directory\DirectoryException $e) {
            self::assertStringContainsString('weird\\x01\\x2Fseg', $e->getMessage());
            self::assertStringContainsString('plain/dest', $e->getMessage());
            // Both forms of the parent-layer label must appear in the
            // rendering — the source side in parentheses and the
            // destination side as "whose parent layer is".
            self::assertStringContainsString('(parent layer "partition")', $e->getMessage());
            self::assertStringContainsString('whose parent layer is ""', $e->getMessage());
        }
    }
}
