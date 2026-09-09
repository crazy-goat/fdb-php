<?php

declare(strict_types=1);

namespace CrazyGoat\FoundationDB\Future;

use CrazyGoat\FoundationDB\KeyValue;
use CrazyGoat\FoundationDB\MappedKeyValue;
use FFI;

/**
 * Result of fdb_transaction_get_mapped_range.
 *
 * The native reply carries, per index row, either a point lookup
 * (GetValueReqAndResultRef, variant index 0) or a range lookup
 * (GetRangeReqAndResultRef, variant index 1) — depending on whether the
 * mapper tuple template ends with a "{...}" range descriptor.
 *
 * NOTE: the public fdb_c.h FDBMappedKeyValue only models the getRange
 * alternative. The real C++ object (MappedKeyValueRef) is laid out as:
 * index key (12B), index value (12B), 80-byte variant union, 4-byte
 * variant index at offset 104 (padded to a 112-byte stride).
 */
final class FutureMappedKeyValueArray extends Future
{
    private const VARIANT_GET_VALUE = 0;
    private const VARIANT_GET_RANGE = 1;

    private ?MappedRangeResult $cachedResult = null;

    public function await(): MappedRangeResult
    {
        if ($this->resolved && $this->cachedResult instanceof MappedRangeResult) {
            return $this->cachedResult;
        }

        $this->blockUntilReady();

        $outKvm = $this->client->fdb->new('FDBMappedKeyValue*');
        $outCount = $this->client->fdb->new('int');
        $outMore = $this->client->fdb->new('fdb_bool_t');

        $this->client->checkError(
            $this->client->fdb->fdb_future_get_mappedkeyvalue_array(
                $this->fpointer,
                FFI::addr($outKvm),
                FFI::addr($outCount),
                FFI::addr($outMore),
            ),
        );

        $count = $outCount->cdata;
        $entries = [];

        for ($i = 0; $i < $count; $i++) {
            $mapped = $outKvm[$i];

            $indexKey = $this->stringFromKey($mapped->key);
            $indexValue = $this->stringFromKey($mapped->value);

            $variant = $mapped->variant_index;

            if ($variant === self::VARIANT_GET_VALUE) {
                $results = [];
                if ($mapped->reqAndResult->getValue->present) {
                    $results[] = new KeyValue(
                        $this->stringFromKey($mapped->reqAndResult->getValue->key),
                        $this->stringFromKey($mapped->reqAndResult->getValue->value),
                    );
                }
            } elseif ($variant === self::VARIANT_GET_RANGE) {
                $results = [];
                $reqAndResult = $mapped->reqAndResult->getRange->reqAndResult;
                $resultCount = $reqAndResult->m_size;
                for ($j = 0; $j < $resultCount; $j++) {
                    $kv = $reqAndResult->data[$j];
                    $results[] = new KeyValue(
                        FFI::string($kv->key, $kv->key_length),
                        FFI::string($kv->value, $kv->value_length),
                    );
                }
            } else {
                throw new \RuntimeException(sprintf(
                    'Unknown mapped key value variant index: %d',
                    $variant,
                ));
            }

            $entries[] = new MappedKeyValue($indexKey, $indexValue, $results);
        }

        $this->cachedResult = new MappedRangeResult($entries, $count, $outMore->cdata !== 0);
        $this->releaseMemory();
        $this->resolved = true;

        return $this->cachedResult;
    }

    private function stringFromKey(FFI\CData $key): string
    {
        return FFI::string($key->key, $key->key_length);
    }
}
