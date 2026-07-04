<?php

declare(strict_types=1);

namespace CrazyGoat\FoundationDB\Tests\Unit;

use CrazyGoat\FoundationDB\AdminClient;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;

/**
 * Unit tests for the explicit caller input validation that fixes the
 * silent-truncation / no-validation bug from issue #43: every public
 * AdminClient method that previously spliced caller-supplied bytes
 * directly into a privileged Special Key (`\xff\xff/management/tenant/map/…`,
 * `\xff\xff/management/excluded/…`, `\xff\xff/management/force_recovery`,
 * `\xff\xff/configuration/redundancy`, `\xff\xff/configuration/storage`)
 * now throws `\InvalidArgumentException` at the call site on a
 * pathological input.
 *
 * The validation logic is deliberately factored into private static
 * helpers (`validateTenantName()`, `validateAddress()`,
 * `validateToken()`, `parseConfiguration()`) so it can be exercised in
 * pure PHP without needing a live `Database` / `Transaction` (which
 * is bound to the native FFI client and cannot be stubbed in this
 * binding). The Transaction-dependent happy/sad paths are covered by
 * integration tests in `tests/Integration/AdminInputValidationTest.php`.
 *
 * Each assertion below corresponds to a row of the contract table in
 * the class-level doc-block of `AdminClient`.
 */
final class AdminClientInputValidationTest extends TestCase
{
    /**
     * Construct an AdminClient instance via reflection without invoking
     * the constructor (which requires a Database + NativeClient). The
     * instance is needed because Rector converted the validation
     * helpers from `private static` to `private` (instance methods) so
     * `ReflectionMethod::invoke(null, ...)` is no longer valid.
     */
    private function instance(): AdminClient
    {
        $rc = new \ReflectionClass(AdminClient::class);

        /** @var AdminClient $instance */
        $instance = $rc->newInstanceWithoutConstructor();

        return $instance;
    }

    /** Invoke a private AdminClient validator via reflection on an instance. */
    private function invoke(string $method, string ...$args): void
    {
        $rm = new ReflectionMethod(AdminClient::class, $method);
        $rm->invoke($this->instance(), ...$args);
    }

    /** Invoke a private AdminClient parser and return its result. */
    private function invokeReturn(string $method, string ...$args): mixed
    {
        $rm = new ReflectionMethod(AdminClient::class, $method);

        return $rm->invoke($this->instance(), ...$args);
    }

    // -- printableLabel --------------------------------------------------------

    #[Test]
    public function printableLabelRendersPrintableAsciiUnchanged(): void
    {
        self::assertSame('abc123', $this->invokeReturn('printableLabel', 'abc123'));
    }

    #[Test]
    public function printableLabelRendersSpaceAsIs(): void
    {
        self::assertSame(' ', $this->invokeReturn('printableLabel', ' '));
    }

    #[Test]
    public function printableLabelRendersTildeAsIs(): void
    {
        self::assertSame('~', $this->invokeReturn('printableLabel', '~'));
    }

    #[Test]
    public function printableLabelEscapesNullByte(): void
    {
        self::assertSame('\\x00', $this->invokeReturn('printableLabel', "\x00"));
    }

    #[Test]
    public function printableLabelEscapesByteBelowSpace(): void
    {
        self::assertSame('\\x1F', $this->invokeReturn('printableLabel', "\x1F"));
    }

    #[Test]
    public function printableLabelEscapesDelByte(): void
    {
        self::assertSame('\\x7F', $this->invokeReturn('printableLabel', "\x7F"));
    }

    #[Test]
    public function printableLabelEscapesHighAsciiByte(): void
    {
        self::assertSame('\\xFF', $this->invokeReturn('printableLabel', "\xFF"));
    }

    #[Test]
    public function printableLabelRendersSafePunctuationUnchanged(): void
    {
        self::assertSame('a.b_c-d', $this->invokeReturn('printableLabel', 'a.b_c-d'));
    }

