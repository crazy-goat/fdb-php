<?php

declare(strict_types=1);

namespace CrazyGoat\FoundationDB\Tests\Integration;

use CrazyGoat\FoundationDB\Directory\DirectoryException;
use CrazyGoat\FoundationDB\Directory\DirectoryLayer;
use CrazyGoat\FoundationDB\Directory\DirectoryPartition;
use CrazyGoat\FoundationDB\Directory\DirectorySubspace;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class DirectoryTest extends TestCase
{
    use DatabaseCleanupTrait;

    private DirectoryLayer $dir;

    protected function setUp(): void
    {
        parent::setUp();
        $this->dir = new DirectoryLayer();
    }

    #[Test]
    public function createDirectory(): void
    {
        $result = $this->dir->create($this->getDatabase(), ['app', 'users']);

        self::assertInstanceOf(DirectorySubspace::class, $result);
        self::assertSame(['app', 'users'], $result->getPath());
    }

    #[Test]
    public function createDirectoryWithLayer(): void
    {
        $result = $this->dir->create($this->getDatabase(), ['app', 'data'], 'my_layer');

        self::assertSame('my_layer', $result->getLayer());
    }

    #[Test]
    public function createDuplicateDirectoryThrows(): void
    {
        $this->dir->create($this->getDatabase(), ['app', 'dup']);

        $this->expectException(DirectoryException::class);
        $this->expectExceptionMessage('Directory already exists');

        $this->dir->create($this->getDatabase(), ['app', 'dup']);
    }

    #[Test]
    public function openExistingDirectory(): void
    {
        $created = $this->dir->create($this->getDatabase(), ['app', 'openme']);
        $opened = $this->dir->open($this->getDatabase(), ['app', 'openme']);

        self::assertSame($created->rawPrefix, $opened->rawPrefix);
        self::assertSame(['app', 'openme'], $opened->getPath());
    }

    #[Test]
    public function openNonExistentDirectoryThrows(): void
    {
        $this->expectException(DirectoryException::class);
        $this->expectExceptionMessage('Directory does not exist');

        $this->dir->open($this->getDatabase(), ['nonexistent']);
    }

    #[Test]
    public function openWithWrongLayerThrows(): void
    {
        $this->dir->create($this->getDatabase(), ['app', 'layered'], 'layer_a');

        $this->expectException(DirectoryException::class);
        $this->expectExceptionMessage('different layer');

        $this->dir->open($this->getDatabase(), ['app', 'layered'], 'layer_b');
    }

    #[Test]
    public function createOrOpenCreatesNew(): void
    {
        $result = $this->dir->createOrOpen($this->getDatabase(), ['app', 'cor_new']);

        self::assertInstanceOf(DirectorySubspace::class, $result);
        self::assertSame(['app', 'cor_new'], $result->getPath());
    }

    #[Test]
    public function createOrOpenOpensExisting(): void
    {
        $created = $this->dir->create($this->getDatabase(), ['app', 'cor_existing']);
        $opened = $this->dir->createOrOpen($this->getDatabase(), ['app', 'cor_existing']);

        self::assertSame($created->rawPrefix, $opened->rawPrefix);
    }

    #[Test]
    public function createOrOpenWithMismatchedLayerThrows(): void
    {
        $this->dir->create($this->getDatabase(), ['app', 'cor_layer'], 'layer_a');

        $this->expectException(DirectoryException::class);
        $this->expectExceptionMessage('different layer');

        $this->dir->createOrOpen($this->getDatabase(), ['app', 'cor_layer'], 'layer_b');
    }

    #[Test]
    public function existsReturnsTrueForExisting(): void
    {
        $this->dir->create($this->getDatabase(), ['app', 'exists_test']);

        self::assertTrue($this->dir->exists($this->getDatabase(), ['app', 'exists_test']));
    }

    #[Test]
    public function existsReturnsFalseForNonExistent(): void
    {
        self::assertFalse($this->dir->exists($this->getDatabase(), ['nonexistent']));
    }

    #[Test]
    public function listSubdirectories(): void
    {
        $this->dir->create($this->getDatabase(), ['app', 'list_a']);
        $this->dir->create($this->getDatabase(), ['app', 'list_b']);
        $this->dir->create($this->getDatabase(), ['app', 'list_c']);

        $result = $this->dir->list($this->getDatabase(), ['app']);

        self::assertContains('list_a', $result);
        self::assertContains('list_b', $result);
        self::assertContains('list_c', $result);
    }

    #[Test]
    public function listEmptyDirectory(): void
    {
        $this->dir->create($this->getDatabase(), ['app', 'empty_dir']);

        $result = $this->dir->list($this->getDatabase(), ['app', 'empty_dir']);

        self::assertSame([], $result);
    }

    #[Test]
    public function listRootDirectory(): void
    {
        $this->dir->create($this->getDatabase(), ['root_a']);
        $this->dir->create($this->getDatabase(), ['root_b']);

        $result = $this->dir->list($this->getDatabase());

        self::assertContains('root_a', $result);
        self::assertContains('root_b', $result);
    }

    #[Test]
    public function moveDirectory(): void
    {
        $this->dir->create($this->getDatabase(), ['app', 'move_src']);

        $this->getDatabase()->set(
            $this->dir->open($this->getDatabase(), ['app', 'move_src'])->pack(['key1']),
            'value1',
        );

        $moved = $this->dir->move($this->getDatabase(), ['app', 'move_src'], ['app', 'move_dst']);

        self::assertSame(['app', 'move_dst'], $moved->getPath());
        self::assertFalse($this->dir->exists($this->getDatabase(), ['app', 'move_src']));
        self::assertTrue($this->dir->exists($this->getDatabase(), ['app', 'move_dst']));

        $value = $this->getDatabase()->get($moved->pack(['key1']));
        self::assertSame('value1', $value);
    }

    #[Test]
    public function moveNonExistentDirectoryThrows(): void
    {
        $this->expectException(DirectoryException::class);
        $this->expectExceptionMessage('Source directory does not exist');

        $this->dir->move($this->getDatabase(), ['nonexistent'], ['destination']);
    }

    #[Test]
    public function moveToExistingDirectoryThrows(): void
    {
        $this->dir->create($this->getDatabase(), ['app', 'move_a']);
        $this->dir->create($this->getDatabase(), ['app', 'move_b']);

        $this->expectException(DirectoryException::class);
        $this->expectExceptionMessage('Destination directory already exists');

        $this->dir->move($this->getDatabase(), ['app', 'move_a'], ['app', 'move_b']);
    }

    #[Test]
    public function removeDirectory(): void
    {
        $this->dir->create($this->getDatabase(), ['app', 'removeme']);

        $result = $this->dir->remove($this->getDatabase(), ['app', 'removeme']);

        self::assertTrue($result);
        self::assertFalse($this->dir->exists($this->getDatabase(), ['app', 'removeme']));
    }

    #[Test]
    public function removeNonExistentDirectoryThrows(): void
    {
        $this->expectException(DirectoryException::class);
        $this->expectExceptionMessage('Directory does not exist');

        $this->dir->remove($this->getDatabase(), ['nonexistent']);
    }

    #[Test]
    public function removeIfExistsRemovesExisting(): void
    {
        $this->dir->create($this->getDatabase(), ['app', 'rife']);

        $result = $this->dir->removeIfExists($this->getDatabase(), ['app', 'rife']);

        self::assertTrue($result);
        self::assertFalse($this->dir->exists($this->getDatabase(), ['app', 'rife']));
    }

    #[Test]
    public function removeIfExistsReturnsFalseForNonExistent(): void
    {
        $result = $this->dir->removeIfExists($this->getDatabase(), ['nonexistent']);

        self::assertFalse($result);
    }

    #[Test]
    public function nestedDirectories(): void
    {
        $this->dir->create($this->getDatabase(), ['app', 'level1', 'level2', 'level3']);

        self::assertTrue($this->dir->exists($this->getDatabase(), ['app']));
        self::assertTrue($this->dir->exists($this->getDatabase(), ['app', 'level1']));
        self::assertTrue($this->dir->exists($this->getDatabase(), ['app', 'level1', 'level2']));
        self::assertTrue($this->dir->exists($this->getDatabase(), ['app', 'level1', 'level2', 'level3']));
    }

    #[Test]
    public function directoryAsSubspace(): void
    {
        $users = $this->dir->create($this->getDatabase(), ['app', 'subspace_test']);

        $this->getDatabase()->set($users->pack(['alice', 'name']), 'Alice');
        $this->getDatabase()->set($users->pack(['alice', 'email']), 'alice@example.com');

        $value = $this->getDatabase()->get($users->pack(['alice', 'name']));
        self::assertSame('Alice', $value);

        $unpacked = $users->unpack($users->pack(['alice', 'name']));
        self::assertSame('alice', $unpacked[0]);
        self::assertSame('name', $unpacked[1]);
    }

    #[Test]
    public function directorySubspaceCreateOrOpen(): void
    {
        $app = $this->dir->createOrOpen($this->getDatabase(), ['app']);
        $users = $app->createOrOpen($this->getDatabase(), ['users']);

        self::assertSame(['app', 'users'], $users->getPath());
    }

    #[Test]
    public function directorySubspaceList(): void
    {
        $app = $this->dir->createOrOpen($this->getDatabase(), ['app']);
        $app->create($this->getDatabase(), ['sub_a']);
        $app->create($this->getDatabase(), ['sub_b']);

        $result = $app->listSubdirectories($this->getDatabase());

        self::assertContains('sub_a', $result);
        self::assertContains('sub_b', $result);
    }

    #[Test]
    public function directorySubspaceExists(): void
    {
        $app = $this->dir->createOrOpen($this->getDatabase(), ['app']);
        $app->create($this->getDatabase(), ['exists_sub']);

        self::assertTrue($app->exists($this->getDatabase(), ['exists_sub']));
        self::assertFalse($app->exists($this->getDatabase(), ['nonexistent_sub']));
    }

    #[Test]
    public function directorySubspaceRemove(): void
    {
        $app = $this->dir->createOrOpen($this->getDatabase(), ['app']);
        $app->create($this->getDatabase(), ['remove_sub']);

        $result = $app->remove($this->getDatabase(), ['remove_sub']);

        self::assertTrue($result);
        self::assertFalse($app->exists($this->getDatabase(), ['remove_sub']));
    }

    #[Test]
    public function directorySubspaceMoveTo(): void
    {
        $this->dir->create($this->getDatabase(), ['app', 'moveto_src']);

        $src = $this->dir->open($this->getDatabase(), ['app', 'moveto_src']);
        $moved = $src->moveTo($this->getDatabase(), ['app', 'moveto_dst']);

        self::assertSame(['app', 'moveto_dst'], $moved->getPath());
        self::assertFalse($this->dir->exists($this->getDatabase(), ['app', 'moveto_src']));
        self::assertTrue($this->dir->exists($this->getDatabase(), ['app', 'moveto_dst']));
    }

    #[Test]
    public function removeDirectoryWithChildren(): void
    {
        $this->dir->create($this->getDatabase(), ['app', 'parent', 'child1']);
        $this->dir->create($this->getDatabase(), ['app', 'parent', 'child2']);

        $this->dir->remove($this->getDatabase(), ['app', 'parent']);

        self::assertFalse($this->dir->exists($this->getDatabase(), ['app', 'parent']));
        self::assertFalse($this->dir->exists($this->getDatabase(), ['app', 'parent', 'child1']));
        self::assertFalse($this->dir->exists($this->getDatabase(), ['app', 'parent', 'child2']));
    }

    #[Test]
    public function directoryPartitionBlocksSubspaceOps(): void
    {
        $partition = $this->dir->create($this->getDatabase(), ['app', 'partition'], 'partition');

        self::assertInstanceOf(DirectoryPartition::class, $partition);

        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('Cannot use a directory partition as a subspace');

        $partition->key();
    }

    #[Test]
    public function directoryPartitionPackThrows(): void
    {
        $partition = $this->dir->create($this->getDatabase(), ['app', 'partition_pack'], 'partition');

        $this->expectException(\LogicException::class);
        $partition->pack(['test']);
    }

    #[Test]
    public function directoryPartitionSubdirectories(): void
    {
        $partition = $this->dir->create($this->getDatabase(), ['app', 'partition_sub'], 'partition');

        self::assertInstanceOf(DirectoryPartition::class, $partition);

        $sub = $partition->create($this->getDatabase(), ['child']);
        self::assertInstanceOf(DirectorySubspace::class, $sub);
        self::assertSame(['child'], $sub->getPath());

        self::assertTrue($partition->exists($this->getDatabase(), ['child']));
    }

    #[Test]
    public function emptyPathThrows(): void
    {
        $this->expectException(DirectoryException::class);
        $this->expectExceptionMessage('Path must not be empty');

        $this->dir->create($this->getDatabase(), []);
    }

    #[Test]
    public function directoryPrefixesAreUnique(): void
    {
        $dir1 = $this->dir->create($this->getDatabase(), ['unique_a']);
        $dir2 = $this->dir->create($this->getDatabase(), ['unique_b']);
        $dir3 = $this->dir->create($this->getDatabase(), ['unique_c']);

        self::assertNotSame($dir1->rawPrefix, $dir2->rawPrefix);
        self::assertNotSame($dir2->rawPrefix, $dir3->rawPrefix);
        self::assertNotSame($dir1->rawPrefix, $dir3->rawPrefix);
    }

    #[Test]
    public function directoryDataIsolation(): void
    {
        $users = $this->dir->create($this->getDatabase(), ['iso', 'users']);
        $orders = $this->dir->create($this->getDatabase(), ['iso', 'orders']);

        $this->getDatabase()->set($users->pack(['alice']), 'user_data');
        $this->getDatabase()->set($orders->pack(['order1']), 'order_data');

        self::assertSame('user_data', $this->getDatabase()->get($users->pack(['alice'])));
        self::assertSame('order_data', $this->getDatabase()->get($orders->pack(['order1'])));

        self::assertNull($this->getDatabase()->get($users->pack(['order1'])));
        self::assertNull($this->getDatabase()->get($orders->pack(['alice'])));
    }

    // --- issue #41: caller-supplied prefix must be validated ------------------

    /**
     * A free binary prefix that fits the directory layout must be accepted
     * and round-trip via open().
     */
    #[Test]
    public function createWithExplicitPrefixSucceedsWhenFree(): void
    {
        // 16 bytes of binary prefix — long enough to be unique across
        // tests runs in CI and short enough that the cluster doesn't
        // object; this also proves binary, non-printable prefixes are
        // accepted (which is what real FDB partitions use externally).
        $explicit = "\x01explicit_prefix_" . random_bytes(8);

        $created = $this->dir->create(
            $this->getDatabase(),
            ['app', 'explicit_ok'],
            layer: '',
            prefix: $explicit,
        );

        self::assertInstanceOf(DirectorySubspace::class, $created);

        // Round-trip: opening the same directory must yield the same
        // rawPrefix value, demonstrating that the explicit prefix was
        // actually persisted (and not silently re-allocated or rewritten).
        $opened = $this->dir->open($this->getDatabase(), ['app', 'explicit_ok']);
        self::assertSame($explicit, $opened->rawPrefix);

        // The subspace must be usable for normal read/write.
        $this->getDatabase()->set($opened->pack(['k']), 'v');
        self::assertSame('v', $this->getDatabase()->get($opened->pack(['k'])));
    }

    /**
     * Two distinct explicit prefixes are both accepted when they don't
     * collide, and yield distinct DirectorySubspace raw prefixes.
     */
    #[Test]
    public function createWithTwoDistinctExplicitPrefixesSucceeds(): void
    {
        $a = "\x01a_explicit_" . random_bytes(8);
        $b = "\x01b_explicit_" . random_bytes(8);

        $dirA = $this->dir->create(
            $this->getDatabase(),
            ['app', 'explicit_a'],
            layer: '',
            prefix: $a,
        );
        $dirB = $this->dir->create(
            $this->getDatabase(),
            ['app', 'explicit_b'],
            layer: '',
            prefix: $b,
        );

        self::assertSame($a, $dirA->rawPrefix);
        self::assertSame($b, $dirB->rawPrefix);
        self::assertNotSame($dirA->rawPrefix, $dirB->rawPrefix);
    }

    /**
     * An empty-string explicit prefix must be rejected up front, with
     * a clear DirectoryException, before any transaction state is
     * mutated. The path must still be creatable afterwards via
     * auto-allocation.
     */
    #[Test]
    public function createWithExplicitEmptyPrefixThrowsAndDoesNotMutate(): void
    {
        try {
            $this->dir->create(
                $this->getDatabase(),
                ['app', 'empty_prefix'],
                layer: '',
                prefix: '',
            );
            self::fail('Expected DirectoryException for empty prefix.');
        } catch (DirectoryException $e) {
            self::assertStringContainsString(
                'Caller-supplied prefix must not be empty.',
                $e->getMessage(),
            );
        }

        // The path must still be creatable via the auto-allocation path
        // (i.e. the failed create() above did not commit a partial write).
        $retry = $this->dir->create(
            $this->getDatabase(),
            ['app', 'empty_prefix'],
            layer: '',
        );
        self::assertInstanceOf(DirectorySubspace::class, $retry);
    }

    /**
     * An explicit prefix that overlaps an existing content-key range
     * must be rejected. We pre-populate the content subspace at a key
     * that lies under (contentSubspace->key() + $rawPrefix) and then
     * attempt to use that $rawPrefix as an explicit directory prefix.
     *
     * The integration coverage here complements the unit-test assertion
     * over the same path by exercising the *real* Transaction probe
     * (`getRangeStartsWith` on a live cluster) against data the test
     * itself wrote under a known prefix.
     */
    #[Test]
    public function createWithExplicitPrefixOverlappingContentThrows(): void
    {
        $raw = "\x02content_prefix_" . random_bytes(8);
        // contentSubspace is constructed with rawPrefix '' (the default);
        // therefore content keys live at "" + ("\x02...")-starting bytes.
        $conflictKey = $raw . 'a';

        $db = $this->getDatabase();
        $db->set($conflictKey, 'occupied');

        try {
            $this->expectException(DirectoryException::class);
            $this->expectExceptionMessage('overlaps existing content keys');

            $this->dir->create(
                $db,
                ['app', 'collide_content_attempt'],
                layer: '',
                prefix: $raw,
            );
        } finally {
            // Cleanup so later tests in the same run aren't affected.
            @$db->clear($conflictKey);
        }
    }
}
