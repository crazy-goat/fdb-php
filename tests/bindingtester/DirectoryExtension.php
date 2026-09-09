<?php

declare(strict_types=1);

namespace CrazyGoat\FoundationDB\Tests\BindingTester;

use CrazyGoat\FoundationDB\Directory\DirectoryLayer;
use CrazyGoat\FoundationDB\Directory\DirectorySubspace;
use CrazyGoat\FoundationDB\Subspace;
use CrazyGoat\FoundationDB\Transaction;
use CrazyGoat\FoundationDB\Transactor;
use CrazyGoat\FoundationDB\Tuple\Bytes;
use CrazyGoat\FoundationDB\Tuple\Tuple;

/**
 * Directory-layer extension for the binding tester stack machine.
 *
 * Implements the additional instructions from the upstream
 * directoryLayerTester spec. Each stack machine owns one instance
 * (directory list, current directory index, error index).
 */
final class DirectoryExtension
{
    private const DEFAULT_NODE_SUBSPACE = "\xFE";

    /** @var list<DirectoryEntry|null> */
    private array $dirList;

    private int $dirIndex = 0;

    private int $errorIndex = 0;

    public function __construct()
    {
        $this->dirList = [
            DirectoryEntry::layer(
                new DirectoryLayer(new Subspace(rawPrefix: self::DEFAULT_NODE_SUBSPACE), new Subspace()),
                [],
                new Subspace(rawPrefix: self::DEFAULT_NODE_SUBSPACE),
                new Subspace(),
            ),
        ];
    }

    /**
     * @param list<mixed> $popped
     * @return list<string>
     */
    private static function toPath(array $popped): array
    {
        $path = [];
        foreach ($popped as $element) {
            $path[] = StackMachine::toRawString($element);
        }

        return $path;
    }

