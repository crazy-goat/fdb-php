<?php

declare(strict_types=1);

namespace CrazyGoat\FoundationDB\Tests\Integration;

use CrazyGoat\FoundationDB\Database;
use CrazyGoat\FoundationDB\Directory\DirectoryException;
use CrazyGoat\FoundationDB\Directory\DirectoryLayer;
use CrazyGoat\FoundationDB\Directory\DirectoryPartition;
use CrazyGoat\FoundationDB\Directory\DirectorySubspace;
use CrazyGoat\FoundationDB\FoundationDB;
use CrazyGoat\FoundationDB\Transaction;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class DirectoryTest extends TestCase
{
    private static bool $initialized = false;

    private static Database $db;

    private DirectoryLayer $dir;

    protected function setUp(): void
    {
        if (!self::$initialized) {
            FoundationDB::reset();
            FoundationDB::apiVersion(730);
            self::$db = FoundationDB::open();
            self::$initialized = true;
        }

        // Clear all directory-related data
        self::$db->transact(function (Transaction $tr): void {
            // Clear directory layer prefix
            $tr->clearRangeStartsWith("\xFE");
            // Clear any test data that might interfere
            $tr->clearRangeStartsWith("test_");
            $tr->clearRangeStartsWith("app_");
            $tr->clearRangeStartsWith("user_");
            $tr->clearRangeStartsWith("tenant_");
            $tr->clearRangeStartsWith("partition_");
        });

        $this->dir = new DirectoryLayer();
    }

    #[Test]
    public function createDirectory(): void
    {
        $result = $this->dir->create(self::$db, ['app', 'users']);

        self::assertInstanceOf(DirectorySubspace::class, $result);
        self::assertSame(['app', 'users'], $result->getPath());
    }

    #[Test]
    public function createDirectoryWithLayer(): void
    {
        $result = $this->dir->create(self::$db, ['app', 'data'], 'my_layer');

        self::assertSame('my_layer', $result->getLayer());
    }

    #[Test]
    public function createDuplicateDirectoryThrows(): void
    {
        $this->dir->create(self::$db, ['app', 'dup']);

        $this->expectException(DirectoryException::class);
        $this->expectExceptionMessage('Directory already exists');

        $this->dir->create(self::$db, ['app', 'dup']);
    }

    #[Test]
    public function openExistingDirectory(): void
    {
        $created = $this->dir->create(self::$db, ['app', 'openme']);
        $opened = $this->dir->open(self::$db, ['app', 'openme']);

        self::assertSame($created->rawPrefix, $opened->rawPrefix);
        self::assertSame(['app', 'openme'], $opened->getPath());
    }

    #[Test]
    public function openNonExistentDirectoryThrows(): void
    {
        $this->expectException(DirectoryException::class);
        $this->expectExceptionMessage('Directory does not exist');

        $this->dir->open(self::$db, ['nonexistent']);
    }

    #[Test]
    public function openWithWrongLayerThrows(): void
    {
        $this->dir->create(self::$db, ['app', 'layered'], 'layer_a');

        $this->expectException(DirectoryException::class);
        $this->expectExceptionMessage('different layer');

        $this->dir->open(self::$db, ['app', 'layered'], 'layer_b');
    }

    #[Test]
    public function createOrOpenCreatesNew(): void
    {
        $result = $this->dir->createOrOpen(self::$db, ['app', 'cor_new']);

        self::assertInstanceOf(DirectorySubspace::class, $result);
        self::assertSame(['app', 'cor_new'], $result->getPath());
    }

    #[Test]
    public function createOrOpenOpensExisting(): void
    {
        $created = $this->dir->create(self::$db, ['app', 'cor_existing']);
        $opened = $this->dir->createOrOpen(self::$db, ['app', 'cor_existing']);

        self::assertSame($created->rawPrefix, $opened->rawPrefix);
    }

    #[Test]
    public function createOrOpenWithMismatchedLayerThrows(): void
    {
        $this->dir->create(self::$db, ['app', 'cor_layer'], 'layer_a');

        $this->expectException(DirectoryException::class);
        $this->expectExceptionMessage('different layer');

        $this->dir->createOrOpen(self::$db, ['app', 'cor_layer'], 'layer_b');
    }

    #[Test]
    public function existsReturnsTrueForExisting(): void
    {
        $this->dir->create(self::$db, ['app', 'exists_test']);

        self::assertTrue($this->dir->exists(self::$db, ['app', 'exists_test']));
    }

    #[Test]
    public function existsReturnsFalseForNonExistent(): void
    {
        self::assertFalse($this->dir->exists(self::$db, ['nonexistent']));
    }

    #[Test]
    public function listSubdirectories(): void
    {
        $this->dir->create(self::$db, ['app', 'list_a']);
        $this->dir->create(self::$db, ['app', 'list_b']);
        $this->dir->create(self::$db, ['app', 'list_c']);

        $result = $this->dir->list(self::$db, ['app']);

        self::assertContains('list_a', $result);
        self::assertContains('list_b', $result);
        self::assertContains('list_c', $result);
    }

    #[Test]
    public function listEmptyDirectory(): void
    {
        $this->dir->create(self::$db, ['app', 'empty_dir']);

        $result = $this->dir->list(self::$db, ['app', 'empty_dir']);

        self::assertSame([], $result);
    }

    #[Test]
    public function listRootDirectory(): void
    {
        $this->dir->create(self::$db, ['root_a']);
        $this->dir->create(self::$db, ['root_b']);

        $result = $this->dir->list(self::$db);

        self::assertContains('root_a', $result);
        self::assertContains('root_b', $result);
    }

    #[Test]
    public function moveDirectory(): void
    {
        $this->dir->create(self::$db, ['app', 'move_src']);

        self::$db->set(
            $this->dir->open(self::$db, ['app', 'move_src'])->pack(['key1']),
            'value1',
        );

        $moved = $this->dir->move(self::$db, ['app', 'move_src'], ['app', 'move_dst']);

        self::assertSame(['app', 'move_dst'], $moved->getPath());
        self::assertFalse($this->dir->exists(self::$db, ['app', 'move_src']));
        self::assertTrue($this->dir->exists(self::$db, ['app', 'move_dst']));

        $value = self::$db->get($moved->pack(['key1']));
        self::assertSame('value1', $value);
    }

    #[Test]
    public function moveNonExistentDirectoryThrows(): void
    {
        $this->expectException(DirectoryException::class);
        $this->expectExceptionMessage('Source directory does not exist');

        $this->dir->move(self::$db, ['nonexistent'], ['destination']);
    }

    #[Test]
    public function moveToExistingDirectoryThrows(): void
    {
        $this->dir->create(self::$db, ['app', 'move_a']);
        $this->dir->create(self::$db, ['app', 'move_b']);

        $this->expectException(DirectoryException::class);
        $this->expectExceptionMessage('Destination directory already exists');

        $this->dir->move(self::$db, ['app', 'move_a'], ['app', 'move_b']);
    }

    #[Test]
    public function removeDirectory(): void
    {
        $this->dir->create(self::$db, ['app', 'removeme']);

        $result = $this->dir->remove(self::$db, ['app', 'removeme']);

        self::assertTrue($result);
        self::assertFalse($this->dir->exists(self::$db, ['app', 'removeme']));
    }

    #[Test]
    public function removeNonExistentDirectoryThrows(): void
    {
        $this->expectException(DirectoryException::class);
        $this->expectExceptionMessage('Directory does not exist');

        $this->dir->remove(self::$db, ['nonexistent']);
    }

    #[Test]
    public function removeIfExistsRemovesExisting(): void
    {
        $this->dir->create(self::$db, ['app', 'rife']);

        $result = $this->dir->removeIfExists(self::$db, ['app', 'rife']);

        self::assertTrue($result);
        self::assertFalse($this->dir->exists(self::$db, ['app', 'rife']));
    }

    #[Test]
    public function removeIfExistsReturnsFalseForNonExistent(): void
    {
        $result = $this->dir->removeIfExists(self::$db, ['nonexistent']);

        self::assertFalse($result);
    }

    #[Test]
    public function nestedDirectories(): void
    {
        $this->dir->create(self::$db, ['app', 'level1', 'level2', 'level3']);

        self::assertTrue($this->dir->exists(self::$db, ['app']));
        self::assertTrue($this->dir->exists(self::$db, ['app', 'level1']));
        self::assertTrue($this->dir->exists(self::$db, ['app', 'level1', 'level2']));
        self::assertTrue($this->dir->exists(self::$db, ['app', 'level1', 'level2', 'level3']));
    }

    #[Test]
    public function directoryAsSubspace(): void
    {
        $users = $this->dir->create(self::$db, ['app', 'subspace_test']);

        self::$db->set($users->pack(['alice', 'name']), 'Alice');
        self::$db->set($users->pack(['alice', 'email']), 'alice@example.com');

        $value = self::$db->get($users->pack(['alice', 'name']));
        self::assertSame('Alice', $value);

        $unpacked = $users->unpack($users->pack(['alice', 'name']));
        self::assertSame('alice', $unpacked[0]);
        self::assertSame('name', $unpacked[1]);
    }

    #[Test]
    public function directorySubspaceCreateOrOpen(): void
    {
        $app = $this->dir->createOrOpen(self::$db, ['app']);
        $users = $app->createOrOpen(self::$db, ['users']);

        self::assertSame(['app', 'users'], $users->getPath());
    }

    #[Test]
    public function directorySubspaceList(): void
    {
        $app = $this->dir->createOrOpen(self::$db, ['app']);
        $app->create(self::$db, ['sub_a']);
        $app->create(self::$db, ['sub_b']);

        $result = $app->listSubdirectories(self::$db);

        self::assertContains('sub_a', $result);
        self::assertContains('sub_b', $result);
    }

    #[Test]
    public function directorySubspaceExists(): void
    {
        $app = $this->dir->createOrOpen(self::$db, ['app']);
        $app->create(self::$db, ['exists_sub']);

        self::assertTrue($app->exists(self::$db, ['exists_sub']));
        self::assertFalse($app->exists(self::$db, ['nonexistent_sub']));
    }

    #[Test]
    public function directorySubspaceRemove(): void
    {
        $app = $this->dir->createOrOpen(self::$db, ['app']);
        $app->create(self::$db, ['remove_sub']);

        $result = $app->remove(self::$db, ['remove_sub']);

        self::assertTrue($result);
        self::assertFalse($app->exists(self::$db, ['remove_sub']));
    }

    #[Test]
    public function directorySubspaceMoveTo(): void
    {
        $this->dir->create(self::$db, ['app', 'moveto_src']);

        $src = $this->dir->open(self::$db, ['app', 'moveto_src']);
        $moved = $src->moveTo(self::$db, ['app', 'moveto_dst']);

        self::assertSame(['app', 'moveto_dst'], $moved->getPath());
        self::assertFalse($this->dir->exists(self::$db, ['app', 'moveto_src']));
        self::assertTrue($this->dir->exists(self::$db, ['app', 'moveto_dst']));
    }

    #[Test]
    public function removeDirectoryWithChildren(): void
    {
        $this->dir->create(self::$db, ['app', 'parent', 'child1']);
        $this->dir->create(self::$db, ['app', 'parent', 'child2']);

        $this->dir->remove(self::$db, ['app', 'parent']);

        self::assertFalse($this->dir->exists(self::$db, ['app', 'parent']));
        self::assertFalse($this->dir->exists(self::$db, ['app', 'parent', 'child1']));
        self::assertFalse($this->dir->exists(self::$db, ['app', 'parent', 'child2']));
    }

    #[Test]
    public function directoryPartitionBlocksSubspaceOps(): void
    {
        $partition = $this->dir->create(self::$db, ['app', 'partition'], 'partition');

        self::assertInstanceOf(DirectoryPartition::class, $partition);

        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('Cannot use a directory partition as a subspace');

        $partition->key();
    }

    #[Test]
    public function directoryPartitionPackThrows(): void
    {
        $partition = $this->dir->create(self::$db, ['app', 'partition_pack'], 'partition');

        $this->expectException(\LogicException::class);
        $partition->pack(['test']);
    }

    #[Test]
    public function directoryPartitionSubdirectories(): void
    {
        $partition = $this->dir->create(self::$db, ['app', 'partition_sub'], 'partition');

        self::assertInstanceOf(DirectoryPartition::class, $partition);

        $sub = $partition->create(self::$db, ['child']);
        self::assertInstanceOf(DirectorySubspace::class, $sub);
        self::assertSame(['child'], $sub->getPath());

        self::assertTrue($partition->exists(self::$db, ['child']));
    }

    #[Test]
    public function emptyPathThrows(): void
    {
        $this->expectException(DirectoryException::class);
        $this->expectExceptionMessage('Path must not be empty');

        $this->dir->create(self::$db, []);
    }

    #[Test]
    public function directoryPrefixesAreUnique(): void
    {
        $dir1 = $this->dir->create(self::$db, ['unique_a']);
        $dir2 = $this->dir->create(self::$db, ['unique_b']);
        $dir3 = $this->dir->create(self::$db, ['unique_c']);

        self::assertNotSame($dir1->rawPrefix, $dir2->rawPrefix);
        self::assertNotSame($dir2->rawPrefix, $dir3->rawPrefix);
        self::assertNotSame($dir1->rawPrefix, $dir3->rawPrefix);
    }

    #[Test]
    public function directoryDataIsolation(): void
    {
        $users = $this->dir->create(self::$db, ['iso', 'users']);
        $orders = $this->dir->create(self::$db, ['iso', 'orders']);

        self::$db->set($users->pack(['alice']), 'user_data');
        self::$db->set($orders->pack(['order1']), 'order_data');

        self::assertSame('user_data', self::$db->get($users->pack(['alice'])));
        self::assertSame('order_data', self::$db->get($orders->pack(['order1'])));

        self::assertNull(self::$db->get($users->pack(['order1'])));
        self::assertNull(self::$db->get($orders->pack(['alice'])));
    }
}
