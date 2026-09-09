<?php

declare(strict_types=1);

namespace CrazyGoat\FoundationDB;

use CrazyGoat\FoundationDB\Chunk\ChunkKeyCodec;
use CrazyGoat\FoundationDB\Enum\StreamingMode;
use CrazyGoat\FoundationDB\Future\FutureDouble;
use CrazyGoat\FoundationDB\Future\FutureGranuleSummaryArray;
use CrazyGoat\FoundationDB\Future\FutureInt64;
use CrazyGoat\FoundationDB\Future\FutureKey;
use CrazyGoat\FoundationDB\Future\FutureKeyArray;
use CrazyGoat\FoundationDB\Future\FutureKeyRangeArray;
use CrazyGoat\FoundationDB\Future\FutureMappedKeyValueArray;
use CrazyGoat\FoundationDB\Future\FutureStringArray;
use CrazyGoat\FoundationDB\Future\FutureValue;
use FFI;
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
        $keyLength = KeyValueLimits::assertValidKey($resolvedKey);

        return new FutureValue(
            $this->client->fdb->fdb_transaction_get(
                $this->tpointer,
                $resolvedKey,
                $keyLength,
                $this->isSnapshot ? 1 : 0,
            ),
            $this->client,
        );
    }

    /**
     * Read back a chunked value written by `Transaction::setValueChunked()`.
     *
     * One range read reassembles all chunks of the active generation:
     *
     * - key holds no chunked value (no metadata record) → `null`
     * - key holds an empty chunked value → `""` (not `null`)
     * - metadata present but malformed / chunk data missing or of the wrong
     *   total length → `ChunkedValueCorruptedException` (loud failure instead
     *   of silently returning garbage)
     *
     * Available on snapshots too (inherited by `Snapshot`); note that snapshot
     * reads create no read-conflict ranges.
     *
     * @throws \InvalidArgumentException               when the key violates
     *                                                 the FDB size limits
     * @throws ChunkedValueCorruptedException          when the stored data
     *                                                 cannot be interpreted
     */
    public function getValueChunked(string|KeyConvertible $key): ?string
    {
        $resolvedKey = $this->resolveKey($key);
        KeyValueLimits::assertValidKey($resolvedKey);

        $metaRaw = $this->get(ChunkKeyCodec::metaKey($resolvedKey))->await();
        if ($metaRaw === null) {
            return null;
        }

        $meta = ChunkKeyCodec::unpackMeta($metaRaw);

        if ($meta['count'] === 0) {
            return '';
        }

        $rows = $this->getRangeAll(
            ChunkKeyCodec::generationBegin($resolvedKey, $meta['generation']),
            ChunkKeyCodec::generationEnd($resolvedKey, $meta['generation']),
        );

        if (count($rows) !== $meta['count']) {
            throw new ChunkedValueCorruptedException(sprintf(
                'Chunked value under "%s" declares %d chunks but %d were found in the range',
                $resolvedKey,
                $meta['count'],
                count($rows),
            ));
        }

        $assembled = '';
        foreach ($rows as $row) {
            $assembled .= $row->value;
        }

        if (strlen($assembled) !== $meta['length']) {
            throw new ChunkedValueCorruptedException(sprintf(
                'Chunked value under "%s" assembles to %d bytes but the metadata declares %d',
                $resolvedKey,
                strlen($assembled),
                $meta['length'],
            ));
        }

        return $assembled;
    }

    public function getKey(KeySelector $selector): FutureKey
    {
        $selectorKeyLength = KeyValueLimits::assertValidRangeEndpoint($selector->key);

        return new FutureKey(
            $this->client->fdb->fdb_transaction_get_key(
                $this->tpointer,
                $selector->key,
                $selectorKeyLength,
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

    /**
     * Total accumulated cost of the transaction so far, in the cluster's
     * cost units (as used by cost-based throttling and the 10,000,000-unit
     * per-transaction cost limit).
     */
    public function getTotalCost(): FutureInt64
    {
        return new FutureInt64(
            $this->client->fdb->fdb_transaction_get_total_cost($this->tpointer),
            $this->client,
        );
    }

    /**
     * Number of seconds this transaction has been throttled due to tag
     * throttling so far.
     */
    public function getTagThrottledDuration(): FutureDouble
    {
        return new FutureDouble(
            $this->client->fdb->fdb_transaction_get_tag_throttled_duration($this->tpointer),
            $this->client,
        );
    }

    public function getEstimatedRangeSizeBytes(string $begin, string $end): FutureInt64
    {
        $beginLength = KeyValueLimits::assertValidRangeEndpoint($begin);
        $endLength = KeyValueLimits::assertValidRangeEndpoint($end);

        return new FutureInt64(
            $this->client->fdb->fdb_transaction_get_estimated_range_size_bytes(
                $this->tpointer,
                $begin,
                $beginLength,
                $end,
                $endLength,
            ),
            $this->client,
        );
    }

    public function getRangeSplitPoints(string $begin, string $end, int $chunkSize): FutureKeyArray
    {
        $beginLength = KeyValueLimits::assertValidRangeEndpoint($begin);
        $endLength = KeyValueLimits::assertValidRangeEndpoint($end);

        return new FutureKeyArray(
            $this->client->fdb->fdb_transaction_get_range_split_points(
                $this->tpointer,
                $begin,
                $beginLength,
                $end,
                $endLength,
                $chunkSize,
            ),
            $this->client,
        );
    }

    /**
     * List the blob granule ranges within the given range. Backed by
     * `fdb_transaction_get_blob_granule_ranges`.
     *
     * Requires the cluster to have blob granules enabled
     * (`blob_granules_enabled=1`).
     *
     * @param int $rangeLimit Maximum number of ranges to return (0 = unlimited).
     */
    public function getBlobGranuleRanges(string $begin, string $end, int $rangeLimit = 0): FutureKeyRangeArray
    {
        $beginLength = KeyValueLimits::assertValidRangeEndpoint($begin);
        $endLength = KeyValueLimits::assertValidRangeEndpoint($end);

        return new FutureKeyRangeArray(
            $this->client->fdb->fdb_transaction_get_blob_granule_ranges(
                $this->tpointer,
                $begin,
                $beginLength,
                $end,
                $endLength,
                $rangeLimit,
            ),
            $this->client,
        );
    }

    /**
     * Read the blob granules covering the given range between
     * `$beginVersion` and `$readVersion`. Backed by
     * `fdb_transaction_read_blob_granules`, which returns an `FDBResult`
     * (a synchronous computation result) rather than a future — the call
     * blocks until the granule files have been fetched and materialized.
     *
     * File data is fetched through the supplied `BlobGranuleLoader`
     * (`FDBReadBlobGranuleContext` callbacks). When
     * `$debugNoMaterialize` is true the loader is not called at all and
     * only the request to the blob workers is issued (useful for testing).
     *
     * @param int|null $readVersion Read version; null for `Database::LATEST_VERSION` (-2),
     *                              meaning "use the transaction's read version".
     *
     * @return list<KeyValue> The materialized key-value pairs, sorted by key.
     */
    public function readBlobGranules(
        string $begin,
        string $end,
        int $beginVersion,
        ?BlobGranuleLoader $loader = null,
        ?int $readVersion = null,
        bool $debugNoMaterialize = false,
        int $granuleParallelism = 1,
    ): array {
        if (!$loader instanceof \CrazyGoat\FoundationDB\BlobGranuleLoader && !$debugNoMaterialize) {
            throw new \InvalidArgumentException(
                'readBlobGranules() requires a BlobGranuleLoader unless $debugNoMaterialize is true.',
            );
        }

        $beginLength = KeyValueLimits::assertValidRangeEndpoint($begin);
        $endLength = KeyValueLimits::assertValidRangeEndpoint($end);
        $readVersion ??= Database::LATEST_VERSION;
        $context = new BlobGranuleReadContext(
            $this->client,
            $loader ?? new class implements BlobGranuleLoader {
                public function startLoad(string $filename, int $offset, int $length, int $fullFileLength): int
                {
                    return 0;
                }

                public function getLoad(int $loadId): string
                {
                    return '';
                }

                public function freeLoad(int $loadId): void
                {
                }
            },
            $granuleParallelism,
        );
        $context->setDebugNoMaterialize($debugNoMaterialize);

        $resultPointer = $this->client->fdb->fdb_transaction_read_blob_granules(
            $this->tpointer,
            $begin,
            $beginLength,
            $end,
            $endLength,
            $beginVersion,
            $readVersion,
            $context->toCData(),
        );

        if ($resultPointer === null) {
            return [];
        }

        try {
            $outKv = $this->client->fdb->new('FDBKeyValue*');
            $outCount = $this->client->fdb->new('int');
            $outMore = $this->client->fdb->new('fdb_bool_t');

            $this->client->checkError(
                $this->client->fdb->fdb_result_get_keyvalue_array(
                    $resultPointer,
                    FFI::addr($outKv),
                    FFI::addr($outCount),
                    FFI::addr($outMore),
                ),
            );

            $count = $outCount->cdata;
            $kvs = [];

            for ($i = 0; $i < $count; $i++) {
                $kv = $outKv[$i];
                $kvs[] = new KeyValue(
                    FFI::string($kv->key, $kv->key_length),
                    FFI::string($kv->value, $kv->value_length),
                );
            }

            return $kvs;
        } finally {
            $this->client->fdb->fdb_result_destroy($resultPointer);
        }
    }

    /**
     * Summarize the blob granules within the given range at (or before) the
     * given version. Backed by `fdb_transaction_summarize_blob_granules`.
     *
     * Note: `rangeLimit` must be at least 1 — the client library asserts
     * `chunkLimit > 0` for this call (error 4100 otherwise).
     *
     * @param int|null $summaryVersion Summary version; null for the transaction's current read version.
     * @param int      $rangeLimit     Maximum number of granules to summarize (must be >= 1).
     */
    public function summarizeBlobGranules(
        string $begin,
        string $end,
        ?int $summaryVersion = null,
        int $rangeLimit = 100,
    ): FutureGranuleSummaryArray {
        if ($rangeLimit < 1) {
            throw new \InvalidArgumentException('summarizeBlobGranules() requires rangeLimit >= 1.');
        }

        $summaryVersion ??= $this->getReadVersion()->await();

        $beginLength = KeyValueLimits::assertValidRangeEndpoint($begin);
        $endLength = KeyValueLimits::assertValidRangeEndpoint($end);

        return new FutureGranuleSummaryArray(
            $this->client->fdb->fdb_transaction_summarize_blob_granules(
                $this->tpointer,
                $begin,
                $beginLength,
                $end,
                $endLength,
                $summaryVersion,
                $rangeLimit,
            ),
            $this->client,
        );
    }

    public function getAddressesForKey(string $key): FutureStringArray
    {
        $keyLength = KeyValueLimits::assertValidKey($key);

        return new FutureStringArray(
            $this->client->fdb->fdb_transaction_get_addresses_for_key(
                $this->tpointer,
                $key,
                $keyLength,
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

        // Validate the resulting range endpoints eagerly so an oversize key fails at
        // the call site instead of when iteration starts.
        KeyValueLimits::assertValidRangeEndpoint($beginSelector->key);
        KeyValueLimits::assertValidRangeEndpoint($endSelector->key);

        return new RangeResult(
            $this,
            $beginSelector,
            $endSelector,
            $options,
            $this->isSnapshot,
            $this->client,
        );
    }

    /**
     * Single-round-trip index lookups (fdb_transaction_get_mapped_range).
     *
     * Performs a range read over an index subspace and, for every index row,
     * fetches the records described by the mapper — all in one round trip to
     * the storage servers. Each resolved index row is returned as a
     * MappedKeyValue pairing the index key/value with its records.
     *
     * The mapper is a packed tuple template (see docs/range-reads.md):
     * elements like "{K[1]}" and "{V[0]}" are substituted with the
     * corresponding tuple components of the index key/value, literal
     * elements are copied verbatim, and "{...}" stands for the remaining
     * tuple elements.
     *
     * NOTE: current FoundationDB versions only support mapped ranges on
     * non-snapshot (read-your-writes) reads — call this on a Transaction.
     * On a Snapshot read a LogicException is thrown; the native client also
     * rejects the request with an FDBException.
     */
    public function getMappedRange(
        string|KeySelector $begin,
        string|KeySelector $end,
        string $mapper,
        ?RangeOptions $options = null,
    ): FutureMappedKeyValueArray {
        if ($this->isSnapshot) {
            throw new \LogicException(
                'getMappedRange() is only supported on non-snapshot (read-your-writes) reads; ' .
                'call it on a Transaction instead of Transaction::snapshot()',
            );
        }

        $options ??= new RangeOptions();

        $beginSelector = $begin instanceof KeySelector
            ? $begin
            : KeySelector::firstGreaterOrEqual($begin);

        $endSelector = $end instanceof KeySelector
            ? $end
            : KeySelector::firstGreaterOrEqual($end);

        // Validate the resulting range endpoints and the mapper eagerly so an
        // oversize key fails at the call site instead of when awaiting.
        $beginKeyLength = KeyValueLimits::assertValidRangeEndpoint($beginSelector->key);
        $endKeyLength = KeyValueLimits::assertValidRangeEndpoint($endSelector->key);
        $mapperLength = KeyValueLimits::assertValidFfiLength($mapper, 'Mapper template');

        return new FutureMappedKeyValueArray(
            $this->client->fdb->fdb_transaction_get_mapped_range(
                $this->tpointer,
                $beginSelector->key,
                $beginKeyLength,
                $beginSelector->orEqual ? 1 : 0,
                $beginSelector->offset,
                $endSelector->key,
                $endKeyLength,
                $endSelector->orEqual ? 1 : 0,
                $endSelector->offset,
                $mapper,
                $mapperLength,
                $options->limit ?? 0,
                0,
                $options->mode->value,
                1,
                // Mapped ranges are only supported on non-snapshot reads
                // (see the guard above).
                0,
                $options->reverse ? 1 : 0,
            ),
            $this->client,
        );
    }

    /**
     * @return list<KeyValue>
     */
    public function getRangeAll(
        string|KeySelector $begin,
        string|KeySelector $end,
        ?RangeOptions $options = null,
    ): array {
        $wantAllOptions = new RangeOptions(
            limit: $options?->limit,
            reverse: $options instanceof RangeOptions && $options->reverse,
            mode: StreamingMode::WantAll,
        );

        return $this->getRange($begin, $end, $wantAllOptions)->toArray();
    }

    /**
     * @return list<KeyValue>
     */
    public function getRangeAllStartsWith(
        string $prefix,
        ?RangeOptions $options = null,
    ): array {
        $end = KeyUtil::strinc($prefix);

        return $this->getRangeAll(
            $prefix,
            $end ?? "\xFF",
            $options,
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

    /**
     * Read a key and decode its value as a little-endian unsigned 64-bit integer.
     *
     * This is the counterpart to Transaction::add(), ::max(), ::min() and other
     * integer-based atomic operations.
     *
     * Stored values smaller than 8 bytes are right-padded with zero bytes;
     * values larger than 8 bytes are rejected with an exception because silently
     * truncating to 8 bytes would be observable only as incorrect data.
     *
     * @return ?int null if the key does not exist
     *
     * @throws \RuntimeException if the stored value is longer than 8 bytes or cannot be unpacked
     */
    public function getInt(string|KeyConvertible $key): ?int
    {
        $raw = $this->get($key)->await();

        if ($raw === null) {
            return null;
        }

        return self::decodeLittleEndianInt($raw);
    }

    /**
     * Decode a little-endian unsigned 64-bit integer from a byte string.
     *
     * Values shorter than 8 bytes are right-padded with zero bytes.
     * Values longer than 8 bytes cause a RuntimeException to make the
     * over-sized stored value visible instead of silently truncating it.
     *
     * @throws \RuntimeException if the value exceeds 8 bytes or unpack fails
     */
    protected static function decodeLittleEndianInt(string $raw): int
    {
        $length = strlen($raw);
        if ($length > 8) {
            throw new \RuntimeException(sprintf(
                'Cannot decode integer: stored value is %d bytes, expected at most 8.',
                $length,
            ));
        }

        if ($length < 8) {
            $raw = str_pad($raw, 8, "\x00");
        }

        $unpacked = unpack('P', $raw);

        if ($unpacked === false) {
            throw new \RuntimeException('Failed to decode integer value');
        }

        return $unpacked[1];
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
