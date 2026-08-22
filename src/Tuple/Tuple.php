<?php

declare(strict_types=1);

namespace CrazyGoat\FoundationDB\Tuple;

final class Tuple
{
    /**
     * The deepest array-nesting level that {@see \CrazyGoat\FoundationDB\Tuple\Tuple}
     * will tolerate in either pack() or unpack(). Nested arrays beyond this
     * depth cause an {@see \InvalidArgumentException} to be raised at the
     * encoded recursion site instead of consuming the call stack. The
     * threshold is deliberately inclusive: a payload whose deepest recursion
     * peak equals {@see Tuple::MAX_NESTING_DEPTH} is accepted, while
     * {@see Tuple::MAX_NESTING_DEPTH}+1 is rejected.
     */
    public const MAX_NESTING_DEPTH = 100;

    private const TYPE_NULL = 0x00;
    private const TYPE_BYTES = 0x01;
    private const TYPE_STRING = 0x02;
    private const TYPE_NESTED = 0x05;
    private const TYPE_INT_ZERO = 0x14;
    private const TYPE_POS_INT_END = 0x1C;
    private const TYPE_POS_BIGINT = 0x1D;
    private const TYPE_NEG_INT_START = 0x0C;
    private const TYPE_NEG_BIGINT = 0x0B;
    private const TYPE_SINGLE_FLOAT = 0x20;
    private const TYPE_DOUBLE_FLOAT = 0x21;
    private const TYPE_FALSE = 0x26;
    private const TYPE_TRUE = 0x27;
    private const TYPE_UUID = 0x30;
    private const TYPE_VERSIONSTAMP = 0x33;

    /**
     * @param list<null|bool|int|float|string|\GMP|Bytes|SingleFloat|Uuid|Versionstamp|list<mixed>> $elements
     */
    public static function pack(array $elements, string $prefix = ''): string
    {
        $result = $prefix;

        foreach ($elements as $element) {
            $result .= self::encodeElement($element, false, 0);
        }

        return $result;
    }

    /**
     * @param list<null|bool|int|float|string|\GMP|Bytes|SingleFloat|Uuid|Versionstamp|list<mixed>> $elements
     */
    public static function packWithVersionstamp(array $elements, string $prefix = ''): string
    {
        $result = $prefix;
        $versionstampOffset = -1;
        $versionstampCount = 0;

        foreach ($elements as $element) {
            self::countVersionstamps($element, $versionstampCount, 0);
        }

        if ($versionstampCount === 0) {
            throw new \InvalidArgumentException(
                'packWithVersionstamp requires exactly one Versionstamp element, found 0',
            );
        }

        if ($versionstampCount > 1) {
            throw new \InvalidArgumentException(
                'packWithVersionstamp requires exactly one Versionstamp element, found ' . $versionstampCount
            );
        }

        foreach ($elements as $element) {
            $offset = strlen($result);
            $encoded = self::encodeElement($element, false, 0);

            if ($element instanceof Versionstamp) {
                $versionstampOffset = $offset + 1;
            } elseif (is_array($element)) {
                $innerOffset = self::findVersionstampOffset($element, $offset + 1, 0);
                if ($innerOffset >= 0) {
                    $versionstampOffset = $innerOffset;
                }
            }

            $result .= $encoded;
        }

        return $result . pack('V', $versionstampOffset);
    }

    /**
     * @return list<null|bool|int|float|string|\GMP|Bytes|SingleFloat|Uuid|Versionstamp|list<mixed>>
     */
    public static function unpack(string $data, int $prefixLength = 0): array
    {
        $pos = $prefixLength;
        $length = strlen($data);
        /** @var list<null|bool|int|float|string|\GMP|Bytes|SingleFloat|Uuid|Versionstamp|list<mixed>> $elements */
        $elements = [];

        while ($pos < $length) {
            [$value, $consumed] = self::decodeElement($data, $pos, $length, 0);
            $elements[] = $value;
            $pos += $consumed;
        }

        return $elements;
    }

