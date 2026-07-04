<?php

declare(strict_types=1);

namespace CrazyGoat\FoundationDB\Tests\Unit;

use CrazyGoat\FoundationDB\Directory\DirectoryLayer;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;

/**
 * Unit tests for the explicit caller-supplied prefix validation that fixes
 * the silent-truncation / no-validation bug from issue #41: a manually
 * supplied prefix used to be written into the directory layer without any
 * conflict check, so it could overlap existing directory metadata or
 * content keys and silently corrupt data. The fix routes every caller-
 * supplied prefix through `validateRawPrefix()` which throws
 * `DirectoryException` if the prefix is empty, conflicts with directory
 * metadata, or overlaps existing content keys.
 *
 * The validation logic is deliberately factored into a static helper
 * (`validateRawPrefix()`) so it can be exercised in pure PHP without
 * needing a live `Transaction` (which is bound to the native FFI client
 * and cannot be stubbed). The Transaction-dependent happy/sad paths are
 * covered by integration tests in `tests/Integration/DirectoryTest.php`.
 */
final class DirectoryPrefixValidationTest extends TestCase
{
    /**
     * @param (callable(string): bool)|null $nodeProbe    Returns true if
     *        any key currently exists under the node-metadata+prefix
     *        range; this mirrors Transaction::getRangeStartsWith().
     * @param (callable(string): bool)|null $contentProbe Returns true if
     *        any key currently exists under the content+prefix range.
     */
    private function validate(
        string $rawPrefix,
        string $contentSubspaceKey,
        ?callable $nodeProbe,
        ?callable $contentProbe,
    ): void {
        $method = new ReflectionMethod(DirectoryLayer::class, 'validateRawPrefix');
        $method->invoke(
            new DirectoryLayer(),
            $rawPrefix,
            $contentSubspaceKey,
            $nodeProbe,
            $contentProbe,
        );
    }

    private function printable(string $prefix): string
    {
        $method = new ReflectionMethod(DirectoryLayer::class, 'printablePrefix');

        /** @var string */
        return $method->invoke(new DirectoryLayer(), $prefix);
    }

    // -- printablePrefix boundaries --------------------------------------------

    #[Test]
    public function printablePrefixRendersPrintableAsciiUnchanged(): void
    {
        self::assertSame('abc123', $this->printable('abc123'));
    }

    #[Test]
    public function printablePrefixRendersSpaceAsIs(): void
    {
        $byte = 0x20;
        self::assertSame(' ', $this->printable(chr($byte)));
    }

    #[Test]
    public function printablePrefixRendersTildeAsIs(): void
    {
        $byte = 0x7E;
        self::assertSame('~', $this->printable(chr($byte)));
    }

    #[Test]
    public function printablePrefixEscapesByteBelowSpace(): void
    {
        $byte = 0x1F;
        self::assertSame('\\x1F', $this->printable(chr($byte)));
    }

    #[Test]
    public function printablePrefixEscapesNullByte(): void
    {
        self::assertSame('\\x00', $this->printable("\x00"));
    }

    #[Test]
    public function printablePrefixEscapesByteAtSevenBitBoundary(): void
    {
        $byte = 0x7F;
        self::assertSame('\\x7F', $this->printable(chr($byte)));
    }

    #[Test]
    public function printablePrefixEscapesHighAsciiByte(): void
    {
        $byte = 0x80;
        self::assertSame('\\x80', $this->printable(chr($byte)));
    }

    #[Test]
    public function printablePrefixEscapesLatinOneByte(): void
    {
        $byte = 0xFF;
        self::assertSame('\\xFF', $this->printable(chr($byte)));
    }

    #[Test]
    public function printablePrefixRendersMixedContent(): void
    {
        $prefix = "ab\x00\xFF~cd";
        self::assertSame('ab\\x00\\xFF~cd', $this->printable($prefix));
    }

    #[Test]
    public function printablePrefixAcceptsEmptyString(): void
    {
        self::assertSame('', $this->printable(''));
    }

    // -- validateRawPrefix: accepted boundaries --------------------------------

    #[Test]
    public function validateAcceptsNonEmptyFreePrefix(): void
    {
        self::expectNotToPerformAssertions();

        $this->validate('myPrefix', '', static fn (string $_): bool => false, static fn (string $_): bool => false);
    }

    #[Test]
    public function validateAcceptsBinaryPrefix(): void
    {
        // Binary prefixes are part of the API; a busy conflict-free binary
        // prefix must be accepted.
        self::expectNotToPerformAssertions();

        $this->validate("\xAA\xBB\xCC", '', static fn (string $_): bool => false, static fn (string $_): bool => false);
    }

