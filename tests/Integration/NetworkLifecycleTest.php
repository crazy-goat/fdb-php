<?php

declare(strict_types=1);

namespace CrazyGoat\FoundationDB\Tests\Integration;

use CrazyGoat\FoundationDB\FoundationDB;
use CrazyGoat\FoundationDB\NativeClient;
use CrazyGoat\FoundationDB\Transaction;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class NetworkLifecycleTest extends TestCase
{
    private static bool $initialized = false;

    protected function setUp(): void
    {
        if (!self::$initialized) {
            FoundationDB::reset();
            FoundationDB::apiVersion(730);
            self::$initialized = true;
        }
    }

    #[Test]
    public function apiVersionIsSet(): void
    {
        self::assertSame(730, FoundationDB::getApiVersion());
    }

    #[Test]
    public function getMaxApiVersionReturnsPositiveInt(): void
    {
        $maxVersion = FoundationDB::getMaxApiVersion();

        self::assertGreaterThanOrEqual(730, $maxVersion);
    }

    #[Test]
    public function apiVersionCannotBeSetTwice(): void
    {
        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('API version already set');

        FoundationDB::apiVersion(730);
    }

    #[Test]
    public function openRequiresApiVersion(): void
    {
        $savedVersion = FoundationDB::getApiVersion();
        FoundationDB::reset();

        try {
            $this->expectException(\LogicException::class);
            $this->expectExceptionMessage('API version must be set');
            FoundationDB::open();
        } finally {
            if ($savedVersion !== null) {
                FoundationDB::apiVersion($savedVersion);
            }
        }
    }

    #[Test]
    public function openDatabaseAndCreateTransaction(): void
    {
        $db = FoundationDB::open();
        $tr = $db->createTransaction();

        self::assertInstanceOf(Transaction::class, $tr);
    }

    #[Test]
    public function basicSetGetClear(): void
    {
        $db = FoundationDB::open();

        $tr = $db->createTransaction();
        $tr->set('test_php_ffi_key', 'hello_world');
        $tr->commit()->await();

        $tr2 = $db->createTransaction();
        $value = $tr2->get('test_php_ffi_key')->await();
        self::assertSame('hello_world', $value);

        $tr3 = $db->createTransaction();
        $tr3->clear('test_php_ffi_key');
        $tr3->commit()->await();

        $tr4 = $db->createTransaction();
        $value = $tr4->get('test_php_ffi_key')->await();
        self::assertNull($value);
    }

    #[Test]
    public function getNonExistentKeyReturnsNull(): void
    {
        $db = FoundationDB::open();

        $tr = $db->createTransaction();
        $value = $tr->get('test_php_ffi_nonexistent_key_' . bin2hex(random_bytes(8)))->await();
        self::assertNull($value);
    }

    #[Test]
    public function openReturnsCachedDatabase(): void
    {
        $db1 = FoundationDB::open();
        $db2 = FoundationDB::open();

        self::assertSame($db1, $db2);
    }

    #[Test]
    public function transactionReset(): void
    {
        $db = FoundationDB::open();

        $tr = $db->createTransaction();
        $tr->set('test_php_ffi_reset_key', 'value1');
        $tr->reset();

        $tr->set('test_php_ffi_reset_key', 'value2');
        $tr->commit()->await();

        $tr2 = $db->createTransaction();
        $value = $tr2->get('test_php_ffi_reset_key')->await();
        self::assertSame('value2', $value);

        $tr3 = $db->createTransaction();
        $tr3->clear('test_php_ffi_reset_key');
        $tr3->commit()->await();
    }

    #[Test]
    public function networkIsStartedAfterOpen(): void
    {
        FoundationDB::open();

        self::assertTrue(NativeClient::getInstance()->isNetworkStarted());
    }
}