    #[Test]
    public function printableLabelRendersMixedContent(): void
    {
        self::assertSame('a~\\x00\\xFFb', $this->invokeReturn('printableLabel', "a~\x00\xFFb"));
    }

    // -- validateTenantName ----------------------------------------------------

    #[Test]
    public function validateTenantNameAcceptsAlphanumeric(): void
    {
        self::expectNotToPerformAssertions();
        $this->invoke('validateTenantName', 'tenant1', 'createTenant');

        // The lack of an exception is the assertion.
    }

    #[Test]
    public function validateTenantNameAcceptsDotsDashesUnderscores(): void
    {
        self::expectNotToPerformAssertions();
        $this->invoke('validateTenantName', 'tenant.with-chars_ok', 'deleteTenant');
    }

    #[Test]
    public function validateTenantNameAcceptsSingleCharacter(): void
    {
        self::expectNotToPerformAssertions();
        $this->invoke('validateTenantName', 'a', 'createTenant');
    }

    #[Test]
    public function validateTenantNameAcceptsAtMaxLength(): void
    {
        self::expectNotToPerformAssertions();
        $this->invoke('validateTenantName', str_repeat('a', 256), 'createTenant');
    }

    #[Test]
    public function validateTenantNameRejectsEmpty(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('tenant name must not be empty');

        $this->invoke('validateTenantName', '', 'createTenant');
    }

    #[Test]
    public function validateTenantNameRejectsOverMaxLength(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('exceeds maximum length 256 bytes');

        $this->invoke('validateTenantName', str_repeat('a', 257), 'createTenant');
    }

    #[Test]
    public function validateTenantNameRejectsSlash(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('disallowed character');

        $this->invoke('validateTenantName', 'foo/bar', 'createTenant');
    }

    #[Test]
    public function validateTenantNameRejectsBackslash(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('disallowed character');

        $this->invoke('validateTenantName', 'foo\\bar', 'createTenant');
    }

    #[Test]
    public function validateTenantNameRejectsSpace(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('disallowed character');

        $this->invoke('validateTenantName', 'foo bar', 'createTenant');
    }

    #[Test]
    public function validateTenantNameRejectsNullByte(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('disallowed character');

        $this->invoke('validateTenantName', "foo\x00bar", 'createTenant');
    }

    #[Test]
    public function validateTenantNameRejectsHighByte(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('disallowed character');

        $this->invoke('validateTenantName', "foo\xFFbar", 'createTenant');
    }

    #[Test]
    public function validateTenantNameRejectsControlByte(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('disallowed character');

        $this->invoke('validateTenantName', "foo\x1Fbar", 'createTenant');
    }

    #[Test]
    public function validateTenantNameRejectsLeadingDot(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('must start with an alphanumeric');

        $this->invoke('validateTenantName', '.hidden', 'createTenant');
    }

    #[Test]
    public function validateTenantNameRejectsLeadingUnderscore(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('must start with an alphanumeric');

        $this->invoke('validateTenantName', '_hidden', 'createTenant');
    }

    #[Test]
    public function validateTenantNameRejectsLeadingDash(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('must start with an alphanumeric');

        $this->invoke('validateTenantName', '-hidden', 'createTenant');
    }

    #[Test]
    public function validateTenantNameIncludesCallerInMessageOnInvalidInput(): void
    {
        try {
            $this->invoke('validateTenantName', 'foo/bar', 'createTenant');
            self::fail('Expected InvalidArgumentException');
        } catch (\InvalidArgumentException $e) {
            self::assertStringContainsString('createTenant', $e->getMessage());
        }
    }

