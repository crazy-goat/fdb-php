<?php

declare(strict_types=1);

namespace CrazyGoat\FoundationDB;

/**
 * Explicit bounds checks for keys, values, and range boundaries at the FFI trust boundary.
 *
 * FoundationDB's `libfdb_c` C API declares length parameters as `int` (32-bit on supported
 * platforms). PHP `strlen()` returns a 64-bit integer, so any length > 2,147,483,647 would
 * silently truncate when passed across the FFI boundary — producing a length/buffer
 * mismatch that can over-read or silently corrupt memory before FoundationDB itself has a
 * chance to validate the input.
 *
 * In addition, FoundationDB enforces hard limits on key, value, and transaction sizes.
 * The native client (server-side) validates these at commit time, surfacing opaque codes
 * `2102` (key too large) and `2103` (value too large). Pre-flight validation at the PHP
 * trust boundary gives the application an immediate, named `\InvalidArgumentException`
 * with the offending length at the offending call site instead of failing opaquely on
 * `commit()`.
 *
 * The constants are taken from the official FoundationDB documentation
 * (<https://apple.github.io/foundationdb/known-limitations.html>):
 * - Keys:   maximum 10,000 bytes
 * - Values: maximum 100,000 bytes
 *
 * @internal Public for testability; not part of the public API.
 */
final class KeyValueLimits
{
    /**
     * Maximum key size enforced by FoundationDB (server-side limit).
     * Keys larger than this are rejected by `fdb_c` with code `2102`.
     */
    public const MAX_KEY_SIZE = 10000;

    /**
     * Maximum value size enforced by FoundationDB (server-side limit).
     * Values larger than this are rejected by `fdb_c` with code `2103`.
     */
    public const MAX_VALUE_SIZE = 100000;

    /**
     * Maximum length that can safely cross the FFI boundary without truncation.
     *
     * `libfdb_c` declares length parameters as 32-bit `int`. PHP `strlen()` returns a
     * 64-bit integer; passing a length larger than `2^31 - 1` would truncate to a negative
     * or wrap-around value at the FFI call and either corrupt the read buffer or be
     * rejected by `libfdb_c`. We refuse to pass such lengths across the FFI surface.
     *
     * `2,147,483,647` is the largest value the C `int` can hold signed. Since both
     * `MAX_KEY_SIZE` and `MAX_VALUE_SIZE` are well below this bound, the guard only fires
     * for pathological inputs and is purely defensive.
     */
    public const MAX_FFI_LENGTH = 2147483647;

    private function __construct()
    {
    }

    /**
     * Validate that a key length is within both the FoundationDB key limit and the
     * FFI 32-bit safety bound. Returns the length on success so callers can avoid
     * recomputing it.
     *
     * @throws \InvalidArgumentException if the key is empty, exceeds the FDB key
     *                                    size limit, or exceeds the FFI 32-bit
     *                                    safety bound.
     */
    public static function assertValidKey(string $key): int
    {
        $length = self::measure($key);

        if ($length === 0) {
            throw new \InvalidArgumentException('FoundationDB keys must not be empty');
        }

        if ($length > self::MAX_KEY_SIZE) {
            throw new \InvalidArgumentException(sprintf(
                'FoundationDB key exceeds maximum size: %d bytes (limit is %d bytes)',
                $length,
                self::MAX_KEY_SIZE,
            ));
        }

        self::assertFfiSafe($length, 'FoundationDB key');

        return $length;
    }

    /**
     * Validate that a value length is within both the FoundationDB value limit and the
     * FFI 32-bit safety bound.
     *
     * Empty values are explicitly allowed — clearing a key with `$tr->set($key, '')` is
     * the canonical way to delete a single key without touching its neighbours, and many
     * administrative special keys (such as `\xff\xff/management/excluded/<addr>`) carry
     * an empty value by design.
     *
     * @throws \InvalidArgumentException if the value exceeds the FDB value size limit
     *                                    or exceeds the FFI 32-bit safety bound.
     */
    public static function assertValidValue(string $value): int
    {
        $length = self::measure($value);

        if ($length > self::MAX_VALUE_SIZE) {
            throw new \InvalidArgumentException(sprintf(
                'FoundationDB value exceeds maximum size: %d bytes (limit is %d bytes)',
                $length,
                self::MAX_VALUE_SIZE,
            ));
        }

        self::assertFfiSafe($length, 'FoundationDB value');

        return $length;
    }

    /**
     * Validate a range-endpoint length (begin or end key of a range, conflict range,
     * or selector key). Range endpoints share the FDB key size limit.
     *
     * Unlike {@see self::assertValidKey()}, a zero-length endpoint is allowed because
     * an inclusive half-open range like `['', "\x00")` is a legitimate query, and the
     * `KeySelector::firstGreaterOrEqual('')` pattern must keep working.
     *
     * @throws \InvalidArgumentException if the endpoint exceeds the FDB key size
     *                                    limit or the FFI 32-bit safety bound.
     */
    public static function assertValidRangeEndpoint(string $endpoint): int
    {
        $length = self::measure($endpoint);

        if ($length > self::MAX_KEY_SIZE) {
            throw new \InvalidArgumentException(sprintf(
                'FoundationDB range endpoint exceeds maximum size: %d bytes (limit is %d bytes)',
                $length,
                self::MAX_KEY_SIZE,
            ));
        }

        self::assertFfiSafe($length, 'FoundationDB range endpoint');

        return $length;
    }

    /**
     * Validate a length that does not have to fit inside the FDB key/value limits
     * because the bytes travel through a different FFI path — option values, tenant
     * names, server addresses — but still cross the FFI boundary as a 32-bit `int`.
     *
     * @throws \InvalidArgumentException if the length exceeds the FFI 32-bit safety bound.
     */
    public static function assertValidFfiLength(string $value, string $label): int
    {
        $length = self::measure($value);
        self::assertFfiSafe($length, $label);

        return $length;
    }

    /**
     * Length computation isolated so the type does not get narrowed by
     * upstream `throw` branches and confuse static analyzers about the
     * downstream FFI guard.
     */
    private static function measure(string $value): int
    {
        return strlen($value);
    }

    /**
     * Defensive truncation guard at the FFI boundary. The PHP `strlen()` returns a
     * 64-bit integer, but `libfdb_c` declares its length parameters as C `int` (32-bit
     * signed). Anything strictly greater than {@see self::MAX_FFI_LENGTH} would truncate
     * silently — throwing here makes the over-sized input visible at the call site
     * instead of producing a length/buffer mismatch inside the libfdb_c runtime.
     *
     * @throws \InvalidArgumentException if the length exceeds the 32-bit signed boundary.
     */
    private static function assertFfiSafe(int $length, string $label): void
    {
        if ($length > self::MAX_FFI_LENGTH) {
            throw new \InvalidArgumentException(sprintf(
                '%s length %d exceeds the 32-bit FFI safety bound (%d); ' .
                'passing it would truncate to int at the C boundary',
                $label,
                $length,
                self::MAX_FFI_LENGTH,
            ));
        }
    }
}
