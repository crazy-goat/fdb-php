<?php

declare(strict_types=1);

namespace CrazyGoat\FoundationDB\Tests\Integration;

use CrazyGoat\FoundationDB\FDBException;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Integration coverage for the future accessors bound in issue #98:
 * `Future::isError()` (fdb_future_is_error) against a live future.
 *
 * `FutureBool` itself has no live producer yet (its first consumer, the blob
 * granule API in #93, is not bound), so the bool path is unit-tested against
 * a compiled libfdb_c stub in `tests/Unit/FutureBoolTest.php`. Here we verify
 * `isError()` against the real client: a successfully resolved future is not
 * in error, and a commit that fails with a conflict resolves to the error
 * state.
 */
final class FutureErrorTest extends TestCase
{
    use DatabaseCleanupTrait;

    #[Test]
    public function resolvedFutureIsNotInErrorState(): void
    {
        $tr = $this->getDatabase()->createTransaction();
        $future = $tr->getReadVersion();

        self::assertGreaterThan(0, $future->await());
        self::assertTrue($future->isReady());
        self::assertFalse($future->isError());
    }

    #[Test]
    public function failedCommitResolvesToErrorState(): void
    {
        $db = $this->getDatabase();
        $key = 'test/future-error/key';

        $trA = $db->createTransaction();
        $trA->set($key, 'value-a');
        $trA->commit()->await();

        // Transaction B reads the key, A' overwrites it in between, so B's
        // commit fails with 1020 (not_committed): the commit future becomes
        // ready and resolves to an error.
        $trB = $db->createTransaction();
        self::assertSame('value-a', $trB->get($key)->await());

        $trC = $db->createTransaction();
        $trC->set($key, 'value-c');
        $trC->commit()->await();

        $trB->set($key, 'value-b');
        $commit = $trB->commit();

        $failed = false;
        try {
            $commit->await();
        } catch (FDBException) {
            $failed = true;
        }
        self::assertTrue($failed, 'conflicting commit must fail');

        // The commit future has now resolved: ready AND in error state —
        // isError() can distinguish this from a successful future without
        // parsing error codes.
        self::assertTrue($commit->isReady());
        self::assertTrue($commit->isError());
    }
}