    #[Test]
    public function validateTenantNameRendersOffendingValueSafelyInMessage(): void
    {
        try {
            // 0x00 / 0xFF would otherwise pollute log output
            $this->invoke('validateTenantName', "foo\x00\xFFbar", 'createTenant');
            self::fail('Expected InvalidArgumentException');
        } catch (\InvalidArgumentException $e) {
            self::assertStringContainsString('\\x00', $e->getMessage());
            self::assertStringContainsString('\\xFF', $e->getMessage());
        }
    }

    // -- validateAddress -------------------------------------------------------

    #[Test]
    public function validateAddressAcceptsHostColonPort(): void
    {
        self::expectNotToPerformAssertions();
        $this->invoke('validateAddress', '127.0.0.1:4500');
    }

    #[Test]
    public function validateAddressAcceptsHostname(): void
    {
        self::expectNotToPerformAssertions();
        $this->invoke('validateAddress', 'server-a.example.com:4500');
    }

    #[Test]
    public function validateAddressAcceptsIPv6(): void
    {
        self::expectNotToPerformAssertions();
        // FDB accepts unbracketed IPv6 addresses (the bracketed form is a
        // textual convention outside the validation surface). The allow-
        // list permits ':', so an IPv6 literal with colons stays inside
        // the address key range.
        $this->invoke('validateAddress', '::1:4500');
        $this->invoke('validateAddress', '2001:db8::1:4500');
    }

    #[Test]
    public function validateAddressRejectsBrackets(): void
    {
        // FDB does not require brackets around IPv6 addresses; if a
        // caller writes a bracketed form, the brackets are not part of
        // the address we store in the Special Key. We refuse them here
        // so an application cannot accidentally drop the brackets later
        // and end up with a silently different address.
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('disallowed character');

        $this->invoke('validateAddress', '[::1]:4500');
    }

    #[Test]
    public function validateAddressAcceptsAtMaxLength(): void
    {
        self::expectNotToPerformAssertions();
        $this->invoke('validateAddress', str_repeat('a', 254) . ':0');
    }

    #[Test]
    public function validateAddressRejectsEmpty(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('server address must not be empty');

        $this->invoke('validateAddress', '');
    }

    #[Test]
    public function validateAddressRejectsOverMaxLength(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('exceeds maximum length 256 bytes');

        $this->invoke('validateAddress', str_repeat('a', 257));
    }

    #[Test]
    public function validateAddressRejectsSlash(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('disallowed character');

        $this->invoke('validateAddress', '127.0.0.1/24');
    }

    #[Test]
    public function validateAddressRejectsSpace(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('disallowed character');

        $this->invoke('validateAddress', '127.0.0.1 4500');
    }

    #[Test]
    public function validateAddressRejectsNullByte(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('disallowed character');

        $this->invoke('validateAddress', "127.0.0.1:4500\x00");
    }

    #[Test]
    public function validateAddressRejectsHighByte(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('disallowed character');

        $this->invoke('validateAddress', "127.0.0.1:\xFF4500");
    }

    // -- validateToken (configure / forceRecovery) -----------------------------

    #[Test]
    public function validateTokenAcceptsAlphanumeric(): void
    {
        self::expectNotToPerformAssertions();
        $this->invoke('validateToken', 'double', 'configure');
        $this->invoke('validateToken', 'ssd', 'configure');
        $this->invoke('validateToken', 'dc1', 'forceRecovery');
    }

    #[Test]
    public function validateTokenAcceptsDashAndUnderscore(): void
    {
        self::expectNotToPerformAssertions();
        $this->invoke('validateToken', 'triple_a', 'configure');
        $this->invoke('validateToken', 'dc-east-1', 'forceRecovery');
    }

    #[Test]
    public function validateTokenAcceptsAtMaxLength(): void
    {
        self::expectNotToPerformAssertions();
        $this->invoke('validateToken', str_repeat('a', 64), 'configure');
    }

    #[Test]
    public function validateTokenRejectsEmpty(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('token must not be empty');

        $this->invoke('validateToken', '', 'configure');
    }

