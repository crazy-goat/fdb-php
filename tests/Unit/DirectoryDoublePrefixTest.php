<?php

declare(strict_types=1);

namespace CrazyGoat\FoundationDB\Tests\Unit;

use CrazyGoat\FoundationDB\Directory\DirectoryLayer;
use CrazyGoat\FoundationDB\Directory\DirectorySubspace;
use CrazyGoat\FoundationDB\Subspace;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;

/**
 * Unit tests for the double-prepend bug from issue #42: the prefix stored
 * in the directory node already includes the content subspace key, but
 * `contentsOfNode()` and `createInternal()` prepended it again when
 * constructing the returned `DirectorySubspace`.  Inside a partition
 * (non-empty content subspace) this produced a doubled prefix (P + P + key
 * instead of P + key), landing directories at the wrong location and
 * breaking cross-binding interop.
 *
 * These tests exercise `contentsOfNode()` via reflection with a
 * pre-built `Subspace` so no live `Transaction` is needed.  The
 * `createInternal()` path (which requires a real transaction) is covered
 * by the integration tests in `tests/Integration/DirectoryTest.php`.
 */
final class DirectoryDoublePrefixTest extends TestCase
{
    /**
     * When the content subspace is empty (the default), the returned
     * DirectorySubspace rawPrefix must equal the stored node prefix
     * exactly — no content subspace key may be prepended again.
     */
    #[Test]
    public function contentsOfNodeWithEmptyContentSubspaceDoesNotDoublePrepend(): void
    {
        $dir = new DirectoryLayer();
        $nodePrefix = "\x01\x02\x03";
        $node = $this->buildNode($dir, $nodePrefix);

        $result = $this->invokeContentsOfNode($dir, $node, ['app', 'test'], '');

        self::assertInstanceOf(DirectorySubspace::class, $result);
        self::assertSame($nodePrefix, $result->rawPrefix);
    }

    /**
     * When the content subspace is non-empty (as inside a partition),
     * the stored prefix already starts with the content subspace key.
     * The returned DirectorySubspace must use that stored prefix
     * directly without prepending the content subspace key a second time.
     */
    #[Test]
    public function contentsOfNodeWithNonEmptyContentSubspaceDoesNotDoublePrepend(): void
    {
        $contentSubspaceKey = "\xFEpartition";
        $contentSubspace = new Subspace(rawPrefix: $contentSubspaceKey);
        $nodeSubspace = new Subspace(rawPrefix: "\xFE");
        $dir = new DirectoryLayer($nodeSubspace, $contentSubspace);

        // The stored prefix already includes the content subspace key
        // (createInternal composes it as contentSubspace->key() . allocatedPrefix).
        $rawAllocated = "\x01\xAA\xBB";
        $storedPrefix = $contentSubspaceKey . $rawAllocated;
        $node = $this->buildNode($dir, $storedPrefix);

        $result = $this->invokeContentsOfNode($dir, $node, ['app', 'inner'], '');

        self::assertInstanceOf(DirectorySubspace::class, $result);
        self::assertSame($storedPrefix, $result->rawPrefix);
    }

    /**
     * The partition branch of contentsOfNode() already passed $prefix
     * directly; verify it stays consistent with the non-partition branch.
     */
    #[Test]
    public function contentsOfNodePartitionBranchUsesPrefixDirectly(): void
    {
        $contentSubspaceKey = "\xFEpartition";
        $contentSubspace = new Subspace(rawPrefix: $contentSubspaceKey);
        $nodeSubspace = new Subspace(rawPrefix: "\xFE");
        $dir = new DirectoryLayer($nodeSubspace, $contentSubspace);

        $rawAllocated = "\x01\xCC\xDD";
        $storedPrefix = $contentSubspaceKey . $rawAllocated;
        $node = $this->buildNode($dir, $storedPrefix);

        $result = $this->invokeContentsOfNode($dir, $node, ['app', 'part'], 'partition');

        // DirectoryPartition blocks key() but rawPrefix is accessible
        self::assertSame($storedPrefix, $result->rawPrefix);
    }

    /**
     * Build a Subspace that looks like a node in the given DirectoryLayer's
     * nodeSubspace, containing the given prefix.  The node key is:
     * nodeSubspace->key() . Tuple::pack([$prefix]).
     */
    private function buildNode(DirectoryLayer $dir, string $prefix): Subspace
    {
        $ref = new \ReflectionProperty(DirectoryLayer::class, 'nodeSubspace');
        /** @var Subspace $nodeSubspace */
        $nodeSubspace = $ref->getValue($dir);

        return $nodeSubspace->subspace($prefix);
    }

    /**
     * @param list<string> $path
     */
    private function invokeContentsOfNode(
        DirectoryLayer $dir,
        Subspace $node,
        array $path,
        string $layer,
    ): DirectorySubspace {
        $method = new ReflectionMethod(DirectoryLayer::class, 'contentsOfNode');

        /** @var DirectorySubspace */
        return $method->invoke($dir, $node, $path, $layer);
    }
}
