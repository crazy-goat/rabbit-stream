<?php

declare(strict_types=1);

namespace CrazyGoat\RabbitStream\Tests\Support;

/**
 * Collects closures a mock was handed (e.g. the per-stream MetadataUpdate
 * handler a Producer/Consumer registers on its connection) so a test can fire
 * them later. Unlike an array captured by reference, at() returns a plain
 * Closure, so calling it needs no type juggling.
 */
final class CapturedClosures
{
    /** @var list<\Closure> */
    private array $closures = [];

    public function add(\Closure $closure): void
    {
        $this->closures[] = $closure;
    }

    public function count(): int
    {
        return count($this->closures);
    }

    /**
     * @throws \LogicException when the mock never captured a closure at $index
     */
    public function at(int $index = 0): \Closure
    {
        if (!isset($this->closures[$index])) {
            throw new \LogicException("No closure was captured at index {$index}");
        }
        return $this->closures[$index];
    }
}
