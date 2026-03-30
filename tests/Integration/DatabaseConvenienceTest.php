<?php

declare(strict_types=1);

namespace CrazyGoat\FoundationDB\Tests\Integration;

use CrazyGoat\FoundationDB\Database;
use CrazyGoat\FoundationDB\FoundationDB;
use CrazyGoat\FoundationDB\KeySelector;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class DatabaseConvenienceTest extends TestCase
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

        self::$db->clearRangeStartsWith('test/conv/');
    }

    #[Test]
    public function getKeyReturnsMatchingKey(): void
    {
        self::$db->set('test/conv/a', '1');
        self::$db->set('test/conv/b', '2');
        self::$db->set('test/conv/c', '3');

        $key = self::$db->getKey(KeySelector::firstGreaterOrEqual('test/conv/b'));
        self::assertSame('test/conv/b', $key);
    }

    #[Test]
    public function getKeyWithFirstGreaterThan(): void
    {
        self::$db->set('test/conv/a', '1');
        self::$db->set('test/conv/b', '2');
        self::$db->set('test/conv/c', '3');

        $key = self::$db->getKey(KeySelector::firstGreaterThan('test/conv/a'));
        self::assertSame('test/conv/b', $key);
    }

    #[Test]
    public function getKeyWithLastLessOrEqual(): void
    {
        self::$db->set('test/conv/a', '1');
        self::$db->set('test/conv/b', '2');
        self::$db->set('test/conv/c', '3');

        $key = self::$db->getKey(KeySelector::lastLessOrEqual('test/conv/c'));
        self::assertSame('test/conv/c', $key);
    }

    #[Test]
    public function getKeyWithLastLessThan(): void
    {
        self::$db->set('test/conv/a', '1');
        self::$db->set('test/conv/b', '2');
        self::$db->set('test/conv/c', '3');

        $key = self::$db->getKey(KeySelector::lastLessThan('test/conv/c'));
        self::assertSame('test/conv/b', $key);
    }

    #[Test]
    public function watchReturnsValidFuture(): void
    {
        self::$db->set('test/conv/watch', 'initial');

        $watchFuture = self::$db->watch('test/conv/watch');

        self::$db->set('test/conv/watch', 'changed');

        $watchFuture->await();

        $this->addToAssertionCount(1);
    }

    #[Test]
    public function getAndWatchReturnsValueAndFuture(): void
    {
        self::$db->set('test/conv/gaw', 'hello');

        [$value, $watchFuture] = self::$db->getAndWatch('test/conv/gaw');

        self::assertSame('hello', $value);

        self::$db->set('test/conv/gaw', 'world');
        $watchFuture->await();

        self::assertSame('world', self::$db->get('test/conv/gaw'));
    }

    #[Test]
    public function getAndWatchReturnsNullForMissingKey(): void
    {
        [$value, $watchFuture] = self::$db->getAndWatch('test/conv/gaw_missing');

        self::assertNull($value);

        self::$db->set('test/conv/gaw_missing', 'appeared');
        $watchFuture->await();

        self::assertSame('appeared', self::$db->get('test/conv/gaw_missing'));
    }

    #[Test]
    public function setAndWatchSetsValueAndReturnsWatchFuture(): void
    {
        $watchFuture = self::$db->setAndWatch('test/conv/saw', 'initial');

        self::assertSame('initial', self::$db->get('test/conv/saw'));

        self::$db->set('test/conv/saw', 'changed');
        $watchFuture->await();

        self::assertSame('changed', self::$db->get('test/conv/saw'));
    }

    #[Test]
    public function clearAndWatchClearsKeyAndReturnsWatchFuture(): void
    {
        self::$db->set('test/conv/caw', 'to_delete');

        $watchFuture = self::$db->clearAndWatch('test/conv/caw');

        self::assertNull(self::$db->get('test/conv/caw'));

        self::$db->set('test/conv/caw', 'reappeared');
        $watchFuture->await();

        self::assertSame('reappeared', self::$db->get('test/conv/caw'));
    }

    #[Test]
    public function addAtomicOperation(): void
    {
        self::$db->set('test/conv/add', pack('P', 10));

        self::$db->add('test/conv/add', pack('P', 5));

        $result = unpack('P', (string) self::$db->get('test/conv/add'));
        self::assertIsArray($result);
        self::assertSame(15, $result[1]);
    }

    #[Test]
    public function bitAndAtomicOperation(): void
    {
        self::$db->set('test/conv/band', "\xFF\x0F");

        self::$db->bitAnd('test/conv/band', "\x0F\x0F");

        $result = self::$db->get('test/conv/band');
        self::assertSame("\x0F\x0F", $result);
    }

    #[Test]
    public function bitOrAtomicOperation(): void
    {
        self::$db->set('test/conv/bor', "\xF0\x00");

        self::$db->bitOr('test/conv/bor', "\x0F\x0F");

        $result = self::$db->get('test/conv/bor');
        self::assertSame("\xFF\x0F", $result);
    }

    #[Test]
    public function bitXorAtomicOperation(): void
    {
        self::$db->set('test/conv/bxor', "\xFF\xFF");

        self::$db->bitXor('test/conv/bxor', "\x0F\x0F");

        $result = self::$db->get('test/conv/bxor');
        self::assertSame("\xF0\xF0", $result);
    }

    #[Test]
    public function maxAtomicOperation(): void
    {
        self::$db->set('test/conv/max', pack('P', 10));

        self::$db->max('test/conv/max', pack('P', 20));

        $result = unpack('P', (string) self::$db->get('test/conv/max'));
        self::assertIsArray($result);
        self::assertSame(20, $result[1]);
    }

    #[Test]
    public function maxAtomicOperationKeepsExistingWhenLarger(): void
    {
        self::$db->set('test/conv/max2', pack('P', 30));

        self::$db->max('test/conv/max2', pack('P', 5));

        $result = unpack('P', (string) self::$db->get('test/conv/max2'));
        self::assertIsArray($result);
        self::assertSame(30, $result[1]);
    }

    #[Test]
    public function minAtomicOperation(): void
    {
        self::$db->set('test/conv/min', pack('P', 20));

        self::$db->min('test/conv/min', pack('P', 10));

        $result = unpack('P', (string) self::$db->get('test/conv/min'));
        self::assertIsArray($result);
        self::assertSame(10, $result[1]);
    }

    #[Test]
    public function minAtomicOperationKeepsExistingWhenSmaller(): void
    {
        self::$db->set('test/conv/min2', pack('P', 5));

        self::$db->min('test/conv/min2', pack('P', 30));

        $result = unpack('P', (string) self::$db->get('test/conv/min2'));
        self::assertIsArray($result);
        self::assertSame(5, $result[1]);
    }

    #[Test]
    public function compareAndClearRemovesKeyWhenValueMatches(): void
    {
        self::$db->set('test/conv/cac', 'match_me');

        self::$db->compareAndClear('test/conv/cac', 'match_me');

        self::assertNull(self::$db->get('test/conv/cac'));
    }

    #[Test]
    public function compareAndClearKeepsKeyWhenValueDiffers(): void
    {
        self::$db->set('test/conv/cac2', 'keep_me');

        self::$db->compareAndClear('test/conv/cac2', 'different');

        self::assertSame('keep_me', self::$db->get('test/conv/cac2'));
    }

    #[Test]
    public function getEstimatedRangeSizeBytesReturnsNonNegative(): void
    {
        for ($i = 0; $i < 10; $i++) {
            self::$db->set("test/conv/est/{$i}", str_repeat('x', 100));
        }

        $size = self::$db->getEstimatedRangeSizeBytes('test/conv/est/', 'test/conv/est0');

        self::assertGreaterThanOrEqual(0, $size);
    }

    #[Test]
    public function getRangeSplitPointsReturnsArray(): void
    {
        for ($i = 0; $i < 10; $i++) {
            self::$db->set("test/conv/split/{$i}", str_repeat('x', 100));
        }

        $points = self::$db->getRangeSplitPoints('test/conv/split/', 'test/conv/split0', 1000);

        self::assertGreaterThanOrEqual(0, count($points));
    }
}
