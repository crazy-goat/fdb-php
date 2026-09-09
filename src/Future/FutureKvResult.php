<?php

declare(strict_types=1);

namespace CrazyGoat\FoundationDB\Future;

use CrazyGoat\FoundationDB\KeyValue;

final readonly class FutureKvResult implements KvsFuture
{
    /**
     * @param list<KeyValue> $kvs
     * @param int<0, max> $count
     */
    public function __construct(
        public array $kvs,
        public int $count,
        public bool $more,
    ) {
    }

    /**
     * Already resolved — this method simply returns $this so that
     * FutureKvResult can be used anywhere a KvsFuture is expected.
     */
    public function await(): FutureKvResult
    {
        return $this;
    }
}
