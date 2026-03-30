<?php

declare(strict_types=1);

namespace CrazyGoat\FoundationDB\Tests\Integration;

use CrazyGoat\FoundationDB\Database;
use CrazyGoat\FoundationDB\FoundationDB;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class DatabaseCloseTest extends TestCase
{
    protected function setUp(): void
    {
        if (FoundationDB::getApiVersion() === null) {
            FoundationDB::apiVersion(730);
        }
    }

    #[Test]
    public function closeReleasesDatabase(): void
    {
        $db = FoundationDB::open();
        $db->close();

        $db2 = FoundationDB::open();
        self::assertInstanceOf(Database::class, $db2);

        $db2->set('test/close_key', 'value');
        self::assertSame('value', $db2->get('test/close_key'));
        $db2->clear('test/close_key');
    }

    #[Test]
    public function closedDatabaseThrowsOnCreateTransaction(): void
    {
        $db = FoundationDB::open();
        $db->close();

        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('closed');
        $db->createTransaction();
    }

    #[Test]
    public function closedDatabaseThrowsOnGet(): void
    {
        $db = FoundationDB::open();
        $db->close();

        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('closed');
        $db->get('key');
    }

    #[Test]
    public function closedDatabaseThrowsOnSet(): void
    {
        $db = FoundationDB::open();
        $db->close();

        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('closed');
        $db->set('key', 'value');
    }

    #[Test]
    public function closedDatabaseThrowsOnTransact(): void
    {
        $db = FoundationDB::open();
        $db->close();

        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('closed');
        $db->transact(fn (): null => null);
    }

    #[Test]
    public function closedDatabaseThrowsOnOpenTenant(): void
    {
        $db = FoundationDB::open();
        $db->close();

        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('closed');
        $db->openTenant('test');
    }

    #[Test]
    public function doubleCloseIsNoOp(): void
    {
        $db = FoundationDB::open();
        $db->close();
        $db->close();

        $db2 = FoundationDB::open();
        self::assertInstanceOf(Database::class, $db2);
        $db2->close();
    }

    #[Test]
    public function reopenAfterCloseReturnsNewInstance(): void
    {
        $db1 = FoundationDB::open();
        $db1->close();

        $db2 = FoundationDB::open();
        self::assertNotSame($db1, $db2);
        $db2->close();
    }
}
