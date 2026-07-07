<?php

declare(strict_types=1);

namespace CrazyGoat\FoundationDB\Tests\Integration;

use CrazyGoat\FoundationDB\Enum\StreamingMode;
use CrazyGoat\FoundationDB\RangeOptions;
use CrazyGoat\FoundationDB\Transaction;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class ReversePaginationTest extends TestCase
{
    use DatabaseCleanupTrait;

    #[Test]
    public function reverseIterationYieldsEachKeyExactlyOnce(): void
    {
        $n = 1000;
        $this->getDatabase()->transact(function (Transaction $tr) use ($n): void {
            for ($i = 0; $i < $n; $i++) {
                $tr->set(sprintf('test/reverse_pagination/key%04d', $i), "value{$i}");
            }
        });

        $range = $this->getDatabase()->getRange(
            'test/reverse_pagination/key0000',
            'test/reverse_pagination/key9999',
            new RangeOptions(reverse: true, mode: StreamingMode::Iterator),
        );

        $keys = [];
        foreach ($range as $kv) {
            $keys[] = $kv->key;
        }

        self::assertCount($n, $keys, 'reverse iteration must return every key');
        self::assertSame($n, count(array_unique($keys)), 'reverse iteration must not duplicate keys');
        self::assertSame('test/reverse_pagination/key0999', $keys[0]);
        self::assertSame('test/reverse_pagination/key0000', $keys[$n - 1]);
    }
}
