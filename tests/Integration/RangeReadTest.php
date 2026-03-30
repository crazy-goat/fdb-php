<?php

declare(strict_types=1);

namespace CrazyGoat\FoundationDB\Tests\Integration;

use CrazyGoat\FoundationDB\Database;
use CrazyGoat\FoundationDB\Enum\StreamingMode;
use CrazyGoat\FoundationDB\FoundationDB;
use CrazyGoat\FoundationDB\KeySelector;
use CrazyGoat\FoundationDB\KeyValue;
use CrazyGoat\FoundationDB\RangeOptions;
use CrazyGoat\FoundationDB\RangeResult;
use CrazyGoat\FoundationDB\Transaction;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class RangeReadTest extends TestCase
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

        self::$db->clearRangeStartsWith('range_test/');
    }

    #[Test]
    public function getRangeWithBeginAndEndKeys(): void
    {
        self::$db->set('range_test/a', '1');
        self::$db->set('range_test/b', '2');
        self::$db->set('range_test/c', '3');
        self::$db->set('range_test/d', '4');

        $result = self::$db->getRange('range_test/a', 'range_test/d');

        self::assertCount(3, $result);
        self::assertSame('range_test/a', $result[0]->key);
        self::assertSame('1', $result[0]->value);
        self::assertSame('range_test/b', $result[1]->key);
        self::assertSame('2', $result[1]->value);
        self::assertSame('range_test/c', $result[2]->key);
        self::assertSame('3', $result[2]->value);
    }

    #[Test]
    public function getRangeIncludesBeginExcludesEnd(): void
    {
        self::$db->set('range_test/x', '10');
        self::$db->set('range_test/y', '20');
        self::$db->set('range_test/z', '30');

        $result = self::$db->getRange('range_test/x', 'range_test/z');

        self::assertCount(2, $result);
        self::assertSame('range_test/x', $result[0]->key);
        self::assertSame('range_test/y', $result[1]->key);
    }

    #[Test]
    public function getRangeWithKeySelectors(): void
    {
        self::$db->set('range_test/a', '1');
        self::$db->set('range_test/b', '2');
        self::$db->set('range_test/c', '3');

        $result = self::$db->getRange(
            KeySelector::firstGreaterThan('range_test/a'),
            KeySelector::firstGreaterOrEqual('range_test/c'),
        );

        self::assertCount(1, $result);
        self::assertSame('range_test/b', $result[0]->key);
        self::assertSame('2', $result[0]->value);
    }

    #[Test]
    public function getRangeStartsWithPrefix(): void
    {
        self::$db->set('range_test/prefix/a', '1');
        self::$db->set('range_test/prefix/b', '2');
        self::$db->set('range_test/prefix/c', '3');
        self::$db->set('range_test/other', '4');

        $result = self::$db->getRangeStartsWith('range_test/prefix/');

        self::assertCount(3, $result);
        self::assertSame('range_test/prefix/a', $result[0]->key);
        self::assertSame('range_test/prefix/b', $result[1]->key);
        self::assertSame('range_test/prefix/c', $result[2]->key);
    }

    #[Test]
    public function getRangeWithLimit(): void
    {
        self::$db->set('range_test/a', '1');
        self::$db->set('range_test/b', '2');
        self::$db->set('range_test/c', '3');
        self::$db->set('range_test/d', '4');

        $result = self::$db->getRangeStartsWith(
            'range_test/',
            new RangeOptions(limit: 2),
        );

        self::assertCount(2, $result);
        self::assertSame('range_test/a', $result[0]->key);
        self::assertSame('range_test/b', $result[1]->key);
    }

    #[Test]
    public function getRangeReverse(): void
    {
        self::$db->set('range_test/a', '1');
        self::$db->set('range_test/b', '2');
        self::$db->set('range_test/c', '3');

        $result = self::$db->getRangeStartsWith(
            'range_test/',
            new RangeOptions(reverse: true),
        );

        self::assertCount(3, $result);
        self::assertSame('range_test/c', $result[0]->key);
        self::assertSame('range_test/b', $result[1]->key);
        self::assertSame('range_test/a', $result[2]->key);
    }

    #[Test]
    public function getRangeReverseWithLimit(): void
    {
        self::$db->set('range_test/a', '1');
        self::$db->set('range_test/b', '2');
        self::$db->set('range_test/c', '3');
        self::$db->set('range_test/d', '4');

        $result = self::$db->getRangeStartsWith(
            'range_test/',
            new RangeOptions(limit: 2, reverse: true),
        );

        self::assertCount(2, $result);
        self::assertSame('range_test/d', $result[0]->key);
        self::assertSame('range_test/c', $result[1]->key);
    }

    #[Test]
    public function getRangeEmptyRange(): void
    {
        $result = self::$db->getRangeStartsWith('range_test/nonexistent/');

        self::assertCount(0, $result);
    }

    #[Test]
    public function getRangeReturnsRangeResultFromTransaction(): void
    {
        self::$db->set('range_test/a', '1');

        $tr = self::$db->createTransaction();
        $rangeResult = $tr->getRangeStartsWith('range_test/');

        self::assertInstanceOf(RangeResult::class, $rangeResult);

        $array = $rangeResult->toArray();
        self::assertCount(1, $array);
        self::assertSame('range_test/a', $array[0]->key);
    }

    #[Test]
    public function getRangeIteratorYieldsKeyValues(): void
    {
        self::$db->set('range_test/a', '1');
        self::$db->set('range_test/b', '2');
        self::$db->set('range_test/c', '3');

        $tr = self::$db->createTransaction();
        $rangeResult = $tr->getRangeStartsWith('range_test/');

        $collected = [];
        foreach ($rangeResult as $kv) {
            self::assertInstanceOf(KeyValue::class, $kv);
            $collected[] = $kv;
        }

        self::assertCount(3, $collected);
        self::assertSame('range_test/a', $collected[0]->key);
        self::assertSame('1', $collected[0]->value);
    }

    #[Test]
    public function getRangePaginationWithManyKeys(): void
    {
        self::$db->transact(function (Transaction $tr): void {
            for ($i = 0; $i < 200; $i++) {
                $tr->set(sprintf('range_test/bulk/%04d', $i), sprintf('value_%04d', $i));
            }
        });

        $result = self::$db->getRangeStartsWith('range_test/bulk/');

        self::assertCount(200, $result);
        self::assertSame('range_test/bulk/0000', $result[0]->key);
        self::assertSame('value_0000', $result[0]->value);
        self::assertSame('range_test/bulk/0199', $result[199]->key);
        self::assertSame('value_0199', $result[199]->value);
    }

    #[Test]
    public function getRangePaginationWithLimitAcrossBatches(): void
    {
        self::$db->transact(function (Transaction $tr): void {
            for ($i = 0; $i < 200; $i++) {
                $tr->set(sprintf('range_test/limited/%04d', $i), sprintf('val_%04d', $i));
            }
        });

        $result = self::$db->getRangeStartsWith(
            'range_test/limited/',
            new RangeOptions(limit: 150),
        );

        self::assertCount(150, $result);
        self::assertSame('range_test/limited/0000', $result[0]->key);
        self::assertSame('range_test/limited/0149', $result[149]->key);
    }

    #[Test]
    public function getRangeWithStreamingModeExact(): void
    {
        self::$db->set('range_test/exact/a', '1');
        self::$db->set('range_test/exact/b', '2');
        self::$db->set('range_test/exact/c', '3');

        $result = self::$db->getRangeStartsWith(
            'range_test/exact/',
            new RangeOptions(limit: 2, mode: StreamingMode::Exact),
        );

        self::assertCount(2, $result);
    }

    #[Test]
    public function getRangeWithStreamingModeWantAll(): void
    {
        self::$db->set('range_test/want_all/a', '1');
        self::$db->set('range_test/want_all/b', '2');
        self::$db->set('range_test/want_all/c', '3');

        $result = self::$db->getRangeStartsWith(
            'range_test/want_all/',
            new RangeOptions(mode: StreamingMode::WantAll),
        );

        self::assertCount(3, $result);
    }

    #[Test]
    public function snapshotGetRange(): void
    {
        self::$db->set('range_test/snap/a', '1');
        self::$db->set('range_test/snap/b', '2');

        $tr = self::$db->createTransaction();
        $snap = $tr->snapshot();
        $result = $snap->getRangeStartsWith('range_test/snap/')->toArray();

        self::assertCount(2, $result);
        self::assertSame('range_test/snap/a', $result[0]->key);
        self::assertSame('range_test/snap/b', $result[1]->key);
    }

    #[Test]
    public function getRangeWithinTransaction(): void
    {
        /** @var list<KeyValue> $result */
        $result = self::$db->transact(function (Transaction $tr): array {
            $tr->set('range_test/txn/a', '1');
            $tr->set('range_test/txn/b', '2');
            $tr->set('range_test/txn/c', '3');

            return $tr->getRangeStartsWith('range_test/txn/')->toArray();
        });

        self::assertCount(3, $result);
        self::assertSame('range_test/txn/a', $result[0]->key);
        self::assertSame('1', $result[0]->value);
    }

    #[Test]
    public function getEstimatedRangeSizeBytes(): void
    {
        self::$db->transact(function (Transaction $tr): void {
            for ($i = 0; $i < 50; $i++) {
                $tr->set(sprintf('range_test/size/%04d', $i), str_repeat('x', 100));
            }
        });

        $tr = self::$db->createTransaction();
        $size = $tr->getEstimatedRangeSizeBytes('range_test/size/', 'range_test/size0')->await();

        self::assertGreaterThanOrEqual(0, $size);
    }
}
