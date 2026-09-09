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
    protected function tearDown(): void
    {
        putenv(NativeClient::LIBRARY_PATH_ENV);
    }

    #[Test]
    public function defaultsToBareSonameWhenNothingIsConfigured(): void
    {
        putenv(NativeClient::LIBRARY_PATH_ENV);

        self::assertSame('libfdb_c.so', NativeClient::resolveLibraryPath());
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

        self::assertSame('libfdb_c.so', NativeClient::resolveLibraryPath());
    }

    #[Test]
    public function emptyExplicitArgumentFallsBackToSoname(): void
    {
        putenv(NativeClient::LIBRARY_PATH_ENV);

        self::assertSame('libfdb_c.so', NativeClient::resolveLibraryPath(''));
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
     * Functional check: when a pinned path is configured, that exact path is
     * the one used for FFI::cdef(). Skipped when no real libfdb_c is installed
     * (unit tests must not require FoundationDB).
     */
    #[Test]
    public function pinnedAbsolutePathIsUsedForLibraryLoading(): void
    {
        $realPath = $this->findRealLibraryPath();

        if ($realPath === null) {
            self::markTestSkipped('libfdb_c.so is not installed on this machine');
        }

        $client = (new \ReflectionClass(NativeClient::class))->newInstance($realPath);

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
     * environment first, then the common Linux install locations.
     */
    private function findRealLibraryPath(): ?string
    {
        $candidates = [
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
