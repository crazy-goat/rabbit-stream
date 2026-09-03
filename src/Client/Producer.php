<?php

declare(strict_types=1);

namespace CrazyGoat\RabbitStream\Client;

use CrazyGoat\RabbitStream\Contract\ProducerInterface;
use CrazyGoat\RabbitStream\Exception\InvalidArgumentException;
use CrazyGoat\RabbitStream\Exception\TimeoutException;
use CrazyGoat\RabbitStream\Exception\UnexpectedResponseException;
use CrazyGoat\RabbitStream\Request\DeclarePublisherRequestV1;
use CrazyGoat\RabbitStream\Request\DeletePublisherRequestV1;
use CrazyGoat\RabbitStream\Request\PublishRequestV1;
use CrazyGoat\RabbitStream\Request\PublishRequestV2;
use CrazyGoat\RabbitStream\Request\QueryPublisherSequenceRequestV1;
use CrazyGoat\RabbitStream\Response\QueryPublisherSequenceResponseV1;
use CrazyGoat\RabbitStream\StreamConnection;
use CrazyGoat\RabbitStream\VO\PublishedMessage;
use CrazyGoat\RabbitStream\VO\PublishedMessageV2;

class Producer implements ProducerInterface
{
    public const DEFAULT_MAX_PENDING_CONFIRMS = 10000;
    private const DEFAULT_BACKPRESSURE_TIMEOUT = 30.0;

    private int $publishingId = 0;
    private int $pendingConfirms = 0;

    private readonly ?\Closure $onConfirm;

    public function __construct(
        private readonly StreamConnection $connection,
        private readonly string $stream,
        private readonly int $publisherId,
        private readonly ?string $name = null,
        ?callable $onConfirm = null,
        private readonly int $maxPendingConfirms = self::DEFAULT_MAX_PENDING_CONFIRMS,
    ) {
        $this->onConfirm = $onConfirm !== null ? \Closure::fromCallable($onConfirm) : null;
        $this->declare();
        $this->initializePublishingId();
    }

    private function initializePublishingId(): void
    {
        if ($this->name !== null && $this->name !== '') {
            $sequence = $this->querySequence();
            $this->publishingId = $sequence + 1;
        }
    }

    private function declare(): void
    {
        $this->connection->registerPublisher(
            $this->publisherId,
            onConfirm: function (array $publishingIds): void {
                $this->pendingConfirms = max(0, $this->pendingConfirms - count($publishingIds));
                if ($this->onConfirm instanceof \Closure) {
                    foreach ($publishingIds as $id) {
                        ($this->onConfirm)(new ConfirmationStatus(true, publishingId: $id));
                    }
                }
            },
            onError: function (array $errors): void {
                $this->pendingConfirms = max(0, $this->pendingConfirms - count($errors));
                if ($this->onConfirm instanceof \Closure) {
                    foreach ($errors as $error) {
                        ($this->onConfirm)(new ConfirmationStatus(
                            false,
                            errorCode: $error->getCode(),
                            publishingId: $error->getPublishingId()
                        ));
                    }
                }
            }
        );

        $this->connection->sendMessage(new DeclarePublisherRequestV1(
            $this->publisherId,
            $this->name,
            $this->stream
        ));
        $this->connection->readMessage();
    }

    /**
     * Publish a single message.
     *
     * @param string $message plain payload (e.g. UTF-8 string or binary data). It is
     *                        automatically wrapped in an AMQP 1.0 Data section on the
     *                        wire, so a consumer's Message::getBody() returns the same
     *                        string unchanged. Use AmqpMessageEncoder::encodeDataSection()
     *                        when publishing pre-encoded bytes via the low-level API.
     * @param ?float $timeout socket write timeout in seconds; null uses connection default
     */
    public function send(string $message, ?float $timeout = null): void
    {
        $this->applyBackpressure($timeout);
        $this->pendingConfirms++;
        $this->connection->sendMessage(new PublishRequestV1(
            $this->publisherId,
            new PublishedMessage($this->publishingId++, AmqpMessageEncoder::encodeDataSection($message))
        ), $timeout);
    }

