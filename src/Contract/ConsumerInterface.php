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
