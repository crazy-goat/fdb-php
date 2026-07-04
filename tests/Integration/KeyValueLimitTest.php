<?php

declare(strict_types=1);

namespace CrazyGoat\FoundationDB\Tests\Integration;

use CrazyGoat\FoundationDB\FDBException;
use CrazyGoat\FoundationDB\KeyValueLimits;
use CrazyGoat\FoundationDB\Transaction;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Tests for key/value size limits in FoundationDB.
 *
 * FoundationDB enforces the following limits:
 * - Key: maximum 10,000 bytes (10KB)
 * - Value: maximum 100,000 bytes (100KB)
 * - Transaction: maximum 10,000,000 bytes (10MB) by default
 *
 * Error codes (client-side validation):
 * - 2102: Key too large (key_outside_legal_size_limit)
 * - 2103: Value too large (value_outside_legal_size_limit)
 * - 2101: Transaction too large
 *
 * As of the fix for #48, the PHP boundary also enforces these limits eagerly so
 * an oversized write fails immediately at the call site with a clear
 * `\InvalidArgumentException` carrying the offending length. The transaction-
 * size limit (2101) is still reported by libfdb_c on commit because it is an
 * aggregate over multiple writes and the client cannot meaningfully pre-compute
 * the running size.
 */
final class KeyValueLimitTest extends TestCase
{
    use DatabaseCleanupTrait;

    private const MAX_KEY_SIZE = 10000;
    private const MAX_VALUE_SIZE = 100000;
    private const DEFAULT_TRANSACTION_SIZE_LIMIT = 10000000; // 10MB
    // Client-side validation error codes (returned by fdb_c, not server)
    private const KEY_TOO_LARGE_CODE = 2102;   // key_outside_legal_size_limit
    private const VALUE_TOO_LARGE_CODE = 2103; // value_outside_legal_size_limit
    private const TRANSACTION_TOO_LARGE_CODE = 2101;

    // ---------------------------------------------------------------------
    // Library-level pre-flight validation (PHP-side guard — the fix for #48)
    //
    // Each test below asserts the new contract: oversized inputs throw
    // \InvalidArgumentException at the call site with a named length so the
    // application sees the failing input without waiting for commit time.
    // ---------------------------------------------------------------------

    #[Test]
    public function keyAtLimitSucceeds(): void
    {
        $key = str_repeat('a', self::MAX_KEY_SIZE);
        $value = 'test_value';

        $this->getDatabase()->set($key, $value);

        $result = $this->getDatabase()->get($key);
        self::assertSame($value, $result);
    }

    #[Test]
    public function valueAtLimitSucceeds(): void
    {
        $key = 'test_key';
        $value = str_repeat('b', self::MAX_VALUE_SIZE);

        $this->getDatabase()->set($key, $value);

        $result = $this->getDatabase()->get($key);
        self::assertSame($value, $result);
    }

    #[Test]
    public function keyOneByteOverLimitThrowsAtCallSite(): void
    {
        $key = str_repeat('a', self::MAX_KEY_SIZE + 1);

        // The Database convenience set() throws InvalidArgumentException
        // because the PHP-side guard rejects the oversize key before the
        // FFI call. The exception type is part of the new contract.
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage(
            'FoundationDB key exceeds maximum size: 10001 bytes (limit is 10000 bytes)',
        );

        $this->getDatabase()->set($key, 'value');
    }

    #[Test]
    public function valueOneByteOverLimitThrowsAtCallSite(): void
    {
        $value = str_repeat('b', self::MAX_VALUE_SIZE + 1);

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage(
            'FoundationDB value exceeds maximum size: 100001 bytes (limit is 100000 bytes)',
        );

        $this->getDatabase()->set('test_key', $value);
    }

    #[Test]
    public function transactionSetWithOversizeKeyThrowsAtCallSite(): void
    {
        $key = str_repeat('a', self::MAX_KEY_SIZE + 1);

        $tr = $this->getDatabase()->createTransaction();

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('FoundationDB key exceeds maximum size');

        // The exception is thrown on set(), not on commit(). This is the
        // whole point of the fix — the application sees the failure
        // immediately rather than as an opaque commit-time error.
        $tr->set($key, 'value');
    }

    #[Test]
    public function transactionSetWithOversizeValueThrowsAtCallSite(): void
    {
        $value = str_repeat('b', self::MAX_VALUE_SIZE + 1);

        $tr = $this->getDatabase()->createTransaction();

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('FoundationDB value exceeds maximum size');

        $tr->set('test_key', $value);
    }

