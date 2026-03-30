<?php

declare(strict_types=1);

namespace CrazyGoat\FoundationDB\Future;

use FFI;

final class FutureValue extends Future
{
    private ?string $cachedResult = null;

    private bool $present = false;

    public function await(): ?string
    {
        if ($this->resolved) {
            return $this->present ? $this->cachedResult : null;
        }

        $this->blockUntilReady();

        $outPresent = $this->client->fdb->new('fdb_bool_t');
        $outValue = $this->client->fdb->new('char*');
        $outValueLength = $this->client->fdb->new('int');

        $this->client->checkError(
            $this->client->fdb->fdb_future_get_value(
                $this->fpointer,
                FFI::addr($outPresent),
                FFI::addr($outValue),
                FFI::addr($outValueLength),
            ),
        );

        $this->present = $outPresent->cdata !== 0;

        if ($this->present) {
            $this->cachedResult = FFI::string($outValue, $outValueLength->cdata);
        }

        $this->releaseMemory();
        $this->resolved = true;

        return $this->present ? $this->cachedResult : null;
    }
}
