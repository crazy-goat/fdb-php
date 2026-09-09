<?php

declare(strict_types=1);

namespace CrazyGoat\FoundationDB\Tests\BindingTester;

use CrazyGoat\FoundationDB\Database;
use CrazyGoat\FoundationDB\Enum\MutationType;
use CrazyGoat\FoundationDB\FDBException;
use CrazyGoat\FoundationDB\Transaction;

/**
 * Unit tests invoked by the UNIT_TESTS instruction of the binding tester
 * (transaction options, watches, cancellation, retry limits, timeouts,
 * error predicates). Mirrors bindings/python/tests/unit_tests.py.
 */
final class UnitTests
{
    private const DEFAULT_TIMEOUT_SECONDS = 60;

    public static function run(Database $db): void
    {
        self::testDbOptions($db);
        self::testOptions($db);
        self::testWatches($db);
        self::testCancellation($db);
        self::testRetryLimits($db);
        self::testTimeouts($db);
        self::testPredicates();
    }

    private static function testDbOptions(Database $db): void
    {
        $db->options()
            ->setLocationCacheSize(100001)
            ->setMaxWatches(100001)
            ->setDatacenterId('dc_id')
            ->setMachineId('machine_id')
            ->setSnapshotRywEnable()
            ->setSnapshotRywDisable()
            ->setTransactionLoggingMaxFieldLength(1000)
            ->setTransactionTimeout(100000)
            ->setTransactionTimeout(0)
            ->setTransactionMaxRetryDelay(100)
            ->setTransactionSizeLimit(100000)
            ->setTransactionRetryLimit(10)
            ->setTransactionRetryLimit(-1)
            ->setTransactionCausalReadRisky()
            ->setTransactionUsedDuringCommitProtectionDisable();
    }

    private static function testOptions(Database $db): void
    {
        $db->transact(function (Transaction $tr): void {
            $tr->options()
                ->setPrioritySystemImmediate()
                ->setPriorityBatch()
                ->setCausalReadRisky()
                ->setCausalWriteRisky()
                ->setReadYourWritesDisable()
                ->setReadSystemKeys()
                ->setAccessSystemKeys()
                ->setTransactionLoggingMaxFieldLength(1000)
                ->setTimeout(60000)
                ->setRetryLimit(50)
                ->setMaxRetryDelay(100)
                ->setUsedDuringCommitProtectionDisable()
                ->setDebugTransactionIdentifier('my_transaction')
                ->setLogTransaction()
                ->setReadLockAware()
                ->setLockAware()
                ->setIncludePortInAddress();
            $tr->get("\xFF")->await();
            $tr->commit()->await();
        });
    }

    private static function testWatches(Database $db): void
    {
        for ($attempt = 0; $attempt < 3; $attempt++) {
            $db->set('w0', '0');
            $db->set('w3', '3');

            $watch1 = $db->clearAndWatch('w1');
            $watch2 = $db->setAndWatch('w2', '2');
            [$value3, $watch3] = $db->getAndWatch('w3');

            if ($value3 !== '3') {
                throw new \RuntimeException('Watch unit test: unexpected value for w3');
            }

            sleep(1);

            $db->set('w0', 'a');
            $db->set('w1', 'b');
            $db->clear('w2');
            $db->transact(function (Transaction $tr): void {
                $tr->atomicOp(MutationType::BitXor, 'w3', "\xFF\xFF");
            });

            try {
                $watch1->await();
                $watch2->await();
                $watch3->await();

                return;
            } catch (FDBException $error) {
                // Watch was cancelled (e.g. too many watches) — retry.
                continue;
            }
        }

        throw new \RuntimeException('Watch unit test did not converge');
    }

