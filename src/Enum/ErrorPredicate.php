<?php

declare(strict_types=1);

namespace CrazyGoat\FoundationDB\Enum;

enum ErrorPredicate: int
{
    case Retryable = 50000;
    case MaybeCommitted = 50001;
    case RetryableNotCommitted = 50002;
}
