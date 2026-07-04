<?php

declare(strict_types=1);

namespace CrazyGoat\FoundationDB\Tests\Unit;

use CrazyGoat\FoundationDB\Database;
use CrazyGoat\FoundationDB\FoundationDB;
use CrazyGoat\FoundationDB\TransactionRetryLimitExceededException;
use PHPUnit\Framework\Attributes\After;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for the explicit-bounds retry-limit / retry-timeout
 * configuration exposed by `Database::checkRetryLimit()` and the
 * process-wide configuration setters on `FoundationDB`.
 *
 * These tests cover the fix for issue #52: the previous `while
 * (true)` retry loops in `Database::transact()`, `readTransact()`,
 * and the four `watch*()` helpers were unbounded and relied entirely
 * on FoundationDB's `fdb_transaction_on_error()` to eventually
 * surface a non-retryable error. A persistently conflicting workload
 * could therefore spin indefinitely. The fix introduces an
 * opt-in, process-wide ceiling that can be configured either as a
 * retry-attempt count or as a wall-clock timeout; the predicate
 * `Database::checkRetryLimit()` decides, without FFI involvement,
 * whether the loop should throw
 * `TransactionRetryLimitExceededException`.
 *
 * The tests are deliberately split so that:
 *
 *  - the pure-PHP predicate (`Database::checkRetryLimit`) is
 *    exercised exhaustively across the boundary matrix
 *    (attempts-only, time-only, both, neither, exact-equality
 *    edge cases);
 *  - the configuration setters cover input validation
 *    (negative ⇒ thrown, zero ⇒ unbounded, positive ⇒ ceiling);
 *  - the exception type itself is validated for the fields it
 *    exposes and the default rendering of its message.
 *
 * Together with the integration test in
 * `tests/Integration/TransactionRetryLimitTest.php` (which exercises
 * the configured ceiling against a live FDB cluster), this file
 * establishes the contract documented in the class-level doc-block
 * of `Database` and on `TransactionRetryLimitExceededException`.
 */
final class TransactionRetryLimitTest extends TestCase
{
    #[After]
    protected function resetRetryConfig(): void
    {
        // Tests deliberately mutate process-wide retry policy; guarantee
        // we don't bleed into the next test in the same PHPUnit run.
        FoundationDB::reset();
    }

    // -- Configuration setters (FoundationDB::defaultTransactionRetryLimit) ---

    #[Test]
    public function defaultRetryLimitDefaultsToZeroUnbounded(): void
    {
        self::assertSame(0, FoundationDB::getDefaultTransactionRetryLimit());
    }

    #[Test]
    public function defaultRetryLimitAcceptsZeroUnboundedExplicitly(): void
    {
        FoundationDB::defaultTransactionRetryLimit(0);

        self::assertSame(0, FoundationDB::getDefaultTransactionRetryLimit());
    }

    #[Test]
    public function defaultRetryLimitAcceptsPositiveCeiling(): void
    {
        FoundationDB::defaultTransactionRetryLimit(100);

        self::assertSame(100, FoundationDB::getDefaultTransactionRetryLimit());
    }

    #[Test]
    public function defaultRetryLimitRejectsNegativeWithInvalidArgumentException(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('defaultTransactionRetryLimit');

        FoundationDB::defaultTransactionRetryLimit(-1);
    }

    #[Test]
    public function defaultRetryLimitRejectsLargeNegativeWithInvalidArgumentException(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        FoundationDB::defaultTransactionRetryLimit(-1000);
    }

    // -- Configuration setters (FoundationDB::defaultTransactionTimeoutSeconds) -

    #[Test]
    public function defaultTimeoutDefaultsToZeroUnbounded(): void
    {
        self::assertSame(0.0, FoundationDB::getDefaultTransactionTimeoutSeconds());
    }

    #[Test]
    public function defaultTimeoutAcceptsZeroUnboundedExplicitly(): void
    {
        FoundationDB::defaultTransactionTimeoutSeconds(0.0);

        self::assertSame(0.0, FoundationDB::getDefaultTransactionTimeoutSeconds());
    }

    #[Test]
    public function defaultTimeoutAcceptsPositiveCeiling(): void
    {
        FoundationDB::defaultTransactionTimeoutSeconds(5.0);

        self::assertSame(5.0, FoundationDB::getDefaultTransactionTimeoutSeconds());
    }

    #[Test]
    public function defaultTimeoutAcceptsFractionalCeiling(): void
    {
        FoundationDB::defaultTransactionTimeoutSeconds(0.001);

        self::assertSame(0.001, FoundationDB::getDefaultTransactionTimeoutSeconds());
    }

    #[Test]
    public function defaultTimeoutRejectsNegativeWithInvalidArgumentException(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('defaultTransactionTimeoutSeconds');

        FoundationDB::defaultTransactionTimeoutSeconds(-0.5);
    }

    // -- FoundationDB::reset clears both ceilings -------------------------------

    #[Test]
    public function resetClearsBothRetryCeilings(): void
    {
        FoundationDB::defaultTransactionRetryLimit(42);
        FoundationDB::defaultTransactionTimeoutSeconds(7.5);

        FoundationDB::reset();

        self::assertSame(0, FoundationDB::getDefaultTransactionRetryLimit());
        self::assertSame(0.0, FoundationDB::getDefaultTransactionTimeoutSeconds());
    }

    // -- TransactionRetryLimitExceededException contract ------------------------

    #[Test]
    public function exceptionCarriesAttemptsAndElapsedFields(): void
    {
        $exception = new TransactionRetryLimitExceededException(attempts: 5, elapsedSeconds: 1.25);

        self::assertSame(5, $exception->attempts);
        self::assertSame(1.25, $exception->elapsedSeconds);
    }

    #[Test]
    public function exceptionDefaultMessageMentionsAttemptsAndElapsed(): void
    {
        $exception = new TransactionRetryLimitExceededException(attempts: 3, elapsedSeconds: 0.5);

        self::assertStringContainsString('3', $exception->getMessage());
        self::assertStringContainsString('0.5', $exception->getMessage());
    }

    #[Test]
    public function exceptionMessagePluralizesAttemptsCorrectly(): void
    {
        $single = new TransactionRetryLimitExceededException(attempts: 1, elapsedSeconds: 0.1);
        $plural = new TransactionRetryLimitExceededException(attempts: 4, elapsedSeconds: 0.1);

        self::assertStringContainsString('1 attempt', $single->getMessage());
        self::assertStringContainsString('4 attempts', $plural->getMessage());
    }

    #[Test]
    public function exceptionHonoursCallerSuppliedMessage(): void
    {
        $exception = new TransactionRetryLimitExceededException(
            attempts: 2,
            elapsedSeconds: 0.0,
            message: 'custom message',
        );

        self::assertSame('custom message', $exception->getMessage());
    }

    #[Test]
    public function exceptionIsARuntimeException(): void
    {
        self::assertInstanceOf(\RuntimeException::class, new TransactionRetryLimitExceededException(1, 0.0));
    }

    // -- Database::checkRetryLimit: attempt ceiling -----------------------------

    #[Test]
    public function checkRetryLimitReturnsNullWhenAttemptUnderLimit(): void
    {
        $result = Database::checkRetryLimit(
            retriesSoFar: 5,
            elapsed: 0.0,
            maxRetries: 10,
            maxSeconds: 0.0,
        );

        self::assertNull($result);
    }

    #[Test]
    public function checkRetryLimitReturnsNullExactlyAtAttemptLimit(): void
    {
        // Boundary: when retries === limit, the loop has used all its
        // budget but hasn't yet exceeded it; the next iteration is
        // what would exceed. The predicate returns null so on_error
        // gets one more attempt before the throw.
        $result = Database::checkRetryLimit(
            retriesSoFar: 10,
            elapsed: 0.0,
            maxRetries: 10,
            maxSeconds: 0.0,
        );

        self::assertNull($result);
    }

    #[Test]
    public function checkRetryLimitThrowsWhenAttemptExceedsLimit(): void
    {
        $result = Database::checkRetryLimit(
            retriesSoFar: 11,
            elapsed: 0.0,
            maxRetries: 10,
            maxSeconds: 0.0,
        );

        self::assertInstanceOf(TransactionRetryLimitExceededException::class, $result);
        self::assertSame(11, $result->attempts);
        self::assertStringContainsString('limit 10', $result->getMessage());
    }

    #[Test]
    public function checkRetryLimitIgnoresAttemptCeilingWhenZero(): void
    {
        $result = Database::checkRetryLimit(
            retriesSoFar: \PHP_INT_MAX,
            elapsed: 0.0,
            maxRetries: 0,
            maxSeconds: 0.0,
        );

        self::assertNull($result);
    }

    // -- Database::checkRetryLimit: time ceiling --------------------------------

    #[Test]
    public function checkRetryLimitReturnsNullWhenElapsedUnderLimit(): void
    {
        $result = Database::checkRetryLimit(
            retriesSoFar: 0,
            elapsed: 2.5,
            maxRetries: 0,
            maxSeconds: 5.0,
        );

        self::assertNull($result);
    }

    #[Test]
    public function checkRetryLimitReturnsNullExactlyAtTimeLimit(): void
    {
        // Boundary: elapsed == maxSeconds is "at the budget edge", not
        // over. The next retry attempt is what should trip the
        // predicate, so this returns null strict-greater than max.
        $result = Database::checkRetryLimit(
            retriesSoFar: 0,
            elapsed: 5.0,
            maxRetries: 0,
            maxSeconds: 5.0,
        );

        self::assertNull($result);
    }

    #[Test]
    public function checkRetryLimitThrowsWhenElapsedExceedsLimit(): void
    {
        $result = Database::checkRetryLimit(
            retriesSoFar: 0,
            elapsed: 5.01,
            maxRetries: 0,
            maxSeconds: 5.0,
        );

        self::assertInstanceOf(TransactionRetryLimitExceededException::class, $result);
        self::assertSame(5.01, $result->elapsedSeconds);
        self::assertStringContainsString('limit 5.0', $result->getMessage());
    }

    #[Test]
    public function checkRetryLimitIgnoresTimeCeilingWhenZero(): void
    {
        $result = Database::checkRetryLimit(
            retriesSoFar: 0,
            elapsed: 1_000_000.0,
            maxRetries: 0,
            maxSeconds: 0.0,
        );

        self::assertNull($result);
    }

    // -- Database::checkRetryLimit: precedence ----------------------------------

    #[Test]
    public function checkRetryLimitPrefersTimeCeilingWhenBothExceeded(): void
    {
        // When both ceilings are exceeded on the same iteration, the
        // wall-clock budget is the one that actually paints the
        // picture of "we ran out of time", so it must surface in the
        // message (the test below asserts via the message text).
        $result = Database::checkRetryLimit(
            retriesSoFar: 1000,
            elapsed: 5.5,
            maxRetries: 10,
            maxSeconds: 5.0,
        );

        self::assertInstanceOf(TransactionRetryLimitExceededException::class, $result);
        self::assertStringContainsString('wall-clock', $result->getMessage());
        self::assertStringContainsString('limit 5.0', $result->getMessage());
    }

    #[Test]
    public function checkRetryLimitPrefersAttemptCeilingWhenOnlyItExceeded(): void
    {
        $result = Database::checkRetryLimit(
            retriesSoFar: 11,
            elapsed: 1.0,
            maxRetries: 10,
            maxSeconds: 5.0,
        );

        self::assertInstanceOf(TransactionRetryLimitExceededException::class, $result);
        self::assertStringContainsString('attempt limit', $result->getMessage());
        self::assertStringContainsString('limit 10', $result->getMessage());
    }

    #[Test]
    public function checkRetryLimitHandlesEmptyBudget(): void
    {
        // Both ceilings disabled (process-default) => always null.
        $result = Database::checkRetryLimit(
            retriesSoFar: 100,
            elapsed: 100.0,
            maxRetries: 0,
            maxSeconds: 0.0,
        );

        self::assertNull($result);
    }

    #[Test]
    public function checkRetryLimitIsPureStaticHelper(): void
    {
        // The helper must not depend on instance state or FFI; we
        // assert this by invoking it without ever creating a
        // `Database` instance. Two opposite branches in the same
        // test confirm the helper is reachable from a static call
        // site (no constructor call, no framework setup).
        self::assertNull(
            Database::checkRetryLimit(retriesSoFar: 0, elapsed: 0.0, maxRetries: 0, maxSeconds: 0.0),
        );
        self::assertInstanceOf(
            TransactionRetryLimitExceededException::class,
            Database::checkRetryLimit(retriesSoFar: 2, elapsed: 0.0, maxRetries: 1, maxSeconds: 0.0),
        );
    }
}
