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

    public static function printable(string $bytes): string
    {
        $result = '';
        $length = strlen($bytes);

        for ($i = 0; $i < $length; $i++) {
            $byte = ord($bytes[$i]);

            if ($byte === 0x5C) {
                $result .= '\\\\';
            } elseif ($byte >= 32 && $byte < 127) {
                $result .= $bytes[$i];
            } else {
                $result .= sprintf('\\x%02x', $byte);
            }
        }

        return $result;
    }

    /**
     * @return array{string, string}
     */
    public static function prefixRange(string $prefix): array
    {
        $end = self::strinc($prefix);

        if ($end === null) {
            throw new \InvalidArgumentException(
                'Cannot compute prefix range: prefix is empty or entirely 0xFF bytes'
            );
        }

        return [$prefix, $end];
    }
}
