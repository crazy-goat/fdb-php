<?php

declare(strict_types=1);

namespace CrazyGoat\FoundationDB\Tests\Unit;

use Closure;
use CrazyGoat\FoundationDB\Enum\StreamingMode;
use CrazyGoat\FoundationDB\Future\FutureKvResult;
use CrazyGoat\FoundationDB\Future\KvResultFuture;
use CrazyGoat\FoundationDB\KeySelector;
use CrazyGoat\FoundationDB\KeyValue;
use CrazyGoat\FoundationDB\RangeOptions;
use CrazyGoat\FoundationDB\RangeResult;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class RangeResultTest extends TestCase
{
    /**
     * Emulates the FoundationDB server's get_range semantics so pagination can be
     * exercised without a running cluster. Selectors are resolved exactly as FDB
     * does: a "firstGreaterOrEqual" selector resolves to the first key >= its key,
     * while "firstGreaterThan" resolves to the first key > its key. The begin
     * selector is an inclusive lower bound (or strictly greater for "firstGreaterThan"),
     * the end selector is an exclusive upper bound (or strictly greater for "firstGreaterThan").
     *
     * @param list<KeyValue> $data
     * @param int<1, max> $batchSize
     * @return Closure(KeySelector, KeySelector, int, StreamingMode, int, bool): KvResultFuture
     */
    private function fakeServer(array $data, int $batchSize): Closure
    {
        $lowerBound = static fn (string $key): int => self::bound($data, $key, false);
        $upperBound = static fn (string $key): int => self::bound($data, $key, true);

        $resolveLower = (static fn(KeySelector $s): int => $s->orEqual ? $upperBound($s->key) : $lowerBound($s->key));
        $resolveUpper = (static fn(KeySelector $s): int => $s->orEqual ? $upperBound($s->key) : $lowerBound($s->key));

        return static function (
            KeySelector $begin,
            KeySelector $end,
            int $limit,
            StreamingMode $mode,
            int $iteration,
            bool $reverse,
        ) use (
            $data,
            $batchSize,
            $resolveLower,
            $resolveUpper
        ): KvResultFuture {
            $lo = $resolveLower($begin);
            $hi = $resolveUpper($end);

            if ($lo < 0) {
                $lo = 0;
            }
            if ($hi > count($data)) {
                $hi = count($data);
            }

            if ($lo >= $hi) {
                return new FakeKvFuture(new FutureKvResult([], 0, false));
            }

            if ($reverse) {
                $from = max($lo, $hi - $batchSize);
                $slice = array_reverse(array_slice($data, $from, $hi - $from));
            } else {
                $to = min($hi, $lo + $batchSize);
                $slice = array_slice($data, $lo, $to - $lo);
            }

            $total = $hi - $lo;
            $more = $total > count($slice);

            return new FakeKvFuture(new FutureKvResult($slice, count($slice), $more));
        };
    }

    /**
     * @param list<KeyValue> $data
     */
    private static function bound(array $data, string $key, bool $strict): int
    {
        $lo = 0;
        $hi = count($data);
        while ($lo < $hi) {
            $mid = intdiv($lo + $hi, 2);
            $cmp = strcmp($data[$mid]->key, $key);
            if ($cmp < 0 || ($strict && $cmp === 0)) {
                $lo = $mid + 1;
            } else {
                $hi = $mid;
            }
        }

        return $lo;
    }

    /**
     * @return list<KeyValue>
     */
    private function dataset(int $n): array
    {
        $data = [];
        for ($i = 0; $i < $n; $i++) {
            $key = sprintf('k%04d', $i);
            $data[] = new KeyValue($key, "value{$i}");
        }

        return $data;
    }

    #[Test]
    public function reverseIterationYieldsEachKeyExactlyOnceInDescendingOrder(): void
    {
        $data = $this->dataset(1000);
        $fetcher = $this->fakeServer($data, 100);

        $result = RangeResult::paginate(
            KeySelector::firstGreaterOrEqual('k0000'),
            KeySelector::firstGreaterOrEqual('k9999'),
            new RangeOptions(reverse: true),
            $fetcher,
        );

        $keys = [];
        foreach ($result as $kv) {
            $keys[] = $kv->key;
        }

        self::assertCount(1000, $keys, 'reverse iteration must return every key');
        self::assertSame(1000, count(array_unique($keys)), 'reverse iteration must not duplicate keys');
        self::assertSame(array_reverse(array_map(static fn (KeyValue $kv): string => $kv->key, $data)), $keys);
    }

    #[Test]
    public function forwardIterationYieldsEachKeyExactlyOnceInAscendingOrder(): void
    {
        $data = $this->dataset(1000);
        $fetcher = $this->fakeServer($data, 100);

        $result = RangeResult::paginate(
            KeySelector::firstGreaterOrEqual('k0000'),
            KeySelector::firstGreaterOrEqual('k9999'),
            new RangeOptions(reverse: false),
            $fetcher,
        );

        $keys = [];
        foreach ($result as $kv) {
            $keys[] = $kv->key;
        }

        self::assertCount(1000, $keys, 'forward iteration must return every key');
        self::assertSame(1000, count(array_unique($keys)), 'forward iteration must not duplicate keys');
        self::assertSame(array_map(static fn (KeyValue $kv): string => $kv->key, $data), $keys);
    }

    #[Test]
    public function reverseIterationWithSmallBatchDoesNotDuplicateBoundaryKeys(): void
    {
        $data = $this->dataset(10);
        $fetcher = $this->fakeServer($data, 4);

        $result = RangeResult::paginate(
            KeySelector::firstGreaterOrEqual('k0000'),
            KeySelector::firstGreaterOrEqual('k9999'),
            new RangeOptions(reverse: true),
            $fetcher,
        );

        $keys = [];
        foreach ($result as $kv) {
            $keys[] = $kv->key;
        }

        self::assertSame(
            ['k0009', 'k0008', 'k0007', 'k0006', 'k0005', 'k0004', 'k0003', 'k0002', 'k0001', 'k0000'],
            $keys,
        );
    }

    #[Test]
    public function zeroLimitReturnsNoRows(): void
    {
        $data = $this->dataset(10);
        $fetcher = $this->fakeServer($data, 4);

        $result = RangeResult::paginate(
            KeySelector::firstGreaterOrEqual('k0000'),
            KeySelector::firstGreaterOrEqual('k9999'),
            new RangeOptions(limit: 0),
            $fetcher,
        );

        self::assertCount(0, iterator_to_array($result));
    }

    #[Test]
    public function nextChunkRequestIsIssuedBeforeCurrentChunkIsConsumed(): void
    {
        $data = $this->dataset(50);
        $events = [];

        $fetcher = function (
            KeySelector $begin,
            KeySelector $end,
            int $limit,
            StreamingMode $mode,
            int $iteration,
            bool $reverse,
        ) use (
            $data,
            &$events
): KvResultFuture {
            $events[] = sprintf('request:%d', $iteration);
            $slice = array_slice($data, ($iteration - 1) * 10, 10);
            $more = count($slice) === 10 && ($iteration - 1) * 10 + 10 < count($data);

            return new FakeKvFuture(new FutureKvResult($slice, count($slice), $more));
        };

        $result = RangeResult::paginate(
            KeySelector::firstGreaterOrEqual('k0000'),
            KeySelector::firstGreaterOrEqual('k0099'),
            new RangeOptions(),
            $fetcher,
        );

        $yielded = [];
        foreach ($result as $kv) {
            if ($yielded === []) {
                // Read-ahead: request #2 must already be in flight before the
                // first item of chunk #1 is handed to the consumer.
                self::assertContains('request:2', $events);
                self::assertNotContains('request:3', $events, 'no further read-ahead until the next page is needed');
            }
            $yielded[] = $kv->key;
        }

        self::assertCount(50, $yielded);
        self::assertSame(['request:1', 'request:2', 'request:3', 'request:4', 'request:5'], $events);
    }

    #[Test]
    public function limitIsHonoredWithReadAheadWithoutOverfetching(): void
    {
        $data = $this->dataset(100);
        $requests = [];
        $fetcher = function (
            KeySelector $begin,
            KeySelector $end,
            int $limit,
            StreamingMode $mode,
            int $iteration,
            bool $reverse,
        ) use (
            $data,
            &$requests
): KvResultFuture {
            $lo = (int) substr($begin->key, 1);
            $slice = array_slice($data, $lo, $limit);
            $requests[] = [$lo, $limit];
            $more = $lo + count($slice) < count($data);

            return new FakeKvFuture(new FutureKvResult($slice, count($slice), $more));
        };

        $result = RangeResult::paginate(
            KeySelector::firstGreaterOrEqual('k0000'),
            KeySelector::firstGreaterOrEqual('k0099'),
            new RangeOptions(limit: 25),
            $fetcher,
        );

        $keys = [];
        foreach ($result as $kv) {
            $keys[] = $kv->key;
        }

        self::assertCount(25, $keys);
        self::assertSame(['k0000', 'k0024'], [$keys[0], $keys[24]]);
        // 25 rows fit in a single request; no page may be requested once the
        // limit is reached — read-ahead must not overfetch past the limit.
        self::assertSame([[0, 25]], $requests);
    }
}
