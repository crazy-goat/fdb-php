<?php

declare(strict_types=1);

namespace CrazyGoat\FoundationDB\Tests\Unit;

use CrazyGoat\FoundationDB\Chunk\ChunkKeyCodec;
use CrazyGoat\FoundationDB\ChunkedValueCorruptedException;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Pure unit tests for the chunked-values key-space codec (issue #115).
 * Verifies the storage-format invariants without a FoundationDB cluster:
 * namespace ordering, generation range boundaries (including the tuple
 * int-encoding size change at 255 → 256) and the metadata record codec.
 * End-to-end behaviour is covered by tests/Integration/ChunkedValuesTest.php.
 */
final class ChunkKeyCodecTest extends TestCase
{
    private const BASE = 'users/alice/blob';

    // -- namespace layout -------------------------------------------------------

    #[Test]
    public function metaKeySortsBeforeAllChunkKeys(): void
    {
        $meta = ChunkKeyCodec::metaKey(self::BASE);
        $firstChunk = ChunkKeyCodec::chunkKey(self::BASE, 0, 0);

        self::assertStringStartsWith(self::BASE . "\x00", $meta);
        self::assertGreaterThan($meta, $firstChunk);
        // ... and before chunk keys of any generation
        self::assertGreaterThan($meta, ChunkKeyCodec::chunkKey(self::BASE, 255, 7));
        self::assertGreaterThan($meta, ChunkKeyCodec::chunkKey(self::BASE, 256, 7));
    }

    #[Test]
    public function namespaceBoundsCoverAllChunkKeys(): void
    {
        $begin = ChunkKeyCodec::namespaceBegin(self::BASE);
        $end = ChunkKeyCodec::namespaceEnd(self::BASE);

        foreach ([[0, 0], [0, 42], [254, 0], [255, 0], [256, 1000]] as [$gen, $index]) {
            $chunk = ChunkKeyCodec::chunkKey(self::BASE, $gen, $index);
            self::assertGreaterThanOrEqual($begin, $chunk);
            self::assertLessThan($end, $chunk, "generation $gen index $index must be inside the namespace");
        }

        // A plain value under the base key and user text suffixes stay outside
        self::assertLessThan($begin, self::BASE);
        self::assertGreaterThan($end, self::BASE . 'abc');
    }

    #[Test]
    public function chunkKeysSortByGenerationThenIndex(): void
    {
        self::assertGreaterThan(
            ChunkKeyCodec::chunkKey(self::BASE, 0, 1),
            ChunkKeyCodec::chunkKey(self::BASE, 0, 2),
        );
        self::assertGreaterThan(
            ChunkKeyCodec::chunkKey(self::BASE, 0, 999),
            ChunkKeyCodec::chunkKey(self::BASE, 1, 0),
        );
    }

    #[Test]
    public function generationBoundariesSpanExactlyOneGeneration(): void
    {
        foreach ([0, 1, 42, 254, 255, 256] as $generation) {
            $begin = ChunkKeyCodec::generationBegin(self::BASE, $generation);
            $end = ChunkKeyCodec::generationEnd(self::BASE, $generation);

            $firstChunk = ChunkKeyCodec::chunkKey(self::BASE, $generation, 0);
            $lastChunk = ChunkKeyCodec::chunkKey(self::BASE, $generation, 100000);

            // begin sorts strictly below the generation's first chunk
            self::assertGreaterThan($begin, $firstChunk, "begin must sort below gen $generation chunks");
            // end sorts strictly above every chunk of the generation
            self::assertLessThan($end, $firstChunk, "end must be above gen $generation first chunk");
            self::assertLessThan($end, $lastChunk, "end must be above gen $generation last chunk");

            // Chunks of the neighbouring generations fall outside the range
            if ($generation > 0) {
                self::assertLessThanOrEqual($begin, ChunkKeyCodec::chunkKey(self::BASE, $generation - 1, 0));
            }
            self::assertGreaterThanOrEqual($end, ChunkKeyCodec::chunkKey(self::BASE, $generation + 1, 0));
        }
    }

    #[Test]
    public function clearBelowGenerationEndExcludesActiveGeneration(): void
    {
        $boundary = ChunkKeyCodec::clearBelowGenerationEnd(self::BASE, 5);

        // Everything strictly below generation 5 — meta and earlier chunks —
        // sorts before the boundary...
        self::assertLessThan($boundary, ChunkKeyCodec::metaKey(self::BASE));
        self::assertLessThan($boundary, ChunkKeyCodec::chunkKey(self::BASE, 4, 999999));

        // ...while the boundary IS the active generation's first chunk key
        // (pack([5, 0])), so an exclusive-end clearRange keeps it.
        self::assertSame(ChunkKeyCodec::chunkKey(self::BASE, 5, 0), $boundary);
        self::assertGreaterThan($boundary, ChunkKeyCodec::chunkKey(self::BASE, 5, 1));
    }

    // -- metadata record --------------------------------------------------------

    #[Test]
    public function metaRecordRoundTrips(): void
    {
        $meta = ChunkKeyCodec::unpackMeta(ChunkKeyCodec::packMeta(1234567890, 42, 7));

        self::assertSame(1234567890, $meta['length']);
        self::assertSame(42, $meta['count']);
        self::assertSame(7, $meta['generation']);
    }

    #[Test]
    public function metaRecordRejectsWrongLength(): void
    {
        $this->expectException(ChunkedValueCorruptedException::class);
        $this->expectExceptionMessage('unexpected length');

        ChunkKeyCodec::unpackMeta('too-short');
    }

    #[Test]
    public function metaRecordRejectsWrongMagic(): void
    {
        $meta = ChunkKeyCodec::packMeta(10, 1, 0);
        $tampered = 'XXXXXX' . substr($meta, 6);

        $this->expectException(ChunkedValueCorruptedException::class);
        $this->expectExceptionMessage('magic');

        ChunkKeyCodec::unpackMeta($tampered);
    }
}
