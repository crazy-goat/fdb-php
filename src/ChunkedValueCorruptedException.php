<?php

declare(strict_types=1);

namespace CrazyGoat\FoundationDB;

/**
 * Thrown by `getValueChunked()` when the chunk namespace of a key exists but
 * cannot be interpreted as a chunked value: the metadata record is missing its
 * magic signature or has an unexpected length, or the stored chunk count /
 * total length do not match what the chunk range actually contains.
 *
 * This signals data written or overwritten by something other than
 * `setValueChunked()` (or real corruption) — it is deliberately a loud
 * exception, not a silent `null`/short read. A *missing* key is not an error:
 * `getValueChunked()` returns `null` in that case.
 */
final class ChunkedValueCorruptedException extends \RuntimeException
{
}
