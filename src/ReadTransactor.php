<?php

declare(strict_types=1);

namespace CrazyGoat\FoundationDB;

interface ReadTransactor
{
    public function readTransact(callable $fn): mixed;
}
