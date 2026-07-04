<?php

declare(strict_types=1);

namespace CrazyGoat\FoundationDB;

use CrazyGoat\FoundationDB\Enum\StreamingMode;
use CrazyGoat\FoundationDB\Future\FutureKeyValueArray;

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
        $beginSelector = $this->beginSelector;
        $endSelector = $this->endSelector;
        $limit = $this->options->limit;
        $reverse = $this->options->reverse;
        $mode = $this->options->mode;
        $iteration = 1;
        $fetched = 0;

        // A limit of 0 means "no rows" — return immediately.
        if ($limit === 0) {
            return;
        }

        while (true) {
            $currentLimit = $limit !== null ? $limit - $fetched : 0;

            $future = $this->getRangeRaw(
                $beginSelector,
                $endSelector,
                $currentLimit,
                $mode,
                $iteration,
                $reverse,
            );

            $result = $future->await();
            $kvs = $result->kvs;
            $count = $result->count;

            foreach ($kvs as $kv) {
                yield $kv;
                $fetched++;
            }

            if ($count === 0 || !$result->more) {
                break;
            }

            if ($limit !== null && $fetched >= $limit) {
                break;
            }

            $lastKey = $kvs[$count - 1]->key;

            if ($reverse) {
                $endSelector = KeySelector::firstGreaterOrEqual($lastKey);
            } else {
                $beginSelector = KeySelector::firstGreaterThan($lastKey);
            }

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