    public function processInstruction(
        StackMachine $machine,
        string $op,
        Transaction $transaction,
        int $instructionNumber,
    ): void {
        $stack = $machine->stack;

        try {
            $entry = $this->dirList[$this->dirIndex]
                ?? throw new \RuntimeException('Directory index out of range');

            switch ($op) {
                case 'DIRECTORY_CREATE_SUBSPACE':
                    $path = self::toPath($machine->popTuple($stack));
                    $rawPrefix = StackMachine::toRawString($machine->pop($stack));
                    $this->append(new DirectoryEntry(new Subspace($path, $rawPrefix)));
                    break;

                case 'DIRECTORY_CREATE_LAYER':
                    [$index1, $index2] = $machine->popValues($stack, 2);
                    $allowManualPrefixes = $machine->pop($stack);
                    $nodeEntry = $this->dirList[(int) $index1] ?? null;
                    $contentEntry = $this->dirList[(int) $index2] ?? null;

                    if ($nodeEntry === null || $contentEntry === null || $nodeEntry->nodeSubspace === null) {
                        $this->append(null);
                        break;
                    }

                    $nodeSubspace = $nodeEntry->nodeSubspace;
                    $contentSubspace = $contentEntry->contentSubspace ?? new Subspace();

                    $this->append(DirectoryEntry::layer(
                        new DirectoryLayer($nodeSubspace, $contentSubspace),
                        [],
                        $nodeSubspace,
                        $contentSubspace,
                    ));
                    unset($allowManualPrefixes);
                    break;

                case 'DIRECTORY_CHANGE':
                    $index = (int) $machine->pop($stack);
                    if (($this->dirList[$index] ?? null) === null) {
                        $index = $this->errorIndex;
                    }
                    $this->dirIndex = $index;
                    break;

                case 'DIRECTORY_SET_ERROR_INDEX':
                    $this->errorIndex = (int) $machine->pop($stack);
                    break;

                case 'DIRECTORY_CREATE_OR_OPEN':
                    $path = self::toPath($machine->popTuple($stack));
                    $layer = $machine->pop($stack);
                    $this->append(new DirectoryEntry(
                        $entry->requireDirectory()->createOrOpen(
                            $transaction,
                            $path,
                            $layer === null ? '' : StackMachine::toRawString($layer),
                        ),
                    ));
                    break;

                case 'DIRECTORY_CREATE':
                    $path = self::toPath($machine->popTuple($stack));
                    [$layer, $prefix] = $machine->popValues($stack, 2);
                    $this->append(new DirectoryEntry(
                        $entry->requireDirectory()->create(
                            $transaction,
                            $path,
                            $layer === null ? '' : StackMachine::toRawString($layer),
                            $prefix === null ? null : StackMachine::toRawString($prefix),
                        ),
                    ));
                    break;

                case 'DIRECTORY_OPEN':
                    $path = self::toPath($machine->popTuple($stack));
                    $layer = $machine->pop($stack);
                    $this->append(new DirectoryEntry(
                        $entry->requireDirectory()->open(
                            $transaction,
                            $path,
                            $layer === null ? '' : StackMachine::toRawString($layer),
                        ),
                    ));
                    break;

                case 'DIRECTORY_MOVE':
                    [$oldPopped, $newPopped] = $machine->popTuples($stack, 2);
                    $oldPath = self::toPath($oldPopped);
                    $newPath = self::toPath($newPopped);
                    $this->append(new DirectoryEntry(
                        $entry->requireDirectory()->move($transaction, $oldPath, $newPath),
                    ));
                    break;

                case 'DIRECTORY_MOVE_TO':
                    $newPath = self::toPath($machine->popTuple($stack));
                    $directory = $entry->requireDirectory();
                    if ($directory instanceof DirectorySubspace) {
                        $this->append(new DirectoryEntry($directory->moveTo($transaction, $newPath)));
                        break;
                    }

                    // Root-level layers cannot be moved with the current
                    // DirectoryLayer API (paths must not be empty).
                    $entry->getLayer()->move($transaction, $entry->getPath(), $newPath);
                    $this->append(new DirectoryEntry($entry->getLayer()));
                    break;

                case 'DIRECTORY_REMOVE':
                    $count = (int) $machine->pop($stack);
                    $directory = $entry->requireDirectory();
                    if ($count === 1) {
                        $path = self::toPath($machine->popTuple($stack));
                        $directory->remove($transaction, $path);
                        break;
                    }
                    $directory->remove($transaction, $entry->getPath());
                    break;

                case 'DIRECTORY_REMOVE_IF_EXISTS':
                    $count = (int) $machine->pop($stack);
                    $directory = $entry->requireDirectory();
                    if ($count === 1) {
                        $path = self::toPath($machine->popTuple($stack));
                        $directory->removeIfExists($transaction, $path);
                        break;
                    }
                    $directory->removeIfExists($transaction, $entry->getPath());
                    break;

                case 'DIRECTORY_LIST':
                    $count = (int) $machine->pop($stack);
                    $directory = $entry->requireDirectory();
                    $path = $count === 1 ? self::toPath($machine->popTuple($stack)) : $entry->getPath();
                    $machine->push($instructionNumber, StackMachine::packList($directory->list($transaction, $path)));
                    break;

                case 'DIRECTORY_EXISTS':
                    $count = (int) $machine->pop($stack);
                    $directory = $entry->requireDirectory();
                    $path = $count === 1 ? self::toPath($machine->popTuple($stack)) : $entry->getPath();
                    $machine->push($instructionNumber, $directory->exists($transaction, $path) ? 1 : 0);
                    break;

                case 'DIRECTORY_PACK_KEY':
                    $keyTuple = $machine->popTuple($stack);
                    $machine->push($instructionNumber, $entry->requireSubspace()->pack($keyTuple));
                    break;

                case 'DIRECTORY_UNPACK_KEY':
                    $key = StackMachine::toRawString($machine->pop($stack));
                    foreach ($entry->requireSubspace()->unpack($key) as $element) {
                        $machine->push($instructionNumber, $element);
                    }
                    break;

                case 'DIRECTORY_RANGE':
                    $tuple = $machine->popTuple($stack);
                    [$begin, $end] = $entry->requireSubspace()->range($tuple);
                    $machine->push($instructionNumber, new Bytes($begin));
                    $machine->push($instructionNumber, new Bytes($end));
                    break;

                case 'DIRECTORY_CONTAINS':
                    $key = StackMachine::toRawString($machine->pop($stack));
                    $machine->push($instructionNumber, $entry->requireSubspace()->contains($key) ? 1 : 0);
                    break;

                case 'DIRECTORY_OPEN_SUBSPACE':
                    $path = self::toPath($machine->popTuple($stack));
                    $this->append(new DirectoryEntry($entry->requireSubspace()->subspace($path)));
                    break;

                case 'DIRECTORY_LOG_SUBSPACE':
                    $prefix = StackMachine::toRawString($machine->pop($stack));
                    $key = $prefix . Tuple::pack([$this->dirIndex]);
                    $transaction->set($key, $entry->key());
                    break;

                case 'DIRECTORY_LOG_DIRECTORY':
                    $prefix = StackMachine::toRawString($machine->pop($stack));
                    $logSubspace = new Subspace([$this->dirIndex], $prefix);

                    $exists = $this->entryExists($entry, $transaction);
                    $children = [];
                    if ($exists) {
                        $children = $this->entryList($entry, $transaction);
                    }

                    $pathElements = array_map(
                        static fn (string $segment): Bytes => new Bytes($segment),
                        $entry->getPath(),
                    );
                    $transaction->set($logSubspace->pack(['path']), Tuple::pack($pathElements));
                    $transaction->set($logSubspace->pack(['layer']), Tuple::pack([new Bytes($entry->getLayerName())]));
                    $transaction->set($logSubspace->pack(['exists']), Tuple::pack([$exists ? 1 : 0]));
                    $transaction->set($logSubspace->pack(['children']), StackMachine::packList($children));
                    break;

                case 'DIRECTORY_STRIP_PREFIX':
                    $value = StackMachine::toRawString($machine->pop($stack));
                    $prefix = $entry->requireSubspace()->key();
                    if (!str_starts_with($value, $prefix)) {
                        throw new \RuntimeException('String does not start with directory prefix');
                    }
                    $machine->push($instructionNumber, new Bytes(substr($value, strlen($prefix))));
                    break;

                default:
                    throw new \RuntimeException('Unknown directory op: ' . $op);
            }
        } catch (\Throwable $error) {
            if ($op === 'DIRECTORY_CREATE_SUBSPACE'
                || $op === 'DIRECTORY_CREATE_LAYER'
                || $op === 'DIRECTORY_CREATE_OR_OPEN'
                || $op === 'DIRECTORY_CREATE'
                || $op === 'DIRECTORY_OPEN'
                || $op === 'DIRECTORY_MOVE'
                || $op === 'DIRECTORY_MOVE_TO'
                || $op === 'DIRECTORY_OPEN_SUBSPACE'
            ) {
                $this->append(null);
            }

            $machine->push($instructionNumber, new Bytes('DIRECTORY_ERROR'));
        }
    }

