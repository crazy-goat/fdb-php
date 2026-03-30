<?php

declare(strict_types=1);

namespace CrazyGoat\FoundationDB\Future;

use CrazyGoat\FoundationDB\KeyValue;
use FFI;

final class FutureKeyValueArray extends Future
{
    private ?FutureKvResult $cachedResult = null;

    public function await(): FutureKvResult
    {
        if ($this->resolved && $this->cachedResult instanceof \CrazyGoat\FoundationDB\Future\FutureKvResult) {
            return $this->cachedResult;
        }

        $this->blockUntilReady();

        $outKv = $this->client->fdb->new('FDBKeyValue*');
        $outCount = $this->client->fdb->new('int');
        $outMore = $this->client->fdb->new('fdb_bool_t');

        $this->client->checkError(
            $this->client->fdb->fdb_future_get_keyvalue_array(
                $this->fpointer,
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

        $this->cachedResult = new FutureKvResult($kvs, $count, $outMore->cdata !== 0);
        $this->releaseMemory();
        $this->resolved = true;

        return $this->cachedResult;
    }
}