    #[Test]
    public function transactDoesNotCatchGuardException(): void
    {
        // transact() retries on FDBException only. A guard rejection is a
        // programmer error, not a transient conflict, so it must propagate
        // out of transact().
        $key = str_repeat('a', self::MAX_KEY_SIZE + 1);

        $this->expectException(\InvalidArgumentException::class);

        $this->getDatabase()->transact(static function (Transaction $tr) use ($key): void {
            $tr->set($key, 'value');
        });
    }

    #[Test]
    public function clearRangeWithOversizeEndpointThrowsAtCallSite(): void
    {
        $longKey = str_repeat('a', self::MAX_KEY_SIZE + 1);

        $tr = $this->getDatabase()->createTransaction();

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('range endpoint exceeds maximum size');

        $tr->clearRange("\x00", $longKey);
    }

    #[Test]
    public function clearWithOversizeKeyThrowsAtCallSite(): void
    {
        $longKey = str_repeat('a', self::MAX_KEY_SIZE + 1);

        $tr = $this->getDatabase()->createTransaction();

        $this->expectException(\InvalidArgumentException::class);

        $tr->clear($longKey);
    }

    #[Test]
    public function atomicOpWithOversizeKeyThrowsAtCallSite(): void
    {
        $longKey = str_repeat('a', self::MAX_KEY_SIZE + 1);

        $tr = $this->getDatabase()->createTransaction();

        $this->expectException(\InvalidArgumentException::class);

        $tr->add($longKey, 1);
    }

    #[Test]
    public function readGetWithOversizeKeyThrowsAtCallSite(): void
    {
        $longKey = str_repeat('a', self::MAX_KEY_SIZE + 1);

        $tr = $this->getDatabase()->createTransaction();

        $this->expectException(\InvalidArgumentException::class);

        // Returns a Future; consuming the future is fine but the assertion
        // is on the boundary, not the result — awaiting a logically-broken
        // get() is irrelevant.
        $tr->get($longKey);
    }

    #[Test]
    public function getRangeWithOversizeEndpointThrowsAtCallSite(): void
    {
        $longKey = str_repeat('a', self::MAX_KEY_SIZE + 1);

        $tr = $this->getDatabase()->createTransaction();

        $this->expectException(\InvalidArgumentException::class);

        $tr->getRange("\x00", $longKey);
    }

    #[Test]
    public function commitSucceedsAfterGuardRejectsOversizeWrite(): void
    {
        // An oversize set() must abort the transaction locally (the FFI call
        // never happens); a subsequent, valid set() in the same transaction
        // then commits cleanly to confirm we did not leave the FDB
        // transaction in a corrupted state.
        $longKey = str_repeat('a', self::MAX_KEY_SIZE + 1);

        try {
            $this->getDatabase()->transact(static function (Transaction $tr) use ($longKey): void {
                $tr->set($longKey, 'value');
            });
            self::fail('Expected \\InvalidArgumentException from oversize key');
        } catch (\InvalidArgumentException) {
            // expected
        }

        // A fresh, legal transaction still commits.
        $this->getDatabase()->set('test/recovery', 'ok');
        self::assertSame('ok', $this->getDatabase()->get('test/recovery'));
    }

    // ---------------------------------------------------------------------
    // Native FDB error codes — the predicate tests still hold because the
    // native codes themselves did not change.
    // ---------------------------------------------------------------------

    #[Test]
    public function keyTooLargeErrorIsNotRetryable(): void
    {
        self::assertFalse(
            FDBException::testPredicate(
                \CrazyGoat\FoundationDB\Enum\ErrorPredicate::Retryable,
                self::KEY_TOO_LARGE_CODE,
            ),
        );
    }

    #[Test]
    public function valueTooLargeErrorIsNotRetryable(): void
    {
        self::assertFalse(
            FDBException::testPredicate(
                \CrazyGoat\FoundationDB\Enum\ErrorPredicate::Retryable,
                self::VALUE_TOO_LARGE_CODE,
            ),
        );
    }

    // ---------------------------------------------------------------------
    // Transaction-size limit — still reported by libfdb_c on commit because
    // it is an aggregate over multiple writes.
    // ---------------------------------------------------------------------

