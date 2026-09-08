<?php

declare(strict_types=1);

namespace CrazyGoat\FoundationDB\Tests\Integration;

use CrazyGoat\FoundationDB\Enum\StreamingMode;
use CrazyGoat\FoundationDB\FDBException;
use CrazyGoat\FoundationDB\MappedKeyValue;
use CrazyGoat\FoundationDB\RangeOptions;
use CrazyGoat\FoundationDB\Transaction;
use CrazyGoat\FoundationDB\Tuple\Tuple;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class MappedRangeTest extends TestCase
{
    use DatabaseCleanupTrait;

    private const INDEX_SUBSPACE = 'mapped_index';

    private const RECORDS_SUBSPACE = 'mapped_records';

    #[Test]
    public function getMappedRangeWithPointMapperReturnsIndexRowsWithMappedRecords(): void
    {
        $this->seedIndexAndRecords();

        $tr = $this->getDatabase()->createTransaction();
        [$begin, $end] = Tuple::range([self::INDEX_SUBSPACE]);

        // Point mapper: ('mapped_records', {K[1]}) — a single record per row.
        $result = $tr->getMappedRange($begin, $end, $this->pointMapper())->await();

        self::assertSame(3, $result->count);
        self::assertFalse($result->more);

        self::assertInstanceOf(MappedKeyValue::class, $result->entries[0]);
        self::assertSame(
            Tuple::pack([self::INDEX_SUBSPACE, 'alice']),
            $result->entries[0]->key,
        );
        self::assertSame('Alice', $result->entries[0]->value);
        self::assertCount(1, $result->entries[0]->results);
        self::assertSame(
            Tuple::pack([self::RECORDS_SUBSPACE, 'alice']),
            $result->entries[0]->results[0]->key,
        );
        self::assertSame('record-alice', $result->entries[0]->results[0]->value);
    }

    #[Test]
    public function getMappedRangeWithRangeMapperReturnsAllMappedRecords(): void
    {
        $this->seedIndexAndRecords();

        $tr = $this->getDatabase()->createTransaction();
        [$begin, $end] = Tuple::range([self::INDEX_SUBSPACE]);

        // Range mapper: ('mapped_records', {K[1]}, {...}) — every record
        // under the ('mapped_records', id) prefix, fetched in the same
        // round trip as the index rows.
        $result = $tr->getMappedRange($begin, $end, $this->rangeMapper(), new RangeOptions(
            mode: StreamingMode::WantAll,
        ))->await();

        self::assertSame(3, $result->count);

        foreach ($result->entries as $mapped) {
            $id = $this->indexIdOf($mapped->key);
            self::assertCount(3, $mapped->results);
            self::assertSame(
                Tuple::pack([self::RECORDS_SUBSPACE, $id]),
                $mapped->results[0]->key,
            );
            self::assertSame('record-' . $id, $mapped->results[0]->value);
            self::assertSame(
                Tuple::pack([self::RECORDS_SUBSPACE, $id, 'name']),
                $mapped->results[1]->key,
            );
            self::assertSame('name-' . $id, $mapped->results[1]->value);
            self::assertSame(
                Tuple::pack([self::RECORDS_SUBSPACE, $id, 'score']),
                $mapped->results[2]->key,
            );
            self::assertSame('score-' . $id, $mapped->results[2]->value);
        }
    }

    #[Test]
    public function getMappedRangeResolvesValueComponents(): void
    {
        // The record key is built from the index VALUE instead of the key:
        // index ('mapped_index', $id) -> $id, mapper ('mapped_records', {V[0]}).
        $this->getDatabase()->transact(function (Transaction $tr): void {
            foreach (['x', 'y'] as $id) {
                $tr->set(Tuple::pack([self::INDEX_SUBSPACE, $id]), Tuple::pack([$id]));
                $tr->set(Tuple::pack([self::RECORDS_SUBSPACE, $id]), 'rec-' . $id);
            }
        });

        $tr = $this->getDatabase()->createTransaction();
        [$begin, $end] = Tuple::range([self::INDEX_SUBSPACE]);
        $mapper = Tuple::pack([self::RECORDS_SUBSPACE, '{V[0]}']);

        $result = $tr->getMappedRange($begin, $end, $mapper)->await();

        self::assertCount(2, $result->entries);

        foreach ($result->entries as $mapped) {
            $unpacked = Tuple::unpack($mapped->value);
            $id = is_string($unpacked[0] ?? null) ? $unpacked[0] : '';
            self::assertSame($id, $this->indexIdOf($mapped->key));
            self::assertCount(1, $mapped->results);
            self::assertSame(Tuple::pack([self::RECORDS_SUBSPACE, $id]), $mapped->results[0]->key);
            self::assertSame('rec-' . $id, $mapped->results[0]->value);
        }
    }

    #[Test]
    public function getMappedRangeRespectsLimit(): void
    {
        $this->seedIndexAndRecords();

        $tr = $this->getDatabase()->createTransaction();
        [$begin, $end] = Tuple::range([self::INDEX_SUBSPACE]);

        $result = $tr->getMappedRange($begin, $end, $this->pointMapper(), new RangeOptions(
            limit: 2,
            mode: StreamingMode::WantAll,
        ))->await();

        self::assertSame(2, $result->count);
        self::assertCount(2, $result->entries);
    }

    #[Test]
    public function getMappedRangeOnEmptyRange(): void
    {
        $tr = $this->getDatabase()->createTransaction();
        [$begin, $end] = Tuple::range([self::INDEX_SUBSPACE]);

        $result = $tr->getMappedRange($begin, $end, $this->pointMapper())->await();

        self::assertSame(0, $result->count);
        self::assertSame([], $result->entries);
    }

    #[Test]
    public function getMappedRangeRejectsSnapshotReads(): void
    {
        $this->seedIndexAndRecords();

        $snap = $this->getDatabase()->createTransaction()->snapshot();
        [$begin, $end] = Tuple::range([self::INDEX_SUBSPACE]);

        $this->expectException(\LogicException::class);
        $snap->getMappedRange($begin, $end, $this->pointMapper());
    }

    #[Test]
    public function getMappedRangeWithInvalidMapperThrowsFdbException(): void
    {
        $this->seedIndexAndRecords();

        $tr = $this->getDatabase()->createTransaction();
        [$begin, $end] = Tuple::range([self::INDEX_SUBSPACE]);

        // A mapper that is not a parseable tuple template.
        $future = $tr->getMappedRange($begin, $end, "not-a-tuple\xFF\xFF\xFF");

        $this->expectException(FDBException::class);
        $future->await();
    }

    /**
     * Mapper: ('mapped_records', {K[1]}) — a point lookup of the second
     * component of the index key tuple.
     */
    private function pointMapper(): string
    {
        return Tuple::pack([self::RECORDS_SUBSPACE, '{K[1]}']);
    }

    /**
     * Mapper: ('mapped_records', {K[1]}, {...}) — a range lookup over the
     * ('mapped_records', id) prefix. The "{...}" descriptor must be the
     * last element of the mapper tuple.
     */
    private function rangeMapper(): string
    {
        return Tuple::pack([self::RECORDS_SUBSPACE, '{K[1]}', '{...}']);
    }

    private function indexIdOf(string $indexKey): string
    {
        $unpacked = Tuple::unpack($indexKey);

        return is_string($unpacked[1] ?? null) ? $unpacked[1] : '';
    }

    private function seedIndexAndRecords(): void
    {
        $this->getDatabase()->transact(function (Transaction $tr): void {
            foreach (['alice', 'bob', 'carol'] as $id) {
                $tr->set(Tuple::pack([self::INDEX_SUBSPACE, $id]), ucfirst($id));
                $tr->set(Tuple::pack([self::RECORDS_SUBSPACE, $id]), 'record-' . $id);
                $tr->set(Tuple::pack([self::RECORDS_SUBSPACE, $id, 'name']), 'name-' . $id);
                $tr->set(Tuple::pack([self::RECORDS_SUBSPACE, $id, 'score']), 'score-' . $id);
            }
        });
    }
}
