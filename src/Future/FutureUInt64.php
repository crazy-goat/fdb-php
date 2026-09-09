<?php

declare(strict_types=1);

namespace CrazyGoat\FoundationDB\Future;

use FFI;

/**
 * Future resolving through `fdb_future_get_uint64`.
 *
 * Values are returned as PHP ints. A uint64 result larger than
 * `PHP_INT_MAX` cannot be represented and will be reported by PHP FFI as a
 * wrapped negative value; the APIs currently backed by this future
 * (`Database::getServerProtocol()`) never produce such values.
 */
final class FutureUInt64 extends Future
{
    private int $cachedResult = 0;

    public function await(): int
    {
        if ($this->resolved) {
            return $this->cachedResult;
        }

        $this->blockUntilReady();

        $out = $this->client->fdb->new('uint64_t');
        $this->client->checkError(
            $this->client->fdb->fdb_future_get_uint64($this->fpointer, FFI::addr($out)),
        );

        $this->cachedResult = $out->cdata;
        $this->releaseMemory();
        $this->resolved = true;

        return $this->cachedResult;
    }
}
