<?php

declare(strict_types=1);

namespace CrazyGoat\RabbitStream\Contract;

use CrazyGoat\RabbitStream\Exception\ConnectionException;
use CrazyGoat\RabbitStream\Exception\DeserializationException;
use CrazyGoat\RabbitStream\Exception\InvalidArgumentException;
use CrazyGoat\RabbitStream\Exception\ProtocolException;
use CrazyGoat\RabbitStream\Exception\TimeoutException;
use CrazyGoat\RabbitStream\Exception\UnexpectedResponseException;

/**
 * Publishes to a super stream's partitions, routing each message through a
 * {@see \CrazyGoat\RabbitStream\Client\Routing\RoutingStrategy}.
 *
 * This is the seam {@see \CrazyGoat\RabbitStream\Client\SuperStreamProducer}
 * implements and the type {@see ConnectionInterface::createSuperStreamProducer()}
 * returns.
 */
interface SuperStreamProducerInterface
{
    /**
     * Mark the current partition list stale after a MetadataUpdate.
     */
    public function markPartitionsStale(): void;

    /**
     * Whether the partition list needs to be refreshed before the next publish.
     *
     * @return bool True when a topology refresh is pending.
     */
    public function isPartitionsStale(): bool;

    /**
     * Number of completed partition refreshes after a MetadataUpdate.
     *
     * @return int Number of refreshes completed.
     */
    public function getRefreshCount(): int;

    /**
     * Re-resolve the partition list now (normally done lazily on the next publish).
     *
     * @throws ProtocolException If the super stream is unavailable or a producer close fails.
     * @throws UnexpectedResponseException If the partitions resolver receives an unexpected response.
     * @throws InvalidArgumentException If the partitions request exceeds the outgoing frame size.
     * @throws ConnectionException If the resolver or producer close encounters a socket failure.
     * @throws DeserializationException If the partitions or producer close response cannot be deserialized.
     * @throws TimeoutException If a response does not arrive in time.
     */
    public function refreshPartitions(): void;

    /**
     * Publish a single message, routed to a partition by the configured
     * {@see \CrazyGoat\RabbitStream\Client\Routing\RoutingStrategy}.
     *
     * A routing key can legitimately resolve to more than one partition; the
     * message is then published once per matching partition.
     *
     * @param string $message plain payload; it is automatically wrapped in an
     *                        AMQP 1.0 Data section on the wire
     * @param string $routingKey key hashed by the routing strategy to pick the
     *                        destination partition(s)
     * @param ?float $timeout socket write timeout in seconds; null uses connection default
     * @throws ConnectionException If the socket is not connected or a write/read fails.
     * @throws DeserializationException If a frame read while draining back-pressure
     *                        cannot be deserialized.
     * @throws InvalidArgumentException If the serialized request exceeds the frame size limit.
     * @throws ProtocolException If the super stream disappeared, or the broker rejects a
     *                        re-declare or sends an unexpected frame.
     * @throws UnexpectedResponseException If the StreamStats or QueryPublisherSequence reply
     *                        used while refreshing partitions or opening a named partition
     *                        producer is of the wrong type.
     * @throws TimeoutException If the write or a back-pressure drain times out.
     */
    public function send(string $message, string $routingKey, ?float $timeout = null): void;

    /**
     * Publish multiple messages, each routed independently, grouped into one
     * batch send per destination partition.
     *
     * @param list<array{0: string, 1: string}> $messages list of [message, routingKey] pairs
     * @param ?float $timeout socket write timeout in seconds; null uses connection default
     * @throws ConnectionException If the socket is not connected or a write/read fails.
     * @throws DeserializationException If a frame read while draining back-pressure
     *                           cannot be deserialized.
     * @throws InvalidArgumentException If the serialized request exceeds the frame size limit.
     * @throws ProtocolException If the super stream disappeared, or the broker rejects a
     *                           re-declare or sends an unexpected frame.
     * @throws UnexpectedResponseException If the Partitions or QueryPublisherSequence reply
     *                           used while refreshing partitions or opening a named partition
     *                           producer is of the wrong type.
     * @throws TimeoutException If the write or a back-pressure drain times out.
     */
    public function sendBatch(array $messages, ?float $timeout = null): void;

    /**
     * Block until every outstanding publish across all partitions has been
     * confirmed or reported failed.
     *
     * @param float $timeout Maximum seconds to wait per partition's outstanding confirms.
     * @throws TimeoutException If confirms are still outstanding when $timeout expires.
     * @throws ConnectionException If the socket is not connected or a read fails.
     * @throws DeserializationException If a frame read while waiting cannot be deserialized.
     * @throws ProtocolException If a server-push frame read while waiting has an unexpected
     *                           version or command.
     */
    public function waitForConfirms(float $timeout = 5.0): void;

    /**
     * Total number of publishes sent but not yet confirmed or reported failed,
     * summed across every partition opened so far.
     *
     * @return int Current number of outstanding (unconfirmed) publishes.
     */
    public function getPendingConfirms(): int;

    /**
     * The partition (physical stream) names this producer currently publishes
     * to. May change after a MetadataUpdate triggers a topology refresh.
     *
     * @return list<string> Partition stream names.
     */
    public function getPartitions(): array;

    /**
     * Close every underlying partition producer and release their publisher
     * ids. Idempotent per underlying producer.
     *
     * @throws ConnectionException If the socket is not connected or an exchange fails.
     * @throws DeserializationException If a DeletePublisher response frame cannot be
     *                           deserialized.
     * @throws ProtocolException If the broker rejects a DeletePublisher with a non-OK
     *                           response code.
     * @throws TimeoutException If a DeletePublisher response does not arrive in time.
     */
    public function close(): void;
}
