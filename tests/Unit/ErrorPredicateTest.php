<?php

declare(strict_types=1);

namespace CrazyGoat\FoundationDB\Tests\Unit;

use CrazyGoat\FoundationDB\Enum\ErrorPredicate;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class ErrorPredicateTest extends TestCase
{
    #[Test]
    public function retryableHasCorrectValue(): void
    {
        self::assertSame(50000, ErrorPredicate::Retryable->value);
    }

    #[Test]
    public function maybeCommittedHasCorrectValue(): void
    {
        self::assertSame(50001, ErrorPredicate::MaybeCommitted->value);
    }

    #[Test]
    public function retryableNotCommittedHasCorrectValue(): void
    {
        self::assertSame(50002, ErrorPredicate::RetryableNotCommitted->value);
    }

    #[Test]
    public function allCasesExist(): void
    {
        $cases = ErrorPredicate::cases();

        self::assertCount(3, $cases);
        self::assertContains(ErrorPredicate::Retryable, $cases);
        self::assertContains(ErrorPredicate::MaybeCommitted, $cases);
        self::assertContains(ErrorPredicate::RetryableNotCommitted, $cases);
    }

    #[Test]
    public function canBeCreatedFromValue(): void
    {
        self::assertSame(ErrorPredicate::Retryable, ErrorPredicate::from(50000));
        self::assertSame(ErrorPredicate::MaybeCommitted, ErrorPredicate::from(50001));
        self::assertSame(ErrorPredicate::RetryableNotCommitted, ErrorPredicate::from(50002));
    }


}
