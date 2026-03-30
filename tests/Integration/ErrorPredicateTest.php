<?php

declare(strict_types=1);

namespace CrazyGoat\FoundationDB\Tests\Integration;

use CrazyGoat\FoundationDB\Enum\ErrorPredicate;
use CrazyGoat\FoundationDB\FDBException;
use CrazyGoat\FoundationDB\FoundationDB;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class ErrorPredicateTest extends TestCase
{
    private static bool $initialized = false;

    protected function setUp(): void
    {
        if (!self::$initialized) {
            FoundationDB::apiVersion(730);
            self::$initialized = true;
        }
    }

    #[Test]
    public function transactionTooOldIsRetryable(): void
    {
        self::assertTrue(FDBException::testPredicate(ErrorPredicate::Retryable, 1007));
    }

    #[Test]
    public function transactionTooOldIsRetryableNotCommitted(): void
    {
        self::assertTrue(FDBException::testPredicate(ErrorPredicate::RetryableNotCommitted, 1007));
    }

    #[Test]
    public function transactionTooOldIsNotMaybeCommitted(): void
    {
        self::assertFalse(FDBException::testPredicate(ErrorPredicate::MaybeCommitted, 1007));
    }

    #[Test]
    public function commitUnknownResultIsMaybeCommitted(): void
    {
        self::assertTrue(FDBException::testPredicate(ErrorPredicate::MaybeCommitted, 1021));
    }

    #[Test]
    public function commitUnknownResultIsRetryable(): void
    {
        self::assertTrue(FDBException::testPredicate(ErrorPredicate::Retryable, 1021));
    }

    #[Test]
    public function commitUnknownResultIsNotRetryableNotCommitted(): void
    {
        self::assertFalse(FDBException::testPredicate(ErrorPredicate::RetryableNotCommitted, 1021));
    }

    #[Test]
    public function notCommittedIsRetryableNotCommitted(): void
    {
        self::assertTrue(FDBException::testPredicate(ErrorPredicate::RetryableNotCommitted, 1020));
    }

    #[Test]
    public function successIsNotRetryable(): void
    {
        self::assertFalse(FDBException::testPredicate(ErrorPredicate::Retryable, 0));
    }

    #[Test]
    public function operationCancelledIsNotRetryable(): void
    {
        self::assertFalse(FDBException::testPredicate(ErrorPredicate::Retryable, 1101));
    }

    #[Test]
    public function exceptionIsRetryableMethod(): void
    {
        try {
            throw new FDBException(1007);
        } catch (FDBException $e) {
            self::assertTrue($e->isRetryable());
            self::assertTrue($e->isRetryableNotCommitted());
            self::assertFalse($e->isMaybeCommitted());
        }
    }

    #[Test]
    public function exceptionIsMaybeCommittedMethod(): void
    {
        try {
            throw new FDBException(1021);
        } catch (FDBException $e) {
            self::assertTrue($e->isRetryable());
            self::assertFalse($e->isRetryableNotCommitted());
            self::assertTrue($e->isMaybeCommitted());
        }
    }
}
