<?php

declare(strict_types=1);

namespace CrazyGoat\FoundationDB\Directory;

use CrazyGoat\FoundationDB\Subspace;
use CrazyGoat\FoundationDB\Transactor;

class DirectorySubspace extends Subspace
{
    /**
     * @param list<string> $path
     */
    public function __construct(
        string $rawPrefix,
        private readonly array $path,
        private readonly string $layer,
        private readonly DirectoryLayer $directoryLayer,
    ) {
        parent::__construct(rawPrefix: $rawPrefix);
    }

    /**
     * @return list<string>
     */
    public function getPath(): array
    {
        return $this->path;
    }

    public function getLayer(): string
    {
        return $this->layer;
    }

    /**
     * @param list<string> $subPath
     */
    public function createOrOpen(Transactor $dbOrTr, array $subPath, string $layer = ''): self
    {
        return $this->directoryLayer->createOrOpen($dbOrTr, [...$this->path, ...$subPath], $layer);
    }

    /**
     * @param list<string> $subPath
     */
    public function create(
        Transactor $dbOrTr,
        array $subPath,
        string $layer = '',
        ?string $prefix = null,
    ): self {
        return $this->directoryLayer->create($dbOrTr, [...$this->path, ...$subPath], $layer, $prefix);
    }

    /**
     * @param list<string> $subPath
     */
    public function open(Transactor $dbOrTr, array $subPath, string $layer = ''): self
    {
        return $this->directoryLayer->open($dbOrTr, [...$this->path, ...$subPath], $layer);
    }

    /**
     * @param list<string> $oldSubPath
     * @param list<string> $newSubPath
     */
    public function move(Transactor $dbOrTr, array $oldSubPath, array $newSubPath): self
    {
        return $this->directoryLayer->move(
            $dbOrTr,
            [...$this->path, ...$oldSubPath],
            [...$this->path, ...$newSubPath],
        );
    }

    /**
     * @param list<string> $newAbsolutePath
     */
    public function moveTo(Transactor $dbOrTr, array $newAbsolutePath): self
    {
        return $this->directoryLayer->move($dbOrTr, $this->path, $newAbsolutePath);
    }

    /**
     * @param list<string> $subPath
     */
    public function remove(Transactor $dbOrTr, array $subPath = []): bool
    {
        return $this->directoryLayer->remove($dbOrTr, [...$this->path, ...$subPath]);
    }

    /**
     * @param list<string> $subPath
     */
    public function removeIfExists(Transactor $dbOrTr, array $subPath = []): bool
    {
        return $this->directoryLayer->removeIfExists($dbOrTr, [...$this->path, ...$subPath]);
    }

    /**
     * @param list<string> $subPath
     * @return list<string>
     */
    public function listSubdirectories(Transactor $dbOrTr, array $subPath = []): array
    {
        return $this->directoryLayer->list($dbOrTr, [...$this->path, ...$subPath]);
    }

    /**
     * @param list<string> $subPath
     */
    public function exists(Transactor $dbOrTr, array $subPath = []): bool
    {
        return $this->directoryLayer->exists($dbOrTr, [...$this->path, ...$subPath]);
    }

    protected function getDirectoryLayer(): DirectoryLayer
    {
        return $this->directoryLayer;
    }
}
