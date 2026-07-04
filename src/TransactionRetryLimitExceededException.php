<?php

declare(strict_types=1);

namespace CrazyGoat\FoundationDB;

/**
 * Thrown by the convenience retry loops (Database::transact(),
 * Database::readTransact(), Database::watch(), Database::getAndWatch(),
 * Database::setAndWatch(), Database::clearAndWatch()) when the
 * library-level transaction retry limit (set via
 * `FoundationDB::defaultTransactionRetryLimit()`) or the configured
 * per-transaction wall-clock timeout
 * (`FoundationDB::defaultTransactionTimeoutSeconds()`) is exceeded
 * before the underlying FDB transaction has succeeded or thrown a
 * non-retryable error.
 *
 * Previously, the loops were unbounded (`while (true)`) and relied
 * entirely on FoundationDB's `fdb_transaction_on_error()` to
 * eventually bubble a non-retryable error back to PHP — so a
 * persistently conflicting workload could spin forever. This
 * exception makes the failure deterministic: the application sees it
 * at the call site with the attempt count and elapsed wall-clock
 * seconds, rather than as an unresponsive process.
 *
 * The exception is not a FoundationDB error (the native transaction
 * never reported one), so it inherits from `\RuntimeException`
 * directly, as the other library-level exceptions in this package
 * do (DirectoryException, RebootWorkerException).
 */
final class TransactionRetryLimitExceededException extends \RuntimeException
{
    public function __construct(
        public readonly int $attempts,
        public readonly float $elapsedSeconds,
        ?string $message = null,
    ) {
        parent::__construct(
            $message ?? sprintf(
                'Transaction retry limit exceeded after %d attempt%s (%.3fs elapsed). '
                . 'FoundationDB::defaultTransactionRetryLimit() / '
                . 'FoundationDB::defaultTransactionTimeoutSeconds() was configured; '
                . 'no FDB error was reported within the configured ceiling.',
                $attempts,
                $attempts === 1 ? '' : 's',
                $elapsedSeconds,
            ),
        );
    }
}
