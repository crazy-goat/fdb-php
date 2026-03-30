<?php

declare(strict_types=1);

namespace CrazyGoat\FoundationDB;

final class RebootWorkerException extends \RuntimeException
{
    public function __construct(
        public readonly string $address,
        string $message = 'Failed to reboot worker',
    ) {
        parent::__construct($message);
    }
}
