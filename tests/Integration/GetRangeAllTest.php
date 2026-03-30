<?php

declare(strict_types=1);

namespace CrazyGoat\FoundationDB\Tests\Integration;

use CrazyGoat\FoundationDB\KeyValue;
use CrazyGoat\FoundationDB\RangeOptions;
use CrazyGoat\FoundationDB\Transaction;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class GetRangeAllTest extends TestCase
{
    use DatabaseCleanupTrait;

    #[Test]
    public function getRangeAllReturnsAllResults(): void
    {
        $this->getDatabase()->transact(function (Transaction $tr): void {
            for ($i = 0; $i < 10; $i++) {
                $tr->set(sprintf('test/rangeall/key%02d', $i), "value{$i}");
            }
        });

        $results = $this->getDatabase()->getRangeAll('test/rangeall/key00', 'test/rangeall/key99');

        self::assertCount(10, $results);
        foreach ($results as $i => $kv) {
            self::assertInstanceOf(KeyValue::class, $kv);
            self::assertSame(sprintf('test/rangeall/key%02d', $i), $kv->key);
            self::assertSame("value{$i}", $kv->value);
        }
    }

    #[Test]
    public function getRangeAllWithLimit(): void
    {
        $this->getDatabase()->transact(function (Transaction $tr): void {
            for ($i = 0; $i < 20; $i++) {
                $tr->set(sprintf('test/rangeall/limit/key%02d', $i), "value{$i}");
            }
        });

        $options = new RangeOptions(limit: 5);
        $results = $this->getDatabase()->getRangeAll(
            'test/rangeall/limit/key00',
            'test/rangeall/limit/key99',
            $options,
        );

        self::assertCount(5, $results);
    }

    #[Test]
    public function getRangeAllWithReverse(): void
    {
        $this->getDatabase()->transact(function (Transaction $tr): void {
            for ($i = 0; $i < 5; $i++) {
                $tr->set(sprintf('test/rangeall/reverse/key%02d', $i), "value{$i}");
            }
        });

        $options = new RangeOptions(reverse: true);
        $results = $this->getDatabase()->getRangeAll(
            'test/rangeall/reverse/key00',
            'test/rangeall/reverse/key99',
            $options,
        );

        self::assertCount(5, $results);
        // Results should be in reverse order
        foreach ($results as $i => $kv) {
            $expectedIndex = 4 - $i;
            self::assertSame(sprintf('test/rangeall/reverse/key%02d', $expectedIndex), $kv->key);
        }
    }

    #[Test]
    public function getRangeAllEmptyRange(): void
    {
        $results = $this->getDatabase()->getRangeAll('test/rangeall/empty/a', 'test/rangeall/empty/z');

        self::assertCount(0, $results);
    }

    #[Test]
    public function getRangeAllWithSnapshot(): void
    {
        $this->getDatabase()->transact(function (Transaction $tr): void {
            for ($i = 0; $i < 5; $i++) {
                $tr->set(sprintf('test/rangeall/snapshot/key%02d', $i), "value{$i}");
            }
        });

        // Use snapshot via transaction, not RangeOptions
        $tr = $this->getDatabase()->createTransaction();
        $snap = $tr->snapshot();
        $results = $snap->getRange(
            'test/rangeall/snapshot/key00',
            'test/rangeall/snapshot/key99',
        )->toArray();

        self::assertCount(5, $results);
    }

    #[Test]
    public function getRangeAllWithStreamingMode(): void
    {
        $this->getDatabase()->transact(function (Transaction $tr): void {
            for ($i = 0; $i < 100; $i++) {
                $tr->set(sprintf('test/rangeall/stream/key%03d', $i), "value{$i}");
            }
        });

        $options = new RangeOptions(mode: \CrazyGoat\FoundationDB\Enum\StreamingMode::Small);
        $results = $this->getDatabase()->getRangeAll(
            'test/rangeall/stream/key000',
            'test/rangeall/stream/key999',
            $options,
        );

        self::assertCount(100, $results);
    }

    #[Test]
    public function getRangeAllStartsWith(): void
    {
        $this->getDatabase()->transact(function (Transaction $tr): void {
            $tr->set('test/rangeall/prefix/a', '1');
            $tr->set('test/rangeall/prefix/b', '2');
            $tr->set('test/rangeall/prefix/c', '3');
            $tr->set('test/rangeall/other', 'other');
        });

        $results = $this->getDatabase()->getRangeAllStartsWith('test/rangeall/prefix/');

        self::assertCount(3, $results);
    }

    #[Test]
    public function getRangeAllWithKeySelectors(): void
    {
        $this->getDatabase()->transact(function (Transaction $tr): void {
            $tr->set('test/rangeall/selectors/a', '1');
            $tr->set('test/rangeall/selectors/b', '2');
            $tr->set('test/rangeall/selectors/c', '3');
        });

        $begin = \CrazyGoat\FoundationDB\KeySelector::firstGreaterOrEqual('test/rangeall/selectors/a');
        $end = \CrazyGoat\FoundationDB\KeySelector::firstGreaterOrEqual('test/rangeall/selectors/d');

        $results = $this->getDatabase()->getRangeAll($begin, $end);

        self::assertCount(3, $results);
    }
}
