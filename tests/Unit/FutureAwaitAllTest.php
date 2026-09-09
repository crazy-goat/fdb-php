<?php

declare(strict_types=1);

namespace CrazyGoat\FoundationDB\Tests\Unit;

use CrazyGoat\FoundationDB\Future\Future;
use CrazyGoat\FoundationDB\Future\FutureBool;
use CrazyGoat\FoundationDB\NativeClient;
use FFI;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for the group-await helpers added in issue #92:
 * Future::onReady() (a PHP-thread completion hook, deliberately NOT backed by
 * the native fdb_future_set_callback, whose callback fires on the FDB network
 * thread where PHP may not execute) and Future::awaitAll().
 *
 * Like FutureBoolTest, no FoundationDB cluster is needed: a tiny stub C
 * library is compiled on the fly and wired into constructor-less objects.
 */
final class FutureAwaitAllTest extends TestCase
{
    private static ?FFI $stub = null;

    public static function setUpBeforeClass(): void
    {
        self::$stub = self::buildStub();
    }

    protected function tearDown(): void
    {
        $this->setReady(1);
        $this->setError(0);
    }

    #[Test]
    public function awaitAllResolvesEveryFutureInInputOrder(): void
    {
        $this->setReady(1);

        $first = $this->makeFuture(1);
        $second = $this->makeFuture(0);

        self::assertSame([true, false], Future::awaitAll([$first, $second]));
    }

    #[Test]
    public function awaitAllAcceptsAnEmptyBatch(): void
    {
        self::assertSame([], Future::awaitAll([]));
    }

    #[Test]
    public function awaitAllRejectsNonFutureEntries(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        /** @phpstan-ignore argument.type (deliberately passing an invalid entry) */
        Future::awaitAll(['not-a-future']);
    }

    #[Test]
    public function onReadyRunsTheHookImmediatelyWhenAlreadyReady(): void
    {
        $this->setReady(1);
        $future = $this->makeFuture(1);

        $calls = [];
        $future->onReady(static function (FutureBool $f) use (&$calls): void {
            $calls[] = $f;
        });

        self::assertSame([$future], $calls, 'the hook must run synchronously for a ready future');
        self::assertFalse($future->isError());
    }

    #[Test]
    public function onReadyBlocksUntilReadyAndThenRunsTheHook(): void
    {
        // The stub flips g_ready back to 1 inside block_until_ready(), emulating
        // the network thread completing the future while PHP waits.
        $this->setReady(0);
        $future = $this->makeFuture(1);

        $calls = [];
        $future->onReady(static function (FutureBool $f) use (&$calls): void {
            $calls[] = $f;
        });

        self::assertSame([$future], $calls, 'the hook must run once the future becomes ready');
        self::assertTrue($future->isReady());
    }

    // -- Stub library ------------------------------------------------------

    private static function buildStub(): FFI
    {
        $source = <<<'C'
            #include <stdint.h>
            struct FDBFuture { unsigned char _opaque; int value; };
            static int g_ready = 1;
            static int g_is_error = 0;
            void fdb_future_destroy(void* f) { (void)f; }
            void fdb_future_release_memory(void* f) { (void)f; }
            void fdb_future_cancel(void* f) { (void)f; }
            int fdb_future_block_until_ready(void* f) { (void)f; g_ready = 1; return 0; }
            int fdb_future_is_ready(void* f) { (void)f; return g_ready; }
            int fdb_future_get_error(void* f) { (void)f; return g_is_error; }
            int fdb_future_get_bool(void* f, int* out) {
                (void)f; *out = ((struct FDBFuture*)f)->value; return g_is_error ? 1020 : 0;
            }
            void fdb_phpunit_stub_set_ready(int v) { g_ready = v; }
            void fdb_phpunit_stub_set_error(int v) { g_is_error = v; }
            C;

        $header = <<<'C'
            typedef struct FDB_future { unsigned char _opaque; int value; } FDBFuture;
            typedef int fdb_bool_t;
            void fdb_future_destroy(FDBFuture* f);
            void fdb_future_release_memory(FDBFuture* f);
            void fdb_future_cancel(FDBFuture* f);
            int fdb_future_block_until_ready(FDBFuture* f);
            int fdb_future_is_ready(FDBFuture* f);
            int fdb_future_get_error(FDBFuture* f);
            int fdb_future_get_bool(FDBFuture* f, int* out);
            void fdb_phpunit_stub_set_ready(int v);
            void fdb_phpunit_stub_set_error(int v);
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

    private function setReady(int $value): void
    {
        $stub = self::$stub;
        \assert($stub instanceof FFI);
        /** @phpstan-ignore-next-line method.notFound — dynamic FFI binding to the compiled stub */
        $stub->fdb_phpunit_stub_set_ready($value);
    }

    private function setError(int $value): void
    {
        $stub = self::$stub;
        \assert($stub instanceof FFI);
        /** @phpstan-ignore-next-line method.notFound — dynamic FFI binding to the compiled stub */
        $stub->fdb_phpunit_stub_set_error($value);
    }

    /** @var list<FFI\CData> Keeps stub struct allocations alive for as long as their futures exist. */
    private static array $keepAlive = [];

    private function makeFuture(int $boolValue): FutureBool
    {
        $stub = self::$stub;
        \assert($stub instanceof FFI);

        $nativeClient = (new \ReflectionClass(NativeClient::class))->newInstanceWithoutConstructor();
        $this->initializeReadOnly($nativeClient, 'fdb', $stub);

        // FFI::addr() does NOT keep the pointee alive — the struct must stay
        // referenced for as long as the future (and its destructor) uses it.
        $pointer = $stub->new('FDBFuture');
        /** @phpstan-ignore-next-line property.notFound — dynamically-defined stub struct field */
        $pointer->value = $boolValue;
        self::$keepAlive[] = $pointer;

        $future = (new \ReflectionClass(FutureBool::class))->newInstanceWithoutConstructor();
        $this->initializeReadOnly($future, 'fpointer', \FFI::addr($pointer));
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
