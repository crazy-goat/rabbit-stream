<?php

declare(strict_types=1);

namespace CrazyGoat\RabbitStream\Contract;

interface ProducerInterface
{
    /**
     * Publish a single message.
     *
     * @param string $message plain payload; it is automatically wrapped in an AMQP 1.0
     *                        Data section on the wire, so consumers see the same string
     * @param ?float $timeout socket write timeout in seconds; null uses connection default
     */
    public function send(string $message, ?float $timeout = null): void;

    /**
     * Publish multiple messages in a single batch.
     *
     * @param string[] $messages plain payloads; each one is automatically wrapped in an
     *                           AMQP 1.0 Data section on the wire (see send())
     * @param ?float $timeout socket write timeout in seconds; null uses connection default
     */
    public function sendBatch(array $messages, ?float $timeout = null): void;

    /**
     * Publish a single message tagged with a stream-filtering value (Publish v2).
     * See Producer::sendWithFilter() for filtering semantics/caveats.
     *
     * @param string      $message     plain payload; see send()
     * @param string|null $filterValue value hashed into the chunk's bloom filter
     * @param ?float      $timeout     socket write timeout in seconds; null uses connection default
     */
    public function sendWithFilter(string $message, ?string $filterValue, ?float $timeout = null): void;

    public function close(): void;

    public function waitForConfirms(float $timeout = 5.0): void;

    public function getLastPublishingId(): ?int;

    public function querySequence(): int;

    public function getPendingConfirms(): int;
}
