<?php

declare(strict_types=1);

namespace CrazyGoat\RabbitStream\Contract;

interface ProducerInterface
{
    public function send(string $message, ?float $timeout = null): void;

    /**
     * @param string[] $messages
     */
    public function sendBatch(array $messages, ?float $timeout = null): void;

    public function close(): void;

    public function waitForConfirms(float $timeout = 5.0): void;

    public function getLastPublishingId(): ?int;

    public function querySequence(): int;
}
