<?php

declare(strict_types=1);

namespace CrazyGoat\FoundationDB\Tests\Integration;

use CrazyGoat\FoundationDB\Chunk\ChunkKeyCodec;
use CrazyGoat\FoundationDB\ChunkedValueCorruptedException;
use CrazyGoat\FoundationDB\ChunkedValueTooLargeException;
use CrazyGoat\FoundationDB\KeyValueLimits;
use CrazyGoat\FoundationDB\MutationBudget;
use CrazyGoat\FoundationDB\Subspace;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Integration tests for chunked values (issue #115):
 * `Transaction::setValueChunked()` / `Database::setValueChunked()` (atomic
 * and non-atomic modes), `ReadTransaction::getValueChunked()` (including
 * snapshot reads) and `deleteValueChunked()`.
 */
final class ChunkedValuesTest extends TestCase
{
    use DatabaseCleanupTrait;

    // -- round trips ------------------------------------------------------------

    #[Test]
    public function smallValueRoundTrips(): void
    {
        $db = $this->getDatabase();

        $db->setValueChunked('chunk/small', 'hello chunked world');

        self::assertSame('hello chunked world', $db->transact(
            fn ($tr) => $tr->getValueChunked('chunk/small'),
        ));
    }

    #[Test]
    public function emptyValueRoundTripsAsEmptyString(): void
    {
        $db = $this->getDatabase();

        $db->setValueChunked('chunk/empty', '');

        self::assertSame('', $db->transact(
            fn ($tr) => $tr->getValueChunked('chunk/empty'),
        ));
    }

    #[Test]
    public function missingKeyReturnsNull(): void
    {
        $db = $this->getDatabase();

        self::assertNull($db->transact(
            fn ($tr) => $tr->getValueChunked('chunk/missing'),
        ));
    }

    #[Test]
    public function multiChunkValueAbovePerValueLimitRoundTrips(): void
    {
        $db = $this->getDatabase();

        // ~600 kB: six chunks at the default chunk size (100 kB)
        $value = random_bytes(600000);

        $db->setValueChunked('chunk/multi', $value);

        self::assertSame($value, $db->transact(
            fn ($tr) => $tr->getValueChunked('chunk/multi'),
        ));
    }

    #[Test]
    public function customChunkSizeAndValueSpanningChunkBoundary(): void
    {
        $db = $this->getDatabase();

        // Exactly 3 chunks of 128 bytes (chunk boundary edge: n * chunkSize)
        $value = random_bytes(3 * 128);

        $db->setValueChunked('chunk/boundary', $value, 128);

        self::assertSame($value, $db->transact(
            fn ($tr) => $tr->getValueChunked('chunk/boundary'),
        ));
    }

    #[Test]
    public function chunkedReadWorksOnSnapshot(): void
    {
        $db = $this->getDatabase();

        $db->setValueChunked('chunk/snap', 'snapshot value');

        $read = $db->readTransact(fn ($snapshot) => $snapshot->getValueChunked('chunk/snap'));

        self::assertSame('snapshot value', $read);
    }

    #[Test]
    public function keyConvertibleKeysAreSupported(): void
    {
        $db = $this->getDatabase();
        $subspace = new Subspace([], 'chunk_subspace/');

        $db->setValueChunked($subspace->pack(['blob', 1]), str_repeat('s', 250000));

        self::assertSame(250000, strlen((string) $db->transact(
            fn ($tr) => $tr->getValueChunked($subspace->pack(['blob', 1])),
        )));
    }

    // -- overwrite / stale tail ---------------------------------------------------

    #[Test]
    public function overwriteFromLargerToSmallerLeavesNoStaleTail(): void
    {
        $db = $this->getDatabase();

        $large = str_repeat('A', 250000);
        $small = str_repeat('B', 150000);

        $db->setValueChunked('chunk/shrink', $large);
        $db->setValueChunked('chunk/shrink', $small);

        self::assertSame($small, $db->transact(
            fn ($tr) => $tr->getValueChunked('chunk/shrink'),
        ));

        // No leftovers: exactly ceil(150000/100000) = 2 chunks + meta
        $count = $db->transact(
            fn ($tr): int => count($tr->getRangeAll(
                ChunkKeyCodec::namespaceBegin('chunk/shrink'),
                ChunkKeyCodec::namespaceEnd('chunk/shrink'),
            )),
        );
        self::assertSame(3, $count); // meta + 2 chunks
    }

    #[Test]
    public function deleteValueChunkedRemovesMetaAndAllChunks(): void
    {
        $db = $this->getDatabase();

        $db->setValueChunked('chunk/gone', str_repeat('x', 300000));
        $db->deleteValueChunked('chunk/gone');

        self::assertNull($db->transact(fn ($tr) => $tr->getValueChunked('chunk/gone')));

        $count = $db->transact(
            fn ($tr): int => count($tr->getRangeAll(
                ChunkKeyCodec::namespaceBegin('chunk/gone'),
                ChunkKeyCodec::namespaceEnd('chunk/gone'),
            )),
        );
        self::assertSame(0, $count);
    }

    // -- atomic cap ----------------------------------------------------------------

    #[Test]
    public function atomicModeRejectsValueAboveCapBeforeAnyMutation(): void
    {
        $db = $this->getDatabase();

        $tooLarge = str_repeat('y', MutationBudget::SPLIT_TARGET_BYTES + 1);

        try {
            $db->transact(function ($tr) use ($tooLarge): void {
                $tr->setValueChunked('chunk/too-large', $tooLarge);
            });
            self::fail('Expected ChunkedValueTooLargeException');
        } catch (ChunkedValueTooLargeException $e) {
            self::assertSame(MutationBudget::SPLIT_TARGET_BYTES + 1, $e->valueSize);
            self::assertSame(MutationBudget::SPLIT_TARGET_BYTES, $e->maxSize);
        }

        self::assertNull($db->transact(fn ($tr) => $tr->getValueChunked('chunk/too-large')));
    }

    #[Test]
    public function valueExactlyAtCapIsAcceptedInAtomicMode(): void
    {
        $db = $this->getDatabase();

        $atCap = str_repeat('z', MutationBudget::SPLIT_TARGET_BYTES);

        $db->setValueChunked('chunk/at-cap', $atCap);

        $read = $db->transact(fn ($tr) => $tr->getValueChunked('chunk/at-cap'));
        self::assertNotNull($read);
        self::assertSame(MutationBudget::SPLIT_TARGET_BYTES, strlen($read));
    }

    #[Test]
    public function invalidChunkSizeIsRejected(): void
    {
        $db = $this->getDatabase();

        $this->expectException(\InvalidArgumentException::class);
        $db->setValueChunked('chunk/bad-size', 'v', 0);
    }

    #[Test]
    public function chunkKeysNearTheKeySizeLimitAreRejected(): void
    {
        $db = $this->getDatabase();

        // A base key 2 bytes below the limit: the metadata key (base + "\x00\x00",
        // exactly 10,000 bytes) still fits, but every chunk key carries the packed
        // [generation, index] tuple suffix and exceeds the 10,000-byte key limit.
        $baseKey = str_repeat('k', KeyValueLimits::MAX_KEY_SIZE - 2);
        $metaKey = ChunkKeyCodec::metaKey($baseKey);

        self::assertSame(KeyValueLimits::MAX_KEY_SIZE, strlen($metaKey));
        self::assertGreaterThan(
            KeyValueLimits::MAX_KEY_SIZE,
            strlen(ChunkKeyCodec::chunkKey($baseKey, 0, 0)),
        );

        try {
            $db->setValueChunked($baseKey, 'value');
            self::fail('Expected the oversized chunk key to be rejected');
        } catch (\InvalidArgumentException $e) {
            self::assertStringContainsString('key exceeds maximum size', $e->getMessage());
        }

        // The transaction was aborted by the exception — nothing was written,
        // and the database stays usable.
        self::assertNull($db->transact(fn ($tr) => $tr->getValueChunked($baseKey)));

        $db->set('chunk/key-limit/after', 'ok');
        self::assertSame('ok', $db->get('chunk/key-limit/after'));
    }

    // -- non-atomic (multi-transaction) mode ---------------------------------------

    #[Test]
    public function nonAtomicModeWritesValuesAboveTheAtomicCap(): void
    {
        $db = $this->getDatabase();

        // ~12 MB: above the atomic cap, only writable in non-atomic mode.
        $value = '';
        $segment = random_bytes(100000);
        for ($i = 0; $i < 120; ++$i) {
            $value .= $segment;
        }

        $db->setValueChunked('chunk/huge', $value, atomic: false);

        $read = $db->transact(fn ($tr) => $tr->getValueChunked('chunk/huge'));
        self::assertNotNull($read);
        self::assertSame(strlen($value), strlen($read));
        self::assertSame($value, $read);
    }

    #[Test]
    public function nonAtomicOverwriteSwapsGenerationAndCleansUp(): void
    {
        $db = $this->getDatabase();

        $db->setValueChunked('chunk/gen', str_repeat('a', 300000), atomic: false);
        $db->setValueChunked('chunk/gen', str_repeat('b', 50000), atomic: false);

        $read = $db->transact(fn ($tr) => $tr->getValueChunked('chunk/gen'));
        self::assertNotNull($read);
        self::assertSame(50000, strlen($read));
        self::assertMatchesRegularExpression('/^b+$/', $read);

        // Old generation must be gone: exactly meta + ceil(50000/100000) = 1 chunk
        $count = $db->transact(
            fn ($tr): int => count($tr->getRangeAll(
                ChunkKeyCodec::namespaceBegin('chunk/gen'),
                ChunkKeyCodec::namespaceEnd('chunk/gen'),
            )),
        );
        self::assertSame(2, $count);
    }

    // -- corruption detection --------------------------------------------------------

    #[Test]
    public function foreignDataUnderMetaKeyIsDetected(): void
    {
        $db = $this->getDatabase();

        // Someone writes raw bytes into the meta slot
        $db->set(ChunkKeyCodec::metaKey('chunk/foreign'), 'not-a-meta-record');

        $this->expectException(ChunkedValueCorruptedException::class);
        $db->transact(fn ($tr) => $tr->getValueChunked('chunk/foreign'));
    }

    #[Test]
    public function missingChunksAreDetected(): void
    {
        $db = $this->getDatabase();

        $db->setValueChunked('chunk/broken', str_repeat('c', 300000));

        // Damage: remove the meta-record's declared chunks but keep the meta
        $db->transact(function ($tr): void {
            $tr->deleteValueChunked('chunk/broken');
            $tr->set(
                ChunkKeyCodec::metaKey('chunk/broken'),
                ChunkKeyCodec::packMeta(300000, 3, 0),
            );
        });

        $this->expectException(ChunkedValueCorruptedException::class);
        $this->expectExceptionMessage('declares 3 chunks');
        $db->transact(fn ($tr) => $tr->getValueChunked('chunk/broken'));
    }
}
