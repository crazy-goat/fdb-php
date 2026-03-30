<?php

declare(strict_types=1);

namespace CrazyGoat\FoundationDB;

use CrazyGoat\FoundationDB\Enum\ErrorPredicate;

final class FDBException extends \RuntimeException
{
    public function __construct(
        public readonly int $fdbCode,
    ) {
        parent::__construct(
            NativeClient::getInstance()->getErrorMessage($fdbCode),
            $fdbCode,
        );
    }

    public function isRetryable(): bool
    {
        return self::testPredicate(ErrorPredicate::Retryable, $this->fdbCode);
    }

    public function isMaybeCommitted(): bool
    {
        return self::testPredicate(ErrorPredicate::MaybeCommitted, $this->fdbCode);
    }

    public function isRetryableNotCommitted(): bool
    {
        return self::testPredicate(ErrorPredicate::RetryableNotCommitted, $this->fdbCode);
    }

    public static function testPredicate(ErrorPredicate $predicate, int $errorCode): bool
    {
        return NativeClient::getInstance()->fdb->fdb_error_predicate($predicate->value, $errorCode) !== 0;
    }
}
