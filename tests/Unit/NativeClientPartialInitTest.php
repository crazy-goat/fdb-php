<?php

declare(strict_types=1);

namespace CrazyGoat\FoundationDB\Tests\Unit;

use CrazyGoat\FoundationDB\FDBException;
use CrazyGoat\FoundationDB\NativeClient;
use FFI;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * Unit tests for NativeClient::ensureNetwork() partial-initialization
 * handling (issue #47).
 *
 * Previously, if fdb_setup_network() succeeded but a later step
 * (dlopen / dlsym / pthread_create) failed, $networkStarted stayed false
 * while the network was already set up: a later ensureNetwork() called
 * fdb_setup_network() a second time (error 2200: network already set up),
 * no shutdown handler was registered and fdb_stop_network() was never
 * called — a wedged, unrecoverable state.
 *
 * These tests exercise the real production code path WITHOUT the real
 * FoundationDB client library: a tiny stub C library is compiled on the fly
 * (cached in the temp dir) and bound through three separate FFI objects —
 * the same split NativeClient uses internally ($fdb, $pthread, $libdl).
 * The stub's counters (setup / stop / dlclose calls) and failure injection
 * flags make every partial-initialization branch deterministic.
 */
final class NativeClientPartialInitTest extends TestCase
{
    private const FDB_STUB_HEADER = <<<'C'
        typedef int fdb_error_t;
        fdb_error_t fdb_setup_network(void);
        fdb_error_t fdb_stop_network(void);
        const char* fdb_get_error(fdb_error_t code);
        int64_t fdb_phpunit_stub_setup_calls(void);
        int64_t fdb_phpunit_stub_stop_calls(void);
        int64_t fdb_phpunit_stub_dlclose_calls(void);
        void fdb_phpunit_stub_set_setup_result(int v);
        void fdb_phpunit_stub_set_pthread_create_result(int v);
        void fdb_phpunit_stub_set_dlopen_fail(int v);
        void fdb_phpunit_stub_set_dlsym_fail(int v);
        void fdb_phpunit_stub_reset_counters(int v);
        C;

    private const PTHREAD_STUB_HEADER = <<<'C'
        typedef unsigned long pthread_t;
        typedef void* (*thread_func)(void*);
        int pthread_create(pthread_t* thread, const void* attr, thread_func start_routine, void* arg);
        int pthread_join(pthread_t thread, void** retval);
        C;

    private const LIBDL_STUB_HEADER = <<<'C'
        void* dlopen(const char* filename, int flags);
        void* dlsym(void* handle, const char* symbol);
        int dlclose(void* handle);
        char* dlerror();
        C;

    private const STUB_SOURCE = <<<'C'
        #include <stdint.h>
        typedef int fdb_error_t;

        static int64_t g_setup_calls = 0;
        static int64_t g_stop_calls = 0;
        static int64_t g_dlclose_calls = 0;
        static int g_setup_result = 0;
        static int g_pthread_create_result = 1;
        static int g_dlopen_fail = 0;
        static int g_dlsym_fail = 0;
        static void* g_fake_handle = (void*) 0x1;

        fdb_error_t fdb_setup_network(void) { g_setup_calls += 1; return g_setup_result; }
        fdb_error_t fdb_stop_network(void) { g_stop_calls += 1; return 0; }
        const char* fdb_get_error(fdb_error_t code) { (void)code; return "stub error"; }

        int pthread_create(unsigned long* thread, const void* attr, void* (*start_routine)(void*), void* arg)
        {
            (void)thread; (void)attr; (void)start_routine; (void)arg;
            return g_pthread_create_result;
        }
        int pthread_join(unsigned long thread, void** retval)
        {
            (void)thread; (void)retval; return 0;
        }

        void* dlopen(const char* filename, int flags)
        {
            (void)filename; (void)flags;
            return g_dlopen_fail ? (void*) 0 : g_fake_handle;
        }
        void* dlsym(void* handle, const char* symbol)
        {
            (void)handle;
            if (g_dlsym_fail) { return (void*) 0; }
            return (void*) &fdb_stop_network;
        }
        int dlclose(void* handle) { (void)handle; g_dlclose_calls += 1; return 0; }
        char* dlerror(void) { return (char*) 0; }

        int64_t fdb_phpunit_stub_setup_calls(void) { return g_setup_calls; }
        int64_t fdb_phpunit_stub_stop_calls(void) { return g_stop_calls; }
        int64_t fdb_phpunit_stub_dlclose_calls(void) { return g_dlclose_calls; }
        void fdb_phpunit_stub_set_setup_result(int v) { g_setup_result = v; }
        void fdb_phpunit_stub_set_pthread_create_result(int v) { g_pthread_create_result = v; }
        void fdb_phpunit_stub_set_dlopen_fail(int v) { g_dlopen_fail = v; }
        void fdb_phpunit_stub_set_dlsym_fail(int v) { g_dlsym_fail = v; }
        void fdb_phpunit_stub_reset_counters(int v)
        {
            g_setup_calls = 0; g_stop_calls = 0; g_dlclose_calls = 0;
        }
        C;

    private static ?FFI $stub = null;

    private static ?string $libraryPath = null;

    private ?NativeClient $savedSingleton = null;

    public static function setUpBeforeClass(): void
    {
        self::$stub = self::buildStub();
    }

    protected function setUp(): void
    {
        // Deterministic baseline: setup succeeds, pthread_create fails,
        // dlopen/dlsym succeed, all counters zeroed.
        $this->stubSet('fdb_phpunit_stub_set_setup_result', 0);
        $this->stubSet('fdb_phpunit_stub_set_pthread_create_result', 1);
        $this->stubSet('fdb_phpunit_stub_set_dlopen_fail', 0);
        $this->stubSet('fdb_phpunit_stub_set_dlsym_fail', 0);
        $this->stubSet('fdb_phpunit_stub_reset_counters', 0);
    }

    // -- Tests --------------------------------------------------------------

    #[Test]
    public function pthreadCreateFailureLeavesConsistentNotStartedState(): void
    {
        $client = $this->makeClient();

        try {
            $client->ensureNetwork();
            self::fail('ensureNetwork() must throw when pthread_create fails');
        } catch (RuntimeException $e) {
            self::assertStringContainsString('pthread_create', $e->getMessage());
        }

        // fdb_setup_network() succeeded, so its documented cleanup
        // (fdb_stop_network()) must have run exactly once...
        self::assertSame(1, $this->counter('fdb_phpunit_stub_stop_calls'));

        // ...and the client must report a clean "not started / not set up"
        // state, with the dlopen'd handle released.
        self::assertFalse($client->isNetworkStarted());
        self::assertFalse($client->isNetworkSetup());
        self::assertSame(1, $this->counter('fdb_phpunit_stub_dlclose_calls'));
    }

    #[Test]
    public function retryAfterPartialFailureReRunsSetupCleanly(): void
    {
        $client = $this->makeClient();

        try {
            $client->ensureNetwork();
            self::fail('ensureNetwork() must throw when pthread_create fails');
        } catch (RuntimeException) {
            // First attempt rolled back: stopped + flagged down (see above).
        }

        self::assertSame(1, $this->counter('fdb_phpunit_stub_setup_calls'));
        self::assertSame(1, $this->counter('fdb_phpunit_stub_stop_calls'));

        // A retry must not be wedged: fdb_setup_network() runs again (the
        // rollback stopped the previous one) and fails identically, with
        // exactly one stop per attempt. This is the behaviour the old code
        // could not deliver: it re-called setup_network on an *already set
        // up* network and never stopped it.
        try {
            $client->ensureNetwork();
            self::fail('second ensureNetwork() must throw while pthread_create keeps failing');
        } catch (RuntimeException) {
            // Expected.
        }

        self::assertSame(2, $this->counter('fdb_phpunit_stub_setup_calls'));
        self::assertSame(2, $this->counter('fdb_phpunit_stub_stop_calls'));
        self::assertSame(2, $this->counter('fdb_phpunit_stub_dlclose_calls'));
    }

    #[Test]
    public function dlopenFailureStopsTheNetwork(): void
    {
        $this->stubSet('fdb_phpunit_stub_set_dlopen_fail', 1);
        $client = $this->makeClient();

        try {
            $client->ensureNetwork();
            self::fail('ensureNetwork() must throw when dlopen fails');
        } catch (RuntimeException $e) {
            self::assertStringContainsString('dlopen', $e->getMessage());
        }

        self::assertSame(1, $this->counter('fdb_phpunit_stub_setup_calls'));
        self::assertSame(1, $this->counter('fdb_phpunit_stub_stop_calls'));
        self::assertFalse($client->isNetworkSetup());
        // dlopen never succeeded, so there is nothing to dlclose.
        self::assertSame(0, $this->counter('fdb_phpunit_stub_dlclose_calls'));
    }

    #[Test]
    public function dlsymFailureClosesTheLibraryHandle(): void
    {
        $this->stubSet('fdb_phpunit_stub_set_dlsym_fail', 1);
        $client = $this->makeClient();

        try {
            $client->ensureNetwork();
            self::fail('ensureNetwork() must throw when dlsym fails');
        } catch (RuntimeException $e) {
            self::assertStringContainsString('dlsym', $e->getMessage());
        }

        self::assertSame(1, $this->counter('fdb_phpunit_stub_stop_calls'));
        self::assertSame(1, $this->counter('fdb_phpunit_stub_dlclose_calls'));
        self::assertFalse($client->isNetworkSetup());
    }

    #[Test]
    public function setupFailureDoesNotStopAnything(): void
    {
        $this->stubSet('fdb_phpunit_stub_set_setup_result', 10);
        // checkError() throws FDBException, whose constructor resolves the
        // error message through NativeClient::getInstance() — on CI the real
        // libfdb_c.so is not installed, so install the stub as the singleton.
        $client = $this->makeClient();
        $this->installSingleton($client);

        try {
            $client->ensureNetwork();
            self::fail('ensureNetwork() must surface the setup error');
        } catch (FDBException) {
            // Expected.
        }

        self::assertSame(1, $this->counter('fdb_phpunit_stub_setup_calls'));
        self::assertSame(0, $this->counter('fdb_phpunit_stub_stop_calls'));
        self::assertFalse($client->isNetworkSetup());
    }

    /**
     * Installs the stub-wired client as the NativeClient singleton and
     * restores the previous value on teardown.
     */
    private function installSingleton(NativeClient $client): void
    {
        $previous = new \ReflectionProperty(NativeClient::class, 'instance');
        /** @var ?NativeClient $value — property is declared ?NativeClient */
        $value = $previous->getValue();
        $this->savedSingleton = $value;

        \Closure::bind(
            static function (?NativeClient $instance): void {
                NativeClient::$instance = $instance;
            },
            null,
            NativeClient::class,
        )($client);
    }

    public function tearDown(): void
    {
        \Closure::bind(
            static function (?NativeClient $instance): void {
                NativeClient::$instance = $instance;
            },
            null,
            NativeClient::class,
        )($this->savedSingleton);
    }

    // -- Stub library -------------------------------------------------------

    /**
     * Compiles (once per machine) a stub replacing libfdb_c + libpthread +
     * libdl for network-lifecycle tests, exposing counting variants of
     * fdb_setup_network / fdb_stop_network, a failing pthread_create and a
     * fake dlopen/dlsym pair. Skips the test when no C compiler is available.
     */
    private static function buildStub(): FFI
    {
        if (!extension_loaded('ffi')) {
            self::markTestSkipped('ext-ffi is not available');
        }

        $cacheKey = md5(self::STUB_SOURCE . PHP_VERSION . PHP_OS_FAMILY);
        $libraryPath = sys_get_temp_dir() . '/fdb-php-phpunit-netstub-' . $cacheKey . '.so';
        $sourcePath = sys_get_temp_dir() . '/fdb-php-phpunit-netstub-' . $cacheKey . '.c';

        if (!is_file($libraryPath)) {
            file_put_contents($sourcePath, self::STUB_SOURCE);

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
                    'Cannot compile the network lifecycle stub (%s): %s',
                    $command,
                    implode("\n", $outputLines),
                ));
            }
        }

        self::$libraryPath = $libraryPath;

        try {
            // Bind the SAME stub under three FFI objects, mirroring the three
            // libraries NativeClient binds in production.
            return FFI::cdef(self::FDB_STUB_HEADER, $libraryPath);
        } catch (\Throwable $e) {
            self::markTestSkipped('Cannot load the network lifecycle stub: ' . $e->getMessage());
        }
    }

    private function stubSet(string $symbol, int $value): void
    {
        $stub = self::$stub;
        \assert($stub instanceof FFI);

        $stub->$symbol($value);
    }

    private function counter(string $symbol): int
    {
        $stub = self::$stub;
        \assert($stub instanceof FFI);

        return (int) $stub->$symbol();
    }

    // -- Object wiring ------------------------------------------------------

    /**
     * Builds a real NativeClient bypassing the constructor: the constructor
     * loads the genuine libfdb_c / libpthread / libdl, which is exactly what
     * the stub replaces. All FFI objects are initialized via the
     * declaring-class-scope Closure::bind trick (portable 8.2-8.4).
     */
    private function makeClient(): NativeClient
    {
        $libraryPath = self::$libraryPath;
        \assert(\is_string($libraryPath) && is_file($libraryPath));

        $client = (new \ReflectionClass(NativeClient::class))->newInstanceWithoutConstructor();
        $this->initializeReadOnly($client, 'fdb', FFI::cdef(self::FDB_STUB_HEADER, $libraryPath));
        $this->initializeReadOnly($client, 'pthread', FFI::cdef(self::PTHREAD_STUB_HEADER, $libraryPath));
        $this->initializeReadOnly($client, 'libdl', FFI::cdef(self::LIBDL_STUB_HEADER, $libraryPath));
        $this->initializeReadOnly($client, 'fdbLibraryPath', $libraryPath);

        return $client;
    }

    /**
     * Initializes a typed (possibly readonly) property without invoking the
     * constructor, portably across 8.2-8.4 (Closure::bind with the declaring
     * class scope; ReflectionProperty::setValue cannot initialize an
     * uninitialized readonly property since PHP 8.3).
     */
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
