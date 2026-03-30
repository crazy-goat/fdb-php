<?php

declare(strict_types=1);

namespace CrazyGoat\FoundationDB\Tests\Integration;

use CrazyGoat\FoundationDB\FDBException;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class OptionsTest extends TestCase
{
    use DatabaseCleanupTrait;

    #[Test]
    public function setTransactionTimeout(): void
    {
        $tr = $this->getDatabase()->createTransaction();
        $tr->options()->setTimeout(5000);

        $tr->set('options_test/timeout', 'value');
        $tr->commit()->await();

        self::assertSame('value', $this->getDatabase()->get('options_test/timeout'));
    }

    #[Test]
    public function setTransactionRetryLimit(): void
    {
        $tr = $this->getDatabase()->createTransaction();
        $tr->options()->setRetryLimit(3);

        $tr->set('options_test/retry', 'value');
        $tr->commit()->await();

        self::assertSame('value', $this->getDatabase()->get('options_test/retry'));
    }

    #[Test]
    public function setTransactionMaxRetryDelay(): void
    {
        $tr = $this->getDatabase()->createTransaction();
        $tr->options()->setMaxRetryDelay(500);

        $tr->set('options_test/delay', 'value');
        $tr->commit()->await();

        self::assertSame('value', $this->getDatabase()->get('options_test/delay'));
    }

    #[Test]
    public function setTransactionSizeLimit(): void
    {
        $tr = $this->getDatabase()->createTransaction();
        $tr->options()->setSizeLimit(100_000);

        $tr->set('options_test/size', 'value');
        $tr->commit()->await();

        self::assertSame('value', $this->getDatabase()->get('options_test/size'));
    }

    #[Test]
    public function setTransactionReadYourWritesDisable(): void
    {
        $tr = $this->getDatabase()->createTransaction();
        $tr->options()->setReadYourWritesDisable();

        $tr->set('options_test/ryw_disabled', 'value');
        $tr->commit()->await();

        self::assertSame('value', $this->getDatabase()->get('options_test/ryw_disabled'));
    }

    #[Test]
    public function setTransactionSnapshotRyw(): void
    {
        $tr = $this->getDatabase()->createTransaction();
        $tr->options()->setSnapshotRywEnable();

        $tr->set('options_test/snap_ryw', 'value');
        $tr->commit()->await();

        self::assertSame('value', $this->getDatabase()->get('options_test/snap_ryw'));
    }

    #[Test]
    public function setDatabaseTransactionTimeout(): void
    {
        $this->getDatabase()->options()->setTransactionTimeout(10_000);

        $this->getDatabase()->set('options_test/db_timeout', 'value');
        self::assertSame('value', $this->getDatabase()->get('options_test/db_timeout'));

        $this->getDatabase()->options()->setTransactionTimeout(0);
    }

    #[Test]
    public function setDatabaseTransactionRetryLimit(): void
    {
        $this->getDatabase()->options()->setTransactionRetryLimit(5);

        $this->getDatabase()->set('options_test/db_retry', 'value');
        self::assertSame('value', $this->getDatabase()->get('options_test/db_retry'));

        $this->getDatabase()->options()->setTransactionRetryLimit(-1);
    }

    #[Test]
    public function transactionTimeoutExpires(): void
    {
        $tr = $this->getDatabase()->createTransaction();
        $tr->options()->setTimeout(1);

        usleep(10_000);

        $this->expectException(FDBException::class);
        $tr->get('options_test/expired')->await();
    }

    #[Test]
    public function chainedTransactionOptions(): void
    {
        $tr = $this->getDatabase()->createTransaction();
        $tr->options()
            ->setTimeout(5000)
            ->setRetryLimit(3)
            ->setMaxRetryDelay(500);

        $tr->set('options_test/chained', 'value');
        $tr->commit()->await();

        self::assertSame('value', $this->getDatabase()->get('options_test/chained'));
    }

    #[Test]
    public function chainedDatabaseOptions(): void
    {
        $this->getDatabase()->options()
            ->setTransactionTimeout(10_000)
            ->setTransactionRetryLimit(5);

        $this->getDatabase()->set('options_test/db_chained', 'value');
        self::assertSame('value', $this->getDatabase()->get('options_test/db_chained'));

        $this->getDatabase()->options()
            ->setTransactionTimeout(0)
            ->setTransactionRetryLimit(-1);
    }

    #[Test]
    public function setTransactionDebugIdentifierAndLog(): void
    {
        $tr = $this->getDatabase()->createTransaction();
        $tr->options()
            ->setDebugTransactionIdentifier('test_txn_001')
            ->setLogTransaction();

        $tr->set('options_test/debug', 'value');
        $tr->commit()->await();

        self::assertSame('value', $this->getDatabase()->get('options_test/debug'));
    }

    #[Test]
    public function setTransactionTag(): void
    {
        $tr = $this->getDatabase()->createTransaction();
        $tr->options()->setTag('test_tag');

        $tr->set('options_test/tag', 'value');
        $tr->commit()->await();

        self::assertSame('value', $this->getDatabase()->get('options_test/tag'));
    }

    #[Test]
    public function databaseLocationCacheSize(): void
    {
        $this->getDatabase()->options()->setLocationCacheSize(50_000);

        $this->getDatabase()->set('options_test/cache', 'value');
        self::assertSame('value', $this->getDatabase()->get('options_test/cache'));
    }

    #[Test]
    public function databaseMaxWatches(): void
    {
        $this->getDatabase()->options()->setMaxWatches(5_000);

        $this->getDatabase()->set('options_test/watches', 'value');
        self::assertSame('value', $this->getDatabase()->get('options_test/watches'));
    }
}
