<?php

declare(strict_types=1);

namespace CrazyGoat\FoundationDB\Tests\BindingTester;

/**
 * Stack for the binding tester stack machine.
 *
 * Items are stored as [instructionNumber, value] pairs with the top of the
 * stack at index 0, mirroring the upstream Python tester semantics.
 */
final class Stack
{
    /** @var list<array{int, mixed}> */
    private array $items = [];

    public function push(int $instructionNumber, mixed $value): void
    {
        array_unshift($this->items, [$instructionNumber, $value]);
    }

    /**
     * Pops the top item and returns its [instructionNumber, value] pair.
     *
     * @return array{int, mixed}
     */
    public function popWithIndex(): array
    {
        $item = array_shift($this->items);

        if ($item === null) {
            throw new \RuntimeException('Stack underflow');
        }

        return $item;
    }

    /**
     * Peeks at the top item without resolving or removing it.
     *
     * @return array{int, mixed}
     */
    public function peekWithIndex(): array
    {
        $item = $this->items[0] ?? null;

        if ($item === null) {
            throw new \RuntimeException('Stack underflow');
        }

        return $item;
    }

    /**
     * Pops `$count` items and returns their values, top first.
     *
     * @return list<mixed>
     */
    public function popValues(int $count): array
    {
        $values = [];
        for ($i = 0; $i < $count; $i++) {
            $values[] = $this->popWithIndex()[1];
        }

        return $values;
    }

    /**
     * Removes and returns all items as a list of
     * [stackIndex, instructionNumber, value] triplets, popping from the top.
     * The oldest item gets stackIndex 0, matching the upstream tester.
     *
     * @return list<array{int, int, mixed}>
     */
    public function drain(): array
    {
        $result = [];

        while ($this->items !== []) {
            [$instructionNumber, $value] = $this->popWithIndex();
            $result[] = [count($this->items), $instructionNumber, $value];
        }

        return $result;
    }

    /**
     * Swaps the items at stack depth 0 and depth $index without changing
     * their instruction numbers.
     */
    public function swap(int $index): void
    {
        if (!isset($this->items[$index])) {
            throw new \RuntimeException(sprintf('SWAP depth %d out of range', $index));
        }

        [$this->items[0], $this->items[$index]] = [$this->items[$index], $this->items[0]];
    }

    public function isEmpty(): bool
    {
        return $this->items === [];
    }
}