    /**
     * Publish a single message tagged with a stream-filtering value.
     *
     * Uses the Publish v2 frame (`PublishRequestV2`/`PublishedMessageV2`) which carries
     * a per-message `filterValue` the broker hashes into a per-chunk bloom filter.
     * A consumer subscribing with matching `filterValues` (see
     * `Connection::createConsumer()`) asks the broker to only deliver chunks whose
     * bloom filter may contain that value — filtering is CHUNK-granular, not
     * message-granular: a delivered chunk can still contain non-matching messages,
     * so callers that need exact filtering must also post-filter on the consume
     * side using the same filter value convention.
     *
     * @param string      $message    plain payload (see send())
     * @param string|null $filterValue value hashed into the chunk's bloom filter;
     *                                 null publishes without a filter value (never
     *                                 matches an active filter, always delivered
     *                                 when `matchUnfiltered` is enabled)
     * @param ?float      $timeout    socket write timeout in seconds; null uses connection default
     */
    public function sendWithFilter(string $message, ?string $filterValue, ?float $timeout = null): void
    {
        $this->applyBackpressure($timeout);
        $this->pendingConfirms++;
        $this->connection->sendMessage(new PublishRequestV2(
            $this->publisherId,
            new PublishedMessageV2(
                $this->publishingId++,
                $filterValue ?? '',
                AmqpMessageEncoder::encodeDataSection($message)
            )
        ), $timeout);
    }

    /**
     * Publish multiple messages in a single batch.
     *
     * @param string[] $messages plain payloads; each one is automatically wrapped in an
     *                           AMQP 1.0 Data section on the wire (see send())
     * @param ?float $timeout socket write timeout in seconds; null uses connection default
     */
    public function sendBatch(array $messages, ?float $timeout = null): void
    {
        if ($messages === []) {
            return;
        }
        $this->applyBackpressure($timeout);
        $published = [];
        foreach ($messages as $message) {
            $published[] = new PublishedMessage($this->publishingId++, AmqpMessageEncoder::encodeDataSection($message));
            $this->pendingConfirms++;
        }
        $this->connection->sendMessage(new PublishRequestV1($this->publisherId, ...$published), $timeout);
    }

    /**
     * Block until pendingConfirms drops below maxPendingConfirms (0 = unlimited,
     * old fire-and-forget behaviour). Drains confirms/errors off the socket via
     * readLoop() one frame at a time so callbacks fire promptly.
     *
     * @throws TimeoutException if the deadline passes before enough confirms arrive
     */
    private function applyBackpressure(?float $timeout): void
    {
        if ($this->maxPendingConfirms <= 0 || $this->pendingConfirms < $this->maxPendingConfirms) {
            return;
        }

        $deadline = microtime(true) + ($timeout ?? self::DEFAULT_BACKPRESSURE_TIMEOUT);
        while ($this->pendingConfirms >= $this->maxPendingConfirms) {
            $remaining = $deadline - microtime(true);
            if ($remaining <= 0) {
                throw new TimeoutException(
                    "Timed out waiting for pending confirms to drop below {$this->maxPendingConfirms} " .
                    "(currently {$this->pendingConfirms})"
                );
            }
            $this->connection->readLoop(maxFrames: 1, timeout: $remaining);
        }
    }

    public function close(): void
    {
        $this->connection->unregisterPublisher($this->publisherId);
        $this->connection->sendMessage(new DeletePublisherRequestV1($this->publisherId));
        $this->connection->readMessage();
    }

    public function waitForConfirms(float $timeout = 5.0): void
    {
        if ($this->pendingConfirms === 0) {
            return;
        }

        $deadline = microtime(true) + $timeout;
        while ($this->pendingConfirms > 0) {
            $remaining = $deadline - microtime(true);
            if ($remaining <= 0) {
                break;
            }
            $this->connection->readLoop(maxFrames: 1, timeout: $remaining);
        }
        if ($this->pendingConfirms > 0) {
            throw new TimeoutException(
                "Timed out waiting for {$this->pendingConfirms} publish confirms"
            );
        }
    }

    public function getLastPublishingId(): ?int
    {
        return $this->publishingId === 0 ? null : $this->publishingId - 1;
    }

    public function getPendingConfirms(): int
    {
        return $this->pendingConfirms;
    }

    public function querySequence(): int
    {
        if ($this->name === null) {
            throw new InvalidArgumentException('Cannot query sequence for unnamed producer');
        }
        $this->connection->sendMessage(
            new QueryPublisherSequenceRequestV1($this->name, $this->stream)
        );
        $response = $this->connection->readMessage();
        if (!$response instanceof QueryPublisherSequenceResponseV1) {
            throw UnexpectedResponseException::create(QueryPublisherSequenceResponseV1::class, $response);
        }
        return $response->getSequence();
    }
}
