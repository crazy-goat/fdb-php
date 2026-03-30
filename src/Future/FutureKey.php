<?php

declare(strict_types=1);

namespace CrazyGoat\FoundationDB\Future;

use FFI;

final class FutureKey extends Future
{
    private string $cachedResult = '';

    public function await(): string
    {
        if ($this->resolved) {
            return $this->cachedResult;
        }

        $this->blockUntilReady();

        $outKey = $this->client->fdb->new('char*');
        $outKeyLength = $this->client->fdb->new('int');

        $this->client->checkError(
            $this->client->fdb->fdb_future_get_key(
                $this->fpointer,
                FFI::addr($outKey),
                FFI::addr($outKeyLength),
            ),
        );

        $this->cachedResult = FFI::string($outKey, $outKeyLength->cdata);
        $this->releaseMemory();
        $this->resolved = true;

        return $this->cachedResult;
    }
}
