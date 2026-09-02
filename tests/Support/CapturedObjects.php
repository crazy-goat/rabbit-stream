<?php

declare(strict_types=1);

namespace CrazyGoat\RabbitStream\Tests\Support;

/**
 * Collects objects a callback was invoked with (e.g. ConfirmationStatus values
 * passed to a Producer's onConfirm), with typed accessors so assertions do not
 * have to defend against a missing offset.
 *
 * @template T of object
 */
final class CapturedObjects
{
    /** @var list<T> */
    private array $items = [];

    /** @param T $item */
    public function add(object $item): void
    {
        $this->items[] = $item;
    }

    public function count(): int
    {
        return count($this->items);
    }

    /**
     * @return T
     * @throws \LogicException when nothing was captured at $index
     */
    public function at(int $index): object
    {
        if (!isset($this->items[$index])) {
            throw new \LogicException("No object was captured at index {$index}");
        }
        return $this->items[$index];
    }

    /** @return list<T> */
    public function all(): array
    {
        return $this->items;
    }
}
