<?php

declare(strict_types=1);

namespace CrazyGoat\RabbitStream\Contract;

use CrazyGoat\RabbitStream\Client\Message;

/**
 * Consumes messages from every partition of a super stream through one
 * object.
 *
 * Offset tracking is entirely PER-PARTITION (each partition is a distinct
 * stream with its own offset sequence) — there is no aggregate,
 * super-stream-wide offset. {@see self::storeOffset()} and
 * {@see self::queryOffset()} always operate on one named partition.
 */
interface SuperStreamConsumerInterface
{
    /**
     * Return whatever messages are already buffered across all partitions
     * without blocking; only if nothing is buffered anywhere does this run a
     * single bounded read against the connection and then collect whatever
     * became buffered.
     *
     * @return Message[]
     */
    public function read(float $timeout = 5.0): array;

    /**
     * Like {@see self::read()}, but returns at most one message, round-robining
     * fairly across partitions that currently have buffered data.
     */
    public function readOne(float $timeout = 5.0): ?Message;

    public function storeOffset(string $partition, int $offset): void;

    public function queryOffset(string $partition): int;

    /** @return list<string> */
    public function getPartitions(): array;

    /** @return array<string, ConsumerInterface> partition stream name => Consumer */
    public function getConsumers(): array;

    public function isActive(string $partition): bool;

    public function close(): void;
}
