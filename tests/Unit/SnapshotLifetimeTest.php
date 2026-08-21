<?php

declare(strict_types=1);

namespace CrazyGoat\FoundationDB\Tests\Unit;

use CrazyGoat\FoundationDB\Database;
use CrazyGoat\FoundationDB\NativeClient;
use CrazyGoat\FoundationDB\Transaction;
use FFI;
use PHPUnit\Framework\Attributes\RequiresPhpExtension;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for the Transaction <-> Snapshot lifetime contract (#38).
 *
 * Transaction::snapshot() must return a fresh Snapshot on every call. The
 * Snapshot holds a strong reference back to its parent Transaction to keep
 * the shared native handle alive; caching the Snapshot on the Transaction
 * used to close a reference cycle (Transaction -> Snapshot -> Transaction)
 * that deferred fdb_transaction_destroy() to the cycle collector — or to
 * process shutdown when zend.enable_gc is disabled.
 *
 * These tests run without the native client library: the objects under test
 * are built via reflection (newInstanceWithoutConstructor) with a fake
 * transaction pointer from a types-only FFI scope. When the parent
 * Transaction is finally freed, its destructor touches the uninitialized
 * NativeClient::$fdb property; the resulting \Error is expected and is used
 * as the observable proof that the destructor ran.
 */
#[RequiresPhpExtension('ffi')]
final class SnapshotLifetimeTest extends TestCase
{
    private ?FFI $scope = null;

    // -- snapshot() identity ----------------------------------------------------

    #[Test]
    public function snapshotReturnsFreshInstancePerCall(): void
    {
        $transaction = $this->createTransaction();
        $first = $transaction->snapshot();
        $second = $transaction->snapshot();

        self::assertNotSame(
            $first,
            $second,
            'snapshot() must not cache the Snapshot on the Transaction — caching closes a reference cycle',
        );

        $this->release($first, $second, $transaction);
    }

    // -- lifetime / cycle -------------------------------------------------------

    #[Test]
    public function snapshotAnchorsParentTransactionUntilReleased(): void
    {
        $transaction = $this->createTransaction();
        $weakTransaction = \WeakReference::create($transaction);
        $snapshot = $transaction->snapshot();

        // Dropping the transaction variable must NOT free it while the
        // snapshot holds its anchor reference.
        $this->release($transaction);

        self::assertNotNull(
            $weakTransaction->get(),
            'Snapshot must keep the parent Transaction (and its native handle) alive',
        );

        // Releasing the snapshot drops the last reference; the parent must be
        // freed deterministically WITHOUT running the cycle collector.
        $destructorRan = $this->release($snapshot);

        self::assertTrue($destructorRan, 'Expected Transaction::__destruct() to run on release');
        self::assertNull(
            $weakTransaction->get(),
            'Transaction must be freed by refcounting alone, without gc_collect_cycles()',
        );
    }

    #[Test]
    public function droppingTransactionAndSnapshotDoesNotRequireCycleCollector(): void
    {
        $transaction = $this->createTransaction();
        $weakTransaction = \WeakReference::create($transaction);
        $snapshot = $transaction->snapshot();
        $weakSnapshot = \WeakReference::create($snapshot);

        $destructorRan = $this->release($transaction, $snapshot);

        self::assertTrue($destructorRan, 'Expected Transaction::__destruct() to run on release');
        self::assertNull($weakTransaction->get(), 'Transaction leaked — reference cycle is back');
        self::assertNull($weakSnapshot->get(), 'Snapshot leaked — reference cycle is back');
    }

    // -- helpers ----------------------------------------------------------------

    /**
     * Build a Transaction without invoking its constructor (no native calls).
     */
    private function createTransaction(): Transaction
    {
        $reflection = new \ReflectionClass(Transaction::class);
        /** @var Transaction $transaction */
        $transaction = $reflection->newInstanceWithoutConstructor();

        $this->setProperty($transaction, 'tpointer', $this->scope()->new('FDBTransaction*'));
        $this->setProperty($transaction, 'db', $this->bareInstance(Database::class));
        $this->setProperty($transaction, 'client', $this->bareInstance(NativeClient::class));
        $this->setProperty($transaction, 'isSnapshot', false);

        return $transaction;
    }

    /**
     * Types-only FFI scope: opaque struct pointer without any native library.
     */
    private function scope(): FFI
    {
        return $this->scope ??= FFI::cdef(
            'typedef struct FDB_transaction FDBTransaction;',
        );
    }

    /**
     * Instance without constructor for collaborators whose state is unused.
     *
     * @template T of object
     *
     * @param class-string<T> $class
     *
     * @return T
     */
    private function bareInstance(string $class): object
    {
        /** @var T */
        return (new \ReflectionClass($class))->newInstanceWithoutConstructor();
    }

    private function setProperty(object $object, string $name, mixed $value): void
    {
        $property = new \ReflectionProperty($object, $name);
        $property->setValue($object, $value);
    }

    /**
     * Drop strong references and report whether a destructor threw.
     *
     * Releasing the last reference to the parent Transaction runs
     * Transaction::__destruct(), which dereferences the uninitialized fake
     * NativeClient::$fdb and throws \Error. That throw is the observable
     * "destructor ran" signal; it is caught here so tests stay clean.
     *
     * Writing through the by-ref variadic slots is invisible to PHPStan's
     * by-ref analysis, hence the targeted ignores.
     *
     * @param ?object ...$references Variables to release (by reference)
     */
    // @phpstan-ignore-next-line parameterByRef.unusedType, parameterByRef.unusedType
    private function release(?object &...$references): bool
    {
        $destructorRan = false;

        try {
            // Iterate keys only: a foreach value copy would keep the last
            // object alive until after this function returns, moving the
            // destructor throw outside the try block.
            foreach (array_keys($references) as $key) {
                $references[$key] = null; // @phpstan-ignore parameterByRef.type
            }
        } catch (\Error) {
            $destructorRan = true;
        }

        return $destructorRan;
    }
}
