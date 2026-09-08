<?php

declare(strict_types=1);

namespace CrazyGoat\FoundationDB\Tests\Unit;

use CrazyGoat\FoundationDB\Future\FutureBool;
use CrazyGoat\FoundationDB\NativeClient;
use FFI;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for the future accessors bound in issue #98:
 * `fdb_future_is_error` (via `Future::isError()`) and
 * `fdb_future_get_bool` (via `FutureBool::await()`).
 *
 * As with TransactionSnapshotLifecycleTest, no FoundationDB cluster or real
 * client library is needed: a tiny stub C library is compiled on the fly and
 * wired into a constructor-less NativeClient via reflection, exercising the
 * real production Future paths end-to-end.
 */
final class FutureBoolTest extends TestCase
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
    public function awaitReturnsTheBoolResolvedByFdbFutureGetBool(): void
    {
        $this->setStubBool(1);
        self::assertSame(true, $this->makeFuture()->await());

        $this->setStubBool(0);
        self::assertSame(false, $this->makeFuture()->await());
    }

    #[Test]
    public function awaitCachesTheResultAcrossCalls(): void
    {
        $this->setStubBool(1);
        $future = $this->makeFuture();

        self::assertSame(true, $future->await());

        // Even if the stub value changed underneath, the cached result wins.
        $this->setStubBool(0);
        self::assertSame(true, $future->await());
    }

    #[Test]
    public function isErrorReportsFalseForASuccessfulFuture(): void
    {
        $this->setStubBool(1);
        self::assertFalse($this->makeFuture()->isError());
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
            #include <stdint.h>
            static int g_is_error = 0;
            static int g_bool_value = 1;
            void fdb_future_destroy(void* f) { (void)f; }
            void fdb_future_release_memory(void* f) { (void)f; }
            void fdb_future_cancel(void* f) { (void)f; }
            int fdb_future_block_until_ready(void* f) { (void)f; return 0; }
            int fdb_future_is_ready(void* f) { (void)f; return 1; }
            int fdb_future_get_error(void* f) { (void)f; return g_is_error ? 1020 : 0; }
            int fdb_future_is_error(void* f) { (void)f; return g_is_error; }
            int fdb_future_get_bool(void* f, int* out) { (void)f; *out = g_bool_value; return g_is_error ? 1020 : 0; }
            void fdb_phpunit_stub_set_error(int v) { g_is_error = v; }
            void fdb_phpunit_stub_set_bool(int v) { g_bool_value = v; }
            C;

        $header = <<<'C'
            typedef struct FDB_future { unsigned char _opaque; } FDBFuture;
            typedef int fdb_bool_t;
            void fdb_future_destroy(FDBFuture* f);
            void fdb_future_release_memory(FDBFuture* f);
            void fdb_future_cancel(FDBFuture* f);
            int fdb_future_block_until_ready(FDBFuture* f);
            int fdb_future_is_ready(FDBFuture* f);
            int fdb_future_get_error(FDBFuture* f);
            int fdb_future_is_error(FDBFuture* f);
            int fdb_future_get_bool(FDBFuture* f, int* out);
            void fdb_phpunit_stub_set_error(int v);
            void fdb_phpunit_stub_set_bool(int v);
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

    private function setStubBool(int $value): void
    {
        $stub = self::$stub;
        \assert($stub instanceof FFI);
        /** @phpstan-ignore-next-line method.notFound — dynamic FFI binding to the compiled stub */
        $stub->fdb_phpunit_stub_set_bool($value);
    }

    private function setStubError(int $value): void
    {
        $stub = self::$stub;
        \assert($stub instanceof FFI);
        /** @phpstan-ignore-next-line method.notFound — dynamic FFI binding to the compiled stub */
        $stub->fdb_phpunit_stub_set_error($value);
    }

    private function makeFuture(): FutureBool
    {
        $stub = self::$stub;
        \assert($stub instanceof FFI);

        $nativeClient = (new \ReflectionClass(NativeClient::class))->newInstanceWithoutConstructor();
        $this->initializeReadOnly($nativeClient, 'fdb', $stub);

        $future = (new \ReflectionClass(FutureBool::class))->newInstanceWithoutConstructor();
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
