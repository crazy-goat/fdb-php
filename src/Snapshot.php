<?php

declare(strict_types=1);

namespace CrazyGoat\FoundationDB;

use FFI\CData;

final class Snapshot extends ReadTransaction implements ReadTransactor
{
    public function __construct(
        CData $tpointer,
        Database $db,
        NativeClient $client,
        private readonly Transaction $parentTransaction,
    ) {
        parent::__construct($tpointer, $db, $client, true);
    }

    public function readTransact(callable $fn): mixed
    {
        return $fn($this);
    }
}
