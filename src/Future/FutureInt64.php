<?php

declare(strict_types=1);

namespace CrazyGoat\FoundationDB\Future;

use FFI;

final class FutureInt64 extends Future
{
    private int $cachedResult = 0;

    public function await(): int
    {
        if ($this->resolved) {
            return $this->cachedResult;
        }

        $this->blockUntilReady();

        $out = $this->client->fdb->new('int64_t');
        $this->client->checkError(
            $this->client->fdb->fdb_future_get_int64($this->fpointer, FFI::addr($out)),
        );

        $this->cachedResult = $out->cdata;
        $this->releaseMemory();
        $this->resolved = true;

        return $this->cachedResult;
    }
}
