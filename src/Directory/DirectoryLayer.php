<?php

declare(strict_types=1);

namespace CrazyGoat\FoundationDB\Directory;

use CrazyGoat\FoundationDB\Subspace;
use CrazyGoat\FoundationDB\Transaction;
use CrazyGoat\FoundationDB\Transactor;
use CrazyGoat\FoundationDB\Tuple\Tuple;

final readonly class DirectoryLayer
{
    private const SUBDIRS = 0;
    private const VERSION = [1, 0, 0];
    private const LAYER_SUFFIX = 'layer';
    private const PARTITION_LAYER = 'partition';

    /**
     * Maximum depth (segment count) accepted for an `oldPath` or
     * `newPath` argument to {@see self::move()}.
     *
     * The FDB key-size limit (10,000 bytes) is the absolute outer bound;
     * 64 segment slots leaves generous headroom for any realistic
     * organisational layout. A bound here means a malformed caller input
     * cannot produce a directory entry whose path component-count
     * eventually exhausts prefix space silently on a hot path, and it
     * gives the assertion in {@see self::validateMoveBounds()} a
     * concrete, documented number rather than a magic one.
     */
    private const MAX_MOVE_PATH_DEPTH = 64;

    private Subspace $rootNode;

    private HighContentionAllocator $allocator;

    public function __construct(
        private Subspace $nodeSubspace = new Subspace(rawPrefix: "\xFE"),
        private Subspace $contentSubspace = new Subspace(),
    ) {
        $this->rootNode = $this->nodeSubspace->subspace($this->nodeSubspace->key());
        $this->allocator = new HighContentionAllocator(
            $this->rootNode->subspace('hca'),
        );
    }

    /**
     * @param list<string> $path
     */
    public function createOrOpen(Transactor $dbOrTr, array $path, string $layer = ''): DirectorySubspace
    {
        $this->validatePath($path);

        return $this->runInTransaction($dbOrTr, function (Transaction $tr) use ($path, $layer): DirectorySubspace {
            $this->checkVersion($tr);

            $existing = $this->find($tr, $path);

            if ($existing instanceof \CrazyGoat\FoundationDB\Subspace) {
                $existingLayer = $this->getNodeLayer($tr, $existing);

                if ($layer !== '' && $existingLayer !== $layer) {
                    throw new DirectoryException(
                        'Directory exists but with a different layer.',
                    );
                }

                return $this->contentsOfNode($existing, $path, $existingLayer);
            }

            return $this->createInternal($tr, $path, $layer);
        });
    }

    /**
     * @param list<string> $path
     */
    public function create(
        Transactor $dbOrTr,
        array $path,
        string $layer = '',
        ?string $prefix = null,
    ): DirectorySubspace {
        $this->validatePath($path);

        return $this->runInTransaction(
            $dbOrTr,
            function (Transaction $tr) use ($path, $layer, $prefix): DirectorySubspace {
                $this->checkVersion($tr);

                $existing = $this->find($tr, $path);

                if ($existing instanceof \CrazyGoat\FoundationDB\Subspace) {
                    throw new DirectoryException('Directory already exists.');
                }

                return $this->createInternal($tr, $path, $layer, $prefix);
            },
        );
    }

    /**
     * @param list<string> $path
     */
    public function open(Transactor $dbOrTr, array $path, string $layer = ''): DirectorySubspace
    {
        $this->validatePath($path);

        return $this->runInTransaction($dbOrTr, function (Transaction $tr) use ($path, $layer): DirectorySubspace {
            $this->checkVersion($tr);

            $existing = $this->find($tr, $path);

            if (!$existing instanceof \CrazyGoat\FoundationDB\Subspace) {
                throw new DirectoryException('Directory does not exist.');
            }

            $existingLayer = $this->getNodeLayer($tr, $existing);

            if ($layer !== '' && $existingLayer !== $layer) {
                throw new DirectoryException(
                    'Directory exists but with a different layer.',
                );
            }

            return $this->contentsOfNode($existing, $path, $existingLayer);
        });
    }

    /**
     * Move a directory and all of its contents (subdirectories and content)
     * from one path to another within the same `DirectoryLayer`.
     *
     * ## Input validation contract (fix for #40)
     *
     * `move()` enforces the canonical FoundationDB directory-layer move
     * rules at the PHP trust boundary and throws
     * `CrazyGoat\FoundationDB\Directory\DirectoryException` on any
     * violation, instead of silently writing the move into the subdirs
     * index and producing an inconsistent / cyclic subtree:
     *
     *  - `newPath` does not begin with `oldPath` as a prefix — otherwise
     *    `newPath` is a descendant of `oldPath`, so moving `oldPath`
     *    under itself creates a cycle in the directory index and leaves
     *    an unreachable subtree.
     *  - `oldPath` !== `newPath` — a "move" to the same path is rejected
     *    explicitly to avoid a no-op that still rewrites index entries
     *    and confuses readers.
     *  - the immediate parents of `oldPath` and `newPath` carry the same
     *    partition layer — moving a directory between two different
     *    partition boundaries (or between partition and top-level) is
     *    rejected so application data cannot silently land in a sibling
     *    partition's prefix space. The check is on the *parents*, not on
     *    `oldPath`'s own `layer` attribute, because a child born inside a
     *    partition carries its parent's partition forward regardless of
     *    its own layer string.
     *  - source exists (already enforced; surfaced with the source-
     *    not-found exception).
     *  - destination does not exist (already enforced; surfaced with the
     *    destination-exists exception).
     *  - destination parent exists (already enforced; surfaced with the
     *    parent-not-found exception).
     *
     * Successful moves return the `DirectorySubspace` resolved at
     * `newPath` (with the original prefix of `oldPath` re-bound).
     *
     * @param list<string> $oldPath Source directory path (must exist, must
     *                               not be empty).
     * @param list<string> $newPath Destination directory path (must not
     *                               exist; must not be empty; must not be
     *                               inside `oldPath`'s subtree; must not
     *                               equal `oldPath`; must share the same
     *                               immediate-parent partition layer as
     *                               `oldPath`).
     *
     * @return DirectorySubspace The directory subspace at `$newPath`,
     *                            re-bound to the source's prefix.
     *
     * @throws DirectoryException If `oldPath` does not exist, if
     *                            `newPath` already exists, if the new
     *                            parent does not exist, or if any of the
     *                            input-validation contract rules above is
     *                            violated.
     */
    public function move(Transactor $dbOrTr, array $oldPath, array $newPath): DirectorySubspace
    {
        $this->validatePath($oldPath);
        $this->validatePath($newPath);
        $this->validateMoveBounds($oldPath, $newPath);

        return $this->runInTransaction($dbOrTr, function (Transaction $tr) use ($oldPath, $newPath): DirectorySubspace {
            $this->checkVersion($tr);

            $oldNode = $this->find($tr, $oldPath);

            if (!$oldNode instanceof \CrazyGoat\FoundationDB\Subspace) {
                throw new DirectoryException('Source directory does not exist.');
            }

            $newNode = $this->find($tr, $newPath);

            if ($newNode instanceof \CrazyGoat\FoundationDB\Subspace) {
                throw new DirectoryException('Destination directory already exists.');
            }

            $newParentPath = array_slice($newPath, 0, -1);

            if ($newParentPath !== []) {
                $newParent = $this->find($tr, $newParentPath);

                if (!$newParent instanceof \CrazyGoat\FoundationDB\Subspace) {
                    throw new DirectoryException('Parent of destination directory does not exist.');
                }
            }

            $oldPrefix = $this->getNodePrefix($oldNode);
            $newParentNode = $newParentPath !== []
                ? $this->find($tr, $newParentPath)
                : $this->rootNode;

            if (!$newParentNode instanceof \CrazyGoat\FoundationDB\Subspace) {
                throw new DirectoryException('Parent of destination directory does not exist.');
            }

            // Crossing a partition boundary is rejected: a directory
            // that lives under a partition node must not be silently
            // re-parented under a different parent (partition vs.
            // top-level, or partition-A vs. partition-B). The canonical
            // check is on the immediate parents' layers: the
            // partition-identity of `oldPath` is determined by its
            // immediate parent's layer, and similarly for `newPath` —
            // the source's *own* layer attribute does not identify its
            // partition membership on its own. The partition-identity
            // of the source IS encoded in the parent though (a child
            // born inside a partition carries the parent's partition
            // forward), so comparing the two immediate-parent layers
            // captures every shape the canonical FDB directory layer
            // refuses.
            $oldParentPath = array_slice($oldPath, 0, -1);
            $oldParentNode = $oldParentPath !== []
                ? $this->find($tr, $oldParentPath)
                : $this->rootNode;

            if (!$oldParentNode instanceof \CrazyGoat\FoundationDB\Subspace) {
                // Old parent is missing — surface the standard
                // "source-not-found" exception rather than a
                // partition-crossing one.
                throw new DirectoryException('Source directory does not exist.');
            }

            $oldLayer = $this->getNodeLayer($tr, $oldNode);
            $oldParentLayer = $this->getNodeLayer($tr, $oldParentNode);
            $newParentLayer = $this->getNodeLayer($tr, $newParentNode);

            $this->assertSamePartitionLayer($oldParentLayer, $newParentLayer, $oldPath, $newPath);

            $lastName = $newPath[count($newPath) - 1];
            $subdirsNode = $newParentNode->subspace(self::SUBDIRS);
            $tr->set($subdirsNode->pack([$lastName]), $oldPrefix);

            $oldLastName = $oldPath[count($oldPath) - 1];
            $oldSubdirsNode = $oldParentNode->subspace(self::SUBDIRS);
            $tr->clear($oldSubdirsNode->pack([$oldLastName]));

            return $this->contentsOfNode(
                $this->nodeWithPrefix($oldPrefix),
                $newPath,
                $oldLayer,
            );
        });
    }

    /**
     * @param list<string> $path
     */
    public function remove(Transactor $dbOrTr, array $path): bool
    {
        $this->validatePath($path);

        return $this->runInTransaction($dbOrTr, function (Transaction $tr) use ($path): bool {
            $this->checkVersion($tr);

            $node = $this->find($tr, $path);

            if (!$node instanceof \CrazyGoat\FoundationDB\Subspace) {
                throw new DirectoryException('Directory does not exist.');
            }

            $this->removeInternal($tr, $node);
            $this->removeFromParent($tr, $path);

            return true;
        });
    }

    /**
     * @param list<string> $path
     */
    public function removeIfExists(Transactor $dbOrTr, array $path): bool
    {
        $this->validatePath($path);

        return $this->runInTransaction($dbOrTr, function (Transaction $tr) use ($path): bool {
            $this->checkVersion($tr);

            $node = $this->find($tr, $path);

            if (!$node instanceof \CrazyGoat\FoundationDB\Subspace) {
                return false;
            }

            $this->removeInternal($tr, $node);
            $this->removeFromParent($tr, $path);

            return true;
        });
    }

    /**
     * @param list<string> $path
     * @return list<string>
     */
    public function list(Transactor $dbOrTr, array $path = []): array
    {
        return $this->runInTransaction($dbOrTr, function (Transaction $tr) use ($path): array {
            $this->checkVersion($tr);

            $node = $path !== [] ? $this->find($tr, $path) : $this->rootNode;

            if (!$node instanceof \CrazyGoat\FoundationDB\Subspace) {
                throw new DirectoryException('Directory does not exist.');
            }

            $subdirsNode = $node->subspace(self::SUBDIRS);
            $results = $tr->getRangeStartsWith($subdirsNode->key())->toArray();

            $names = [];
            foreach ($results as $kv) {
                $decoded = $subdirsNode->unpack($kv->key);
                /** @var string $name */
                $name = $decoded[0];
                $names[] = $name;
            }

            return $names;
        });
    }

    /**
     * @param list<string> $path
     */
    public function exists(Transactor $dbOrTr, array $path): bool
    {
        $this->validatePath($path);

        return $this->runInTransaction($dbOrTr, function (Transaction $tr) use ($path): bool {
            $this->checkVersion($tr);

            return $this->find($tr, $path) instanceof \CrazyGoat\FoundationDB\Subspace;
        });
    }

    /**
     * @param list<string> $path
     */
    private function find(Transaction $tr, array $path): ?Subspace
    {
        $node = $this->rootNode;

        foreach ($path as $name) {
            $subdirsNode = $node->subspace(self::SUBDIRS);
            $prefixValue = $tr->get($subdirsNode->pack([$name]))->await();

            if ($prefixValue === null) {
                return null;
            }

            $node = $this->nodeWithPrefix($prefixValue);
        }

        return $node;
    }

    private function nodeWithPrefix(string $prefix): Subspace
    {
        return $this->nodeSubspace->subspace($prefix);
    }

    /**
     * @param list<string> $path
     */
    private function contentsOfNode(Subspace $node, array $path, string $layer): DirectorySubspace
    {
        $prefix = $this->getNodePrefix($node);

        if ($layer === self::PARTITION_LAYER) {
            return new DirectoryPartition($prefix, $path, $layer, $this);
        }

        return new DirectorySubspace(
            $prefix,
            $path,
            $layer,
            $this,
        );
    }

    private function getNodePrefix(Subspace $node): string
    {
        $nodeKey = $node->key();
        $prefixLength = strlen($this->nodeSubspace->key());

        $packed = substr($nodeKey, $prefixLength);
        $decoded = Tuple::unpack($packed);

        if ($decoded === []) {
            return '';
        }

        /** @var string $prefix */
        $prefix = $decoded[0];

        return $prefix;
    }

    private function getNodeLayer(Transaction $tr, Subspace $node): string
    {
        $layerValue = $tr->get($node->pack([self::LAYER_SUFFIX]))->await();

        return $layerValue ?? '';
    }

    /**
     * @param list<string> $path
     */
    private function createInternal(
        Transaction $tr,
        array $path,
        string $layer,
        ?string $prefix = null,
    ): DirectorySubspace {
        if ($prefix === null) {
            $prefix = $this->allocator->allocate($tr);
            $prefix = $this->contentSubspace->key() . $prefix;

            if (!$this->isPrefixFree($tr, $prefix)) {
                throw new DirectoryException(
                    'Allocated prefix conflicts with existing directory metadata.',
                );
            }
        } else {
            // Caller-supplied prefix: validate length, node-metadata range
            // freedom, and content-range freedom before any write so a
            // conflicting explicit prefix cannot silently corrupt or
            // overwrite existing keys.
            $this->assertValidCallerSuppliedPrefix($tr, $prefix);
        }

        $parentPath = array_slice($path, 0, -1);
        $parentNode = $this->rootNode;

        if ($parentPath !== []) {
            $existingParent = $this->find($tr, $parentPath);
            $parentNode = $existingParent ?? $this->createParents($tr, $parentPath);
        }

        $lastName = $path[count($path) - 1];
        $subdirsNode = $parentNode->subspace(self::SUBDIRS);
        $tr->set($subdirsNode->pack([$lastName]), $prefix);

        $node = $this->nodeWithPrefix($prefix);

        if ($layer !== '') {
            $tr->set($node->pack([self::LAYER_SUFFIX]), $layer);
        }

        if ($layer === self::PARTITION_LAYER) {
            return new DirectoryPartition($prefix, $path, $layer, $this);
        }

        return new DirectorySubspace(
            $prefix,
            $path,
            $layer,
            $this,
        );
    }

    /**
     * @param list<string> $path
     */
    private function createParents(Transaction $tr, array $path): Subspace
    {
        $node = $this->rootNode;

        foreach ($path as $name) {
            $subdirsNode = $node->subspace(self::SUBDIRS);
            $prefixValue = $tr->get($subdirsNode->pack([$name]))->await();

            if ($prefixValue === null) {
                $newPrefix = $this->allocator->allocate($tr);
                $newPrefix = $this->contentSubspace->key() . $newPrefix;
                $tr->set($subdirsNode->pack([$name]), $newPrefix);
                $node = $this->nodeWithPrefix($newPrefix);
            } else {
                $node = $this->nodeWithPrefix($prefixValue);
            }
        }

        return $node;
    }

    private function removeInternal(Transaction $tr, Subspace $node): void
    {
        $subdirsNode = $node->subspace(self::SUBDIRS);
        $subdirs = $tr->getRangeStartsWith($subdirsNode->key())->toArray();

        foreach ($subdirs as $kv) {
            $childPrefix = $kv->value;
            $childNode = $this->nodeWithPrefix($childPrefix);
            $this->removeInternal($tr, $childNode);
        }

        $prefix = $this->getNodePrefix($node);

        $tr->clearRangeStartsWith($prefix);
        $tr->clearRangeStartsWith($node->key());
    }

    /**
     * @param list<string> $path
     */
    private function removeFromParent(Transaction $tr, array $path): void
    {
        $parentPath = array_slice($path, 0, -1);
        $parentNode = $parentPath !== []
            ? $this->find($tr, $parentPath)
            : $this->rootNode;

        if (!$parentNode instanceof \CrazyGoat\FoundationDB\Subspace) {
            return;
        }

        $lastName = $path[count($path) - 1];
        $subdirsNode = $parentNode->subspace(self::SUBDIRS);
        $tr->clear($subdirsNode->pack([$lastName]));
    }

    private function isPrefixFree(Transaction $tr, string $prefix): bool
    {
        $nodePrefix = $this->nodeSubspace->key() . $prefix;
        return $tr->getRangeStartsWith($nodePrefix)->toArray() === [];
    }

    /**
     * Validate a caller-supplied raw (NOT content-prefixed) prefix before it
     * enters the directory layer state. Throws DirectoryException with a
     * descriptive message if validation fails; the transaction is not
     * modified. The two probe callables receive the content-subspace-
     * prefixed key and return true if any key exists at that prefix.
     *
     * @param callable(string): bool $nodeProbe    true if directory-metadata
     *        keys already exist under the given key.
     * @param callable(string): bool $contentProbe true if content keys
     *        already exist under the given key.
     */
    private function validateRawPrefix(
        string $rawPrefix,
        string $contentSubspaceKey,
        callable $nodeProbe,
        callable $contentProbe,
    ): void {
        if ($rawPrefix === '') {
            throw new DirectoryException(
                'Caller-supplied prefix must not be empty.',
            );
        }

        $contentPrefixed = $contentSubspaceKey . $rawPrefix;

        if ($nodeProbe($contentPrefixed)) {
            throw new DirectoryException(
                sprintf(
                    'Caller-supplied prefix conflicts with existing directory metadata: %s.',
                    $this->printablePrefix($contentPrefixed),
                ),
            );
        }

        if ($contentProbe($contentPrefixed)) {
            throw new DirectoryException(
                sprintf(
                    'Caller-supplied prefix overlaps existing content keys: %s.',
                    $this->printablePrefix($contentPrefixed),
                ),
            );
        }
    }

    /**
     * Adapter that calls {@see self::validateRawPrefix()} against live
     * probes derived from a real FoundationDB transaction.
     */
    private function assertValidCallerSuppliedPrefix(
        Transaction $tr,
        string $prefix,
    ): void {
        $this->validateRawPrefix(
            $prefix,
            $this->contentSubspace->key(),
            // The node-metadata range query prepends the nodeSubspace->key()
            // on top of the (contentSubspace->key() + prefix) composition.
            function (string $key) use ($tr): bool {
                $nodeKey = $this->nodeSubspace->key() . $key;

                return $tr->getRangeStartsWith($nodeKey)->toArray() !== [];
            },
            fn(string $key): bool => $tr->getRangeStartsWith($key)->toArray() !== []
        );
    }

    private function printablePrefix(string $prefix): string
    {
        // Render non-printable bytes with \xHH so the error message is
        // diagnostic even when the prefix contains binary data.
        $out = '';
        $length = strlen($prefix);

        for ($i = 0; $i < $length; $i++) {
            $byte = ord($prefix[$i]);
            if ($byte >= 0x20 && $byte < 0x7F) {
                $out .= $prefix[$i];
            } else {
                $out .= sprintf('\x%02X', $byte);
            }
        }

        return $out;
    }

    private function checkVersion(Transaction $tr): void
    {
        $versionKey = $this->rootNode->pack(['version']);
        $versionValue = $tr->get($versionKey)->await();

        if ($versionValue === null) {
            $this->initializeDirectory($tr);

            return;
        }

        if (strlen($versionValue) < 12) {
            throw new DirectoryException('Cannot load directory with unknown version.');
        }

        $unpacked = unpack('V3', $versionValue);

        if ($unpacked === false) {
            throw new DirectoryException('Cannot load directory with unknown version.');
        }

        $major = $unpacked[1];
        $minor = $unpacked[2];

        if ($major > self::VERSION[0]) {
            throw new DirectoryException(
                sprintf(
                    'Cannot load directory with version %d.%d.%d (supported: %d.%d.%d).',
                    $major,
                    $minor,
                    $unpacked[3],
                    self::VERSION[0],
                    self::VERSION[1],
                    self::VERSION[2],
                ),
            );
        }
    }

    private function initializeDirectory(Transaction $tr): void
    {
        $versionKey = $this->rootNode->pack(['version']);
        $versionValue = pack('V3', self::VERSION[0], self::VERSION[1], self::VERSION[2]);
        $tr->set($versionKey, $versionValue);
    }

    /**
     * @param list<string> $path
     */
    private function validatePath(array $path): void
    {
        if ($path === []) {
            throw new DirectoryException('Path must not be empty.');
        }
    }

    /**
     * Enforce the path-level move constraints that do not require a
     * transaction (no FDB lookup, no transaction-bound `Transaction`):
     *
     *  - `newPath` must not begin with `oldPath` as a prefix
     *    ("a/b" → "a/b/c" rejected, "a/b" → "a" rejected because
     *     `array_slice($newPath, 0, count($oldPath)) === $oldPath`);
     *  - `oldPath` and `newPath` must not be identical
     *    (a "move to the same path" is a silent no-op);
     *  - the two paths must stay within the same depth bound
     *    (`MAX_MOVE_PATH_DEPTH`) so the resulting directory tree cannot
     *    be made arbitrarily deep by repeated moves that escape existing
     *    bounds.
     *
     * These three rules together reject every shape that the canonical
     * FoundationDB directory layer refuses and surface each as
     * `DirectoryException` with a printable rendering of both paths so
     * the call site can be identified from the message.
     *
     * @param list<string> $oldPath
     * @param list<string> $newPath
     *
     * @throws DirectoryException If `newPath` is inside `oldPath`'s
     *                            subtree, if the two paths are equal, or
     *                            if either exceeds `MAX_MOVE_PATH_DEPTH`.
     */
    private function validateMoveBounds(array $oldPath, array $newPath): void
    {
        if ($oldPath === $newPath) {
            throw new DirectoryException(sprintf(
                'move: source and destination paths are identical (%s).',
                $this->printablePath($oldPath),
            ));
        }

        if (count($oldPath) > self::MAX_MOVE_PATH_DEPTH) {
            throw new DirectoryException(sprintf(
                'move: source path exceeds maximum depth %d (got %d): %s',
                self::MAX_MOVE_PATH_DEPTH,
                count($oldPath),
                $this->printablePath($oldPath),
            ));
        }

        if (count($newPath) > self::MAX_MOVE_PATH_DEPTH) {
            throw new DirectoryException(sprintf(
                'move: destination path exceeds maximum depth %d (got %d): %s',
                self::MAX_MOVE_PATH_DEPTH,
                count($newPath),
                $this->printablePath($newPath),
            ));
        }

        $sameLengthOrLonger = count($newPath) >= count($oldPath);
        $newPathPrefixOfOld = $sameLengthOrLonger
            && array_slice($newPath, 0, count($oldPath)) === $oldPath;
        if ($newPathPrefixOfOld) {
            throw new DirectoryException(sprintf(
                'move: destination path %s is inside the source path\'s subtree %s '
                . '(would create a cycle in the directory index).',
                $this->printablePath($newPath),
                $this->printablePath($oldPath),
            ));
        }
    }

    /**
     * Reject moves that cross a partition boundary. The canonical FDB
     * directory layer does not allow a directory to be re-parented
     * from inside one partition (or outside any partition) into a
     * different partition or the top level; allowing it would re-bind
     * a prefix into a different partition's content space at the FDB
     * layer.
     *
     * The check is performed on the **immediate parents'** layers of
     * `oldPath` and `newPath`, because a directory's own `layer`
     * attribute does not identify its partition membership on its own
     * — a child born inside a partition carries the parent's partition
     * forward even though its own layer string is `""`. Comparing the
     * two immediate parents covers every shape the canonical Java
     * directory layer refuses (top-level → top-level, partition-A →
     * partition-A, partition → top, top → partition,
     * partition-A → partition-B).
     *
     * Both sides must either both carry the empty-string layer
     * (top-level) or both carry the same explicit layer string. The
     * empty-string sentinel matches itself as a valid counterpart.
     *
     * @param list<string> $oldPath
     * @param list<string> $newPath
     *
     * @throws DirectoryException If the move crosses a partition boundary.
     */
    private function assertSamePartitionLayer(
        string $oldParentLayer,
        string $newParentLayer,
        array $oldPath,
        array $newPath,
    ): void {
        // Both legs are top-level (non-partition), or both carry the same
        // explicit layer string — either layout is fine.
        if ($oldParentLayer === $newParentLayer) {
            return;
        }

        throw new DirectoryException(sprintf(
            'move: cannot move directory %s (parent layer "%s") into path %s '
            . 'whose parent layer is "%s" (partition crossings are disallowed).',
            $this->printablePath($oldPath),
            $oldParentLayer,
            $this->printablePath($newPath),
            $newParentLayer,
        ));
    }

    /**
     * Render a path safely for inclusion in an exception message. Each
     * segment is rendered via {@see self::printableSegment()} so control
     * bytes, DEL, and high bytes are escaped as `\xHH`. The segments are
     * joined with `"/"` to mirror the convention already used by the
     * AdminClient validator.
     *
     * @param list<string> $path
     */
    private function printablePath(array $path): string
    {
        $rendered = [];
        foreach ($path as $segment) {
            $rendered[] = $this->printableSegment($segment);
        }

        return implode('/', $rendered);
    }

    /**
     * Render a single path segment for an exception message. Bytes
     * below 0x20, DEL (0x7F), and high bytes (0x80–0xFF) are rendered as
     * `\xHH`; printable bytes (0x20–0x7E) are kept as-is. `/` is also
     * escaped because paths are joined with `"/"` and a literal slash
     * in a segment would be ambiguous in the rendered output.
     */
    private function printableSegment(string $value): string
    {
        $out = '';
        $length = strlen($value);
        for ($i = 0; $i < $length; $i++) {
            $byte = ord($value[$i]);
            if ($byte < 0x20 || $byte === 0x7F || $byte >= 0x80 || $byte === 0x2F) {
                $out .= sprintf('\\x%02X', $byte);
            } else {
                $out .= $value[$i];
            }
        }

        return $out;
    }

    /**
     * @template T
     * @param callable(Transaction): T $fn
     * @return T
     */
    private function runInTransaction(Transactor $dbOrTr, callable $fn): mixed
    {
        return $dbOrTr->transact($fn);
    }
}
