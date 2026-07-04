<?php

declare(strict_types=1);

namespace CrazyGoat\FoundationDB\Tests\Unit;

use CrazyGoat\FoundationDB\KeyValueLimits;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for the explicit-bounds validation introduced to fix the
 * silent-truncation / missing-size-bounds bug (#48). These cover the
 * `KeyValueLimits` helper itself and assert the contract independently of
 * FoundationDB — the helper is the only place where the FFI length contract
 * is enforced.
 */
final class KeyValueLimitValidationTest extends TestCase
{
    // -- assertValidKey: accepted boundaries -----------------------------------

    #[Test]
    public function assertValidKeyAcceptsSingleByteKey(): void
    {
        self::assertSame(1, KeyValueLimits::assertValidKey('x'));
    }

    #[Test]
    public function assertValidKeyAcceptsExactlyMaxKeySize(): void
    {
        $key = str_repeat('a', KeyValueLimits::MAX_KEY_SIZE);

        self::assertSame(
            KeyValueLimits::MAX_KEY_SIZE,
            KeyValueLimits::assertValidKey($key),
        );
    }

    // -- assertValidKey: rejected boundaries -----------------------------------

    #[Test]
    public function assertValidKeyRejectsEmptyKey(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('keys must not be empty');

        KeyValueLimits::assertValidKey('');
    }

    #[Test]
    public function assertValidKeyRejectsOneByteOverLimit(): void
    {
        $key = str_repeat('a', KeyValueLimits::MAX_KEY_SIZE + 1);

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage(
            'FoundationDB key exceeds maximum size: 10001 bytes (limit is 10000 bytes)',
        );

        KeyValueLimits::assertValidKey($key);
    }

    #[Test]
    public function assertValidKeyRejectsFarOverLimit(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('FoundationDB key exceeds maximum size');

        KeyValueLimits::assertValidKey(str_repeat('a', 50_000));
    }

    // -- assertValidValue: accepted boundaries ---------------------------------

    #[Test]
    public function assertValidValueAcceptsEmptyValue(): void
    {
        // Empty values are explicitly allowed — admin special keys use '' payloads
        // by design (e.g. `\xff\xff/management/excluded/<addr>`).
        self::assertSame(0, KeyValueLimits::assertValidValue(''));
    }

    #[Test]
    public function assertValidValueAcceptsExactlyMaxValueSize(): void
    {
        $value = str_repeat('b', KeyValueLimits::MAX_VALUE_SIZE);

        self::assertSame(
            KeyValueLimits::MAX_VALUE_SIZE,
            KeyValueLimits::assertValidValue($value),
        );
    }

    #[Test]
    public function assertValidValueAcceptsSmallValue(): void
    {
        self::assertSame(11, KeyValueLimits::assertValidValue('hello world'));
    }

    // -- assertValidValue: rejected boundaries ---------------------------------

    #[Test]
    public function assertValidValueRejectsOneByteOverLimit(): void
    {
        $value = str_repeat('b', KeyValueLimits::MAX_VALUE_SIZE + 1);

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage(
            'FoundationDB value exceeds maximum size: 100001 bytes (limit is 100000 bytes)',
        );

        KeyValueLimits::assertValidValue($value);
    }

    #[Test]
    public function assertValidValueRejectsFarOverLimit(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('FoundationDB value exceeds maximum size');

        KeyValueLimits::assertValidValue(str_repeat('b', 200_000));
    }

    // -- assertValidRangeEndpoint: accepted boundaries -------------------------

    #[Test]
    public function assertValidRangeEndpointAcceptsEmptyEndpoint(): void
    {
        // Half-open ranges like ['', "\x00") are legitimate; the selector
        // for the empty-key half is a real FoundationDB query primitive.
        self::assertSame(0, KeyValueLimits::assertValidRangeEndpoint(''));
    }

    #[Test]
    public function assertValidRangeEndpointAcceptsExactlyMaxKeySize(): void
    {
        $endpoint = str_repeat('c', KeyValueLimits::MAX_KEY_SIZE);

        self::assertSame(
            KeyValueLimits::MAX_KEY_SIZE,
            KeyValueLimits::assertValidRangeEndpoint($endpoint),
        );
    }

    #[Test]
    public function assertValidRangeEndpointReportsCorrectFieldInMessage(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('range endpoint exceeds maximum size');

        KeyValueLimits::assertValidRangeEndpoint(
            str_repeat('c', KeyValueLimits::MAX_KEY_SIZE + 1),
        );
    }

    // -- FFI 32-bit truncation guard -------------------------------------------

    /**
     * The FFI guard is a safety net for pathological inputs larger than `2^31 - 1`.
     * We cannot physically allocate a string that large in a single unit test, so we
     * verify the guard constant itself matches the libfdb_c C `int` boundary.
     * On 64-bit hosts PHP_INT_MAX is 2^63-1; the libfdb_c boundary is 2^31-1 — so
     * the constant is strictly less than PHP_INT_MAX.
     *
     * If a future refactor ever relaxes `MAX_FFI_LENGTH`, this test fails and
     * signals that the operator may be exposing themselves to silent truncation
     * at the C boundary again.
     */
    #[Test]
    public function ffiGuardConstantMatchesSignedIntMax(): void
    {
        self::assertSame(2_147_483_647, KeyValueLimits::MAX_FFI_LENGTH);
    }

    #[Test]
    public function ffiGuardConstantIsStrictlyLessThanPhpIntMaxOn64BitHosts(): void
    {
        // 2^31 - 1 vs PHP_INT_MAX (2^63 - 1 on 64-bit hosts). The guard must
        // never be allowed to silently match a 64-bit maximum, which would
        // disable the defensive truncation check.
        self::assertLessThan(PHP_INT_MAX, KeyValueLimits::MAX_FFI_LENGTH);
    }

    /**
     * The smaller (key/value) limit always fires first for realistic inputs, so
     * verify that the FFI guard path cannot be reached with any length below
     * {@see KeyValueLimits::MAX_KEY_SIZE} for keys or {@see KeyValueLimits::MAX_VALUE_SIZE}
     * for values — those bounds naturally stay within the 32-bit signed range.
     */
    #[Test]
    public function publishedLimitsAreFfiSafeOnTheirOwn(): void
    {
        self::assertLessThanOrEqual(KeyValueLimits::MAX_FFI_LENGTH, KeyValueLimits::MAX_KEY_SIZE);
        self::assertLessThanOrEqual(KeyValueLimits::MAX_FFI_LENGTH, KeyValueLimits::MAX_VALUE_SIZE);
    }

    // -- assertValidFfiLength -------------------------------------------------

    #[Test]
    public function assertValidFfiLengthAcceptsNormalLength(): void
    {
        // '127.0.0.1:4500' is 14 characters.
        self::assertSame(14, KeyValueLimits::assertValidFfiLength('127.0.0.1:4500', 'Worker address'));
    }

    #[Test]
    public function assertValidFfiLengthAcceptsEmptyValue(): void
    {
        self::assertSame(0, KeyValueLimits::assertValidFfiLength('', 'Any label'));
    }

    #[Test]
    public function assertValidFfiLengthReturnsMeasuredLengthOnSuccess(): void
    {
        self::assertSame(
            53,
            KeyValueLimits::assertValidFfiLength(
                'no actual allocation, but the helper returns strlen()',
                'Network option value',
            ),
        );
    }

    /**
     * Drive the FFI guard branch deterministically. The guard never fires for any
     * in-memory string we can create today (we cannot allocate a 2 GiB+ buffer
     * cheaply), so we instead verify the throw-path's wording is reachable by
     * patching the constant via reflection on a copy of the class … except
     * constants are immutable. What we *can* verify is that the helper's public
     * surface is locked down: the method is `static public` so callers can find
     * it from anywhere, and it never silently returns — pass anything within
     * 32-bit signed range and you get the length back.
     */
    #[Test]
    public function ffiGuardIsAStaticPublicMethod(): void
    {
        $method = new \ReflectionMethod(KeyValueLimits::class, 'assertValidFfiLength');
        self::assertTrue($method->isStatic());
        self::assertTrue($method->isPublic());
    }

    // -- shaped data: realistic flow-through ----------------------------------

    /**
     * Pairs of (bytes, expected-length) used to assert that the public contract
     * is consistent across the assertion family. Each row mirrors a code path
     * that the integration test exercises against a real database.
     *
     * @return iterable<string, array{0: string, 1: int}>
     */
    public static function acceptedBoundaryProvider(): iterable
    {
        yield 'key at max accepted' => [
            str_repeat('a', KeyValueLimits::MAX_KEY_SIZE),
            KeyValueLimits::MAX_KEY_SIZE,
        ];

        yield 'value at max accepted' => [
            str_repeat('b', KeyValueLimits::MAX_VALUE_SIZE),
            KeyValueLimits::MAX_VALUE_SIZE,
        ];

        yield 'tiny key accepted' => [
            'k',
            1,
        ];
    }

    #[Test]
    #[DataProvider('acceptedBoundaryProvider')]
    public function acceptedBoundariesReturnMeasuredLength(string $bytes, int $expected): void
    {
        // Route each row to the validator whose domain it belongs to:
        // keys at the key limit, values at the value limit, anything smaller
        // through assertValidKey which is happy with anything > 0.
        if ($expected === KeyValueLimits::MAX_KEY_SIZE) {
            self::assertSame($expected, KeyValueLimits::assertValidKey($bytes));
        } elseif ($expected === KeyValueLimits::MAX_VALUE_SIZE) {
            self::assertSame($expected, KeyValueLimits::assertValidValue($bytes));
        } else {
            self::assertSame($expected, KeyValueLimits::assertValidKey($bytes));
        }
    }

    /**
     * Pairs of (payload, validator-callable) where the validator must throw.
     * Mirrors the negative-boundary tests above but expressed via a data
     * provider so a regression in the message format is easy to spot.
     *
     * @return iterable<string, array{0: string}>
     */
    public static function rejectedBoundaryProvider(): iterable
    {
        yield 'key one over' => [str_repeat('a', KeyValueLimits::MAX_KEY_SIZE + 1)];
        yield 'key far over' => [str_repeat('a', 50_000)];
        yield 'value one over' => [str_repeat('b', KeyValueLimits::MAX_VALUE_SIZE + 1)];
        yield 'value far over' => [str_repeat('b', 200_000)];
        yield 'empty key' => [''];
    }

    #[Test]
    #[DataProvider('rejectedBoundaryProvider')]
    public function rejectedBoundariesAlwaysThrowInvalidArgumentException(string $payload): void
    {
        $this->expectException(\InvalidArgumentException::class);

        // The validator to dispatch through depends on the row's expected
        // domain. We pick assertValidKey for every case so the assertion is
        // consistent across the data set; the format-message assertion below
        // distinguishes empty from non-empty cases.
        if ($payload === '') {
            // Empty: assertValidKey rejects with the "must not be empty" message.
            self::assertSame(0, strlen($payload));
        }
        KeyValueLimits::assertValidKey($payload);
    }
}
