<?php

declare(strict_types=1);

namespace CrazyGoat\FoundationDB\Future;

use FFI;

final class FutureKeyArray extends Future
{
    /** @var list<string> */
    private array $cachedResult = [];

    /**
     * @return list<string>
     */
    public function await(): array
    {
        if ($this->resolved) {
            return $this->cachedResult;
        }

        $this->blockUntilReady();

        $outKeys = $this->client->fdb->new('FDBKey*');
        $outCount = $this->client->fdb->new('int');

        $this->client->checkError(
            $this->client->fdb->fdb_future_get_key_array(
                $this->fpointer,
                FFI::addr($outKeys),
                FFI::addr($outCount),
            ),
        );

        $count = $outCount->cdata;
        $keys = [];

        for ($i = 0; $i < $count; $i++) {
            $key = $outKeys[$i];
            $keys[] = FFI::string($key->key, $key->key_length);
        }

        $this->cachedResult = $keys;
        $this->releaseMemory();
        $this->resolved = true;

        return $this->cachedResult;
    }
}
