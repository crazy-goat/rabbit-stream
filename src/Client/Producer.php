<?php

declare(strict_types=1);

namespace CrazyGoat\RabbitStream\Client;

use CrazyGoat\RabbitStream\Contract\ProducerInterface;
use CrazyGoat\RabbitStream\Enum\ResponseCodeEnum;
use CrazyGoat\RabbitStream\Exception\InvalidArgumentException;
use CrazyGoat\RabbitStream\Exception\ProtocolException;
use CrazyGoat\RabbitStream\Exception\TimeoutException;
use CrazyGoat\RabbitStream\Exception\UnexpectedResponseException;
use CrazyGoat\RabbitStream\Request\DeclarePublisherRequestV1;
use CrazyGoat\RabbitStream\Request\DeletePublisherRequestV1;
use CrazyGoat\RabbitStream\Request\PublishRequestV1;
use CrazyGoat\RabbitStream\Request\PublishRequestV2;
use CrazyGoat\RabbitStream\Request\QueryPublisherSequenceRequestV1;
use CrazyGoat\RabbitStream\Response\MetadataUpdateResponseV1;
use CrazyGoat\RabbitStream\Response\QueryPublisherSequenceResponseV1;
use CrazyGoat\RabbitStream\StreamConnection;
use CrazyGoat\RabbitStream\VO\PublishedMessage;
use CrazyGoat\RabbitStream\VO\PublishedMessageV2;

/**
 * High-level publisher for one stream.
 *
 * Recovery after MetadataUpdate: when the broker reports the stream as
 * unavailable (deleted, leader moved) it also forgets every publisher declared
 * on it. The producer registers a per-stream handler on the connection
 * ({@see StreamConnection::registerMetadataUpdateHandler()}) and marks itself
 * stale; the next send()/sendBatch()/sendWithFilter() re-runs DeclarePublisher
 * (retrying with back-off for up to $redeclareTimeout seconds while the stream
 * is being recreated) before publishing. A PublishError with
 * PUBLISHER_NOT_EXIST or STREAM_NOT_AVAILABLE is treated the same way.
 *
 * Messages that were in flight when the stream went away are never confirmed
 * by the broker; they are reported to the onConfirm callback as failed
 * (ConfirmationStatus with the MetadataUpdate response code) so the
 * application can decide whether to resend them. A named producer re-reads
 * its publishing sequence from the broker on re-declare, so ids never collide.
 *
 * Note: this handles the single-node / delete-and-recreate case. In a cluster,
 * a leader move to another node cannot be followed on the same connection
 * (publishers must be connected to the leader); the re-declare then fails
 * with STREAM_NOT_AVAILABLE after $redeclareTimeout.
 */
class Producer implements ProducerInterface
{
    public const DEFAULT_MAX_PENDING_CONFIRMS = 10000;
    public const DEFAULT_REDECLARE_TIMEOUT = 5.0;
    private const DEFAULT_BACKPRESSURE_TIMEOUT = 30.0;
    private const REDECLARE_INITIAL_BACKOFF = 0.05;
    private const REDECLARE_MAX_BACKOFF = 1.0;

    private int $publishingId = 0;
    private int $pendingConfirms = 0;

    private readonly ?\Closure $onConfirm;

    /** Called with the publisher id once close() has run, so the owning Connection can reclaim it (#388). */
    private readonly ?\Closure $onClose;
    private bool $closed = false;

    /** Set by a MetadataUpdate for our stream (or a fatal PublishError): the broker no longer knows this publisher. */
    private bool $stale = false;
    private ?int $staleCode = null;
    private int $redeclareCount = 0;

    public function __construct(
        private readonly StreamConnection $connection,
        private readonly string $stream,
        private readonly int $publisherId,
        private readonly ?string $name = null,
        ?callable $onConfirm = null,
        private readonly int $maxPendingConfirms = self::DEFAULT_MAX_PENDING_CONFIRMS,
        private readonly float $redeclareTimeout = self::DEFAULT_REDECLARE_TIMEOUT,
        ?callable $onClose = null,
    ) {
        if ($this->redeclareTimeout < 0) {
            throw new InvalidArgumentException('redeclareTimeout must be >= 0');
        }
        $this->onConfirm = $onConfirm !== null ? \Closure::fromCallable($onConfirm) : null;
        $this->onClose = $onClose !== null ? \Closure::fromCallable($onClose) : null;
        $this->declare();
        $this->initializePublishingId();
    }

    /**
     * Whether the broker has dropped this publisher (MetadataUpdate / fatal
     * PublishError) and the next publish will re-declare it first.
     */
    public function isStale(): bool
    {
        return $this->stale;
    }

    /**
     * Number of successful re-declarations after a MetadataUpdate.
     */
    public function getRedeclareCount(): int
    {
        return $this->redeclareCount;
    }

    private function markStale(int $code): void
    {
        $this->stale = true;
        $this->staleCode = $code;
        // The broker forgot the publisher together with its unconfirmed
        // messages: they will never be confirmed. Report them as failed so the
        // application can resend, and stop counting them against back-pressure.
        $lost = $this->pendingConfirms;
        $this->pendingConfirms = 0;
        if ($lost > 0 && $this->onConfirm instanceof \Closure) {
            for ($id = $this->publishingId - $lost; $id < $this->publishingId; $id++) {
                ($this->onConfirm)(new ConfirmationStatus(false, errorCode: $code, publishingId: $id));
            }
        }
    }

