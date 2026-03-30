<?php

declare(strict_types=1);

namespace CrazyGoat\FoundationDB\Enum;

enum StreamingMode: int
{
    case WantAll = -2;
    case Iterator = -1;
    case Exact = -3;
    case Small = -4;
    case Medium = -5;
    case Large = -6;
    case Serial = -7;
}
