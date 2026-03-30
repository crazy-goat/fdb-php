<?php

declare(strict_types=1);

namespace CrazyGoat\FoundationDB;

use CrazyGoat\FoundationDB\Future\FutureInt64;
use CrazyGoat\FoundationDB\Future\FutureKey;
use CrazyGoat\FoundationDB\Future\FutureKeyArray;
use CrazyGoat\FoundationDB\Future\FutureStringArray;
use CrazyGoat\FoundationDB\Future\FutureValue;
use FFI\CData;

class ReadTransaction
{
    public function __construct(
        protected readonly CData $tpointer,
        protected readonly Database $db,
        protected readonly NativeClient $client,
        protected readonly bool $isSnapshot,
    ) {
    }

    public function get(string|KeyConvertible $key): FutureValue
    {
        $resolvedKey = $this->resolveKey($key);

        return new FutureValue(
            $this->client->fdb->fdb_transaction_get(
                $this->tpointer,
                $resolvedKey,
                strlen($resolvedKey),
                $this->isSnapshot ? 1 : 0,
            ),
            $this->client,
        );
    }

    public function getKey(KeySelector $selector): FutureKey
    {
        return new FutureKey(
            $this->client->fdb->fdb_transaction_get_key(
                $this->tpointer,
                $selector->key,
                strlen($selector->key),
                $selector->orEqual ? 1 : 0,
                $selector->offset,
                $this->isSnapshot ? 1 : 0,
            ),
            $this->client,
        );
    }

    public function getReadVersion(): FutureInt64
    {
        return new FutureInt64(
            $this->client->fdb->fdb_transaction_get_read_version($this->tpointer),
            $this->client,
        );
    }

    public function getEstimatedRangeSizeBytes(string $begin, string $end): FutureInt64
    {
        return new FutureInt64(
            $this->client->fdb->fdb_transaction_get_estimated_range_size_bytes(
                $this->tpointer,
                $begin,
                strlen($begin),
                $end,
                strlen($end),
            ),
            $this->client,
        );
    }

    public function getRangeSplitPoints(string $begin, string $end, int $chunkSize): FutureKeyArray
    {
        return new FutureKeyArray(
            $this->client->fdb->fdb_transaction_get_range_split_points(
                $this->tpointer,
                $begin,
                strlen($begin),
                $end,
                strlen($end),
                $chunkSize,
            ),
            $this->client,
        );
    }

    public function getAddressesForKey(string $key): FutureStringArray
    {
        return new FutureStringArray(
            $this->client->fdb->fdb_transaction_get_addresses_for_key(
                $this->tpointer,
                $key,
                strlen($key),
            ),
            $this->client,
        );
    }

    public function getRange(
        string|KeySelector $begin,
        string|KeySelector $end,
        ?RangeOptions $options = null,
    ): RangeResult {
        $options ??= new RangeOptions();

        $beginSelector = $begin instanceof KeySelector
            ? $begin
            : KeySelector::firstGreaterOrEqual($begin);

        $endSelector = $end instanceof KeySelector
            ? $end
            : KeySelector::firstGreaterOrEqual($end);

        return new RangeResult(
            $this,
            $beginSelector,
            $endSelector,
            $options,
            $this->isSnapshot,
            $this->client,
        );
    }

    public function getRangeStartsWith(
        string $prefix,
        ?RangeOptions $options = null,
    ): RangeResult {
        $end = KeyUtil::strinc($prefix);

        return $this->getRange(
            $prefix,
            $end ?? "\xFF",
            $options,
        );
    }

    /** @internal */
    public function getPointer(): CData
    {
        return $this->tpointer;
    }

    protected function resolveKey(string|KeyConvertible $key): string
    {
        if ($key instanceof KeyConvertible) {
            return $key->asFoundationDbKey();
        }

        return $key;
    }
}
