<?php

declare(strict_types=1);

namespace CrazyGoat\FoundationDB\Tests\Unit;

use CrazyGoat\FoundationDB\NativeClient;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for the pthread/dl library selection (issue #165).
 *
 * libpthread/libdl are separate shared objects on Linux but are exported by
 * libSystem on macOS, so the mapping must follow the platform instead of
 * hardcoding the Linux sonames.
 */
final class NativeClientPlatformLibrariesTest extends TestCase
{
    #[Test]
    public function darwinUsesLibSystemForPthreadAndDl(): void
    {
        $libraries = NativeClient::nativeLibraries('Darwin');

        self::assertSame('libSystem.B.dylib', $libraries['pthread']);
        self::assertSame('libSystem.B.dylib', $libraries['dl']);
    }

    #[Test]
    public function linuxUsesTheSeparatePthreadAndDlLibraries(): void
    {
        $libraries = NativeClient::nativeLibraries('Linux');

        self::assertSame('libpthread.so.0', $libraries['pthread']);
        self::assertSame('libdl.so.2', $libraries['dl']);
    }

    #[Test]
    public function unsupportedOsFamilyIsRejectedInsteadOfUsingLinuxSonames(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Windows');

        NativeClient::nativeLibraries('Windows');
    }

    #[Test]
    public function unsupportedOsFamilyHasNoDefaultLibraryName(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Windows');

        NativeClient::defaultLibraryName('Windows');
    }

    #[Test]
    public function defaultLibraryNameFollowsThePlatform(): void
    {
        self::assertSame('libfdb_c.dylib', NativeClient::defaultLibraryName('Darwin'));
        self::assertSame('libfdb_c.so', NativeClient::defaultLibraryName('Linux'));
    }

    #[Test]
    public function currentPlatformResolvesToAUsableMapping(): void
    {
        if (!in_array(PHP_OS_FAMILY, ['Linux', 'Darwin'], true)) {
            self::markTestSkipped(sprintf('No native library mapping for %s', PHP_OS_FAMILY));
        }

        $libraries = NativeClient::nativeLibraries();

        self::assertNotSame('', $libraries['pthread']);
        self::assertNotSame('', $libraries['dl']);
        self::assertStringEndsWith(
            PHP_OS_FAMILY === 'Darwin' ? '.dylib' : '.so',
            NativeClient::defaultLibraryName(),
        );
    }
}
