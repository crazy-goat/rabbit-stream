<?php

declare(strict_types=1);

namespace CrazyGoat\RabbitStream\Contract;

use CrazyGoat\RabbitStream\Exception\ConnectionException;
use CrazyGoat\RabbitStream\Exception\DeserializationException;
use CrazyGoat\RabbitStream\Exception\InvalidArgumentException;
use CrazyGoat\RabbitStream\Exception\ProtocolException;
use CrazyGoat\RabbitStream\Exception\TimeoutException;
use CrazyGoat\RabbitStream\Exception\UnexpectedResponseException;

interface ProducerInterface
{
    /**
     * Publish a single message.
     *
     * @param string $message plain payload; it is automatically wrapped in an AMQP 1.0
     *                        Data section on the wire, so consumers see the same string
     * @param ?float $timeout socket write timeout in seconds; null uses connection default
     * @throws ConnectionException If the socket is not connected or a write/read fails.
     * @throws DeserializationException If a frame read while draining back-pressure
     *                        cannot be deserialized.
     * @throws InvalidArgumentException If the serialized request exceeds the frame size limit.
     * @throws ProtocolException If the broker rejects a re-declare or sends an unexpected frame.
     * @throws TimeoutException If the write or a back-pressure drain times out.
     */
    public function send(string $message, ?float $timeout = null): void;

    /**
     * Publish multiple messages in a single batch.
     *
     * @param string[] $messages plain payloads; each one is automatically wrapped in an
     *                           AMQP 1.0 Data section on the wire (see send())
     * @param ?float $timeout socket write timeout in seconds; null uses connection default
     * @throws ConnectionException If the socket is not connected or a write/read fails.
     * @throws DeserializationException If a frame read while draining back-pressure
     *                           cannot be deserialized.
     * @throws InvalidArgumentException If the serialized request exceeds the frame size limit.
     * @throws ProtocolException If the broker rejects a re-declare or sends an unexpected frame.
     * @throws TimeoutException If the write or a back-pressure drain times out.
     */
    public function sendBatch(array $messages, ?float $timeout = null): void;

    /**
     * Publish a single message tagged with a stream-filtering value (Publish v2).
     * See Producer::sendWithFilter() for filtering semantics/caveats.
     *
     * @param string      $message     plain payload; see send()
     * @param string|null $filterValue value hashed into the chunk's bloom filter
     * @param ?float      $timeout     socket write timeout in seconds; null uses connection default
     * @throws ConnectionException If the socket is not connected or a write/read fails.
     * @throws DeserializationException If a frame read while draining back-pressure
     *                                 cannot be deserialized.
     * @throws InvalidArgumentException If the serialized request exceeds the frame size limit.
     * @throws ProtocolException If $filterValue is non-null but the broker lacks Publish v2,
     *                                 or a re-declare is rejected.
     * @throws TimeoutException If the write or a back-pressure drain times out.
     */
    public function sendWithFilter(string $message, ?string $filterValue, ?float $timeout = null): void;

    /**
     * Delete the publisher on the broker and release its id.
     *
     * Idempotent; safe to call more than once. Does not close the connection.
     *
     * @throws ConnectionException If the socket is not connected or a write/read fails.
     * @throws DeserializationException If a frame read while draining confirms
     *                          cannot be deserialized.
     * @throws ProtocolException If the broker sends an unexpected response frame.
     * @throws TimeoutException If the DeletePublisher response does not arrive in time.
     */
    public function close(): void;

    /**
     * Block until every outstanding publish has been confirmed or reported failed.
     *
     * @param float $timeout Maximum seconds to wait for the outstanding confirms.
     * @throws TimeoutException If confirms are still outstanding when $timeout expires.
     * @throws ConnectionException If the socket is not connected or a read fails.
     * @throws DeserializationException If a frame read while waiting cannot be deserialized.
     * @throws ProtocolException If a server-push frame read while waiting has an unexpected version or command.
     */
    public function waitForConfirms(float $timeout = 5.0): void;

    /**
     * The publishing id of the most recent publish, or null if none was sent.
     *
     * For a named producer the constructor queries the broker's last confirmed
     * sequence and resumes from sequence + 1, so this can be non-null before the
     * first send().
     *
     * @return int|null Last publishing id used, or null when nothing has been
     *                  published yet (anonymous producer before its first send()).
     */
    public function getLastPublishingId(): ?int;

    /**
     * Query the broker for this named producer's last confirmed publishing id.
     *
     * @return int Highest publishing id confirmed for this producer's name.
     * @throws InvalidArgumentException If this is an anonymous producer (a `null` or `""` name).
     * @throws ConnectionException If the socket is not connected or the exchange fails.
     * @throws DeserializationException If the response frame cannot be deserialized.
     * @throws ProtocolException If the broker answers with a non-OK response code.
     * @throws TimeoutException If the response does not arrive in time.
     * @throws UnexpectedResponseException If the reply has an unexpected type.
     */
    public function querySequence(): int;

    /**
     * Number of publishes sent but not yet confirmed or reported failed.
     *
     * @return int Current number of outstanding (unconfirmed) publishes.
     */
    public function getPendingConfirms(): int;
}
