<?php

declare(strict_types=1);

namespace CrazyGoat\FoundationDB\Directory;

use CrazyGoat\FoundationDB\Subspace;
use CrazyGoat\FoundationDB\Transactor;

final class DirectoryPartition extends DirectorySubspace
{
    private readonly DirectoryLayer $internalDirectoryLayer;

    /**
     * @param list<string> $path
     */
    public function __construct(
        string $rawPrefix,
        array $path,
        string $layer,
        DirectoryLayer $parentDirectoryLayer,
    ) {
        parent::__construct($rawPrefix, $path, $layer, $parentDirectoryLayer);

        $this->internalDirectoryLayer = new DirectoryLayer(
            new Subspace(rawPrefix: $rawPrefix . "\xFE"),
            new Subspace(rawPrefix: $rawPrefix),
        );
    }

    public function key(): string
    {
        throw new \LogicException('Cannot use a directory partition as a subspace.');
    }

    /**
     * @param list<mixed> $tuple
     */
    public function pack(array $tuple = []): string
    {
        throw new \LogicException('Cannot use a directory partition as a subspace.');
    }

    /**
     * @return list<mixed>
     */
    public function unpack(string $key): array
    {
        throw new \LogicException('Cannot use a directory partition as a subspace.');
    }

    /**
     * @param list<mixed> $tuple
     * @return array{string, string}
     */
    public function range(array $tuple = []): array
    {
        throw new \LogicException('Cannot use a directory partition as a subspace.');
    }

    public function contains(string $key): bool
    {
        throw new \LogicException('Cannot use a directory partition as a subspace.');
    }

    public function asFoundationDbKey(): string
    {
        throw new \LogicException('Cannot use a directory partition as a subspace.');
    }

    /**
     * @param list<string> $subPath
     */
    public function createOrOpen(Transactor $dbOrTr, array $subPath, string $layer = ''): DirectorySubspace
    {
        return $this->internalDirectoryLayer->createOrOpen($dbOrTr, $subPath, $layer);
    }

    /**
     * @param list<string> $subPath
     */
    public function create(
        Transactor $dbOrTr,
        array $subPath,
        string $layer = '',
        ?string $prefix = null,
    ): DirectorySubspace {
        return $this->internalDirectoryLayer->create($dbOrTr, $subPath, $layer, $prefix);
    }

    /**
     * @param list<string> $subPath
     */
    public function open(Transactor $dbOrTr, array $subPath, string $layer = ''): DirectorySubspace
    {
        return $this->internalDirectoryLayer->open($dbOrTr, $subPath, $layer);
    }

    /**
     * @param list<string> $oldSubPath
     * @param list<string> $newSubPath
     */
    public function move(Transactor $dbOrTr, array $oldSubPath, array $newSubPath): DirectorySubspace
    {
        return $this->internalDirectoryLayer->move($dbOrTr, $oldSubPath, $newSubPath);
    }

    /**
     * @param list<string> $subPath
     */
    public function remove(Transactor $dbOrTr, array $subPath = []): bool
    {
        return $this->internalDirectoryLayer->remove($dbOrTr, $subPath);
    }

    /**
     * @param list<string> $subPath
     */
    public function removeIfExists(Transactor $dbOrTr, array $subPath = []): bool
    {
        return $this->internalDirectoryLayer->removeIfExists($dbOrTr, $subPath);
    }

    /**
     * @param list<string> $subPath
     * @return list<string>
     */
    public function listSubdirectories(Transactor $dbOrTr, array $subPath = []): array
    {
        return $this->internalDirectoryLayer->list($dbOrTr, $subPath);
    }

    /**
     * @param list<string> $subPath
     */
    public function exists(Transactor $dbOrTr, array $subPath = []): bool
    {
        return $this->internalDirectoryLayer->exists($dbOrTr, $subPath);
    }
}
