<?php

declare(strict_types=1);

namespace CrazyGoat\FoundationDB;

interface KeyConvertible
{
    public function asFoundationDbKey(): string;
}
