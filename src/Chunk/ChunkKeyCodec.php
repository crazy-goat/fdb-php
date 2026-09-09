<?php

declare(strict_types=1);

namespace CrazyGoat\FoundationDB\Chunk;

use CrazyGoat\FoundationDB\Tuple\Tuple;

/**
 * Key-space codec for chunked values (issue #115).
 *
 * A chunked value stored under a base key `$key` lives in a dedicated
 * `\x00`-namespaced sub-key space:
 *
 * - metadata key:  `$key . "\x00" . "\x00"` (sorts before every chunk key)
 * - chunk keys:    `$key . "\x00" . Tuple::pack([$generation, $index])`
 * - whole space:   `[$key . "\x00", $key . "\x01")`
 *
 * The leading `\x00` byte sorts before every tuple type byte (null is
 * `0x00`; ints start at `0x0C`, strings and all other types later), so the
 * namespace can never collide with user text suffixes (e.g. `$key . "abc"`)
 * and the metadata key always sorts before all chunks. The metadata key and
 * the packed tuple suffixes are unreachable through the library's public
 * Tuple / Subspace / Directory API — mixing plain `set()` and
 * `setValueChunked()` on the same base key is documented as unsupported.
 *
 * Metadata record layout (22 bytes):
 *
 * ```
 * "FDBCK1" (6 B magic) | total_length (8 B big-endian)
 *                      | chunk_count (4 B) | generation (4 B)
 * ```
 *
 * The magic header makes foreign or tampered data detectable: a read
 * mismatching the magic raises `ChunkedValueCorruptedException` instead of
 * silently returning garbage. The `generation` field is the active-generation
 * pointer used by the non-atomic (multi-transaction) write mode: new chunks
 * are written under `generation + 1` across several transactions and the
 * final micro-transaction atomically swaps the metadata — readers see either
 * the whole old or the whole new value.
 *
 * This codec is `@internal`: it is private to the chunked layer. Batch
 * helpers (#116) share `MutationBudget` byte accounting only and must never
 * touch the `\x00` namespace.
 *
 * @internal
 */
final class ChunkKeyCodec
{
    /** Namespace separator: sorts before every tuple type byte. */
    private const SEPARATOR = "\x00";

    /** Magic signature stored as the first 6 bytes of the metadata record. */
    private const META_MAGIC = 'FDBCK1';

    /** Total metadata record length: magic(6) + length(8) + count(4) + generation(4). */
    public const META_LENGTH = 22;

    /** First byte of the chunk key space (exclusive upper bound of the namespace). */
    public const NAMESPACE_END = "\x01";

    private function __construct()
    {
    }

    public static function metaKey(string $baseKey): string
    {
        return $baseKey . self::SEPARATOR . self::SEPARATOR;
    }

    public static function chunkKey(string $baseKey, int $generation, int $index): string
    {
        return $baseKey . self::SEPARATOR . Tuple::pack([$generation, $index]);
    }

    /**
     * First key of the whole chunk namespace for a base key (inclusive lower
     * bound for range operations).
     */
    public static function namespaceBegin(string $baseKey): string
    {
        return $baseKey . self::SEPARATOR;
    }

    /**
     * One-past-the-end of the whole chunk namespace (exclusive upper bound).
     */
    public static function namespaceEnd(string $baseKey): string
    {
        return $baseKey . self::NAMESPACE_END;
    }

    /**
     * Inclusive lower bound of the range holding generation `$generation`'s
     * chunks — the first byte boundary whose keys all start with the packed
     * `[generation]` prefix.
     */
    public static function generationBegin(string $baseKey, int $generation): string
    {
        return $baseKey . self::SEPARATOR . Tuple::pack([$generation]);
    }

    /**
     * Exclusive upper bound of generation `$generation`'s chunk range.
     */
    public static function generationEnd(string $baseKey, int $generation): string
    {
        return $baseKey . self::SEPARATOR . Tuple::pack([$generation + 1]);
    }

    /**
     * Exclusive upper bound for clearing every generation strictly below
     * `$generation` (including the metadata key): chunks of the active
     * generation start exactly at this boundary, so they are never removed.
     */
    public static function clearBelowGenerationEnd(string $baseKey, int $generation): string
    {
        return $baseKey . self::SEPARATOR . Tuple::pack([$generation, 0]);
    }

    /**
     * @return string the 22-byte metadata record
     */
    public static function packMeta(int $totalLength, int $chunkCount, int $generation): string
    {
        return pack('a6JNN', self::META_MAGIC, $totalLength, $chunkCount, $generation);
    }

    /**
     * Parse a metadata record into `['length' => int, 'count' => int, 'generation' => int]`.
     *
     * @return array{length: int, count: int, generation: int}
     *
     * @throws \CrazyGoat\FoundationDB\ChunkedValueCorruptedException on a
     *         malformed record (wrong length or magic)
     */
    public static function unpackMeta(string $meta): array
    {
        if (strlen($meta) !== self::META_LENGTH) {
            throw new \CrazyGoat\FoundationDB\ChunkedValueCorruptedException(
                sprintf(
                    'Chunked-value metadata record has unexpected length %d (expected %d)',
                    strlen($meta),
                    self::META_LENGTH,
                ),
            );
        }

        $unpacked = unpack('a6magic/Jlength/Ncount/Ngeneration', $meta);

        if ($unpacked === false || $unpacked['magic'] !== self::META_MAGIC) {
            throw new \CrazyGoat\FoundationDB\ChunkedValueCorruptedException(
                'Chunked-value metadata record has an unknown magic signature — the key was '
                . 'probably written or overwritten by something other than setValueChunked()',
            );
        }

        return [
            'length' => $unpacked['length'],
            'count' => $unpacked['count'],
            'generation' => $unpacked['generation'],
        ];
    }
}
