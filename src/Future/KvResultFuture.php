<?php

declare(strict_types=1);

namespace CrazyGoat\FoundationDB\Future;

/**
 * Anything that eventually resolves to a FutureKvResult chunk of a range read.
 *
 * RangeResult::paginate() consumes these so it can issue the request for the
 * next chunk (read-ahead) before the current chunk has been fully consumed,
 * while unit tests can substitute lightweight fakes.
 */
interface KvResultFuture
{
    public function await(): FutureKvResult;
}
