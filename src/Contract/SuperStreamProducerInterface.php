<?php

declare(strict_types=1);

namespace CrazyGoat\RabbitStream\Contract;

interface SuperStreamProducerInterface
{
    /**
     * Publish a single message, routed to a partition by the configured
     * {@see \CrazyGoat\RabbitStream\Client\Routing\RoutingStrategy}.
     *
     * @param ?float $timeout socket write timeout in seconds; null uses connection default
     */
    public function send(string $message, string $routingKey, ?float $timeout = null): void;

    /**
     * Publish multiple messages, each routed independently, grouped into one
     * batch send per destination partition.
     *
     * @param list<array{0: string, 1: string}> $messages list of [message, routingKey] pairs
     * @param ?float $timeout socket write timeout in seconds; null uses connection default
     */
    public function sendBatch(array $messages, ?float $timeout = null): void;

    public function waitForConfirms(float $timeout = 5.0): void;

    public function getPendingConfirms(): int;

    /** @return list<string> */
    public function getPartitions(): array;

    public function close(): void;
}
