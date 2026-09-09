<?php

declare(strict_types=1);

namespace CrazyGoat\FoundationDB;

use Closure;
use CrazyGoat\FoundationDB\Enum\StreamingMode;
use CrazyGoat\FoundationDB\Future\FutureKeyValueArray;
use CrazyGoat\FoundationDB\Future\KvsFuture;

/** @implements \IteratorAggregate<int, KeyValue> */
final readonly class RangeResult implements \IteratorAggregate
{
    public function __construct(
        private ReadTransaction $transaction,
        private KeySelector $beginSelector,
        private KeySelector $endSelector,
        private RangeOptions $options,
        private bool $snapshot,
        private NativeClient $client,
    ) {
    }

    /**
     * @return \Generator<int, KeyValue>
     */
    public function getIterator(): \Generator
    {
        yield from self::paginate(
            $this->beginSelector,
            $this->endSelector,
            $this->options,
            fn (
                KeySelector $begin,
                KeySelector $end,
                int $limit,
                StreamingMode $mode,
                int $iteration,
                bool $reverse,
            ): FutureKeyValueArray => $this->getRangeRaw($begin, $end, $limit, $mode, $iteration, $reverse),
        );
    }

    /**
     * Iterates a range across server batches, advancing the (exclusive) endpoint
     * strictly past the last key of the previous batch so that no key is yielded twice.
     *
     * Read-ahead: while the current batch is being consumed, the request for the
     * next batch is already in flight (the fetcher returns an un-awaited
     * future), so page N+1's network round trip overlaps with the consumption
     * of page N instead of serializing after it.
     *
     * @param Closure(KeySelector, KeySelector, int, StreamingMode, int, bool): KvsFuture $fetcher
     * @return \Generator<int, KeyValue>
     */
    public static function paginate(
        KeySelector $beginSelector,
        KeySelector $endSelector,
        RangeOptions $options,
        Closure $fetcher,
    ): \Generator {
        $limit = $options->limit;
        $reverse = $options->reverse;
        $mode = $options->mode;
        $iteration = 1;
        $fetched = 0;

        // A limit of 0 means "no rows" — return immediately.
        if ($limit === 0) {
            return;
        }

        $future = $fetcher($beginSelector, $endSelector, $limit ?? 0, $mode, $iteration, $reverse);

        while (true) {
            $result = $future->await();
            $kvs = $result->kvs;
            $count = $result->count;
            $fetched += $count;

            $exhausted = $count === 0
                || !$result->more
                || ($limit !== null && $fetched >= $limit);

            if ($exhausted) {
                foreach ($kvs as $kv) {
                    yield $kv;
                }

                break;
            }

            // Prefetch the next batch BEFORE yielding the current one, so its
            // round trip overlaps with the consumer processing this batch.
            $lastKey = $kvs[$count - 1]->key;
            $nextLimit = $limit !== null ? $limit - $fetched : 0;

            if ($reverse) {
                $endSelector = KeySelector::firstGreaterOrEqual($lastKey);
            } else {
                $beginSelector = KeySelector::firstGreaterThan($lastKey);
            }

            $iteration++;
            $future = $fetcher($beginSelector, $endSelector, $nextLimit, $mode, $iteration, $reverse);

            foreach ($kvs as $kv) {
                yield $kv;
            }
        }
    }

    /**
     * @return list<KeyValue>
     */
    public function toArray(): array
    {
        return iterator_to_array($this->getIterator(), false);
    }

    private function getRangeRaw(
        KeySelector $begin,
        KeySelector $end,
        int $limit,
        StreamingMode $mode,
        int $iteration,
        bool $reverse,
    ): FutureKeyValueArray {
        $beginKeyLength = KeyValueLimits::assertValidRangeEndpoint($begin->key);
        $endKeyLength = KeyValueLimits::assertValidRangeEndpoint($end->key);

        return new FutureKeyValueArray(
            $this->client->fdb->fdb_transaction_get_range(
                $this->transaction->getPointer(),
                $begin->key,
                $beginKeyLength,
                $begin->orEqual ? 1 : 0,
                $begin->offset,
                $end->key,
                $endKeyLength,
                $end->orEqual ? 1 : 0,
                $end->offset,
                $limit,
                0,
                $mode->value,
                $iteration,
                $this->snapshot ? 1 : 0,
                $reverse ? 1 : 0,
            ),
            $this->client,
        );
    }
}