    /**
     * Re-declare the publisher after the broker dropped it. Retries with
     * exponential back-off while the stream does not exist / is not available
     * (it is typically being recreated), for up to $redeclareTimeout seconds.
     *
     * @throws ProtocolException with the broker's last response code when the stream is still gone
     */
    private function ensureDeclared(): void
    {
        if (!$this->stale) {
            return;
        }

        $deadline = microtime(true) + $this->redeclareTimeout;
        $backoff = self::REDECLARE_INITIAL_BACKOFF;
        while (true) {
            try {
                $this->connection->request(new DeclarePublisherRequestV1(
                    $this->publisherId,
                    $this->name,
                    $this->stream
                ));
                break;
            } catch (ProtocolException $e) {
                $retryable = in_array(
                    $e->getResponseCode(),
                    [ResponseCodeEnum::STREAM_NOT_EXIST, ResponseCodeEnum::STREAM_NOT_AVAILABLE],
                    true
                );
                if (!$retryable || microtime(true) + $backoff > $deadline) {
                    throw new ProtocolException(
                        sprintf(
                            'Publisher %d on stream "%s" was dropped by the broker (code 0x%04x) and could not be '
                            . 're-declared within %.1fs: %s',
                            $this->publisherId,
                            $this->stream,
                            $this->staleCode ?? 0,
                            $this->redeclareTimeout,
                            $e->getMessage()
                        ),
                        previous: $e,
                        responseCode: $e->getResponseCode()
                    );
                }
                usleep((int) ($backoff * 1_000_000));
                $backoff = min($backoff * 2, self::REDECLARE_MAX_BACKOFF);
            }
        }

        $this->stale = false;
        $this->staleCode = null;
        $this->redeclareCount++;
        // A recreated stream starts its dedup sequence from scratch; a leader
        // move keeps it. Either way the broker knows best.
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
                $fatal = null;
                foreach ($errors as $error) {
                    if ($this->onConfirm instanceof \Closure) {
                        ($this->onConfirm)(new ConfirmationStatus(
                            false,
                            errorCode: $error->getCode(),
                            publishingId: $error->getPublishingId()
                        ));
                    }
                    if (
                        $error->getCode() === ResponseCodeEnum::PUBLISHER_NOT_EXIST->value
                        || $error->getCode() === ResponseCodeEnum::STREAM_NOT_AVAILABLE->value
                    ) {
                        $fatal = $error->getCode();
                    }
                }
                // The broker does not know this publisher any more (we may have
                // missed the MetadataUpdate, e.g. it arrived on a frame nobody
                // read yet): re-declare before the next publish.
                if ($fatal !== null && !$this->stale) {
                    $this->markStale($fatal);
                }
            }
        );

        $this->connection->registerMetadataUpdateHandler(
            $this->stream,
            "publisher-{$this->publisherId}",
            function (MetadataUpdateResponseV1 $update): void {
                $this->markStale($update->getCode());
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
        $this->ensureDeclared();
        $this->applyBackpressure($timeout);
        // Both counters are advanced only after a successful write: a throwing
        // send() used to leave pendingConfirms raised forever, so every later
        // waitForConfirms() blocked for its full timeout and then threw, for a
        // message the broker had never seen (GitHub #395).
        $this->connection->sendMessage(new PublishRequestV1(
            $this->publisherId,
            new PublishedMessage($this->publishingId, AmqpMessageEncoder::encodeDataSection($message))
        ), $timeout);
        $this->publishingId++;
        $this->pendingConfirms++;
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
        $this->ensureDeclared();
        $this->applyBackpressure($timeout);
        // Counters advance only after a successful write — see send() (#395).
        $this->connection->sendMessage(new PublishRequestV2(
            $this->publisherId,
            new PublishedMessageV2(
                $this->publishingId,
                $filterValue ?? '',
                AmqpMessageEncoder::encodeDataSection($message)
            )
        ), $timeout);
        $this->publishingId++;
        $this->pendingConfirms++;
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
        $this->ensureDeclared();
        $this->applyBackpressure($timeout);
        $published = [];
        $publishingId = $this->publishingId;
        foreach ($messages as $message) {
            $published[] = new PublishedMessage($publishingId++, AmqpMessageEncoder::encodeDataSection($message));
        }
        // Counters advance only after a successful write — see send() (#395).
        $this->connection->sendMessage(new PublishRequestV1($this->publisherId, ...$published), $timeout);
        $this->publishingId = $publishingId;
        $this->pendingConfirms += count($published);
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

    /**
     * Delete the publisher on the broker and release its id.
     *
     * Idempotent: a second call is a no-op, so the publisher id cannot be
     * handed back twice (and then to two live producers at once).
     */
    public function close(): void
    {
        if ($this->closed) {
            return;
        }
        $this->closed = true;

        $this->connection->unregisterPublisher($this->publisherId);
        $this->connection->unregisterMetadataUpdateHandler($this->stream, "publisher-{$this->publisherId}");

        try {
            if (!$this->stale) {
                // A stale publisher is already gone on the broker;
                // DeletePublisher would only earn a PUBLISHER_NOT_EXIST error.
                $this->connection->sendMessage(new DeletePublisherRequestV1($this->publisherId));
                $this->connection->readMessage();
            }
        } finally {
            // The id goes back to the pool even when DeletePublisher fails —
            // this producer will never use it again either way (#388).
            if ($this->onClose instanceof \Closure) {
                ($this->onClose)($this->publisherId);
            }
        }
    }

    /** Whether close() has already run. */
    public function isClosed(): bool
    {
        return $this->closed;
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
