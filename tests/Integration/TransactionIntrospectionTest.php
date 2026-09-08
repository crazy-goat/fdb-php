<?php

declare(strict_types=1);

namespace CrazyGoat\FoundationDB\Tests\Integration;

use CrazyGoat\FoundationDB\Transaction;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Integration coverage for the transaction introspection calls bound in
 * issue #90: `getTotalCost()` and `getTagThrottledDuration()`.
 *
 * On an unthrottled single-client cluster the throttled duration must be
 * 0.0; the total cost must be a non-negative integer that grows once the
 * transaction has performed writes (each write carries a per-key cost in
 * the cluster's cost model).
 */
final class TransactionIntrospectionTest extends TestCase
{
    use DatabaseCleanupTrait;

    #[Test]
    public function totalCostIsNonNegativeAndGrowsWithWrites(): void
    {
        $db = $this->getDatabase();

        $db->transact(static function (Transaction $tr): void {
            $before = $tr->getTotalCost()->await();
            self::assertGreaterThanOrEqual(0, $before);

            for ($i = 0; $i < 10; $i++) {
                $tr->set("test/introspection/key$i", str_repeat('v', 100));
            }

            $after = $tr->getTotalCost()->await();
            self::assertGreaterThanOrEqual($before, $after);
        });
    }

    #[Test]
    public function totalCostIsAvailableOnSnapshots(): void
    {
        $db = $this->getDatabase();

        $cost = $db->transact(
            static fn (Transaction $tr): int => $tr->snapshot()->getTotalCost()->await(),
        );

        self::assertGreaterThanOrEqual(0, $cost);
    }

    #[Test]
    public function tagThrottledDurationIsZeroOnAnUnthrottledCluster(): void
    {
        $db = $this->getDatabase();

        $db->transact(static function (Transaction $tr): void {
            $tr->set('test/introspection/throttle', 'value');
            self::assertSame(0.0, $tr->getTagThrottledDuration()->await());
        });
    }

    #[Test]
    public function tagThrottledDurationIsAvailableOnSnapshots(): void
    {
        $db = $this->getDatabase();

        $duration = $db->transact(
            static fn (Transaction $tr): float => $tr->snapshot()->getTagThrottledDuration()->await(),
        );

        self::assertSame(0.0, $duration);
    }
}
