<?php

declare(strict_types=1);

namespace CrazyGoat\FoundationDB\Tests\Integration;

use CrazyGoat\FoundationDB\FDBException;
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
    public function keyExceedingLimitThrowsException(): void
    {
        $key = str_repeat('a', self::MAX_KEY_SIZE + 1);
        $value = 'test_value';

        try {
            $this->getDatabase()->set($key, $value);
            self::fail('Expected FDBException for key too large');
        } catch (FDBException $e) {
            self::assertSame(self::KEY_TOO_LARGE_CODE, $e->fdbCode);
        }
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
    public function valueExceedingLimitThrowsException(): void
    {
        $key = 'test_key';
        $value = str_repeat('b', self::MAX_VALUE_SIZE + 1);

        try {
            $this->getDatabase()->set($key, $value);
            self::fail('Expected FDBException for value too large');
        } catch (FDBException $e) {
            self::assertSame(self::VALUE_TOO_LARGE_CODE, $e->fdbCode);
        }
    }

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

    #[Test]
    public function transactionWithKeyTooLargeThrowsOnCommit(): void
    {
        $key = str_repeat('a', self::MAX_KEY_SIZE + 1);
        $value = 'test_value';

        $tr = $this->getDatabase()->createTransaction();
        $tr->set($key, $value);

        // The error is thrown during commit, not during set()
        try {
            $tr->commit()->await();
            self::fail('Expected FDBException for key too large');
        } catch (FDBException $e) {
            self::assertSame(self::KEY_TOO_LARGE_CODE, $e->fdbCode);
        }
    }

    #[Test]
    public function transactionWithValueTooLargeThrowsOnCommit(): void
    {
        $key = 'test_key';
        $value = str_repeat('b', self::MAX_VALUE_SIZE + 1);

        $tr = $this->getDatabase()->createTransaction();
        $tr->set($key, $value);

        // The error is thrown during commit, not during set()
        try {
            $tr->commit()->await();
            self::fail('Expected FDBException for value too large');
        } catch (FDBException $e) {
            self::assertSame(self::VALUE_TOO_LARGE_CODE, $e->fdbCode);
        }
    }

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
}