    #[Test]
    public function transactionAtDefaultSizeLimitSucceeds(): void
    {
        // Create a transaction that stays within the 10MB limit
        // Keys also count toward the transaction size, so we leave headroom
        $keyPrefix = 'test/tx_limit/';
        $valueSize = 99800; // ~99KB each (leaving room for key bytes)
        $numPairs = 100;

        $tr = $this->getDatabase()->createTransaction();

        for ($i = 0; $i < $numPairs; ++$i) {
            $key = $keyPrefix . $i;
            $value = str_repeat('x', $valueSize);
            $tr->set($key, $value);
        }

        // Should succeed: 100 * (99800 + ~15) ≈ 9.98MB < 10MB
        $tr->commit()->await();

        // Verify data was written
        $result = $this->getDatabase()->get($keyPrefix . '0');
        self::assertSame(str_repeat('x', $valueSize), $result);
    }

    #[Test]
    public function transactionExceedingDefaultSizeLimitThrowsException(): void
    {
        // Create a transaction that exceeds the 10MB limit
        $keyPrefix = 'test/tx_limit/';
        $valueSize = 100000; // 100KB each
        $numPairs = (self::DEFAULT_TRANSACTION_SIZE_LIMIT / $valueSize) + 1; // 101 pairs = 10.1MB

        $tr = $this->getDatabase()->createTransaction();

        for ($i = 0; $i < $numPairs; ++$i) {
            $key = $keyPrefix . $i;
            $value = str_repeat('x', $valueSize);
            $tr->set($key, $value);
        }

        try {
            $tr->commit()->await();
            self::fail('Expected FDBException for transaction too large');
        } catch (FDBException $e) {
            self::assertSame(self::TRANSACTION_TOO_LARGE_CODE, $e->fdbCode);
        }
    }

    #[Test]
    public function customSizeLimitAllowsSmallerTransactions(): void
    {
        // Set a custom size limit of 1MB
        $customLimit = 1000000; // 1MB
        $keyPrefix = 'test/custom_limit/';
        $valueSize = 100000; // 100KB each
        $numPairs = ($customLimit / $valueSize) + 1; // 11 pairs = 1.1MB, exceeds 1MB limit

        $tr = $this->getDatabase()->createTransaction();
        $tr->options()->setSizeLimit($customLimit);

        for ($i = 0; $i < $numPairs; ++$i) {
            $key = $keyPrefix . $i;
            $value = str_repeat('y', $valueSize);
            $tr->set($key, $value);
        }

        try {
            $tr->commit()->await();
            self::fail('Expected FDBException for transaction exceeding custom limit');
        } catch (FDBException $e) {
            self::assertSame(self::TRANSACTION_TOO_LARGE_CODE, $e->fdbCode);
        }
    }

    #[Test]
    public function transactionTooLargeErrorIsNotRetryable(): void
    {
        self::assertFalse(
            FDBException::testPredicate(
                \CrazyGoat\FoundationDB\Enum\ErrorPredicate::Retryable,
                self::TRANSACTION_TOO_LARGE_CODE,
            ),
        );
    }

    // ---------------------------------------------------------------------
    // Boundary-mirror of the unit tests: ensure the live cluster accepts the
    // exact-accepted sizes without rejecting them via libfdb_c's own validation.
    // ---------------------------------------------------------------------

    #[Test]
    public function clearRangeWithBoundaryKeysSucceeds(): void
    {
        $key = str_repeat('a', self::MAX_KEY_SIZE);

        $this->getDatabase()->set($key, 'value');
        self::assertSame('value', $this->getDatabase()->get($key));

        // Clear via a 10 KB exact-boundary begin key. End is the strinc of
        // the begin, also 10 KB; both pass the boundary check.
        $this->getDatabase()->clearRange($key, \CrazyGoat\FoundationDB\KeyUtil::strinc($key) ?? '');
        self::assertNull($this->getDatabase()->get($key));
    }

    #[Test]
    public function getRangeWithBoundaryPrefixSucceeds(): void
    {
        $prefix = str_repeat('p', self::MAX_KEY_SIZE);

        $this->getDatabase()->set($prefix, 'value');

        $results = $this->getDatabase()->getRangeStartsWith($prefix);
        self::assertCount(1, $results);
        self::assertSame('value', $results[0]->value);
    }

    #[Test]
    public function publicLimitsMatchConstants(): void
    {
        // Mirror the unit-test boundary contract on the integration side
        // so a refactor that drifts the constants in src/KeyValueLimits.php
        // away from the FoundationDB-documented limits fails here.
        self::assertSame(10_000, KeyValueLimits::MAX_KEY_SIZE);
        self::assertSame(100_000, KeyValueLimits::MAX_VALUE_SIZE);
    }
}
