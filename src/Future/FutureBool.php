<?php

declare(strict_types=1);

namespace CrazyGoat\FoundationDB\Future;

use FFI;

final class FutureBool extends Future
{
    private bool $cachedResult = false;

    public function await(): bool
    {
        if ($this->resolved) {
            return $this->cachedResult;
        }

        $this->blockUntilReady();

        $out = $this->client->fdb->new('fdb_bool_t');
        $this->client->checkError(
            $this->client->fdb->fdb_future_get_bool($this->fpointer, FFI::addr($out)),
        );

        $this->cachedResult = (bool) $out->cdata;
        $this->releaseMemory();
        $this->resolved = true;

        return $this->cachedResult;
    }
}
