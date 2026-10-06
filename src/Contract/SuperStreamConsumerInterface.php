<?php

declare(strict_types=1);

namespace CrazyGoat\RabbitStream\Contract;

use CrazyGoat\RabbitStream\Client\Message;
use CrazyGoat\RabbitStream\Exception\ConnectionException;
use CrazyGoat\RabbitStream\Exception\DeserializationException;
use CrazyGoat\RabbitStream\Exception\InvalidArgumentException;
use CrazyGoat\RabbitStream\Exception\ProtocolException;
use CrazyGoat\RabbitStream\Exception\TimeoutException;
use CrazyGoat\RabbitStream\Exception\UnexpectedResponseException;

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
     * @param float $timeout Seconds to wait for at least one buffered message
     *                       before returning; `0` returns immediately with
     *                       whatever is already buffered.
     * @return Message[] Messages from all partitions, each partition's buffered
     *                            messages oldest first; an empty array when none
     *                            arrived within $timeout.
     * @throws ProtocolException If re-establishing a lost partition subscription fails
     *                            with a non-retryable broker error.
     * @throws UnexpectedResponseException If the StreamStats reply used while
     *                            re-subscribing is not a StreamStats response.
     * @throws ConnectionException If the socket is not connected or a read/write fails.
     * @throws DeserializationException If a delivered chunk or server-push frame cannot
     *                            be deserialized.
     * @throws TimeoutException If a credit or heartbeat frame cannot be written within
     *                            the socket timeout, or a re-subscribe request does not get
     *                            a reply in time.
     * @throws InvalidArgumentException If re-establishing a lost subscription builds a
     *                            Subscribe frame that exceeds the negotiated outgoing frame size.
     */
    public function read(float $timeout = 5.0): array;

    /**
     * Like {@see self::read()}, but returns at most one message, round-robining
     * fairly across partitions that currently have buffered data.
     *
     * @param float $timeout Seconds to wait for a message before giving up; `0`
     *                       returns immediately with whatever is already buffered.
     * @return Message|null The next message in round-robin order, or null when
     *                            none arrived within $timeout.
     * @throws ProtocolException If re-establishing a lost partition subscription fails
     *                            with a non-retryable broker error.
     * @throws UnexpectedResponseException If the StreamStats reply used while
     *                            re-subscribing is not a StreamStats response.
     * @throws ConnectionException If the socket is not connected or a read/write fails.
     * @throws DeserializationException If a delivered chunk or server-push frame cannot
     *                            be deserialized.
     * @throws TimeoutException If a credit or heartbeat frame cannot be written within
     *                            the socket timeout, or a re-subscribe request does not get
     *                            a reply in time.
     * @throws InvalidArgumentException If re-establishing a lost subscription builds a
     *                            Subscribe frame that exceeds the negotiated outgoing frame size.
     */
    public function readOne(float $timeout = 5.0): ?Message;

    /**
     * Store the next offset to consume for one partition's consumer name.
     *
     * @param string $partition Partition (physical stream) name.
     * @param int $offset Next offset to consume for that partition.
     * @throws InvalidArgumentException If $partition is not a partition of this super
     *                            stream, or the StoreOffset frame exceeds the negotiated
     *                            outgoing frame size.
     * @throws ProtocolException If the partition's consumer has no name.
     * @throws ConnectionException If the socket is not connected or the write fails.
     * @throws TimeoutException If the write does not complete within the socket timeout.
     */
    public function storeOffset(string $partition, int $offset): void;

    /**
     * Query the offset stored on the broker for one partition's consumer name.
     *
     * `null` means nothing has been stored yet for this partition (the broker
     * answered `NO_OFFSET`, `0x13`) — a normal first-run outcome, not an error.
     *
     * @param string $partition Partition (physical stream) name.
     * @return int|null The stored next offset to consume for this partition, or
     *                            null when no offset is stored.
     * @throws InvalidArgumentException If $partition is not a partition of this super stream.
     * @throws ProtocolException If the partition's consumer has no name, or the broker
     *                            returns a non-OK response code other than NO_OFFSET.
     * @throws UnexpectedResponseException If the server replies with something other
     *                            than a QueryOffset response.
     * @throws ConnectionException If the socket is not connected or the request fails.
     * @throws DeserializationException If the response frame cannot be deserialized.
     * @throws TimeoutException If the response does not arrive in time.
     */
    public function queryOffset(string $partition): ?int;

    /**
     * The partition (physical stream) names this consumer subscribes to.
     *
     * @return list<string> Partition stream names.
     */
    public function getPartitions(): array;

    /**
     * The underlying per-partition consumers, keyed by partition stream name.
     *
     * @return array<string, ConsumerInterface> Partition stream name => Consumer.
     */
    public function getConsumers(): array;

    /**
     * Whether one partition's consumer is currently allowed to receive messages.
     *
     * @param string $partition Partition (physical stream) name.
     * @return bool True when the broker has that partition's consumer active.
     * @throws InvalidArgumentException If $partition is not a partition of this super stream.
     */
    public function isActive(string $partition): bool;

    /**
     * Close every underlying partition consumer and release their subscription
     * ids. Idempotent per underlying consumer.
     *
     * @throws ProtocolException If the broker rejects an Unsubscribe with a non-OK
     *                            response code.
     * @throws ConnectionException If the socket is not connected or an exchange fails.
     * @throws DeserializationException If an Unsubscribe response frame cannot be
     *                            deserialized.
     * @throws TimeoutException If an Unsubscribe response does not arrive in time.
     * @throws InvalidArgumentException If an Unsubscribe (or an auto-commit StoreOffset)
     *                            frame exceeds the negotiated outgoing frame size.
     */
    public function close(): void;
}
