<?php

declare(strict_types=1);

namespace CrazyGoat\FoundationDB\Tests\Unit;

use CrazyGoat\FoundationDB\Database;
use CrazyGoat\FoundationDB\NativeClient;
use CrazyGoat\FoundationDB\Transaction;
use FFI;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for the Transaction <-> Snapshot lifetime contract (issue #38).
 *
 * `Transaction::snapshot()` previously cached the Snapshot on the Transaction
 * while the Snapshot held a strong back-reference to its parent, forming a
 * reference cycle (Transaction -> snapshotInstance -> parentTransaction).
 * Because both sides held strong references, the refcount never reached zero
 * on scope exit, so `Transaction::__destruct()` (`fdb_transaction_destroy()`)
 * did not run deterministically -- it was deferred to the cycle collector,
 * and with `zend.enable_gc=0` until process shutdown. A long-running worker
 * calling `readTransact()` or any directory operation accumulated undestroyed
 * native transaction handles.
 *
 * These tests verify the fix deterministically WITHOUT a FoundationDB cluster
 * or the real client library: a tiny stub C library is compiled on the fly
 * (cached in the temp dir) and exposes a counting `fdb_transaction_destroy()`.
 * Real `Transaction` / `Database` / `NativeClient` objects are instantiated
 * without running their constructors and wired to the stub via reflection, so
 * the actual production destructor path is exercised end-to-end.
 */
final class TransactionSnapshotLifecycleTest extends TestCase
{
    private static ?FFI $stub = null;

    public static function setUpBeforeClass(): void
    {
        self::$stub = self::buildStub();
    }

    // -- Tests -------------------------------------------------------------

    #[Test]
    public function snapshotReturnsFreshInstancePerCall(): void
    {
        $tx = self::makeTransaction();

        $first = $tx->snapshot();
        $second = $tx->snapshot();

        // Fresh object every call: caching the Snapshot is what created the cycle.
        self::assertNotSame($first, $second);

        // Both share the parent's native handle, so reads stay consistent...
        self::assertSame($tx->getPointer(), $first->getPointer());
        self::assertSame($tx->getPointer(), $second->getPointer());

        // ...and each Snapshot still anchors its parent transaction.
        $parent = $this->parentOf($first);
        self::assertSame($tx, $parent);
    }

    #[Test]
    public function transactionHandleIsDestroyedOnScopeExitWithoutCycleCollector(): void
    {
        $before = $this->destroyCalls();

        $scope = static function (): void {
            $tx = self::makeTransaction();
            // Snapshot anchors the Transaction one-directionally; the returned
            // object is intentionally discarded here (that is the point of
            // this scope-exit test), so the "no effect" result is expected.
            /** @phpstan-ignore-next-line method.resultUnused */
            $tx->snapshot();
            // Both objects go out of scope here.
        };

        // With the old cyclic cache the destructor could not run here: the
        // graph was only collectable by the cycle collector, so the counter
        // would stay untouched and the Error-free return would fail the assert.
        $scope();

        self::assertSame(
            $before + 1,
            $this->destroyCalls(),
            'fdb_transaction_destroy() must run exactly once when the '
            . 'transaction + snapshot graph leaves scope with gc disabled',
        );
    }

    #[Test]
    public function repeatedSnapshotLoopsDoNotAccumulateNativeHandles(): void
    {
        $before = $this->destroyCalls();
        $iterations = 25;

        for ($i = 0; $i < $iterations; $i++) {
            // Mirrors Database::readTransact() / HighContentionAllocator::
            // allocate(): a short-lived transaction whose work goes through
            // ->snapshot(). Each iteration is its own scope so both objects
            // are released before the next one starts. The returned Snapshot
            // is intentionally discarded (that is the point of this test).
            /** @phpstan-ignore-next-line method.resultUnused */
            self::makeTransaction()->snapshot();
        }

        self::assertSame(
            $before + $iterations,
            $this->destroyCalls(),
            'every loop iteration must release its native transaction handle',
        );
    }

    // -- Stub library ------------------------------------------------------

    /**
     * Compiles (once per machine) a minimal stand-in for libfdb_c.so exposing
     * a counting fdb_transaction_destroy(), and binds it through FFI. Skips
     * the test when no C compiler is available rather than failing CI images
     * that legitimately ship without one.
     */
    private static function buildStub(): FFI
    {
        $source = <<<'C'
            #include <stdint.h>
            static int64_t g_destroy_calls = 0;
            void fdb_transaction_destroy(void* tr) { (void)tr; g_destroy_calls += 1; }
            int64_t fdb_phpunit_stub_destroy_calls(void) { return g_destroy_calls; }
            void fdb_database_destroy(void* d) { (void)d; }
            C;

        $header = <<<'C'
            typedef struct FDB_transaction { unsigned char _opaque; } FDB_transaction;
            void fdb_transaction_destroy(FDB_transaction* tr);
            int64_t fdb_phpunit_stub_destroy_calls(void);
            void fdb_database_destroy(FDB_transaction* d);
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
                    'Cannot compile the FDB transaction stub (%s): %s',
                    $command,
                    implode("\n", $outputLines),
                ));
            }
        }

        try {
            return FFI::cdef($header, $libraryPath);
        } catch (\Throwable $e) {
            self::markTestSkipped('Cannot load the FDB transaction stub: ' . $e->getMessage());
        }
    }

    private function destroyCalls(): int
    {
        $stub = self::$stub;
        \assert($stub instanceof FFI);

        /** @phpstan-ignore-next-line method.notFound — dynamic FFI binding to the compiled stub */
        return $stub->fdb_phpunit_stub_destroy_calls();
    }

    // -- Object wiring -----------------------------------------------------

    /**
     * Builds a real Transaction wired to the stub, bypassing constructors:
     * NativeClient::__construct() loads the genuine libfdb_c and Database's
     * constructor expects a native database handle. Only three read-only
     * properties on ReadTransaction plus the two aggregate objects matter
     * for the lifetime path under test.
     */
    private static function makeTransaction(): Transaction
    {
        $stub = self::$stub;
        \assert($stub instanceof FFI);

        $nativeClient = (new \ReflectionClass(NativeClient::class))->newInstanceWithoutConstructor();
        self::initializeReadOnly($nativeClient, 'fdb', $stub);

        $database = (new \ReflectionClass(Database::class))->newInstanceWithoutConstructor();
        self::initializeReadOnly($database, 'dpointer', $stub->new('FDB_transaction*'));
        self::initializeReadOnly($database, 'client', $nativeClient);

        $transaction = (new \ReflectionClass(Transaction::class))->newInstanceWithoutConstructor();
        // Production wires a pointer-typed handle (from
        // fdb_database_create_transaction's out-parameter), so mirror that:
        // FFI validates the CData kind when __destruct() calls into the stub.
        self::initializeReadOnly($transaction, 'tpointer', $stub->new('FDB_transaction*'));
        self::initializeReadOnly($transaction, 'db', $database);
        self::initializeReadOnly($transaction, 'client', $nativeClient);

        return $transaction;
    }

    /**
     * Initializes a typed (possibly readonly) property without invoking the
     * constructor, portably across 8.2-8.4.
     *
     * There is no reflection-free way to initialise a *private* readonly
     * property from outside its declaring class. We use a closure bound to the
     * DECLARING class of the property (not the instantiated object): PHP
     * allows initialising an uninitialized readonly property from the scope
     * that declared it, which works regardless of the runtime PHP version.
     */
    private static function initializeReadOnly(object $object, string $property, mixed $value): void
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

    private function parentOf(object $snapshot): Transaction
    {
        $reflection = new \ReflectionProperty($snapshot, 'parentTransaction');
        $parent = $reflection->getValue($snapshot);
        \assert($parent instanceof Transaction);

        return $parent;
    }
}
