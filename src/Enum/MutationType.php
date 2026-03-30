<?php

declare(strict_types=1);

namespace CrazyGoat\FoundationDB\Enum;

enum MutationType: int
{
    case Add = 2;
    case BitAnd = 6;
    case BitOr = 7;
    case BitXor = 8;
    case AppendIfFits = 9;
    case Max = 12;
    case Min = 13;
    case SetVersionstampedKey = 14;
    case SetVersionstampedValue = 15;
    case ByteMin = 16;
    case ByteMax = 17;
    case CompareAndClear = 20;
}
