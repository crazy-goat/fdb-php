<?php

declare(strict_types=1);

namespace CrazyGoat\FoundationDB\Tests\Integration;

use CrazyGoat\FoundationDB\FDBException;
use CrazyGoat\FoundationDB\FoundationDB;
use CrazyGoat\FoundationDB\NativeClient;
use CrazyGoat\FoundationDB\Transaction;
use PHPUnit\Framework\Attributes\RunInSeparateProcess;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class NetworkLifecycleTest extends TestCase
{
    protected function setUp(): void
    {
        if (FoundationDB::getApiVersion() === null) {
            FoundationDB::apiVersion(730);
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

    /**
     * Runs in a separate process: libfdb_c allows fdb_setup_network() only
     * once per process, so stopping the network here would poison every
     * later test in the shared integration suite.
     */
    #[Test]
    #[RunInSeparateProcess]
    public function networkCanBeStoppedAndRestarted(): void
    {
        FoundationDB::open();
        $client = NativeClient::getInstance();
        self::assertTrue($client->isNetworkStarted());

        $client->stopNetwork();
        self::assertFalse($client->isNetworkStarted());
        self::assertFalse($client->isNetworkSetup());

        // Shutdown is idempotent: a second stopNetwork() is a no-op and must
        // not throw or wedge the process.
        $client->stopNetwork();
        self::assertFalse($client->isNetworkStarted());

        // libfdb_c allows fdb_setup_network() only once per process, so a
        // full re-setup is not possible here — what matters (issue #47) is
        // that stopping leaves a CONSISTENT state: the flags are cleared and
        // ensureNetwork() surfaces libfdb_c's "configured only once" error
        // instead of silently wedging in a half-initialized state.
        FoundationDB::apiVersion(730);
        try {
            $client->ensureNetwork();
            self::fail('ensureNetwork() after stop must surface the libfdb_c setup error');
        } catch (FDBException $e) {
            self::assertStringContainsString('only once', $e->getMessage());
        }
        self::assertFalse($client->isNetworkStarted());
        self::assertFalse($client->isNetworkSetup());
    }

    /**
     * Runs in a separate process for the same reason as above: stopping the
     * network is only possible once per process. Verifies that completion
     * hooks registered via `FoundationDB::onNetworkThreadCompletion()` are
     * invoked exactly once, after the network has actually stopped.
     */
    #[Test]
    #[RunInSeparateProcess]
    public function networkCompletionHooksRunOnStopNetwork(): void
    {
        $calls = [];
        FoundationDB::onNetworkThreadCompletion(function () use (&$calls): void {
            $calls[] = 'flush-traces';
        });

        FoundationDB::open();
        self::assertSame([], $calls, 'Hooks must not run before the network stops');

        $client = NativeClient::getInstance();
        $client->stopNetwork();

        self::assertSame(['flush-traces'], $calls);
    }
}