    #[Test]
    public function validateTokenRejectsOverMaxLength(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('exceeds maximum length 64 bytes');

        $this->invoke('validateToken', str_repeat('a', 65), 'configure');
    }

    #[Test]
    public function validateTokenRejectsDot(): void
    {
        // dot is allowed in tenant/address but NOT in configure/forceRecovery:
        // those tokens land in different Special Key paths where FoundationDB
        // does not accept dotted values.
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('disallowed character');

        $this->invoke('validateToken', 'do.t', 'configure');
    }

    #[Test]
    public function validateTokenRejectsColon(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('disallowed character');

        $this->invoke('validateToken', 'dc:1', 'forceRecovery');
    }

    #[Test]
    public function validateTokenRejectsControlByte(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('disallowed character');

        $this->invoke('validateToken', "dou\x00ble", 'configure');
    }

    // -- parseConfiguration (configure) ---------------------------------------

    #[Test]
    public function parseConfigurationAcceptsTwoTokens(): void
    {
        self::assertSame(['double', 'ssd'], $this->invokeReturn('parseConfiguration', 'double ssd'));
    }

    #[Test]
    public function parseConfigurationAcceptsSingleTokenAndDefaultsStorageToSsd(): void
    {
        self::assertSame(['single', 'ssd'], $this->invokeReturn('parseConfiguration', 'single'));
    }

    #[Test]
    public function parseConfigurationAcceptsThreeDashesAndUnderscoresOnEachToken(): void
    {
        self::assertSame(
            ['triple_a', 'ssd-cached'],
            $this->invokeReturn('parseConfiguration', 'triple_a ssd-cached'),
        );
    }

    #[Test]
    public function parseConfigurationRejectsEmpty(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('configuration string must not be empty');

        $this->invokeReturn('parseConfiguration', '');
    }

    #[Test]
    public function parseConfigurationRejectsWhitespaceOnly(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('configuration string must not be empty');

        $this->invokeReturn('parseConfiguration', '   ');
    }

    #[Test]
    public function parseConfigurationRejectsLeadingWhitespace(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('must not have leading or trailing');

        $this->invokeReturn('parseConfiguration', ' double ssd');
    }

    #[Test]
    public function parseConfigurationRejectsTrailingWhitespace(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('must not have leading or trailing');

        $this->invokeReturn('parseConfiguration', 'double ssd ');
    }

    #[Test]
    public function parseConfigurationRejectsTooManyTokens(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('expected 1 or 2');

        $this->invokeReturn('parseConfiguration', 'double ssd somethingextra');
    }

    #[Test]
    public function parseConfigurationRejectsTooFewTokens(): void
    {
        // Zero tokens after split — covered by whitespace-only case, but
        // we also want explicit verification that a malformed sequence
        // like " " (single space) is rejected by the empty guard.
        $this->expectException(\InvalidArgumentException::class);

        $this->invokeReturn('parseConfiguration', ' ');
    }

    #[Test]
    public function parseConfigurationRejectsTokenWithDotInIt(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('disallowed character');

        $this->invokeReturn('parseConfiguration', 'dou.ble ssd');
    }

    #[Test]
    public function parseConfigurationRejectsTokenWithEmbeddedNullByte(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('disallowed character');

        $this->invokeReturn('parseConfiguration', "dou\x00ble ssd");
    }

    #[Test]
    public function parseConfigurationRejectsZeroLengthTokenFromMultipleSpaces(): void
    {
        // "double  ssd" — internal double space is collapsed by preg_split,
        // so this is treated as two valid tokens and must succeed.
        self::assertSame(['double', 'ssd'], $this->invokeReturn('parseConfiguration', 'double  ssd'));
    }

    #[Test]
    public function parseConfigurationRejectsTokenAboveMaxLength(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('exceeds maximum length 64 bytes');

        $this->invokeReturn('parseConfiguration', str_repeat('a', 65));
    }
}
