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
 * A subscription that delivers messages from a single stream.
 *
 * This is the seam {@see \CrazyGoat\RabbitStream\Client\Consumer} implements
 * and the type {@see ConnectionInterface::createConsumer()} returns, so a test
 * double only has to satisfy this contract.
 */
interface ConsumerInterface
{
    /**
     * Wait for messages and return everything received as a batch.
     *
     * Blocks until at least one message is buffered or `$timeout` elapses, then
     * drains the whole in-memory buffer. Server-push frames other than Deliver
     * (heartbeats, a producer's confirms, ConsumerUpdate) are handled
     * transparently and do not end the wait.
     *
     * @param float $timeout Seconds to wait for at least one message before
     *                       returning whatever the buffer holds; `0` returns
     *                       immediately with whatever is already buffered and
     *                       does not poll the socket.
     * @return Message[] Every buffered unread message, oldest first; an empty
     *                            array when none arrived within $timeout.
     * @throws ProtocolException If re-establishing a lost subscription fails with a
     *                            non-retryable broker error.
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
     * Wait for a single message and return it.
     *
     * Like {@see self::read()}, but removes and returns only the oldest
     * message. Server-push frames other than Deliver do not end the wait.
     *
     * @param float $timeout Seconds to wait for a message before giving up; `0`
     *                       returns immediately with whatever is already
     *                       buffered and does not poll the socket.
     * @return Message|null The oldest unread message, or null when none arrived
     *                            within $timeout.
     * @throws ProtocolException If re-establishing a lost subscription fails with a
     *                            non-retryable broker error.
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
     * Whether at least one already-buffered, not-yet-read message is currently
     * held in memory (no I/O — purely a check against the in-process buffer).
     *
     * @return bool True when read()/readOne() can return a message without
     *                            blocking or touching the socket.
     */
    public function hasUnread(): bool;

    /**
     * Non-blocking drain of whatever messages are already buffered, without
     * reading any incoming frames (no readLoop() call; it may still send a
     * withheld-credit frame). Returns an empty array if nothing is buffered.
     *
     * @return Message[] Every buffered unread message, oldest first; an empty
     *                            array when the buffer is empty.
     * @throws ConnectionException If a withheld credit frame cannot be written.
     * @throws TimeoutException If a withheld credit frame cannot be written within
     *                            the socket timeout.
     */
    public function drain(): array;

    /**
     * Store the next offset to consume for this consumer's name on the broker.
     *
     * The stored value is `lastProcessedOffset + 1`, so it can be handed to
     * `OffsetSpec::offset()` (inclusive) directly. The protocol defines no
     * StoreOffset response, so this is fire-and-forget.
     *
     * @param int $offset Next offset to consume.
     * @throws ProtocolException If this consumer has no name (offsets are
     *                            name-scoped on the broker).
     * @throws ConnectionException If the socket is not connected or the write fails.
     * @throws TimeoutException If the write does not complete within the socket timeout.
     */
    public function storeOffset(int $offset): void;

    /**
     * Query the offset stored on the broker for this consumer's name.
     *
     * `null` means nothing has been stored yet (the broker answered
     * `NO_OFFSET`, `0x13`) — a normal first-run outcome, not an error.
     *
     * @return int|null The stored next offset to consume, or null when no offset
     *                  is stored.
     * @throws ProtocolException If this consumer has no name, or the broker returns a
     *                            non-OK response code other than NO_OFFSET.
     * @throws UnexpectedResponseException If the server replies with something other
     *                            than a QueryOffset response.
     * @throws ConnectionException If the socket is not connected or the request fails.
     * @throws DeserializationException If the response frame cannot be deserialized.
     * @throws TimeoutException If the response does not arrive in time.
     */
    public function queryOffset(): ?int;

    /**
     * Unsubscribe on the broker and release the subscription id.
     *
     * Idempotent: a second call is a no-op, so the subscription id cannot be
     * handed back twice (and then to two live consumers at once).
     *
     * @throws ProtocolException If the broker rejects the Unsubscribe with a non-OK
     *                            response code.
     * @throws ConnectionException If the socket is not connected or the exchange fails.
     * @throws DeserializationException If the Unsubscribe response frame cannot be
     *                            deserialized.
     * @throws TimeoutException If the Unsubscribe response does not arrive in time.
     */
    public function close(): void;

    /**
     * Whether this consumer is currently allowed to receive messages. Always
     * true unless created with singleActiveConsumer, in which case it tracks
     * the broker's most recent ConsumerUpdate activation state.
     *
     * @return bool True when the broker has this consumer active, false while a
     *                            single-active-consumer handover has it paused.
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
