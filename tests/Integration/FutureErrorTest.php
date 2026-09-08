<?php

declare(strict_types=1);

namespace CrazyGoat\FoundationDB\Tests\Integration;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Integration coverage for the future accessors bound in issue #98:
 * `Future::isError()` (fdb_future_is_error) against a live future.
 *
 * `FutureBool` itself has no live producer yet (its first consumer, the blob
 * granule API in #93, is not bound), so the bool path is unit-tested against
 * a compiled libfdb_c stub in `tests/Unit/FutureBoolTest.php`. Here we verify
 * `isError()` against the real client: a future read from a transaction that
 * has been reset is in the error state, while a fresh read-version future is
 * not.
 */
final class FutureErrorTest extends TestCase
{
    use DatabaseCleanupTrait;

    #[Test]
    public function freshFutureIsNotInErrorState(): void
    {
        $tr = $this->getDatabase()->createTransaction();
        $future = $tr->getReadVersion();

        self::assertTrue($future->isReady());
        self::assertFalse($future->isError());
        self::assertGreaterThan(0, $future->await());
    }

    #[Test]
    public function futureOfAResetTransactionReportsErrorState(): void
    {
        $tr = $this->getDatabase()->createTransaction();
        $future = $tr->getReadVersion();

        // Resetting the transaction invalidates futures created from it:
        // they resolve to an error immediately.
        $tr->reset();

        self::assertTrue($future->isReady());
        self::assertTrue($future->isError());

        self::expectException(\CrazyGoat\FoundationDB\FDBException::class);
        $future->await();
    }
}
