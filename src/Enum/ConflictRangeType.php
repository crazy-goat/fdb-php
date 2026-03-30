<?php

declare(strict_types=1);

namespace CrazyGoat\FoundationDB\Enum;

enum ConflictRangeType: int
{
    case Read = 0;
    case Write = 1;
}