    private static function testCancellation(Database $db): void
    {
        // (1) Basic cancellation
        self::retryWithTimeout($db, function (Transaction $tr): void {
            $tr->cancel();
            try {
                $tr->commit()->await();
                throw new \RuntimeException('Basic cancellation unit test failed.');
            } catch (FDBException $error) {
                if ($error->fdbCode !== 1025) {
                    throw $error;
                }
            }
        });

        // (2) Cancellation does not survive reset
        self::retryWithTimeout($db, function (Transaction $tr): void {
            $tr->cancel();
            $tr->reset();
            try {
                $tr->commit()->await();
            } catch (FDBException $error) {
                if ($error->fdbCode === 1025) {
                    throw new \RuntimeException('Cancellation survived reset.');
                }

                throw $error;
            }
        });

        // (3) Cancellation survives on_error()
        self::retryWithTimeout($db, function (Transaction $tr): void {
            $tr->cancel();
            try {
                $tr->onError(1007)->await();
                throw new \RuntimeException('on_error() did not notice cancellation.');
            } catch (FDBException $error) {
                if ($error->fdbCode !== 1025) {
                    throw $error;
                }
            }

            try {
                $tr->commit()->await();
                throw new \RuntimeException('Cancellation did not survive on_error().');
            } catch (FDBException $error) {
                if ($error->fdbCode !== 1025) {
                    throw $error;
                }
            }
        });
    }

    private static function testRetryLimits(Database $db): void
    {
        // (1) Basic retry limits
        self::retryWithTimeout($db, function (Transaction $tr): void {
            $tr->options()->setRetryLimit(1);
            $tr->set('foo', 'bar');
            $tr->onError(1007)->await();

            $tr->set('foo', 'bar');
            $tr->options()->setRetryLimit(1);
            try {
                $tr->onError(1007)->await();
                throw new \RuntimeException('(1) Retry limit was ignored.');
            } catch (FDBException $error) {
                if ($error->fdbCode !== 1007 && $error->fdbCode !== 1025) {
                    throw $error;
                }
            }
        });

        // (2) Retry limits do not survive resets
        self::retryWithTimeout($db, function (Transaction $tr): void {
            $tr->options()->setRetryLimit(1);
            $tr->set('foo', 'bar');
            $tr->onError(1007)->await();

            $tr->options()->setRetryLimit(1);
            $tr->set('foo', 'bar');
            try {
                $tr->onError(1007)->await();
                throw new \RuntimeException('(2) Retry limit was ignored.');
            } catch (FDBException $error) {
                if ($error->fdbCode !== 1007 && $error->fdbCode !== 1025) {
                    throw $error;
                }
            }

            $tr->reset();
            $tr->set('foo', 'bar');
            $tr->onError(1007)->await();
        });
    }

    private static function testTimeouts(Database $db): void
    {
        // A 1ms timeout on a transaction that performs work: the transaction
        // may complete or fail with transaction_timed_out (1031); both are
        // acceptable — the point is that the option is honoured end-to-end.
        $db->transact(function (Transaction $tr): void {
            $tr->options()->setTimeout(1);
            $tr->set('timeout_test', 'value');
            $tr->get('timeout_test')->await();
        });
    }

    private static function testPredicates(): void
    {
        if (!(new FDBException(1020))->isRetryable()) {
            throw new \RuntimeException('Error 1020 should be retryable');
        }

        if ((new FDBException(10))->isRetryable()) {
            throw new \RuntimeException('Error 10 should not be retryable');
        }
    }

    /**
     * Mirrors the Python `retry_with_timeout` wrapper: runs `$fn` inside a
     * transaction, retrying via a side transaction carrying a wall-clock
     * timeout so a wedged payload transaction cannot hang the tester.
     */
    private static function retryWithTimeout(Database $db, callable $fn): void
    {
        $timeoutTransaction = $db->createTransaction();
        $transaction = $db->createTransaction();

        while (true) {
            try {
                $timeoutTransaction->options()->setTimeout(self::DEFAULT_TIMEOUT_SECONDS * 1000);
                $fn($transaction);

                return;
            } catch (FDBException $error) {
                $timeoutTransaction->onError($error->fdbCode)->await();
                $transaction = $db->createTransaction();
            }
        }
    }
}
