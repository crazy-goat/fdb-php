<?php

declare(strict_types=1);

namespace CrazyGoat\FoundationDB\Future;

use CrazyGoat\FoundationDB\KeyRange;
use FFI;

final class FutureKeyRangeArray extends Future
{
    /** @var list<KeyRange> */
    private array $cachedResult = [];

    /**
     * @return list<KeyRange>
     */
    public function await(): array
    {
        if ($this->resolved) {
            return $this->cachedResult;
        }

        $this->blockUntilReady();

        $outRanges = $this->client->fdb->new('FDBKeyRange*');
        $outCount = $this->client->fdb->new('int');

        $this->client->checkError(
            $this->client->fdb->fdb_future_get_keyrange_array(
                $this->fpointer,
                FFI::addr($outRanges),
                FFI::addr($outCount),
            ),
        );

        $count = $outCount->cdata;
        $ranges = [];

        for ($i = 0; $i < $count; $i++) {
            $range = $outRanges[$i];
            $ranges[] = new KeyRange(
                FFI::string($range->begin_key, $range->begin_key_length),
                FFI::string($range->end_key, $range->end_key_length),
            );
        }

        $this->cachedResult = $ranges;
        $this->releaseMemory();
        $this->resolved = true;

        return $this->cachedResult;
    }
}