    #[Test]
    public function validateAcceptsPrefixExactlyOneByteLong(): void
    {
        self::expectNotToPerformAssertions();

        $this->validate('x', '', static fn (string $_): bool => false, static fn (string $_): bool => false);
    }

    #[Test]
    public function validateAcceptsFreePrefixWhenContentSubspaceKeyIsSet(): void
    {
        // The contentSubspaceKey parameter is prepended to produce the
        // full key the probes receive; we verify both probes ran and that
        // they received the contentSubspaceKey+rawPrefix composition.

        $observed = [];
        $nodeProbe = function (string $key) use (&$observed): bool {
            $observed[] = ['node', $key];

            return false;
        };
        $contentProbe = function (string $key) use (&$observed): bool {
            $observed[] = ['content', $key];

            return false;
        };

        $this->validate('raw', "\xFE", $nodeProbe, $contentProbe);

        // Both probes were consulted, and the keys passed to all probes
        // are the contentSubspaceKey + raw prefix composition. (The
        // node-range probe in the live path additionally prepends the
        // nodeSubspace->key(); the unit-test helper intentionally focuses
        // on the post-content-subspace prefix because that is what
        // DirectoryLayer::createInternal() — the lone caller — needs.)
        self::assertCount(2, $observed);
        self::assertContains(['node', "\xFEraw"], $observed);
        self::assertContains(['content', "\xFEraw"], $observed);
    }

    // -- validateRawPrefix: rejected boundaries --------------------------------

    #[Test]
    public function validateRejectsEmptyPrefix(): void
    {
        $this->expectException(\CrazyGoat\FoundationDB\Directory\DirectoryException::class);
        $this->expectExceptionMessage('Caller-supplied prefix must not be empty.');

        // Predicates aren't reached, but supply non-conflicting stubs to
        // make the test fail loudly if the guard ever moves.
        $this->validate('', '', static fn (string $_): bool => false, static fn (string $_): bool => false);
    }

    #[Test]
    public function validateRejectsPrefixOverlappingDirectoryMetadata(): void
    {
        $this->expectException(\CrazyGoat\FoundationDB\Directory\DirectoryException::class);
        $this->expectExceptionMessage('Caller-supplied prefix conflicts with existing directory metadata');

        // Simulate: directory metadata already exists under node+prefix.
        $this->validate(
            'collideMeta',
            '',
            static fn (string $_): bool => true,
            static fn (string $_): bool => false,
        );
    }

    #[Test]
    public function validateRejectsPrefixOverlappingContentKeys(): void
    {
        $this->expectException(\CrazyGoat\FoundationDB\Directory\DirectoryException::class);
        $this->expectExceptionMessage('Caller-supplied prefix overlaps existing content keys');

        $this->validate(
            'collideContent',
            '',
            static fn (string $_): bool => false,
            static fn (string $_): bool => true,
        );
    }

    #[Test]
    public function validateChecksNodeRangeBeforeContentRange(): void
    {
        // When both ranges report conflicts, the metadata message wins
        // (it's the earlier, more specific diagnostic).
        $this->expectException(\CrazyGoat\FoundationDB\Directory\DirectoryException::class);
        $this->expectExceptionMessage('conflicts with existing directory metadata');

        $this->validate(
            'both',
            '',
            static fn (string $_): bool => true,
            static fn (string $_): bool => true,
        );
    }

    #[Test]
    public function validateErrorMessageIncludesPrintablePrefixForMetadataConflict(): void
    {
        try {
            $this->validate(
                "maul\x01\xFF",
                '',
                static fn (string $_): bool => true,
                static fn (string $_): bool => false,
            );
            self::fail('Expected DirectoryException was not thrown.');
        } catch (\CrazyGoat\FoundationDB\Directory\DirectoryException $e) {
            // The exception message must contain a printable rendering of
            // the offending key so the developer can identify the conflict.
            // Printable form escapes control bytes with \xHH while keeping
            // printable bytes verbatim.
            self::assertStringContainsString('maul\\x01\\xFF', $e->getMessage());
        }
    }

    #[Test]
    public function validateErrorMessageIncludesPrintablePrefixForContentConflict(): void
    {
        try {
            $this->validate(
                "user\x01~data",
                '',
                static fn (string $_): bool => false,
                static fn (string $_): bool => true,
            );
            self::fail('Expected DirectoryException was not thrown.');
        } catch (\CrazyGoat\FoundationDB\Directory\DirectoryException $e) {
            self::assertStringContainsString('user\\x01~data', $e->getMessage());
        }
    }
}
