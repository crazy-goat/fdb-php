<?php

declare(strict_types=1);

namespace CrazyGoat\FoundationDB\Future;

use FFI;

final class FutureDouble extends Future
{
    private float $cachedResult = 0.0;

    public function await(): float
    {
        if ($this->resolved) {
            return $this->cachedResult;
        }

        $this->blockUntilReady();

        $out = $this->client->fdb->new('double');
        $this->client->checkError(
            $this->client->fdb->fdb_future_get_double($this->fpointer, FFI::addr($out)),
        );

        $this->cachedResult = $out->cdata;
        $this->releaseMemory();
        $this->resolved = true;

        return $this->cachedResult;
    }
}
