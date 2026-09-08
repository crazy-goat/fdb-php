<?php

declare(strict_types=1);

namespace CrazyGoat\FoundationDB\Tests\Unit;

use CrazyGoat\FoundationDB\Future\FutureDouble;
use CrazyGoat\FoundationDB\NativeClient;
use FFI;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for the `fdb_future_get_double` accessor bound in issue #90
 * (via `FutureDouble::await()`), which backs
 * `Transaction::getTagThrottledDuration()`.
 *
 * As with FutureBoolTest, no FoundationDB cluster or real client library is
 * needed: a tiny stub C library is compiled on the fly and wired into a
 * constructor-less NativeClient via reflection, exercising the real
 * production Future paths end-to-end.
 */
final class FutureDoubleTest extends TestCase
{
    private static ?FFI $stub = null;

    public static function setUpBeforeClass(): void
    {
        self::$stub = self::buildStub();
    }

    protected function tearDown(): void
    {
        $stub = self::$stub;
        \assert($stub instanceof FFI);
        /** @phpstan-ignore-next-line method.notFound — dynamic FFI binding to the compiled stub */
        $stub->fdb_phpunit_stub_set_error(0);
    }

    #[Test]
    public function awaitReturnsTheDoubleResolvedByFdbFutureGetDouble(): void
    {
        $this->setStubDouble(0.0);
        self::assertSame(0.0, $this->makeFuture()->await());

        $this->setStubDouble(12.345);
        self::assertSame(12.345, $this->makeFuture()->await());
    }

    #[Test]
    public function awaitCachesTheResultAcrossCalls(): void
    {
        $this->setStubDouble(7.5);
        $future = $this->makeFuture();

        self::assertSame(7.5, $future->await());

        // Even if the stub value changed underneath, the cached result wins.
        $this->setStubDouble(1.25);
        self::assertSame(7.5, $future->await());
    }

    #[Test]
    public function isErrorReportsFalseForASuccessfulFuture(): void
    {
        $this->setStubDouble(0.0);
        self::assertFalse($this->makeFuture()->isError());
    }

    #[Test]
    public function isErrorIsCachedAndSurvivesMemoryRelease(): void
    {
        $this->setStubDouble(0.0);
        $future = $this->makeFuture();

        self::assertSame(0.0, $future->await());

        // After await() the future's memory is released; the stub now returns
        // garbage from fdb_future_get_error(). isError() must answer from the
        // state captured before the release, not re-query the handle.
        self::assertFalse($future->isError());
    }

    #[Test]
    public function isErrorReportsTrueForAFutureInErrorState(): void
    {
        $this->setStubError(1);
        self::assertTrue($this->makeFuture()->isError());
    }

    // -- Stub library ------------------------------------------------------

    private static function buildStub(): FFI
    {
        $source = <<<'C'
            static int g_is_error = 0;
            static double g_double_value = 0.0;
            static int g_released = 0;
            void fdb_future_destroy(void* f) { (void)f; }
            void fdb_future_release_memory(void* f) { (void)f; g_released = 1; }
            void fdb_future_cancel(void* f) { (void)f; }
            int fdb_future_block_until_ready(void* f) { (void)f; return 0; }
            int fdb_future_is_ready(void* f) { (void)f; return 1; }
            // Mimics the real client: querying the error code after the
            // future's memory has been released is undefined behavior — the
            // stub returns garbage (non-zero) to catch that path.
            int fdb_future_get_error(void* f) { (void)f; return (g_is_error || g_released) ? 1020 : 0; }
            int fdb_future_get_double(void* f, double* out)
            {
                (void)f;
                *out = g_double_value;
                return g_is_error ? 1020 : 0;
            }
            void fdb_phpunit_stub_set_error(int v) { g_is_error = v; g_released = 0; }
            void fdb_phpunit_stub_set_double(double v) { g_double_value = v; g_released = 0; }
            C;

        $header = <<<'C'
            typedef struct FDB_future { unsigned char _opaque; } FDBFuture;
            void fdb_future_destroy(FDBFuture* f);
            void fdb_future_release_memory(FDBFuture* f);
            void fdb_future_cancel(FDBFuture* f);
            int fdb_future_block_until_ready(FDBFuture* f);
            int fdb_future_is_ready(FDBFuture* f);
            int fdb_future_get_error(FDBFuture* f);
            int fdb_future_get_double(FDBFuture* f, double* out);
            void fdb_phpunit_stub_set_error(int v);
            void fdb_phpunit_stub_set_double(double v);
            C;

        if (!extension_loaded('ffi')) {
            self::markTestSkipped('ext-ffi is not available');
        }

        $cacheKey = md5($source . $header . PHP_VERSION . PHP_OS_FAMILY);
        $libraryPath = sys_get_temp_dir() . '/fdb-php-phpunit-stub-' . $cacheKey . '.so';
        $sourcePath = sys_get_temp_dir() . '/fdb-php-phpunit-stub-' . $cacheKey . '.c';

        if (!is_file($libraryPath)) {
            file_put_contents($sourcePath, $source);

            $flags = PHP_OS_FAMILY === 'Darwin' ? '-dynamiclib' : '-shared';
            $command = sprintf(
                'cc %s -fPIC -o %s %s 2>&1',
                $flags,
                escapeshellarg($libraryPath),
                escapeshellarg($sourcePath),
            );
            exec($command, $outputLines, $exitCode);

            if ($exitCode !== 0) {
                self::markTestSkipped(sprintf(
                    'Cannot compile the FDB future stub (%s): %s',
                    $command,
                    implode("\n", $outputLines),
                ));
            }
        }

        try {
            return FFI::cdef($header, $libraryPath);
        } catch (\Throwable $e) {
            self::markTestSkipped('Cannot load the FDB future stub: ' . $e->getMessage());
        }
    }

    private function setStubDouble(float $value): void
    {
        $stub = self::$stub;
        \assert($stub instanceof FFI);
        /** @phpstan-ignore-next-line method.notFound — dynamic FFI binding to the compiled stub */
        $stub->fdb_phpunit_stub_set_double($value);
    }

    private function setStubError(int $value): void
    {
        $stub = self::$stub;
        \assert($stub instanceof FFI);
        /** @phpstan-ignore-next-line method.notFound — dynamic FFI binding to the compiled stub */
        $stub->fdb_phpunit_stub_set_error($value);
    }

    private function makeFuture(): FutureDouble
    {
        $stub = self::$stub;
        \assert($stub instanceof FFI);

        $nativeClient = (new \ReflectionClass(NativeClient::class))->newInstanceWithoutConstructor();
        $this->initializeReadOnly($nativeClient, 'fdb', $stub);

        $future = (new \ReflectionClass(FutureDouble::class))->newInstanceWithoutConstructor();
        $this->initializeReadOnly($future, 'fpointer', $stub->new('FDBFuture*'));
        $this->initializeReadOnly($future, 'client', $nativeClient);

        return $future;
    }

    private function initializeReadOnly(object $object, string $property, mixed $value): void
    {
        $declaringClass = (new \ReflectionProperty($object, $property))->getDeclaringClass()->getName();

        \Closure::bind(
            static function (object $target, string $name, mixed $val): void {
                $target->$name = $val;
            },
            null,
            $declaringClass,
        )($object, $property, $value);
    }
}
