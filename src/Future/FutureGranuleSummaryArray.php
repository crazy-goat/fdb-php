<?php

declare(strict_types=1);

namespace CrazyGoat\FoundationDB\Future;

use CrazyGoat\FoundationDB\BlobGranuleSummary;
use CrazyGoat\FoundationDB\KeyRange;
use FFI;

final class FutureGranuleSummaryArray extends Future
{
    /** @var list<BlobGranuleSummary> */
    private array $cachedResult = [];

    /**
     * @return list<BlobGranuleSummary>
     */
    public function await(): array
    {
        if ($this->resolved) {
            return $this->cachedResult;
        }

        $this->blockUntilReady();

        $outSummaries = $this->client->fdb->new('FDBGranuleSummary*');
        $outCount = $this->client->fdb->new('int');

        $this->client->checkError(
            $this->client->fdb->fdb_future_get_granule_summary_array(
                $this->fpointer,
                FFI::addr($outSummaries),
                FFI::addr($outCount),
            ),
        );

        $count = $outCount->cdata;
        $summaries = [];

        for ($i = 0; $i < $count; $i++) {
            $summary = $outSummaries[$i];
            $summaries[] = new BlobGranuleSummary(
                new KeyRange(
                    FFI::string($summary->begin_key, $summary->begin_key_length),
                    FFI::string($summary->end_key, $summary->end_key_length),
                ),
                $summary->snapshot_version,
                $summary->snapshot_size,
                $summary->delta_version,
                $summary->delta_size,
            );
        }

        $this->cachedResult = $summaries;
        $this->releaseMemory();
        $this->resolved = true;

        return $this->cachedResult;
    }
}
