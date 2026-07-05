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
        /**
         * GC anchor: keeps the parent Transaction alive as long as the Snapshot exists.
         * The Snapshot shares the same native transaction handle (tpointer) as its parent,
         * so we must prevent the parent from being garbage-collected prematurely.
         *
         * @phpstan-ignore property.onlyWritten
         */
        private readonly Transaction $parentTransaction,
    ) {
        parent::__construct($tpointer, $db, $client, true);
    }

    public function readTransact(callable $fn): mixed
    {
        return $fn($this);
    }
}
