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
     * @param list<string> $oldPath
     * @param list<string> $newPath
     */
    public function move(Transactor $dbOrTr, array $oldPath, array $newPath): DirectorySubspace
    {
        $this->validatePath($oldPath);
        $this->validatePath($newPath);

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

            $lastName = $newPath[count($newPath) - 1];
            $subdirsNode = $newParentNode->subspace(self::SUBDIRS);
            $tr->set($subdirsNode->pack([$lastName]), $oldPrefix);

            $oldParentPath = array_slice($oldPath, 0, -1);
            $oldParentNode = $oldParentPath !== []
                ? $this->find($tr, $oldParentPath)
                : $this->rootNode;

            if ($oldParentNode instanceof \CrazyGoat\FoundationDB\Subspace) {
                $oldLastName = $oldPath[count($oldPath) - 1];
                $oldSubdirsNode = $oldParentNode->subspace(self::SUBDIRS);
                $tr->clear($oldSubdirsNode->pack([$oldLastName]));
            }

            $layer = $this->getNodeLayer($tr, $oldNode);

            return $this->contentsOfNode(
                $this->nodeWithPrefix($oldPrefix),
                $newPath,
                $layer,
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
            $this->contentSubspace->key() . $prefix,
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
            $this->contentSubspace->key() . $prefix,
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

        $tr->clearRangeStartsWith($this->contentSubspace->key() . $prefix);
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
     * @template T
     * @param callable(Transaction): T $fn
     * @return T
     */
    private function runInTransaction(Transactor $dbOrTr, callable $fn): mixed
    {
        return $dbOrTr->transact($fn);
    }
}
