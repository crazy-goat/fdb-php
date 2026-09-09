<?php

declare(strict_types=1);

namespace CrazyGoat\FoundationDB;

use Closure;
use CrazyGoat\FoundationDB\Enum\StreamingMode;
use CrazyGoat\FoundationDB\Future\FutureKeyValueArray;
use CrazyGoat\FoundationDB\Future\KvResultFuture;

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
            ): KvResultFuture => $this->getRangeRaw($begin, $end, $limit, $mode, $iteration, $reverse),
        );
    }

    /**
     * Iterates a range across server batches, advancing the (exclusive) endpoint
     * strictly past the last key of the previous batch so that no key is yielded twice.
     *
     * Read-ahead: as soon as the current chunk resolves and more pages remain,
     * the request for the NEXT chunk is issued BEFORE any item of the current
     * chunk is yielded — so the next page is already in flight while the
     * consumer processes the current one, mirroring Java's AsyncIterable.
     *
     * @param Closure(KeySelector, KeySelector, int, StreamingMode, int, bool): KvResultFuture $fetcher
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
        $begin = $beginSelector;
        $end = $endSelector;

        // A limit of 0 means "no rows" — return immediately.
        if ($limit === 0) {
            return;
        }

        $pending = null;
        $nextBegin = null;
        $nextEnd = null;

        while (true) {
            $currentLimit = $limit !== null ? $limit - $fetched : 0;

            $pending ??= $fetcher($begin, $end, $currentLimit, $mode, $iteration, $reverse);
            $result = $pending->await();
            $pending = null;

            $kvs = $result->kvs;
            $count = $result->count;

            // Decide whether another page is needed; if so, issue the request
            // now (read-ahead) instead of after the current chunk is drained.
            $more = $count > 0
                && $result->more
                && ($limit === null || $fetched + $count < $limit);

            if ($more) {
                $lastKey = $kvs[$count - 1]->key;

                if ($reverse) {
                    $nextBegin = $begin;
                    $nextEnd = KeySelector::firstGreaterOrEqual($lastKey);
                } else {
                    $nextBegin = KeySelector::firstGreaterThan($lastKey);
                    $nextEnd = $end;
                }

                $pending = $fetcher(
                    $nextBegin,
                    $nextEnd,
                    $limit !== null ? $limit - $fetched - $count : 0,
                    $mode,
                    $iteration + 1,
                    $reverse,
                );
            }

            foreach ($kvs as $kv) {
                yield $kv;
                $fetched++;
            }

            if (!$more) {
                break;
            }

            $begin = $nextBegin;
            $end = $nextEnd;
            $iteration++;
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
