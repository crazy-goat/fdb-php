<?php

declare(strict_types=1);

namespace CrazyGoat\FoundationDB\Tests\Integration;

use CrazyGoat\FoundationDB\Database;
use CrazyGoat\FoundationDB\FoundationDB;
use CrazyGoat\FoundationDB\KeyValue;
use CrazyGoat\FoundationDB\RangeOptions;
use CrazyGoat\FoundationDB\Transaction;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class GetRangeAllTest extends TestCase
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

        self::$db->clearRangeStartsWith('test/rangeall/');
    }

    #[Test]
    public function getRangeAllReturnsAllResults(): void
    {
        self::$db->transact(function (Transaction $tr): void {
            for ($i = 0; $i < 10; $i++) {
                $tr->set(sprintf('test/rangeall/key%02d', $i), "value$i");
            }
        });

        $results = self::$db->getRangeAll('test/rangeall/', 'test/rangeall0');

        self::assertCount(10, $results);
        self::assertSame('test/rangeall/key00', $results[0]->key);
        self::assertSame('value0', $results[0]->value);
        self::assertSame('test/rangeall/key09', $results[9]->key);
    }

    #[Test]
    public function getRangeAllStartsWithReturnsAllResults(): void
    {
        self::$db->transact(function (Transaction $tr): void {
            for ($i = 0; $i < 5; $i++) {
                $tr->set("test/rangeall/prefix$i", "val$i");
            }
        });

        $results = self::$db->getRangeAllStartsWith('test/rangeall/prefix');

        self::assertCount(5, $results);
        self::assertSame('test/rangeall/prefix0', $results[0]->key);
        self::assertSame('val0', $results[0]->value);
    }

    #[Test]
    public function getRangeAllWithLimitRespectsLimit(): void
    {
        self::$db->transact(function (Transaction $tr): void {
            for ($i = 0; $i < 10; $i++) {
                $tr->set(sprintf('test/rangeall/lim%02d', $i), "v$i");
            }
        });

        $results = self::$db->getRangeAll(
            'test/rangeall/lim',
            'test/rangeall/lin',
            new RangeOptions(limit: 3),
        );

        self::assertCount(3, $results);
    }

    #[Test]
    public function getRangeAllWithReverseReturnsReversed(): void
    {
        self::$db->transact(function (Transaction $tr): void {
            for ($i = 0; $i < 5; $i++) {
                $tr->set(sprintf('test/rangeall/rev%02d', $i), "v$i");
            }
        });

        $results = self::$db->getRangeAll(
            'test/rangeall/rev',
            'test/rangeall/rew',
            new RangeOptions(reverse: true),
        );

        self::assertCount(5, $results);
        self::assertSame('test/rangeall/rev04', $results[0]->key);
        self::assertSame('test/rangeall/rev00', $results[4]->key);
    }

    #[Test]
    public function getRangeAllEmptyRangeReturnsEmptyArray(): void
    {
        $results = self::$db->getRangeAllStartsWith('test/rangeall/nonexistent');

        self::assertSame([], $results);
    }

    #[Test]
    public function getRangeAllOnTransactionLevel(): void
    {
        self::$db->transact(function (Transaction $tr): void {
            $tr->set('test/rangeall/txn1', 'a');
            $tr->set('test/rangeall/txn2', 'b');
            $tr->set('test/rangeall/txn3', 'c');
        });

        /** @var list<KeyValue> $results */
        $results = self::$db->transact(fn(Transaction $tr): array => $tr->getRangeAllStartsWith('test/rangeall/txn'));

        self::assertCount(3, $results);
    }
}
