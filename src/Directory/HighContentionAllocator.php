<?php

declare(strict_types=1);

namespace CrazyGoat\FoundationDB\Directory;

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
            [$begin, $end] = $this->counters->range();
            $rangeResult = $tr->snapshot()->getRangeStartsWith(
                $this->counters->key(),
            )->toArray();

            $start = 0;
            $window = 0;

            if ($rangeResult !== []) {
                $lastKv = $rangeResult[count($rangeResult) - 1];
                $decoded = $this->counters->unpack($lastKv->key);
                /** @var int $start */
                $start = $decoded[0];
            }

            $windowAdvanced = false;

            if ($start > 0) {
                $window = $this->windowSize($start);

                if ($rangeResult !== []) {
                    $lastKv = $rangeResult[count($rangeResult) - 1];
                    $countValue = $lastKv->value;
                    $count = $this->decodeCount($countValue);

                    if ($count * 2 >= $window) {
                        $tr->clearRangeStartsWith($this->counters->key());
                        $tr->setOption(30);
                        $tr->clearRangeStartsWith($this->recent->key());
                        $start += $window;
                        $windowAdvanced = true;
                    }
                }
            }

            if ($window === 0) {
                $window = $this->windowSize($start);
            }

            $tr->add(
                $this->counters->pack([$start]),
                1,
            );

            $candidate = $start + random_int(0, $window - 1);

            $candidateKey = $this->recent->pack([$candidate]);
            $latestCounter = $tr->snapshot()->getRangeStartsWith(
                $this->counters->key(),
            )->toArray();

            if ($latestCounter !== []) {
                $currentStart = $this->counters->unpack($latestCounter[count($latestCounter) - 1]->key);
                /** @var int $currentStartVal */
                $currentStartVal = $currentStart[0];

                if ($currentStartVal > $start) {
                    continue;
                }
            }

            $existingValue = $tr->snapshot()->get($candidateKey)->await();
            $tr->addWriteConflictKey($candidateKey);

            if ($existingValue === null) {
                $tr->set($candidateKey, '');

                return Tuple::pack([$candidate]);
            }
        }
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
