<?php

declare(strict_types=1);

namespace CrazyGoat\FoundationDB\Tests\Integration;

use CrazyGoat\FoundationDB\Future\Future;
use CrazyGoat\FoundationDB\KeySelector;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Integration coverage for the group-await helper added in issue #92:
 * futures issued up front run concurrently inside the FDB client, so
 * `Future::awaitAll()` resolves them with roughly one round trip of total
 * latency instead of N — against a live cluster.
 */
final class FutureAwaitAllIntegrationTest extends TestCase
{
    use DatabaseCleanupTrait;

    #[Test]
    public function awaitAllResolvesABatchOfParallelReads(): void
    {
        $db = $this->getDatabase();

        $tr = $db->createTransaction();
        $expected = [];
        for ($i = 0; $i < 50; $i++) {
            $key = 'test/future-await-all/k' . sprintf('%03d', $i);
            $tr->set($key, 'v' . $i);
            $expected[$key] = 'v' . $i;
        }
        $tr->commit()->await();

        // Issue every read up front, then resolve them as a group.
        $reader = $db->createTransaction();
        $futures = [];
        foreach (array_keys($expected) as $key) {
            $futures[] = $reader->get($key);
        }

        $values = Future::awaitAll($futures);

        self::assertCount(count($expected), $values);
        self::assertSame(array_values($expected), $values);
    }

    #[Test]
    public function awaitAllWithMixedFutureTypesResolvesInOrder(): void
    {
        $db = $this->getDatabase();

        $writer = $db->createTransaction();
        $writer->set('test/future-await-all/mixed', 'yes');
        $writer->commit()->await();

        $reader = $db->createTransaction();

        $results = Future::awaitAll([
            $reader->get('test/future-await-all/mixed'),
            $reader->getReadVersion(),
            $reader->getKey(KeySelector::lastLessOrEqual('test/future-await-all/mixed')),
        ]);

        self::assertSame('yes', $results[0]);
        self::assertGreaterThan(0, $results[1]);
        self::assertSame('test/future-await-all/mixed', $results[2]);
    }
}
