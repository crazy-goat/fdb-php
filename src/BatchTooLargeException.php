<?php

declare(strict_types=1);

namespace CrazyGoat\FoundationDB;

/**
 * Thrown when a batch write exceeds the per-transaction mutation budget
 * enforced by `MutationBudget`.
 *
 * Unlike the opaque `value_too_large` (2103) / `too_many_mutations` server
 * errors, this exception is raised synchronously at the call site — before
 * any mutation has been queued on the transaction — and carries the measured
 * batch size alongside the enforced limit so the caller can react precisely
 * (e.g. fall back to Database::setBatch(..., split: true)).
 */
final class BatchTooLargeException extends \RuntimeException
{
    public function __construct(
        public readonly int $batchSize,
        public readonly int $maxBatchSize,
    ) {
        parent::__construct(sprintf(
            'Batch exceeds the FoundationDB transaction mutation budget: %d bytes of key+value data '
            . '(limit is %d bytes). Reduce the batch size, or use Database::setBatch(..., split: true) '
            . 'to commit it across multiple transactions.',
            $batchSize,
            $maxBatchSize,
        ));
    }
}
