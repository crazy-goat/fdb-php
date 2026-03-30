<?php

declare(strict_types=1);

namespace CrazyGoat\FoundationDB\Tests\Integration;

use CrazyGoat\FoundationDB\KeySelector;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class DatabaseConvenienceTest extends TestCase
{
    use DatabaseCleanupTrait;

    #[Test]
    public function getKeyReturnsMatchingKey(): void
    {
        $this->getDatabase()->set('test/conv/a', '1');
        $this->getDatabase()->set('test/conv/b', '2');
        $this->getDatabase()->set('test/conv/c', '3');

        $key = $this->getDatabase()->getKey(KeySelector::firstGreaterOrEqual('test/conv/b'));
        self::assertSame('test/conv/b', $key);
    }

    #[Test]
    public function getKeyWithFirstGreaterThan(): void
    {
        $this->getDatabase()->set('test/conv/a', '1');
        $this->getDatabase()->set('test/conv/b', '2');
        $this->getDatabase()->set('test/conv/c', '3');

        $key = $this->getDatabase()->getKey(KeySelector::firstGreaterThan('test/conv/a'));
        self::assertSame('test/conv/b', $key);
    }

    #[Test]
    public function getKeyWithLastLessOrEqual(): void
    {
        $this->getDatabase()->set('test/conv/a', '1');
        $this->getDatabase()->set('test/conv/b', '2');
        $this->getDatabase()->set('test/conv/c', '3');

        $key = $this->getDatabase()->getKey(KeySelector::lastLessOrEqual('test/conv/c'));
        self::assertSame('test/conv/c', $key);
    }

    #[Test]
    public function getKeyWithLastLessThan(): void
    {
        $this->getDatabase()->set('test/conv/a', '1');
        $this->getDatabase()->set('test/conv/b', '2');
        $this->getDatabase()->set('test/conv/c', '3');

        $key = $this->getDatabase()->getKey(KeySelector::lastLessThan('test/conv/c'));
        self::assertSame('test/conv/b', $key);
    }

    #[Test]
    public function watchReturnsValidFuture(): void
    {
        $this->getDatabase()->set('test/conv/watch', 'initial');

        $watchFuture = $this->getDatabase()->watch('test/conv/watch');

        $this->getDatabase()->set('test/conv/watch', 'changed');

        $watchFuture->await();

        $this->addToAssertionCount(1);
    }

    #[Test]
    public function getAndWatchReturnsValueAndFuture(): void
    {
        $this->getDatabase()->set('test/conv/gaw', 'hello');

        [$value, $watchFuture] = $this->getDatabase()->getAndWatch('test/conv/gaw');

        self::assertSame('hello', $value);

        $this->getDatabase()->set('test/conv/gaw', 'world');
        $watchFuture->await();

        self::assertSame('world', $this->getDatabase()->get('test/conv/gaw'));
    }

    #[Test]
    public function getAndWatchReturnsNullForMissingKey(): void
    {
        [$value, $watchFuture] = $this->getDatabase()->getAndWatch('test/conv/gaw_missing');

        self::assertNull($value);

        $this->getDatabase()->set('test/conv/gaw_missing', 'appeared');
        $watchFuture->await();

        self::assertSame('appeared', $this->getDatabase()->get('test/conv/gaw_missing'));
    }

    #[Test]
    public function setAndWatchSetsValueAndReturnsWatchFuture(): void
    {
        $watchFuture = $this->getDatabase()->setAndWatch('test/conv/saw', 'initial');

        self::assertSame('initial', $this->getDatabase()->get('test/conv/saw'));

        $this->getDatabase()->set('test/conv/saw', 'changed');
        $watchFuture->await();

        self::assertSame('changed', $this->getDatabase()->get('test/conv/saw'));
    }

    #[Test]
    public function clearAndWatchClearsKeyAndReturnsWatchFuture(): void
    {
        $this->getDatabase()->set('test/conv/caw', 'to_delete');

        $watchFuture = $this->getDatabase()->clearAndWatch('test/conv/caw');

        self::assertNull($this->getDatabase()->get('test/conv/caw'));

        $this->getDatabase()->set('test/conv/caw', 'reappeared');
        $watchFuture->await();

        self::assertSame('reappeared', $this->getDatabase()->get('test/conv/caw'));
    }

    #[Test]
    public function addAtomicOperation(): void
    {
        $this->getDatabase()->set('test/conv/add', pack('P', 10));

        $this->getDatabase()->add('test/conv/add', 5);

        self::assertSame(15, $this->getDatabase()->getInt('test/conv/add'));
    }

    #[Test]
    public function bitAndAtomicOperation(): void
    {
        $this->getDatabase()->set('test/conv/band', pack('P', 0xFF0F));

        $this->getDatabase()->bitAnd('test/conv/band', 0x0F0F);

        self::assertSame(0x0F0F, $this->getDatabase()->getInt('test/conv/band'));
    }

    #[Test]
    public function bitOrAtomicOperation(): void
    {
        $this->getDatabase()->set('test/conv/bor', pack('P', 0xF000));

        $this->getDatabase()->bitOr('test/conv/bor', 0x0F0F);

        self::assertSame(0xFF0F, $this->getDatabase()->getInt('test/conv/bor'));
    }

    #[Test]
    public function bitXorAtomicOperation(): void
    {
        $this->getDatabase()->set('test/conv/bxor', pack('P', 0xFFFF));

        $this->getDatabase()->bitXor('test/conv/bxor', 0x0F0F);

        self::assertSame(0xF0F0, $this->getDatabase()->getInt('test/conv/bxor'));
    }

    #[Test]
    public function maxAtomicOperation(): void
    {
        $this->getDatabase()->set('test/conv/max', pack('P', 10));

        $this->getDatabase()->max('test/conv/max', 20);

        self::assertSame(20, $this->getDatabase()->getInt('test/conv/max'));
    }

    #[Test]
    public function maxAtomicOperationKeepsExistingWhenLarger(): void
    {
        $this->getDatabase()->set('test/conv/max2', pack('P', 30));

        $this->getDatabase()->max('test/conv/max2', 5);

        self::assertSame(30, $this->getDatabase()->getInt('test/conv/max2'));
    }

    #[Test]
    public function minAtomicOperation(): void
    {
        $this->getDatabase()->set('test/conv/min', pack('P', 20));

        $this->getDatabase()->min('test/conv/min', 10);

        self::assertSame(10, $this->getDatabase()->getInt('test/conv/min'));
    }

    #[Test]
    public function minAtomicOperationKeepsExistingWhenSmaller(): void
    {
        $this->getDatabase()->set('test/conv/min2', pack('P', 5));

        $this->getDatabase()->min('test/conv/min2', 30);

        self::assertSame(5, $this->getDatabase()->getInt('test/conv/min2'));
    }

    #[Test]
    public function getIntReturnsNullForMissingKey(): void
    {
        self::assertNull($this->getDatabase()->getInt('test/conv/nonexistent'));
    }

    #[Test]
    public function compareAndClearRemovesKeyWhenValueMatches(): void
    {
        $this->getDatabase()->set('test/conv/cac', 'match_me');

        $this->getDatabase()->compareAndClear('test/conv/cac', 'match_me');

        self::assertNull($this->getDatabase()->get('test/conv/cac'));
    }

    #[Test]
    public function compareAndClearKeepsKeyWhenValueDiffers(): void
    {
        $this->getDatabase()->set('test/conv/cac2', 'keep_me');

        $this->getDatabase()->compareAndClear('test/conv/cac2', 'different');

        self::assertSame('keep_me', $this->getDatabase()->get('test/conv/cac2'));
    }

    #[Test]
    public function getEstimatedRangeSizeBytesReturnsNonNegative(): void
    {
        for ($i = 0; $i < 10; $i++) {
            $this->getDatabase()->set("test/conv/est/{$i}", str_repeat('x', 100));
        }

        $size = $this->getDatabase()->getEstimatedRangeSizeBytes('test/conv/est/', 'test/conv/est0');

        self::assertGreaterThanOrEqual(0, $size);
    }

    #[Test]
    public function getRangeSplitPointsReturnsArray(): void
    {
        for ($i = 0; $i < 10; $i++) {
            $this->getDatabase()->set("test/conv/split/{$i}", str_repeat('x', 100));
        }

        $points = $this->getDatabase()->getRangeSplitPoints('test/conv/split/', 'test/conv/split0', 1000);

        self::assertGreaterThanOrEqual(0, count($points));
    }
}
