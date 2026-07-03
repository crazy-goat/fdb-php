<?php

declare(strict_types=1);

namespace CrazyGoat\FoundationDB\Future;

use FFI;

final class FutureStringArray extends Future
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

        $outStrings = $this->client->fdb->new('char**');
        $outCount = $this->client->fdb->new('int');

        $this->client->checkError(
            $this->client->fdb->fdb_future_get_string_array(
                $this->fpointer,
                FFI::addr($outStrings),
                FFI::addr($outCount),
            ),
        );

        $count = $outCount->cdata;
        $strings = [];

        for ($i = 0; $i < $count; $i++) {
            $strings[] = FFI::string($outStrings[$i]);
        }

        $this->cachedResult = $strings;
        $this->releaseMemory();
        $this->resolved = true;

        return $this->cachedResult;
    }
}