    private function append(?DirectoryEntry $entry): void
    {
        $this->dirList[] = $entry;
    }

    private function entryExists(DirectoryEntry $entry, Transactor $transactor): bool
    {
        if ($entry->subspace instanceof Subspace) {
            return $entry->subspace->exists($transactor, $entry->getPath());
        }

        return $entry->getLayer()->exists($transactor, $entry->getPath());
    }

    /**
     * @return list<string>
     */
    private function entryList(DirectoryEntry $entry, Transactor $transactor): array
    {
        if ($entry->subspace instanceof Subspace) {
            return $entry->subspace->listSubdirectories($transactor, $entry->getPath());
        }

        return $entry->getLayer()->list($transactor, $entry->getPath());
    }
}

/**
 * An entry in the tester's directory list: a plain subspace, a directory
 * subspace, or a directory layer (with its tracked path).
 */
final class DirectoryEntry
{
    public function __construct(
        public readonly ?Subspace $subspace,
        private readonly ?DirectoryLayer $layer = null,
        public readonly ?Subspace $nodeSubspace = null,
        public readonly ?Subspace $contentSubspace = null,
    ) {
    }

    public static function layer(
        DirectoryLayer $layer,
        array $path,
        Subspace $nodeSubspace,
        Subspace $contentSubspace,
    ): self {
        return new self(null, $layer, $nodeSubspace, $contentSubspace);
    }

    public function getLayer(): DirectoryLayer
    {
        if ($this->layer === null) {
            throw new \RuntimeException('Entry is not a directory layer');
        }

        return $this->layer;
    }

    public function requireDirectory(): DirectorySubspace|DirectoryLayer
    {
        if ($this->layer !== null) {
            return $this->layer;
        }

        if ($this->subspace instanceof DirectorySubspace) {
            return $this->subspace;
        }

        throw new \RuntimeException('Entry does not support directory operations');
    }

    public function requireSubspace(): Subspace
    {
        if ($this->subspace === null) {
            throw new \RuntimeException('Entry is not a subspace');
        }

        return $this->subspace;
    }

    /**
     * @return list<string>
     */
    public function getPath(): array
    {
        if ($this->subspace instanceof DirectorySubspace) {
            return $this->subspace->getPath();
        }

        return [];
    }

    public function getLayerName(): string
    {
        if ($this->subspace instanceof DirectorySubspace) {
            return $this->subspace->getLayer();
        }

        return '';
    }

    public function key(): string
    {
        if ($this->subspace !== null) {
            return $this->subspace->key();
        }

        if ($this->nodeSubspace !== null) {
            return $this->nodeSubspace->key();
        }

        throw new \RuntimeException('Entry has no key');
    }
}
