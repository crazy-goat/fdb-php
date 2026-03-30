<?php

declare(strict_types=1);

namespace CrazyGoat\FoundationDB\Enum;

enum StreamingMode: int
{
    case WantAll = -2;
    case Iterator = -1;
    case Exact = 0;
    case Small = 1;
    case Medium = 2;
    case Large = 3;
    case Serial = 4;
}
