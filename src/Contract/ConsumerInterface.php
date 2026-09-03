<?php

declare(strict_types=1);

namespace CrazyGoat\RabbitStream\Contract;

use CrazyGoat\RabbitStream\Client\Message;

interface ConsumerInterface
{
    /**
     * @return Message[]
     */
    public function read(float $timeout = 5.0): array;

    public function readOne(float $timeout = 5.0): ?Message;

    /**
     * Whether at least one already-buffered, not-yet-read message is currently
     * held in memory (no I/O — purely a check against the in-process buffer).
     */
    public function hasUnread(): bool;

    /**
     * Non-blocking drain of whatever messages are already buffered, without
     * performing any connection I/O. Returns an empty array if nothing is
     * buffered.
     *
     * @return Message[]
     */
    public function drain(): array;

    public function storeOffset(int $offset): void;

    public function queryOffset(): int;

    public function close(): void;

    /**
     * Whether this consumer is currently allowed to receive messages. Always
     * true unless created with singleActiveConsumer, in which case it tracks
     * the broker's most recent ConsumerUpdate activation state.
     */
    public function isActive(): bool;

    /**
     * Override the default single-active-consumer resume logic.
     *
     * @param callable $callback Called with (bool $active, ConsumerInterface $this): ?OffsetSpec.
     *                           Return null to keep the current position (offsetType 0).
     */
    public function onConsumerUpdate(callable $callback): void;
}