    /**
     * @param list<null|bool|int|float|string|\GMP|Bytes|SingleFloat|Uuid|Versionstamp|list<mixed>> $elements
     */
    public static function hasIncompleteVersionstamp(array $elements): bool
    {
        foreach ($elements as $element) {
            if (self::elementHasIncompleteVersionstamp($element, 0)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param list<null|bool|int|float|string|\GMP|Bytes|SingleFloat|Uuid|Versionstamp|list<mixed>> $tuple1
     * @param list<null|bool|int|float|string|\GMP|Bytes|SingleFloat|Uuid|Versionstamp|list<mixed>> $tuple2
     */
    public static function compare(array $tuple1, array $tuple2): int
    {
        $packed1 = self::pack($tuple1);
        $packed2 = self::pack($tuple2);

        return strcmp($packed1, $packed2) <=> 0;
    }

    /**
     * @param list<null|bool|int|float|string|\GMP|Bytes|SingleFloat|Uuid|Versionstamp|list<mixed>> $elements
     * @return array{string, string}
     */
    public static function range(array $elements): array
    {
        $packed = self::pack($elements);

        return [$packed . "\x00", $packed . "\xFF"];
    }

    private static function encodeElement(mixed $element, bool $nested, int $depth): string
    {
        if ($element === null) {
            return $nested ? "\x00\xFF" : "\x00";
        }

        if (is_bool($element)) {
            return $element ? chr(self::TYPE_TRUE) : chr(self::TYPE_FALSE);
        }

        if ($element instanceof Bytes) {
            return self::encodeBytes($element);
        }

        if (is_string($element)) {
            return self::encodeString($element);
        }

        if (is_int($element)) {
            return self::encodeInt($element);
        }

        if ($element instanceof \GMP) {
            return self::encodeGmp($element);
        }

        if ($element instanceof SingleFloat) {
            return self::encodeSingleFloat($element);
        }

        if (is_float($element)) {
            return self::encodeDouble($element);
        }

        if ($element instanceof Uuid) {
            return self::encodeUuid($element);
        }

        if ($element instanceof Versionstamp) {
            return self::encodeVersionstamp($element);
        }

        if (is_array($element)) {
            /** @var list<mixed> $element */
            return self::encodeNestedTuple($element, $depth + 1);
        }

        throw new \InvalidArgumentException('Unsupported tuple element type: ' . get_debug_type($element));
    }

    /**
     * @throws \InvalidArgumentException If `$depth` would recurse past {@see self::MAX_NESTING_DEPTH}.
     */
    private static function assertDepth(int $depth): void
    {
        if ($depth > self::MAX_NESTING_DEPTH) {
            throw new \InvalidArgumentException(
                'Tuple nesting depth exceeds the maximum of ' . self::MAX_NESTING_DEPTH
                . ' (denial-of-service guard); got depth ' . $depth,
            );
        }
    }

    private static function encodeBytes(Bytes $bytes): string
    {
        return chr(self::TYPE_BYTES) . str_replace("\x00", "\x00\xFF", $bytes->data) . "\x00";
    }

    private static function encodeString(string $value): string
    {
        return chr(self::TYPE_STRING) . str_replace("\x00", "\x00\xFF", $value) . "\x00";
    }

    private static function encodeInt(int $value): string
    {
        if ($value === 0) {
            return chr(self::TYPE_INT_ZERO);
        }

        if ($value > 0) {
            return self::encodePositiveInt($value);
        }

        return self::encodeNegativeInt($value);
    }

    private static function encodePositiveInt(int $value): string
    {
        $byteCount = self::bytesNeeded($value);
        $code = chr((self::TYPE_INT_ZERO + $byteCount) & 0xFF);
        $bytes = '';

        for ($i = $byteCount - 1; $i >= 0; $i--) {
            $bytes .= chr(($value >> ($i * 8)) & 0xFF);
        }

        return $code . $bytes;
    }

    private static function encodeNegativeInt(int $value): string
    {
        if ($value === PHP_INT_MIN) {
            if (!extension_loaded('gmp')) {
                throw new \RuntimeException('GMP extension is required for encoding PHP_INT_MIN');
            }
            return self::encodeNegativeGmp(gmp_init($value));
        }

        $absValue = -$value;
        $byteCount = self::bytesNeeded($absValue);

        if ($byteCount === 8) {
            if (!extension_loaded('gmp')) {
                throw new \RuntimeException('GMP extension is required for large integer support');
            }
            return self::encodeNegativeGmp(gmp_init($value));
        }

        $code = chr((self::TYPE_INT_ZERO - $byteCount) & 0xFF);
        $maxVal = self::maxValueForBytes($byteCount);
        $adjusted = $maxVal + $value;
        $bytes = '';

        for ($i = $byteCount - 1; $i >= 0; $i--) {
            $bytes .= chr(($adjusted >> ($i * 8)) & 0xFF);
        }

        return $code . $bytes;
    }

    private static function encodeGmp(\GMP $value): string
    {
        if (!extension_loaded('gmp')) {
            throw new \RuntimeException('GMP extension is required for arbitrary-precision integer support');
        }

        $sign = gmp_sign($value);

        if ($sign === 0) {
            return chr(self::TYPE_INT_ZERO);
        }

        if ($sign > 0) {
            return self::encodePositiveGmp($value);
        }

        return self::encodeNegativeGmp($value);
    }

    private static function encodePositiveGmp(\GMP $value): string
    {
        $bytes = self::gmpToBytes($value);
        $byteCount = strlen($bytes);

        if ($byteCount <= 8) {
            $code = chr(self::TYPE_INT_ZERO + $byteCount);
            return $code . $bytes;
        }

        if ($byteCount > 255) {
            throw new \InvalidArgumentException('Integer value is too large to encode (exceeds 255 bytes)');
        }

        return chr(self::TYPE_POS_BIGINT) . chr($byteCount) . $bytes;
    }

    private static function encodeNegativeGmp(\GMP $value): string
    {
        $absValue = gmp_abs($value);
        $bytes = self::gmpToBytes($absValue);
        $byteCount = strlen($bytes);

        if ($byteCount <= 8) {
            $code = chr(self::TYPE_INT_ZERO - $byteCount);
            $maxVal = gmp_sub(gmp_pow(gmp_init(256), $byteCount), gmp_init(1));
            $adjusted = gmp_add($maxVal, $value);
            $adjustedBytes = self::gmpToBytesFixed($adjusted, $byteCount);
            return $code . $adjustedBytes;
        }

        if ($byteCount > 255) {
            throw new \InvalidArgumentException('Integer value is too large to encode (exceeds 255 bytes)');
        }

        $invertedLength = chr(255 - $byteCount);
        $adjustedBytes = '';
        for ($i = 0; $i < $byteCount; $i++) {
            $adjustedBytes .= chr((ord($bytes[$i]) ^ 0xFF) & 0xFF);
        }

        return chr(self::TYPE_NEG_BIGINT) . $invertedLength . $adjustedBytes;
    }

    private static function encodeSingleFloat(SingleFloat $float): string
    {
        $packed = pack('G', $float->value);
        $data = unpack('N', $packed);
        if ($data === false) {
            throw new \InvalidArgumentException('Failed to encode single float');
        }
        /** @var int $intVal */
        $intVal = $data[1];

        if (($intVal & 0x80000000) !== 0) {
            $intVal = ~$intVal & 0xFFFFFFFF;
        } else {
            $intVal |= 0x80000000;
        }

        return chr(self::TYPE_SINGLE_FLOAT) . pack('N', $intVal);
    }

    private static function encodeDouble(float $value): string
    {
        $bytes = pack('E', $value);
        $data = unpack('J', $bytes);
        if ($data === false) {
            throw new \InvalidArgumentException('Failed to encode double float');
        }
        /** @var int $intVal */
        $intVal = $data[1];

        $intVal = $intVal < 0 ? ~$intVal : $intVal | (1 << 63);

        return chr(self::TYPE_DOUBLE_FLOAT) . pack('J', $intVal);
    }

    private static function encodeUuid(Uuid $uuid): string
    {
        return chr(self::TYPE_UUID) . $uuid->bytes;
    }

    private static function encodeVersionstamp(Versionstamp $vs): string
    {
        return chr(self::TYPE_VERSIONSTAMP) . $vs->trVersion . pack('n', $vs->userVersion);
    }

    /**
     * @param list<mixed> $elements
     */
    private static function encodeNestedTuple(array $elements, int $depth): string
    {
        self::assertDepth($depth);
        $result = chr(self::TYPE_NESTED);

        foreach ($elements as $element) {
            $result .= self::encodeElement($element, true, $depth);
        }

        return $result . "\x00";
    }

    /**
     * @return array{null|bool|int|float|string|\GMP|Bytes|SingleFloat|Uuid|Versionstamp|list<mixed>, int}
     */
    private static function decodeElement(string $data, int $pos, int $length, int $depth): array
    {
        if ($pos >= $length) {
            throw new \InvalidArgumentException('Unexpected end of data at position ' . $pos);
        }

        $code = ord($data[$pos]);

        return match (true) {
            $code === self::TYPE_NULL => [null, 1],
            $code === self::TYPE_BYTES => self::decodeBytes($data, $pos, $length),
            $code === self::TYPE_STRING => self::decodeString($data, $pos, $length),
            $code === self::TYPE_NESTED => self::decodeNestedTuple($data, $pos, $length, $depth + 1),
            $code === self::TYPE_INT_ZERO => [0, 1],
            $code > self::TYPE_INT_ZERO && $code <= self::TYPE_POS_INT_END
                => self::decodePositiveInt($data, $pos, $length, $code),
            $code >= self::TYPE_NEG_INT_START && $code < self::TYPE_INT_ZERO
                => self::decodeNegativeInt($data, $pos, $length, $code),
            $code === self::TYPE_POS_BIGINT => self::decodePositiveBigInt($data, $pos, $length),
            $code === self::TYPE_NEG_BIGINT => self::decodeNegativeBigInt($data, $pos, $length),
            $code === self::TYPE_SINGLE_FLOAT => self::decodeSingleFloat($data, $pos, $length),
            $code === self::TYPE_DOUBLE_FLOAT => self::decodeDouble($data, $pos, $length),
            $code === self::TYPE_FALSE => [false, 1],
            $code === self::TYPE_TRUE => [true, 1],
            $code === self::TYPE_UUID => self::decodeUuid($data, $pos, $length),
            $code === self::TYPE_VERSIONSTAMP => self::decodeVersionstamp($data, $pos, $length),
            default => throw new \InvalidArgumentException(
                'Unknown type code 0x' . dechex($code) . ' at position ' . $pos,
            ),
        };
    }

    /**
     * @return array{Bytes, int}
     */
    private static function decodeBytes(string $data, int $pos, int $length): array
    {
        $end = self::findTerminator($data, $pos + 1, $length);
        $raw = substr($data, $pos + 1, $end - $pos - 1);
        $unescaped = str_replace("\x00\xFF", "\x00", $raw);

        return [new Bytes($unescaped), $end - $pos + 1];
    }

    /**
     * @return array{string, int}
     */
    private static function decodeString(string $data, int $pos, int $length): array
    {
        $end = self::findTerminator($data, $pos + 1, $length);
        $raw = substr($data, $pos + 1, $end - $pos - 1);
        $unescaped = str_replace("\x00\xFF", "\x00", $raw);

        return [$unescaped, $end - $pos + 1];
    }

    /**
     * @return array{list<mixed>, int}
     */
    private static function decodeNestedTuple(string $data, int $pos, int $length, int $depth): array
    {
        self::assertDepth($depth);
        $elements = [];
        $innerPos = $pos + 1;

        while ($innerPos < $length) {
            if ($data[$innerPos] === "\x00") {
                if ($innerPos + 1 < $length && $data[$innerPos + 1] === "\xFF") {
                    $elements[] = null;
                    $innerPos += 2;
                    continue;
                }

                return [$elements, $innerPos - $pos + 1];
            }

            [$value, $consumed] = self::decodeElement($data, $innerPos, $length, $depth);
            $elements[] = $value;
            $innerPos += $consumed;
        }

        throw new \InvalidArgumentException('Unterminated nested tuple starting at position ' . $pos);
    }

    /**
     * @return array{int|\GMP, int}
     */
    private static function decodePositiveInt(string $data, int $pos, int $length, int $code): array
    {
        $byteCount = $code - self::TYPE_INT_ZERO;

        if ($pos + 1 + $byteCount > $length) {
            throw new \InvalidArgumentException(
                'Truncated positive integer at position ' . $pos . ': need ' . $byteCount . ' bytes'
            );
        }

        $value = 0;
        for ($i = 0; $i < $byteCount; $i++) {
            $value = ($value << 8) | ord($data[$pos + 1 + $i]);
        }

        // Positive ints encode in up to 8 bytes. An 8-byte value whose
        // most significant bit is set is >= 2^63, which overflows PHP's
        // 64-bit signed int (the cumulative `<< 8` would silently wrap
        // negative past PHP_INT_MAX), so it must be decoded as an
        // arbitrary-precision GMP number. An 8-byte value with the top bit
        // clear (e.g. PHP_INT_MAX) still fits in a plain int. We test this
        // explicitly via the top byte instead of relying on the overflow
        // (which static analysis cannot model) so the check is never
        // reported as "always false" by newer PHPStan versions.
        if ($byteCount === 8 && (ord($data[$pos + 1]) & 0x80) !== 0) {
            if (!extension_loaded('gmp')) {
                throw new \RuntimeException('GMP extension is required for large integer support');
            }
            $gmpValue = gmp_init(0);
            for ($i = 0; $i < $byteCount; $i++) {
                $gmpValue = gmp_add(gmp_mul($gmpValue, gmp_init(256)), gmp_init(ord($data[$pos + 1 + $i])));
            }
            return [$gmpValue, 1 + $byteCount];
        }

        return [$value, 1 + $byteCount];
    }

    /**
     * @return array{int|\GMP, int}
     */
    private static function decodeNegativeInt(string $data, int $pos, int $length, int $code): array
    {
        $byteCount = self::TYPE_INT_ZERO - $code;

        if ($pos + 1 + $byteCount > $length) {
            throw new \InvalidArgumentException(
                'Truncated negative integer at position ' . $pos . ': need ' . $byteCount . ' bytes'
            );
        }

        if ($byteCount === 8) {
            if (!extension_loaded('gmp')) {
                throw new \RuntimeException('GMP extension is required for large integer support');
            }
            $gmpAdjusted = gmp_init(0);
            for ($i = 0; $i < 8; $i++) {
                $gmpAdjusted = gmp_add(gmp_mul($gmpAdjusted, gmp_init(256)), gmp_init(ord($data[$pos + 1 + $i])));
            }
            $gmpMax = gmp_sub(gmp_pow(gmp_init(256), 8), gmp_init(1));
            $gmpValue = gmp_sub($gmpAdjusted, $gmpMax);
            if (gmp_cmp($gmpValue, gmp_init(PHP_INT_MIN)) >= 0) {
                return [gmp_intval($gmpValue), 9];
            }
            return [$gmpValue, 9];
        }

        $adjusted = 0;
        for ($i = 0; $i < $byteCount; $i++) {
            $adjusted = ($adjusted << 8) | ord($data[$pos + 1 + $i]);
        }

        $maxVal = self::maxValueForBytes($byteCount);
        $value = $adjusted - $maxVal;

        return [$value, 1 + $byteCount];
    }

    /**
     * @return array{\GMP, int}
     */
    private static function decodePositiveBigInt(string $data, int $pos, int $length): array
    {
        if (!extension_loaded('gmp')) {
            throw new \RuntimeException('GMP extension is required for arbitrary-precision integer support');
        }

        if ($pos + 2 > $length) {
            throw new \InvalidArgumentException('Truncated big integer at position ' . $pos);
        }

        $byteCount = ord($data[$pos + 1]);

        if ($pos + 2 + $byteCount > $length) {
            throw new \InvalidArgumentException(
                'Truncated big integer at position ' . $pos . ': need ' . $byteCount . ' bytes'
            );
        }

        $value = gmp_init(0);
        for ($i = 0; $i < $byteCount; $i++) {
            $value = gmp_add(gmp_mul($value, gmp_init(256)), gmp_init(ord($data[$pos + 2 + $i])));
        }

        return [$value, 2 + $byteCount];
    }

    /**
     * @return array{\GMP, int}
     */
    private static function decodeNegativeBigInt(string $data, int $pos, int $length): array
    {
        if (!extension_loaded('gmp')) {
            throw new \RuntimeException('GMP extension is required for arbitrary-precision integer support');
        }

        if ($pos + 2 > $length) {
            throw new \InvalidArgumentException('Truncated big integer at position ' . $pos);
        }

        $byteCount = 255 - ord($data[$pos + 1]);

        if ($pos + 2 + $byteCount > $length) {
            throw new \InvalidArgumentException(
                'Truncated big integer at position ' . $pos . ': need ' . $byteCount . ' bytes'
            );
        }

        $invertedBytes = substr($data, $pos + 2, $byteCount);
        $value = gmp_init(0);
        for ($i = 0; $i < $byteCount; $i++) {
            $byte = ord($invertedBytes[$i]) ^ 0xFF;
            $value = gmp_add(gmp_mul($value, gmp_init(256)), gmp_init($byte));
        }

        return [gmp_neg($value), 2 + $byteCount];
    }

    /**
     * @return array{SingleFloat, int}
     */
    private static function decodeSingleFloat(string $data, int $pos, int $length): array
    {
        if ($pos + 5 > $length) {
            throw new \InvalidArgumentException('Truncated single float at position ' . $pos);
        }

        $bytes = substr($data, $pos + 1, 4);
        $unpacked = unpack('N', $bytes);
        if ($unpacked === false) {
            throw new \InvalidArgumentException('Failed to decode single float at position ' . $pos);
        }
        /** @var int $intVal */
        $intVal = $unpacked[1];

        if (($intVal & 0x80000000) !== 0) {
            $intVal &= ~0x80000000;
        } else {
            $intVal = ~$intVal & 0xFFFFFFFF;
        }

        $bytes = pack('N', $intVal);
        $result = unpack('G', $bytes);
        if ($result === false) {
            throw new \InvalidArgumentException('Failed to decode single float at position ' . $pos);
        }

        return [new SingleFloat($result[1]), 5];
    }

    /**
     * @return array{float, int}
     */
    private static function decodeDouble(string $data, int $pos, int $length): array
    {
        if ($pos + 9 > $length) {
            throw new \InvalidArgumentException('Truncated double float at position ' . $pos);
        }

        $bytes = substr($data, $pos + 1, 8);
        $unpacked = unpack('J', $bytes);
        if ($unpacked === false) {
            throw new \InvalidArgumentException('Failed to decode double float at position ' . $pos);
        }
        /** @var int $intVal */
        $intVal = $unpacked[1];

        $intVal = $intVal < 0 ? $intVal & ~(1 << 63) : ~$intVal;

        $bytes = pack('J', $intVal);
        $result = unpack('E', $bytes);
        if ($result === false) {
            throw new \InvalidArgumentException('Failed to decode double float at position ' . $pos);
        }

        return [$result[1], 9];
    }

    /**
     * @return array{Uuid, int}
     */
    private static function decodeUuid(string $data, int $pos, int $length): array
    {
        if ($pos + 17 > $length) {
            throw new \InvalidArgumentException('Truncated UUID at position ' . $pos);
        }

        return [new Uuid(substr($data, $pos + 1, 16)), 17];
    }

    /**
     * @return array{Versionstamp, int}
     */
    private static function decodeVersionstamp(string $data, int $pos, int $length): array
    {
        if ($pos + 13 > $length) {
            throw new \InvalidArgumentException('Truncated versionstamp at position ' . $pos);
        }

        $trVersion = substr($data, $pos + 1, 10);
        $userVersionData = unpack('n', substr($data, $pos + 11, 2));
        if ($userVersionData === false) {
            throw new \InvalidArgumentException('Failed to decode versionstamp user version at position ' . $pos);
        }

        return [new Versionstamp($trVersion, $userVersionData[1]), 13];
    }

    private static function findTerminator(string $data, int $start, int $length): int
    {
        $pos = $start;

        while ($pos < $length) {
            if ($data[$pos] === "\x00") {
                if ($pos + 1 < $length && $data[$pos + 1] === "\xFF") {
                    $pos += 2;
                    continue;
                }

                return $pos;
            }

            $pos++;
        }

        throw new \InvalidArgumentException('Unterminated byte/string value starting at position ' . ($start - 1));
    }

    private static function bytesNeeded(int $value): int
    {
        if ($value <= 0xFF) {
            return 1;
        }
        if ($value <= 0xFFFF) {
            return 2;
        }
        if ($value <= 0xFFFFFF) {
            return 3;
        }
        if ($value <= 0xFFFFFFFF) {
            return 4;
        }
        if ($value <= 0xFFFFFFFFFF) {
            return 5;
        }
        if ($value <= 0xFFFFFFFFFFFF) {
            return 6;
        }
        if ($value <= 0xFFFFFFFFFFFFFF) {
            return 7;
        }

        return 8;
    }

    private static function maxValueForBytes(int $byteCount): int
    {
        return match ($byteCount) {
            1 => 0xFF,
            2 => 0xFFFF,
            3 => 0xFFFFFF,
            4 => 0xFFFFFFFF,
            5 => 0xFFFFFFFFFF,
            6 => 0xFFFFFFFFFFFF,
            7 => 0xFFFFFFFFFFFFFF,
            default => PHP_INT_MAX,
        };
    }

    private static function gmpToBytes(\GMP $value): string
    {
        if (gmp_sign($value) === 0) {
            return "\x00";
        }

        $hex = gmp_strval(gmp_abs($value), 16);
        if (strlen($hex) % 2 !== 0) {
            $hex = '0' . $hex;
        }

        $bytes = '';
        for ($i = 0; $i < strlen($hex); $i += 2) {
            $bytes .= chr(((int) hexdec(substr($hex, $i, 2))) & 0xFF);
        }

        return $bytes;
    }

    private static function gmpToBytesFixed(\GMP $value, int $byteCount): string
    {
        $bytes = self::gmpToBytes($value);
        $currentLength = strlen($bytes);

        if ($currentLength < $byteCount) {
            $bytes = str_repeat("\x00", $byteCount - $currentLength) . $bytes;
        } elseif ($currentLength > $byteCount) {
            $bytes = substr($bytes, $currentLength - $byteCount);
        }

        return $bytes;
    }

    private static function elementHasIncompleteVersionstamp(mixed $element, int $depth): bool
    {
        if ($element instanceof Versionstamp) {
            return !$element->isComplete();
        }

        if (is_array($element)) {
            self::assertDepth($depth);
            foreach ($element as $child) {
                if (self::elementHasIncompleteVersionstamp($child, $depth + 1)) {
                    return true;
                }
            }
        }

        return false;
    }

    private static function countVersionstamps(mixed $element, int &$count, int $depth): void
    {
        if ($element instanceof Versionstamp) {
            $count++;
        } elseif (is_array($element)) {
            self::assertDepth($depth);
            foreach ($element as $child) {
                self::countVersionstamps($child, $count, $depth + 1);
            }
        }
    }

    /**
     * @param list<mixed> $elements
     */
    private static function findVersionstampOffset(array $elements, int $baseOffset, int $depth): int
    {
        $offset = $baseOffset;

        foreach ($elements as $element) {
            if ($element instanceof Versionstamp) {
                return $offset + 1;
            }

            if (is_array($element)) {
                self::assertDepth($depth);
                /** @var list<mixed> $element */
                $innerOffset = self::findVersionstampOffset($element, $offset + 1, $depth + 1);
                if ($innerOffset >= 0) {
                    return $innerOffset;
                }
            }

            $encoded = self::encodeElement($element, false, $depth);
            $offset += strlen($encoded);
        }

        return -1;
    }
}
