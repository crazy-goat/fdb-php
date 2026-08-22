<?php

declare(strict_types=1);

namespace CrazyGoat\FoundationDB\Directory;

use CrazyGoat\FoundationDB\KeyValue;
use CrazyGoat\FoundationDB\Subspace;
use CrazyGoat\FoundationDB\Transaction;
use CrazyGoat\FoundationDB\Tuple\Tuple;

final readonly class HighContentionAllocator
{
    private Subspace $counters;

    private Subspace $recent;

    public function __construct(
        private Subspace $subspace,
    ) {
        $this->counters = $this->subspace->subspace(0);
        $this->recent = $this->subspace->subspace(1);
    }

    public function allocate(Transaction $tr): string
    {
        while (true) {
            $rangeResult = $tr->snapshot()
                ->getRangeStartsWith($this->counters->key())
                ->toArray();

            [$start, $window] = $this->advanceWindow($tr, $this->counterStart($rangeResult), $rangeResult);

            $tr->add($this->counters->pack([$start]), 1);

            $candidate = $start + random_int(0, $window - 1);

            if ($this->counterAdvancedAway($tr, $start)) {
                continue;
            }

            $candidateKey = $this->recent->pack([$candidate]);
            $existingValue = $tr->snapshot()->get($candidateKey)->await();
            $tr->addWriteConflictKey($candidateKey);

            if ($existingValue === null) {
                $tr->set($candidateKey, '');

                return Tuple::pack([$candidate]);
            }
        }
    }

    /**
     * Decode the highest counter-start currently stored under the
     * counters subspace, or `0` when no counter has been written yet.
     *
     * @param list<KeyValue> $rangeResult
     */
    private function counterStart(array $rangeResult): int
    {
        if ($rangeResult === []) {
            return 0;
        }

        $lastKv = $rangeResult[count($rangeResult) - 1];
        $decoded = $this->counters->unpack($lastKv->key);
        /** @var int $start */
        $start = $decoded[0];

        return $start;
    }

    /**
     * When the current counter window is at least half consumed, retire
     * it: clear the counters and the "recently allocated" record, then
     * advance the start by a full window so later allocations move on.
     *
     * The returned window is the size used to pick candidates for the
     * *current* allocation pass — which stays the size of the window
     * that was in effect at the start of this pass even when the window
     * is retired mid-pass, matching the original retry loop's behaviour.
     *
     * @param int         $start        Counter start that opened the current window.
     * @param list<KeyValue> $rangeResult Snapshot of the counters range.
     *
     * @return array{int, int} The `[start, window]` to use for the current pass.
     */
    private function advanceWindow(Transaction $tr, int $start, array $rangeResult): array
    {
        if ($start === 0 || $rangeResult === []) {
            return [$start, $this->windowSize($start)];
        }

        $window = $this->windowSize($start);
        $lastKv = $rangeResult[count($rangeResult) - 1];
        $count = $this->decodeCount($lastKv->value);

        if ($count * 2 < $window) {
            return [$start, $window];
        }

        $tr->clearRangeStartsWith($this->counters->key());
        $tr->options()->setNextWriteNoWriteConflictRange();
        $tr->clearRangeStartsWith($this->recent->key());

        return [$start + $window, $window];
    }

    /**
     * True when a concurrent allocation already advanced the counters
     * past the start we are working with, in which case the current
     * attempt must be abandoned and re-read from a fresh snapshot.
     */
    private function counterAdvancedAway(Transaction $tr, int $start): bool
    {
        $latestCounter = $tr->snapshot()->getRangeStartsWith(
            $this->counters->key(),
        )->toArray();

        if ($latestCounter === []) {
            return false;
        }

        $currentStart = $this->counters->unpack($latestCounter[count($latestCounter) - 1]->key);
        /** @var int $currentStartVal */
        $currentStartVal = $currentStart[0];

        return $currentStartVal > $start;
    }

    private function windowSize(int $start): int
    {
        if ($start < 255) {
            return 64;
        }

        if ($start < 65535) {
            return 1024;
        }

        return 8192;
    }

    private function decodeCount(string $value): int
    {
        $length = strlen($value);
        if ($length > 8) {
            throw new \RuntimeException(sprintf(
                'Cannot decode counter: stored value is %d bytes, expected at most 8.',
                $length,
            ));
        }

        if ($length < 8) {
            $value = str_pad($value, 8, "\x00");
        }

        $unpacked = unpack('P', $value);

        if ($unpacked === false) {
            return 0;
        }

        return $unpacked[1];
    }
}
