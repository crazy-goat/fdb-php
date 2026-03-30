<?php

declare(strict_types=1);

namespace CrazyGoat\FoundationDB;

interface Transactor
{
    public function transact(callable $fn): mixed;
}
