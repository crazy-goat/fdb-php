<?php

declare(strict_types=1);

namespace CrazyGoat\FoundationDB\Tests\Integration;

use CrazyGoat\FoundationDB\Database;
use CrazyGoat\FoundationDB\FoundationDB;
use CrazyGoat\FoundationDB\Transaction;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class BasicCrudTest extends TestCase
{
    private static bool $initialized = false;

    private static Database $db;

    protected function setUp(): void
    {
        if (!self::$initialized) {
            FoundationDB::reset();
            FoundationDB::apiVersion(730);
            self::$db = FoundationDB::open();
            self::$initialized = true;
        }

        self::$db->clearRangeStartsWith('test/');
    }

    #[Test]
    public function databaseConvenienceSetAndGet(): void
    {
        self::$db->set('test/key1', 'value1');

        $value = self::$db->get('test/key1');
        self::assertSame('value1', $value);
    }

    #[Test]
    public function databaseConvenienceClear(): void
    {
        self::$db->set('test/key1', 'value1');
        self::$db->clear('test/key1');

        $value = self::$db->get('test/key1');
        self::assertNull($value);
    }

    #[Test]
    public function databaseConvenienceClearRange(): void
    {
        self::$db->set('test/a', '1');
        self::$db->set('test/b', '2');
        self::$db->set('test/c', '3');

        self::$db->clearRange('test/a', 'test/c');

        self::assertNull(self::$db->get('test/a'));
        self::assertNull(self::$db->get('test/b'));
        self::assertSame('3', self::$db->get('test/c'));
    }

    #[Test]
    public function databaseConvenienceClearRangeStartsWith(): void
    {
        self::$db->set('test/prefix/a', '1');
        self::$db->set('test/prefix/b', '2');
        self::$db->set('test/other', '3');

        self::$db->clearRangeStartsWith('test/prefix/');

        self::assertNull(self::$db->get('test/prefix/a'));
        self::assertNull(self::$db->get('test/prefix/b'));
        self::assertSame('3', self::$db->get('test/other'));
    }

    #[Test]
    public function transactRetryLoop(): void
    {
        self::$db->transact(function (Transaction $tr): void {
            $tr->set('test/transact', 'works');
        });

        $value = self::$db->get('test/transact');
        self::assertSame('works', $value);
    }

    #[Test]
    public function readYourWritesWithinTransaction(): void
    {
        $tr = self::$db->createTransaction();
        $tr->set('test/ryw', 'hello');

        $value = $tr->get('test/ryw')->await();
        self::assertSame('hello', $value);

        $tr->commit()->await();
    }

    #[Test]
    public function transactionGetReadVersion(): void
    {
        $tr = self::$db->createTransaction();
        $version = $tr->getReadVersion()->await();

        self::assertGreaterThan(0, $version);
    }

    #[Test]
    public function transactionGetCommittedVersion(): void
    {
        $tr = self::$db->createTransaction();
        $tr->set('test/committed_version', 'value');
        $tr->commit()->await();

        $version = $tr->getCommittedVersion();
        self::assertGreaterThan(0, $version);
    }

    #[Test]
    public function transactionGetApproximateSize(): void
    {
        $tr = self::$db->createTransaction();
        $tr->set('test/approx_size', 'value');

        $size = $tr->getApproximateSize()->await();
        self::assertGreaterThan(0, $size);
    }

    #[Test]
    public function snapshotRead(): void
    {
        self::$db->set('test/snapshot', 'snap_value');

        $tr = self::$db->createTransaction();
        $snap = $tr->snapshot();

        $value = $snap->get('test/snapshot')->await();
        self::assertSame('snap_value', $value);
    }

    #[Test]
    public function transactComposability(): void
    {
        $result = self::$db->transact(function (Transaction $tr): string {
            $tr->set('test/compose', 'composed');

            /** @var string */
            return $tr->transact(function (Transaction $inner): string {
                $value = $inner->get('test/compose')->await();

                return $value ?? 'not_found';
            });
        });

        self::assertSame('composed', $result);
    }

    #[Test]
    public function getAddressesForKey(): void
    {
        self::$db->set('test/addresses', 'value');

        $tr = self::$db->createTransaction();
        $addresses = $tr->getAddressesForKey('test/addresses')->await();

        self::assertNotEmpty($addresses);
    }
}
