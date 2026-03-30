<?php

declare(strict_types=1);

namespace CrazyGoat\FoundationDB\Tests\Integration;

use CrazyGoat\FoundationDB\Transaction;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class BasicCrudTest extends TestCase
{
    use DatabaseCleanupTrait;

    #[Test]
    public function databaseConvenienceSetAndGet(): void
    {
        $db = $this->getDatabase();
        $db->set('test/key1', 'value1');

        $value = $db->get('test/key1');
        self::assertSame('value1', $value);
    }

    #[Test]
    public function databaseConvenienceClear(): void
    {
        $this->getDatabase()->set('test/key1', 'value1');
        $this->getDatabase()->clear('test/key1');

        $value = $this->getDatabase()->get('test/key1');
        self::assertNull($value);
    }

    #[Test]
    public function databaseConvenienceClearRange(): void
    {
        $this->getDatabase()->set('test/a', '1');
        $this->getDatabase()->set('test/b', '2');
        $this->getDatabase()->set('test/c', '3');

        $this->getDatabase()->clearRange('test/a', 'test/c');

        self::assertNull($this->getDatabase()->get('test/a'));
        self::assertNull($this->getDatabase()->get('test/b'));
        self::assertSame('3', $this->getDatabase()->get('test/c'));
    }

    #[Test]
    public function databaseConvenienceClearRangeStartsWith(): void
    {
        $this->getDatabase()->set('test/prefix/a', '1');
        $this->getDatabase()->set('test/prefix/b', '2');
        $this->getDatabase()->set('test/other', '3');

        $this->getDatabase()->clearRangeStartsWith('test/prefix/');

        self::assertNull($this->getDatabase()->get('test/prefix/a'));
        self::assertNull($this->getDatabase()->get('test/prefix/b'));
        self::assertSame('3', $this->getDatabase()->get('test/other'));
    }

    #[Test]
    public function transactRetryLoop(): void
    {
        $this->getDatabase()->transact(function (Transaction $tr): void {
            $tr->set('test/transact', 'works');
        });

        $value = $this->getDatabase()->get('test/transact');
        self::assertSame('works', $value);
    }

    #[Test]
    public function readYourWritesWithinTransaction(): void
    {
        $tr = $this->getDatabase()->createTransaction();
        $tr->set('test/ryw', 'hello');

        $value = $tr->get('test/ryw')->await();
        self::assertSame('hello', $value);

        $tr->commit()->await();
    }

    #[Test]
    public function transactionGetReadVersion(): void
    {
        $tr = $this->getDatabase()->createTransaction();
        $version = $tr->getReadVersion()->await();

        self::assertGreaterThan(0, $version);
    }

    #[Test]
    public function transactionGetCommittedVersion(): void
    {
        $tr = $this->getDatabase()->createTransaction();
        $tr->set('test/committed_version', 'value');
        $tr->commit()->await();

        $version = $tr->getCommittedVersion();
        self::assertGreaterThan(0, $version);
    }

    #[Test]
    public function transactionGetApproximateSize(): void
    {
        $tr = $this->getDatabase()->createTransaction();
        $tr->set('test/approx_size', 'value');

        $size = $tr->getApproximateSize()->await();
        self::assertGreaterThan(0, $size);
    }

    #[Test]
    public function snapshotRead(): void
    {
        $this->getDatabase()->set('test/snapshot', 'snap_value');

        $tr = $this->getDatabase()->createTransaction();
        $snap = $tr->snapshot();

        $value = $snap->get('test/snapshot')->await();
        self::assertSame('snap_value', $value);
    }

    #[Test]
    public function transactComposability(): void
    {
        $result = $this->getDatabase()->transact(function (Transaction $tr): string {
            $tr->set('test/compose', 'composed');

            /** @var string */
            return $tr->transact(function (Transaction $inner): string {
                $value = $inner->get('test/compose')->await();

                return $value ?? 'not_found';
            });
        });

        self::assertSame('composed', $result);
    }

    #[Test]
    public function getAddressesForKey(): void
    {
        $this->getDatabase()->set('test/addresses', 'value');

        $tr = $this->getDatabase()->createTransaction();
        $addresses = $tr->getAddressesForKey('test/addresses')->await();

        self::assertNotEmpty($addresses);
    }
}
