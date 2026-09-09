<?php

declare(strict_types=1);

namespace CrazyGoat\FoundationDB\Tests\Unit;

use CrazyGoat\FoundationDB\Future\Future;
use CrazyGoat\FoundationDB\Future\FutureUInt64;
use CrazyGoat\FoundationDB\NativeClient;
use FFI;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for Future::awaitAll() and Future::onReady() (issue #92).
 *
 * As with the other Future tests, no FoundationDB cluster or real client
 * library is needed: a tiny stub C library is compiled on the fly and wired
 * into constructor-less NativeClient/Future objects via reflection,
 * exercising the real production paths end-to-end.
 *
 * The stub lets each future become ready only after N polls of
 * fdb_future_is_ready(), and records how many polls had happened globally
 * when each future was first awaited. That allows asserting that awaitAll()
 * really waits for the slowest future BEFORE resolving any of them, instead
 * of serializing the waits.
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
        $stub = self::$stub;
        \assert($stub instanceof FFI);
        /** @phpstan-ignore-next-line method.notFound — dynamic FFI binding to the compiled stub */
        $stub->fdb_phpunit_stub_reset();
    }

    #[Test]
    public function awaitAllResolvesEveryFutureInTheInputOrder(): void
    {
        $a = $this->makeFuture(0);
        $this->setStubValue($a, 111);
        $b = $this->makeFuture(2);
        $this->setStubValue($b, 222);
        $c = $this->makeFuture(5);
        $this->setStubValue($c, 333);

        self::assertSame(
            ['a' => 111, 'b' => 222, 'c' => 333],
            FutureUInt64::awaitAll(['a' => $a, 'b' => $b, 'c' => $c]),
        );
    }

    #[Test]
    public function awaitAllWaitsForTheSlowestFutureBeforeResolvingAnyOfThem(): void
    {
        $fast = $this->makeFuture(0);
        $slow = $this->makeFuture(5);

        FutureUInt64::awaitAll([$fast, $slow]);

        // Both futures must have been awaited only after the global poll
        // counter had passed the slow future's threshold — proving the wait
        // was overlapped (one grouped wait), not serialized (N waits).
        $fastAwaitedAt = $this->pollsAtFirstAwait($fast);
        self::assertSame($fastAwaitedAt, $this->pollsAtFirstAwait($slow));
        self::assertGreaterThanOrEqual(5, $fastAwaitedAt);
    }

    #[Test]
    public function awaitAllSkipsAlreadyResolvedFutures(): void
    {
        $resolved = $this->makeFuture(0);
        $this->setStubValue($resolved, 7);
        self::assertSame(7, $resolved->await());

        $pending = $this->makeFuture(2);
        $this->setStubValue($pending, 8);

        self::assertSame([7, 8], FutureUInt64::awaitAll([$resolved, $pending]));
    }

    #[Test]
    public function awaitAllAcceptsAPlainList(): void
    {
        $futures = [];
        foreach ([3, 1, 2] as $i => $threshold) {
            $futures[$i] = $this->makeFuture($threshold);
            $this->setStubValue($futures[$i], $i * 10);
        }

        self::assertSame([0, 10, 20], FutureUInt64::awaitAll($futures));
    }

    #[Test]
    public function onReadyHookFiresOnceTheFutureIsAwaited(): void
    {
        $future = $this->makeFuture(1);
        $this->setStubValue($future, 42);

        $received = [];
        $future->onReady(static function (FutureUInt64 $f) use (&$received): void {
            $received[] = $f;
        });

        // Not fired yet — the future is still pending and nothing resolved it.
        self::assertSame([], $received);

        self::assertSame(42, $future->await());
        self::assertSame([$future], $received);
    }

    #[Test]
    public function onReadyHooksFireOnlyOnce(): void
    {
        $future = $this->makeFuture(0);
        $this->setStubValue($future, 1);

        $calls = 0;
        $future->onReady(static function () use (&$calls): void {
            $calls++;
        });

        $future->await();
        $future->await();

        self::assertSame(1, $calls);
    }

    #[Test]
    public function onReadyHookRegisteredAfterResolutionFiresImmediately(): void
    {
        $future = $this->makeFuture(0);
        $this->setStubValue($future, 5);
        self::assertSame(5, $future->await());

        $received = [];
        $future->onReady(static function (FutureUInt64 $f) use (&$received): void {
            $received[] = $f;
        });

        self::assertSame([$future], $received);
    }

    #[Test]
    public function awaitAllTriggersOnReadyHooks(): void
    {
        $future = $this->makeFuture(2);
        $this->setStubValue($future, 9);

        $calls = 0;
        $future->onReady(static function () use (&$calls): void {
            $calls++;
        });

        self::assertSame([9], FutureUInt64::awaitAll([$future]));
        self::assertSame(1, $calls);
    }

    // -- Stub library ------------------------------------------------------

    private static function buildStub(): FFI
    {
        $source = <<<'C'
            #define MAX_FUTURES 16
            static void* g_ptrs[MAX_FUTURES];
            static int g_ready_after[MAX_FUTURES];
            static int g_polls[MAX_FUTURES];
            static int g_polls_at_first_await[MAX_FUTURES];
            static long long g_total_polls = 0;
            static int g_is_error = 0;
            static unsigned long long g_uint64_value[MAX_FUTURES];
            static int g_released[MAX_FUTURES];

            static int slot_for(void* f)
            {
                for (int i = 0; i < MAX_FUTURES; i++) {
                    if (g_ptrs[i] == f) {
                        return i;
                    }
                }
                for (int i = 0; i < MAX_FUTURES; i++) {
                    if (g_ptrs[i] == 0) {
                        g_ptrs[i] = f;
                        return i;
                    }
                }
                return 0;
            }

            void fdb_future_destroy(void* f) { (void)f; }
            void fdb_future_release_memory(void* f) { (void)f; g_released[slot_for(f)] = 1; }
            void fdb_future_cancel(void* f) { (void)f; }
            int fdb_future_block_until_ready(void* f)
            {
                int slot = slot_for(f);
                if (g_polls_at_first_await[slot] < 0) {
                    g_polls_at_first_await[slot] = g_total_polls;
                }
                return 0;
            }
            int fdb_future_is_ready(void* f)
            {
                int slot = slot_for(f);
                g_total_polls++;
                return g_polls[slot] >= g_ready_after[slot] ? 1 : (g_polls[slot]++, 0);
            }
            int fdb_future_get_error(void* f) { (void)f; return (g_is_error || g_released[slot_for(f)]) ? 1020 : 0; }
            int fdb_future_get_uint64(void* f, unsigned long long* out)
            {
                *out = g_uint64_value[slot_for(f)];
                return g_is_error ? 1020 : 0;
            }
            void fdb_phpunit_stub_reset(void)
            {
                for (int i = 0; i < MAX_FUTURES; i++) {
                    g_ptrs[i] = 0;
                    g_ready_after[i] = 0;
                    g_polls[i] = 0;
                    g_polls_at_first_await[i] = -1;
                    g_released[i] = 0;
                }
                g_total_polls = 0;
                g_is_error = 0;
                for (int i = 0; i < MAX_FUTURES; i++) {
                    g_uint64_value[i] = 0;
                }
            }
            void fdb_phpunit_stub_set_ready_after(void* f, int n)
            {
                int slot = slot_for(f);
                g_ready_after[slot] = n;
                g_polls[slot] = 0;
                g_polls_at_first_await[slot] = -1;
            }
            long long fdb_phpunit_stub_get_polls_at_first_await(void* f)
            {
                return g_polls_at_first_await[slot_for(f)];
            }
            void fdb_phpunit_stub_set_uint64(void* f, unsigned long long v) { g_uint64_value[slot_for(f)] = v; }
            C;

        $header = <<<'C'
            typedef struct FDB_future { unsigned char _opaque; } FDBFuture;
            void fdb_future_destroy(FDBFuture* f);
            void fdb_future_release_memory(FDBFuture* f);
            void fdb_future_cancel(FDBFuture* f);
            int fdb_future_block_until_ready(FDBFuture* f);
            int fdb_future_is_ready(FDBFuture* f);
            int fdb_future_get_error(FDBFuture* f);
            int fdb_future_get_uint64(FDBFuture* f, unsigned long long* out);
            void fdb_phpunit_stub_reset(void);
            void fdb_phpunit_stub_set_ready_after(FDBFuture* f, int n);
            long long fdb_phpunit_stub_get_polls_at_first_await(FDBFuture* f);
            void fdb_phpunit_stub_set_uint64(FDBFuture* f, unsigned long long v);
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

    private function setStubValue(FutureUInt64 $future, int $value): void
    {
        $stub = self::$stub;
        \assert($stub instanceof FFI);
        /** @phpstan-ignore-next-line method.notFound — dynamic FFI binding to the compiled stub */
        $stub->fdb_phpunit_stub_set_uint64($this->pointerOf($future), $value);
    }

    private function pollsAtFirstAwait(FutureUInt64 $future): int
    {
        $stub = self::$stub;
        \assert($stub instanceof FFI);
        /** @phpstan-ignore-next-line method.notFound — dynamic FFI binding to the compiled stub */
        return $stub->fdb_phpunit_stub_get_polls_at_first_await($this->pointerOf($future));
    }

    private function pointerOf(FutureUInt64 $future): FFI\CData
    {
        $property = new \ReflectionProperty(Future::class, 'fpointer');
        $pointer = $property->getValue($future);
        \assert($pointer instanceof \FFI\CData);

        return $pointer;
    }

    private function makeFuture(int $readyAfter): FutureUInt64
    {
        $stub = self::$stub;
        \assert($stub instanceof FFI);

        $nativeClient = (new \ReflectionClass(NativeClient::class))->newInstanceWithoutConstructor();
        $this->initializeReadOnly($nativeClient, 'fdb', $stub);

        $pointer = FFI::addr($stub->new('FDBFuture'));
        /** @phpstan-ignore-next-line method.notFound — dynamic FFI binding to the compiled stub */
        $stub->fdb_phpunit_stub_set_ready_after($pointer, $readyAfter);

        $future = (new \ReflectionClass(FutureUInt64::class))->newInstanceWithoutConstructor();
        $this->initializeReadOnly($future, 'fpointer', $pointer);
        $this->initializeReadOnly($future, 'client', $nativeClient);

        return $future;
    }

    private function initializeReadOnly(object $object, string $property, mixed $value): void
    {
        $declaringClass = (new \ReflectionProperty($object, $property))->getDeclaringClass()->getName();
        $property = new \ReflectionProperty($declaringClass, $property);
        $property->setValue($object, $value);
    }
}
