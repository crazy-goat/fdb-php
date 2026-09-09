<?php

declare(strict_types=1);

namespace CrazyGoat\FoundationDB\Tests\Unit;

use CrazyGoat\FoundationDB\Future\FutureKvResult;
use CrazyGoat\FoundationDB\Future\KvResultFuture;

/**
 * A resolved range chunk wrapped in a pending-looking future, so paginate()
 * exercises its real await() + read-ahead path without a cluster.
 */
final readonly class FakeKvFuture implements KvResultFuture
{
    public function __construct(private FutureKvResult $result)
    {
    }

    public function await(): FutureKvResult
    {
        return $this->result;
    }
}
