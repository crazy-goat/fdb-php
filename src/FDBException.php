<?php

declare(strict_types=1);

namespace CrazyGoat\FoundationDB;

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
}
