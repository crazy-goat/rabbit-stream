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
 * Recovery after MetadataUpdate: each partition {@see Producer} re-declares
 * itself on the next publish (see its class docblock). On top of that,
 * {@see Connection::createSuperStreamProducer()} registers a per-partition
 * MetadataUpdate handler that calls markPartitionsStale(); the next
 * send()/sendBatch() then re-resolves the partition list through the injected
 * resolver (a Partitions request), drops producers of partitions that no
 * longer exist and resets the routing strategy's cache. RabbitMQ cannot add
 * partitions to an existing super stream, so the refresh is about
 * availability, not about a changed partition count.
 */
class SuperStreamProducer implements SuperStreamProducerInterface
{
    /** @var array<string, ProducerInterface> partition stream name => Producer */
    private array $producers = [];

    private bool $partitionsStale = false;
    private int $refreshCount = 0;

    /**
     * @param list<string> $partitions
     * @param \Closure(string): ProducerInterface $producerFactory
     * @param (\Closure(): list<string>)|null $partitionsResolver re-resolves the partition
     *        list after a MetadataUpdate; null keeps the initial list forever
     */
    public function __construct(
        private array $partitions,
        private readonly RoutingStrategy $strategy,
        private readonly \Closure $producerFactory,
        private readonly ?\Closure $partitionsResolver = null,
    ) {
    }

    /**
     * Called (by the MetadataUpdate handler wired in Connection) when one of the
     * partitions became unavailable: the next publish refreshes the topology.
     */
    public function markPartitionsStale(): void
    {
        $this->partitionsStale = true;
    }

    public function isPartitionsStale(): bool
    {
        return $this->partitionsStale;
    }

    /** Number of completed partition refreshes after a MetadataUpdate. */
    public function getRefreshCount(): int
    {
        return $this->refreshCount;
    }

    /**
     * Re-resolve the partition list now (normally done lazily on the next publish).
     * Producers of partitions that disappeared are closed and dropped; the
     * routing strategy forgets any cached decisions.
     *
     * @throws \CrazyGoat\RabbitStream\Exception\ProtocolException if the super stream itself is gone
     */
    public function refreshPartitions(): void
    {
        if ($this->partitionsResolver instanceof \Closure) {
            $this->partitions = ($this->partitionsResolver)();
            foreach ($this->producers as $partition => $producer) {
                if (!in_array($partition, $this->partitions, true)) {
                    $producer->close();
                    unset($this->producers[$partition]);
                }
            }
        }
        $this->strategy->reset();
        $this->partitionsStale = false;
        $this->refreshCount++;
    }

    private function ensureFreshPartitions(): void
    {
        if ($this->partitionsStale) {
            $this->refreshPartitions();
        }
    }

    private function producerFor(string $partition): ProducerInterface
    {
        return $this->producers[$partition] ??= ($this->producerFactory)($partition);
    }

    public function send(string $message, string $routingKey, ?float $timeout = null): void
    {
        $this->ensureFreshPartitions();
        foreach ($this->strategy->route($routingKey, $this->partitions) as $partition) {
            $this->producerFor($partition)->send($message, $timeout);
        }
    }

    public function sendBatch(array $messages, ?float $timeout = null): void
    {
        if ($messages === []) {
            return;
        }
        $this->ensureFreshPartitions();

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
