<?php

declare(strict_types=1);

namespace CrazyGoat\RabbitStream\Client;

use CrazyGoat\RabbitStream\Client\Routing\RoutingStrategy;
use CrazyGoat\RabbitStream\Contract\ProducerInterface;
use CrazyGoat\RabbitStream\Contract\SuperStreamProducerInterface;

/**
 * Publishes to a super stream's partitions, routing each message to the
 * correct partition(s) via a {@see RoutingStrategy}.
 *
 * Opens one {@see Producer} per partition lazily (on first publish to that
 * partition), through a factory closure injected by
 * {@see Connection::createSuperStreamProducer()} — this class has no direct
 * dependency on StreamConnection or on how publisher IDs/names are minted,
 * it only knows "give me a Producer for this partition stream name".
 *
 * CAVEAT: partition membership is resolved once, when this producer (and its
 * partition list) is created. A MetadataUpdate for a partition stream (e.g.
 * the partition's leader changed, or the super stream's partition set itself
 * changed) is NOT automatically detected or handled here — a caller that
 * needs to react to that must recreate the SuperStreamProducer.
 */
class SuperStreamProducer implements SuperStreamProducerInterface
{
    /** @var array<string, ProducerInterface> partition stream name => Producer */
    private array $producers = [];

    /**
     * @param list<string> $partitions
     * @param \Closure(string): ProducerInterface $producerFactory
     */
    public function __construct(
        private readonly array $partitions,
        private readonly RoutingStrategy $strategy,
        private readonly \Closure $producerFactory,
    ) {
    }

    private function producerFor(string $partition): ProducerInterface
    {
        return $this->producers[$partition] ??= ($this->producerFactory)($partition);
    }

    public function send(string $message, string $routingKey, ?float $timeout = null): void
    {
        foreach ($this->strategy->route($routingKey, $this->partitions) as $partition) {
            $this->producerFor($partition)->send($message, $timeout);
        }
    }

    public function sendBatch(array $messages, ?float $timeout = null): void
    {
        if ($messages === []) {
            return;
        }

        /** @var array<string, list<string>> $grouped partition => messages */
        $grouped = [];
        foreach ($messages as [$message, $routingKey]) {
            foreach ($this->strategy->route($routingKey, $this->partitions) as $partition) {
                $grouped[$partition][] = $message;
            }
        }

        foreach ($grouped as $partition => $partitionMessages) {
            $this->producerFor($partition)->sendBatch($partitionMessages, $timeout);
        }
    }

    public function waitForConfirms(float $timeout = 5.0): void
    {
        foreach ($this->producers as $producer) {
            $producer->waitForConfirms($timeout);
        }
    }

    public function getPendingConfirms(): int
    {
        $total = 0;
        foreach ($this->producers as $producer) {
            $total += $producer->getPendingConfirms();
        }
        return $total;
    }

    /** @return list<string> */
    public function getPartitions(): array
    {
        return $this->partitions;
    }

    public function close(): void
    {
        foreach ($this->producers as $producer) {
            $producer->close();
        }
        $this->producers = [];
    }
}
