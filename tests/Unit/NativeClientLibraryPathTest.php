<?php

declare(strict_types=1);

namespace CrazyGoat\FoundationDB\Tests\Unit;

use CrazyGoat\FoundationDB\NativeClient;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for NativeClient library path resolution (issue #49).
 *
 * Loading the native library by bare soname ("libfdb_c.so") depends on the
 * dynamic linker search path and is therefore subject to library hijacking.
 * NativeClient now supports pinning an absolute path via the FDB_LIBRARY_PATH
 * environment variable; these tests cover the resolution/selection logic.
 */
final class NativeClientLibraryPathTest extends TestCase
{
    /** @var string|false Ambient value saved by setUp() so tearDown() can restore it. */
    private string|false $ambientLibraryPath;

    protected function setUp(): void
    {
        $this->ambientLibraryPath = getenv(NativeClient::LIBRARY_PATH_ENV);
        parent::setUp();
    }

    protected function tearDown(): void
    {
        self::restoreLibraryPathEnv($this->ambientLibraryPath);
        NativeClient::resetInstance();
    }

    /**
     * Restores the ambient FDB_LIBRARY_PATH instead of unsetting it, so a run
     * like `FDB_LIBRARY_PATH=/opt/fdb/lib/libfdb_c.so composer test` keeps the
     * pin for later suites (for example Integration) in the same process.
     *
     * @internal
     */
    public static function restoreLibraryPathEnv(string|false $saved): void
    {
        if ($saved === false) {
            putenv(NativeClient::LIBRARY_PATH_ENV);
        } else {
            putenv(NativeClient::LIBRARY_PATH_ENV . '=' . $saved);
        }
    }

    #[Test]
    public function defaultsToBareSonameWhenNothingIsConfigured(): void
    {
        putenv(NativeClient::LIBRARY_PATH_ENV);

        self::assertSame(NativeClient::defaultLibraryName(), NativeClient::resolveLibraryPath());
    }

    #[Test]
    public function usesEnvironmentVariableWhenSet(): void
    {
        putenv(NativeClient::LIBRARY_PATH_ENV . '=/opt/fdb/lib/libfdb_c.so');

        self::assertSame('/opt/fdb/lib/libfdb_c.so', NativeClient::resolveLibraryPath());
    }

    #[Test]
    public function explicitArgumentTakesPrecedenceOverEnvironment(): void
    {
        putenv(NativeClient::LIBRARY_PATH_ENV . '=/opt/fdb/lib/libfdb_c.so');

        self::assertSame(
            '/usr/local/lib/libfdb_c.so',
            NativeClient::resolveLibraryPath('/usr/local/lib/libfdb_c.so'),
        );
    }

    #[Test]
    public function emptyEnvironmentVariableFallsBackToSoname(): void
    {
        putenv(NativeClient::LIBRARY_PATH_ENV . '=');

        self::assertSame(NativeClient::defaultLibraryName(), NativeClient::resolveLibraryPath());
    }

    #[Test]
    public function emptyExplicitArgumentFallsBackToSoname(): void
    {
        putenv(NativeClient::LIBRARY_PATH_ENV);

        self::assertSame(NativeClient::defaultLibraryName(), NativeClient::resolveLibraryPath(''));
    }

    #[Test]
    public function relativeConfiguredPathIsRejected(): void
    {
        putenv(NativeClient::LIBRARY_PATH_ENV . '=lib/libfdb_c.so');

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage(NativeClient::LIBRARY_PATH_ENV);

        NativeClient::resolveLibraryPath();
    }

    #[Test]
    public function relativeExplicitPathIsRejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        NativeClient::resolveLibraryPath('lib/libfdb_c.so');
    }

    /**
     * Verifies the teardown contract through the helper tearDown() uses: a
     * test may overwrite or unset FDB_LIBRARY_PATH, and the value saved by
     * setUp() must come back. Testing the helper directly avoids invoking
     * tearDown() twice (manually and from PHPUnit).
     */
    #[Test]
    public function restoreLibraryPathEnvRestoresTheSavedValue(): void
    {
        $saved = '/opt/fdb/lib/libfdb_c.so';

        putenv(NativeClient::LIBRARY_PATH_ENV . '=' . $saved);
        self::restoreLibraryPathEnv(false);
        self::assertFalse(getenv(NativeClient::LIBRARY_PATH_ENV));

        self::restoreLibraryPathEnv($saved);
        self::assertSame($saved, getenv(NativeClient::LIBRARY_PATH_ENV));
    }

    /**
     * Functional check: when a pinned path is configured, that exact path is
     * the one used for FFI::cdef(). Environment-dependent: skipped when no
     * real libfdb_c is installed, because unit tests must not require
     * FoundationDB. The path is exercised for real in the compose php
     * container and in the unit-test CI matrix, which installs the
     * FoundationDB client library for every supported PHP version; elsewhere
     * (for example a macOS host without the client) the test is skipped
     * explicitly rather than silently.
     */
    #[Test]
    public function pinnedAbsolutePathIsUsedForLibraryLoading(): void
    {
        $realPath = $this->findRealLibraryPath();

        if ($realPath === null) {
            self::markTestSkipped('libfdb_c is not installed on this machine');
        }

        putenv(NativeClient::LIBRARY_PATH_ENV . '=' . $realPath);
        NativeClient::resetInstance();
        $client = NativeClient::getInstance();

        self::assertSame($realPath, $client->getLibraryPath());
        // The pinned path must be loadable by FFI::cdef() with the real header.
        /** @var string $header */
        $header = (new \ReflectionClass(NativeClient::class))->getConstant('FDB_HEADER');
        $ffi = \FFI::cdef($header, $realPath);

        self::assertInstanceOf(\FFI::class, $ffi);
        // @phpstan-ignore method.notFound (dynamically bound C function)
        self::assertGreaterThan(0, $ffi->fdb_get_max_api_version());
    }

    /**
     * Locates a real libfdb_c on this machine, or returns null. Tries the
     * FDB_LIBRARY_PATH environment variable first, then the common macOS
     * (Homebrew/pkg) and Linux install locations.
     */
    private function findRealLibraryPath(): ?string
    {
        $configured = getenv(NativeClient::LIBRARY_PATH_ENV);
        if (is_string($configured) && $configured !== '' && is_file($configured)) {
            return $configured;
        }

        $candidates = [
            // macOS: Apple silicon Homebrew, Intel Homebrew, pkg installer.
            '/opt/homebrew/lib/libfdb_c.dylib',
            '/usr/local/lib/libfdb_c.dylib',
            '/usr/lib/libfdb_c.dylib',
            // Linux: distro and local installs, 32/64-bit layouts.
            '/usr/local/lib64/libfdb_c.so',
            '/usr/lib64/libfdb_c.so',
            '/usr/lib/x86_64-linux-gnu/libfdb_c.so',
            '/usr/lib/aarch64-linux-gnu/libfdb_c.so',
            '/usr/local/lib/libfdb_c.so',
            '/usr/lib/libfdb_c.so',
        ];

        foreach ($candidates as $candidate) {
            if (is_file($candidate)) {
                return $candidate;
            }
        }

        return null;
    }
}
