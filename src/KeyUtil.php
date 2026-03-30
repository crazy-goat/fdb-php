<?php

declare(strict_types=1);

namespace CrazyGoat\FoundationDB;

final class KeyUtil
{
    private function __construct()
    {
    }

    public static function strinc(string $key): ?string
    {
        $unpacked = unpack('C*', $key);

        if ($unpacked === false) {
            return null;
        }

        $bytes = array_values($unpacked);
        $length = count($bytes);

        for ($i = $length - 1; $i >= 0; $i--) {
            if ($bytes[$i] < 0xFF) {
                $bytes[$i]++;
                return pack('C*', ...array_slice($bytes, 0, $i + 1));
            }
        }

        return null;
    }
}
