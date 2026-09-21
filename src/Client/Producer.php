<?php

declare(strict_types=1);

namespace CrazyGoat\RabbitStream\Client;

use CrazyGoat\RabbitStream\Contract\ProducerInterface;
use CrazyGoat\RabbitStream\Enum\KeyEnum;
use CrazyGoat\RabbitStream\Enum\ResponseCodeEnum;
use CrazyGoat\RabbitStream\Exception\ConnectionException;
use CrazyGoat\RabbitStream\Exception\DeserializationException;
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
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

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
    /**
     * How long close() waits for in-flight PublishConfirm/PublishError frames
     * to drain before giving up (GitHub #474).
     */
    private const CLOSE_CONFIRM_DRAIN_TIMEOUT = 2.0;

    private int $publishingId = 0;

    /**
     * Publishing ids that have been sent but not yet confirmed (nor reported as
     * failed). Keyed by id so a duplicate confirm for an already-confirmed id is
     * a no-op instead of drifting a bare counter (GitHub #521).
     *
     * @var array<int, true>
     */
    private array $pendingConfirms = [];

    private readonly ?\Closure $onConfirm;

    /** Called with the publisher id once close() has run, so the owning Connection can reclaim it (#388). */
    private readonly ?\Closure $onClose;
    private bool $closed = false;

    /**
     * Cumulative number of unconfirmed publishes abandoned by a bounded
     * close() drain timeout. Those confirms can never arrive (the publisher id
     * is released), so they are reported here and at warning level instead of
     * disappearing silently (GitHub #522).
     */
    private int $lostConfirmCount = 0;

    private readonly LoggerInterface $logger;

    /** Set by a MetadataUpdate for our stream (or a fatal PublishError): the broker no longer knows this publisher. */
    private bool $stale = false;
    private ?int $staleCode = null;
    private int $redeclareCount = 0;

    /**
     * Create a producer and immediately declare it on the broker.
     *
     * The DeclarePublisher round trip runs from the constructor, and a named
     * producer also runs a QueryPublisherSequence round trip, so a broker
     * rejection or a transport failure surfaces here rather than on the first
     * send(). The owning Connection allocates the publisher id and passes it
     * in; direct instantiation is not recommended — use
     * Connection::createProducer().
     *
     * @param StreamConnection $connection Connection the producer publishes on.
     * @param string $stream Stream to publish to.
     * @param int $publisherId Publisher id already reserved on $connection.
     * @param string|null $name Unique producer name for broker-side
     *                          deduplication; null (or "") for an anonymous
     *                          producer. A named producer queries its last
     *                          confirmed publishing sequence on construction, so
     *                          getLastPublishingId() can be non-null before the
     *                          first send().
     * @param callable|null $onConfirm Called with (ConfirmationStatus $status)
     *                          for each publish as confirms/errors arrive; null
     *                          to disable the callback.
     * @param int $maxPendingConfirms Back-pressure cap on outstanding
     *                          (unconfirmed) publishes; once reached,
     *                          send()/sendBatch()/sendWithFilter() block
     *                          draining confirms until the count drops below
     *                          it. 0 disables the cap.
     * @param float $redeclareTimeout Seconds a publish keeps retrying
     *                          DeclarePublisher after a MetadataUpdate dropped
     *                          the publisher; 0 fails on the first attempt. Must
     *                          be >= 0.
     * @param callable|null $onClose Called with the publisher id once close()
     *                          has run, so the owning Connection can reclaim
     *                          it; null to disable the callback.
     * @param LoggerInterface|null $logger PSR-3 logger for warnings (e.g. lost
     *                          confirms); defaults to a NullLogger.
     * @throws InvalidArgumentException If $redeclareTimeout is negative.
     * @throws ConnectionException If the socket is not connected or the
     *                          DeclarePublisher/QueryPublisherSequence exchange
     *                          fails.
     * @throws DeserializationException If a handshake response frame cannot be
     *                          deserialized.
     * @throws ProtocolException If the broker rejects DeclarePublisher with a
     *                          non-OK response code, or a frame has an
     *                          unexpected version or command.
     * @throws TimeoutException If a handshake response does not arrive in time.
     * @throws UnexpectedResponseException If the QueryPublisherSequence reply is
     *                          not a QueryPublisherSequenceResponseV1.
     */
    public function __construct(
        private readonly StreamConnection $connection,
        private readonly string $stream,
        private readonly int $publisherId,
        private readonly ?string $name = null,
        ?callable $onConfirm = null,
        private readonly int $maxPendingConfirms = self::DEFAULT_MAX_PENDING_CONFIRMS,
        private readonly float $redeclareTimeout = self::DEFAULT_REDECLARE_TIMEOUT,
        ?callable $onClose = null,
        ?LoggerInterface $logger = null,
    ) {
        if ($this->redeclareTimeout < 0) {
            throw new InvalidArgumentException('redeclareTimeout must be >= 0');
        }
        $this->onConfirm = $onConfirm !== null ? \Closure::fromCallable($onConfirm) : null;
        $this->onClose = $onClose !== null ? \Closure::fromCallable($onClose) : null;
        $this->logger = $logger ?? new NullLogger();
        $this->declare();
        $this->initializePublishingId();
    }

    /**
     * Whether the broker has dropped this publisher (MetadataUpdate / fatal
     * PublishError) and the next publish will re-declare it first.
     *
     * @return bool True while the publisher is stale and the next publish will
     *              re-run DeclarePublisher before sending.
     */
    public function isStale(): bool
    {
        return $this->stale;
    }

    /**
     * Number of successful re-declarations after a MetadataUpdate.
     *
     * @return int Count of successful re-declarations since construction; a
     *             number that keeps growing means the stream is flapping.
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
        // messages: they will never be confirmed. Report exactly the ids that
        // are still outstanding as failed so the application can resend, and
        // stop counting them against back-pressure. Reporting the raw set (not
        // a synthesised publishingId-lost..publishingId-1 range) means ids that
        // were already confirmed are never double-reported (GitHub #521).
        $lost = array_keys($this->pendingConfirms);
        $this->pendingConfirms = [];
        if ($this->onConfirm instanceof \Closure) {
            foreach ($lost as $id) {
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
                foreach ($publishingIds as $id) {
                    // A duplicate confirm for an id that is no longer
                    // outstanding is ignored: decrementing a bare counter for
                    // it used to drift pendingConfirms far enough that
                    // waitForConfirms() could return before every real publish
                    // was confirmed (GitHub #521).
                    if (!isset($this->pendingConfirms[$id])) {
                        continue;
                    }
                    unset($this->pendingConfirms[$id]);
                    if ($this->onConfirm instanceof \Closure) {
                        ($this->onConfirm)(new ConfirmationStatus(true, publishingId: $id));
                    }
                }
            },
            onError: function (array $errors): void {
                $fatal = null;
                foreach ($errors as $error) {
                    if (
                        $error->getCode() === ResponseCodeEnum::PUBLISHER_NOT_EXIST->value
                        || $error->getCode() === ResponseCodeEnum::STREAM_NOT_AVAILABLE->value
                    ) {
                        $fatal = $error->getCode();
                    }
                    $publishingId = $error->getPublishingId();
                    // A duplicate error for an id that is no longer outstanding
                    // has already been reported; ignore it (GitHub #521).
                    if (!isset($this->pendingConfirms[$publishingId])) {
                        continue;
                    }
                    unset($this->pendingConfirms[$publishingId]);
                    if ($this->onConfirm instanceof \Closure) {
                        ($this->onConfirm)(new ConfirmationStatus(
                            false,
                            errorCode: $error->getCode(),
                            publishingId: $publishingId
                        ));
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
     * The message is encoded as a single AMQP 1.0 Data section wrapping the
     * raw payload bytes, so a consumer's Message::getBody() returns the exact
     * same string. Publishing a value that is already a full AMQP message would
     * wrap it a second time; use AmqpMessageEncoder::encodeDataSection() and
     * the low-level API for pre-encoded bytes instead.
     *
     * @param string $message plain payload (e.g. UTF-8 string or binary data). It is
     *                        automatically wrapped in an AMQP 1.0 Data section on the
     *                        wire, so a consumer's Message::getBody() returns the same
     *                        string unchanged. Use AmqpMessageEncoder::encodeDataSection()
     *                        when publishing pre-encoded bytes via the low-level API.
     * @param ?float $timeout socket write timeout in seconds; null uses connection default
     * @throws ConnectionException If the socket is not connected or the
     *                        Publish/DeclarePublisher write or read fails.
     * @throws DeserializationException If a response or server-push frame read
     *                        while draining back-pressure cannot be deserialized.
     * @throws InvalidArgumentException If the serialized Publish request
     *                        exceeds the negotiated outgoing frame size.
     * @throws ProtocolException If the broker rejects a re-declare with a
     *                        non-OK response code, or a frame has an unexpected
     *                        version or command.
     * @throws TimeoutException If the write, a re-declare, or the
     *                        maxPendingConfirms back-pressure drain times out.
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
        $this->pendingConfirms[$this->publishingId] = true;
        $this->publishingId++;
    }

    /**
     * Publish a single message tagged with a stream-filtering value.
     *
     * The frame version is chosen from the broker's negotiated command
     * versions: a non-null $filterValue needs Publish v2
     * (`PublishRequestV2`/`PublishedMessageV2`), which carries the per-message
     * `filterValue` the broker hashes into a per-chunk bloom filter. With a
     * null filter value, and on a broker that never negotiated Publish v2, the
     * plain v1 frame is sent instead — v1 has no filter field. Asking for a
     * non-null filter on such a broker is a hard error rather than a silent
     * unfiltered publish.
     *
     * Filtering is CHUNK-granular, not message-granular: a delivered chunk can
     * still contain non-matching messages, so callers that need exact filtering
     * must also post-filter on the consume side using the same filter value
     * convention.
     *
     * @param string      $message    plain payload (see send())
     * @param string|null $filterValue value hashed into the chunk's bloom filter;
     *                                 null publishes without a filter value (never
     *                                 matches an active filter, always delivered
     *                                 when `matchUnfiltered` is enabled)
     * @param ?float      $timeout    socket write timeout in seconds; null uses connection default
     * @throws ConnectionException If the socket is not connected or the
     *                                 Publish/DeclarePublisher write or read fails.
     * @throws DeserializationException If a response or server-push frame read
     *                                 while draining back-pressure cannot be deserialized.
     * @throws InvalidArgumentException If the serialized Publish request
     *                                 exceeds the negotiated outgoing frame size.
     * @throws ProtocolException If $filterValue is not null but the broker does
     *                                 not support Publish v2, or a re-declare is
     *                                 rejected with a non-OK response code.
     * @throws TimeoutException If the write, a re-declare, or the
     *                                 maxPendingConfirms back-pressure drain times out.
     */
    public function sendWithFilter(string $message, ?string $filterValue, ?float $timeout = null): void
    {
        $this->ensureDeclared();
        $this->applyBackpressure($timeout);

        if ($filterValue !== null && !$this->connection->supportsCommandVersion(KeyEnum::PUBLISH, 2)) {
            throw new ProtocolException(
                'The broker does not support Publish v2 (per-message filter values); '
                . 'cannot publish a message with a filter value'
            );
        }

        // Counters advance only after a successful write — see send() (#395).
        if ($filterValue === null) {
            // Publish v1 per the protocol: use it when there is no filter value.
            $this->connection->sendMessage(new PublishRequestV1(
                $this->publisherId,
                new PublishedMessage($this->publishingId, AmqpMessageEncoder::encodeDataSection($message))
            ), $timeout);
        } else {
            $this->connection->sendMessage(new PublishRequestV2(
                $this->publisherId,
                new PublishedMessageV2(
                    $this->publishingId,
                    $filterValue,
                    AmqpMessageEncoder::encodeDataSection($message)
                )
            ), $timeout);
        }
        $this->pendingConfirms[$this->publishingId] = true;
        $this->publishingId++;
    }

    /**
     * Publish multiple messages in a single batch.
     *
     * An empty array is a no-op and returns without touching the socket.
     *
     * @param string[] $messages plain payloads; each one is automatically wrapped in an
     *                           AMQP 1.0 Data section on the wire (see send())
     * @param ?float $timeout socket write timeout in seconds; null uses connection default
     * @throws ConnectionException If the socket is not connected or the
     *                           Publish/DeclarePublisher write or read fails.
     * @throws DeserializationException If a response or server-push frame read
     *                           while draining back-pressure cannot be deserialized.
     * @throws InvalidArgumentException If the serialized Publish request
     *                           exceeds the negotiated outgoing frame size.
     * @throws ProtocolException If the broker rejects a re-declare with a
     *                           non-OK response code, or a frame has an
     *                           unexpected version or command.
     * @throws TimeoutException If the write, a re-declare, or the
     *                           maxPendingConfirms back-pressure drain times out.
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
        for ($id = $this->publishingId; $id < $publishingId; $id++) {
            $this->pendingConfirms[$id] = true;
        }
        $this->publishingId = $publishingId;
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
        if ($this->maxPendingConfirms <= 0 || count($this->pendingConfirms) < $this->maxPendingConfirms) {
            return;
        }

        $deadline = microtime(true) + ($timeout ?? self::DEFAULT_BACKPRESSURE_TIMEOUT);
        while (($pending = count($this->pendingConfirms)) >= $this->maxPendingConfirms) {
            $remaining = $deadline - microtime(true);
            if ($remaining <= 0) {
                throw new TimeoutException(
                    "Timed out waiting for pending confirms to drop below {$this->maxPendingConfirms} " .
                    "(currently {$pending})"
                );
            }
            $this->connection->readLoop(maxFrames: 1, timeout: $remaining);
        }
    }

    /**
     * Delete the publisher on the broker and release its id.
     *
     * Idempotent: a second call is a no-op, so the publisher id cannot be
     * handed back twice (and then to two live producers at once). The confirm
     * callback stays registered through the DeletePublisher exchange and any
     * still-in-flight confirms are drained (bounded) before the id is released;
     * anything not drained in time is counted by getLostConfirmCount().
     *
     * @throws ConnectionException If the socket is not connected or the
     *                          DeletePublisher write or read fails.
     * @throws DeserializationException If a response or server-push frame read
     *                          while draining confirms cannot be deserialized.
     * @throws InvalidArgumentException If the serialized DeletePublisher
     *                          request exceeds the negotiated outgoing frame size.
     * @throws ProtocolException If the DeletePublisher response has an
     *                          unexpected version or command.
     * @throws TimeoutException If the DeletePublisher response does not arrive
     *                          in time.
     */
    public function close(): void
    {
        if ($this->closed) {
            return;
        }
        $this->closed = true;

        try {
            if (!$this->stale) {
                // A stale publisher is already gone on the broker;
                // DeletePublisher would only earn a PUBLISHER_NOT_EXIST error.
                $this->connection->sendMessage(new DeletePublisherRequestV1($this->publisherId));
                // The confirm callback must stay registered until the
                // DeletePublisher response has been read: PublishConfirm /
                // PublishError frames for messages still in flight may arrive
                // on the socket while we wait, and dropping them would leave
                // pendingConfirms raised forever (GitHub #474).
                $this->connection->readMessage();
                $this->drainPendingConfirms();
            }
        } finally {
            $this->connection->unregisterPublisher($this->publisherId);
            $this->connection->unregisterMetadataUpdateHandler($this->stream, "publisher-{$this->publisherId}");
            // The id goes back to the pool even when DeletePublisher fails —
            // this producer will never use it again either way (#388).
            if ($this->onClose instanceof \Closure) {
                ($this->onClose)($this->publisherId);
            }
        }
    }

    /**
     * Drain PublishConfirm/PublishError frames for messages published before
     * close(). Runs while the confirm callback is still registered so the
     * frames decrement pendingConfirms instead of being dropped.
     *
     * Bounded: if the broker never confirms the in-flight messages within
     * CLOSE_CONFIRM_DRAIN_TIMEOUT, close() gives up rather than hanging. The
     * abandoned confirms are then lost, but they are counted in
     * getLostConfirmCount() and logged at warning level (GitHub #522).
     */
    private function drainPendingConfirms(): void
    {
        // Bounded drain: leftover confirms after the timeout are lost. Do not
        // let them vanish silently — the caller is closing the producer and
        // will never see an onConfirm for them, so count them and log the
        // count plus a bounded prefix of the affected publishing ids (GitHub
        // #522). The full id list can be unbounded in fire-and-forget mode
        // (maxPendingConfirms: 0), so it must never reach the logger verbatim.
        if ($this->drainUntilZero(self::CLOSE_CONFIRM_DRAIN_TIMEOUT)) {
            return;
        }

        $lost = array_keys($this->pendingConfirms);
        $lostCount = count($lost);
        $this->lostConfirmCount += $lostCount;
        $message = sprintf(
            'Producer %d on stream "%s" closed after the %.1fs drain timeout with %d publish(es) '
            . 'still unconfirmed; their confirms are lost',
            $this->publisherId,
            $this->stream,
            self::CLOSE_CONFIRM_DRAIN_TIMEOUT,
            $lostCount
        );
        if ($lostCount > StreamConnection::MAX_LOGGED_PUBLISHING_IDS) {
            $message .= sprintf(
                ' (%d publishing ids in total; only the first %d are logged)',
                $lostCount,
                StreamConnection::MAX_LOGGED_PUBLISHING_IDS
            );
        }
        $this->logger->warning(
            $message,
            [
                'publisherId' => $this->publisherId,
                'stream' => $this->stream,
                'lostCount' => $lostCount,
                'publishingIds' => array_slice($lost, 0, StreamConnection::MAX_LOGGED_PUBLISHING_IDS),
            ]
        );
    }

    /**
     * Run readLoop() until pendingConfirms reaches 0 or the deadline expires.
     * Returns true when drained, false on timeout.
     */
    private function drainUntilZero(float $timeout): bool
    {
        $deadline = microtime(true) + $timeout;
        while (count($this->pendingConfirms) > 0) {
            $remaining = $deadline - microtime(true);
            if ($remaining <= 0) {
                return false;
            }
            $this->connection->readLoop(maxFrames: 1, timeout: $remaining);
        }

        return true;
    }

    /**
     * Whether close() has already run.
     *
     * @return bool True once close() has been called, even when the
     *              DeletePublisher exchange failed (the id is still released).
     */
    public function isClosed(): bool
    {
        return $this->closed;
    }

    /**
     * Block until every outstanding publish has been confirmed or failed.
     *
     * Returns immediately when nothing is outstanding. Reads
     * PublishConfirm/PublishError frames off the socket (dispatching them to
     * the onConfirm callback) until pendingConfirms reaches 0 or the deadline
     * passes. A timeout does not discard the outstanding confirms: they can
     * still arrive on a later waitForConfirms()/readLoop().
     *
     * @param float $timeout Maximum seconds to wait for the outstanding confirms.
     * @throws TimeoutException If confirms are still outstanding when $timeout expires.
     * @throws ConnectionException If the socket is not connected or a read fails.
     * @throws DeserializationException If a PublishConfirm/PublishError or
     *                       other frame read while waiting cannot be deserialized.
     */
    public function waitForConfirms(float $timeout = 5.0): void
    {
        if ($this->pendingConfirms === []) {
            return;
        }

        if (!$this->drainUntilZero($timeout)) {
            throw new TimeoutException(
                'Timed out waiting for ' . count($this->pendingConfirms) . ' publish confirms'
            );
        }
    }

    /**
     * The publishing id of the most recent publish, or null if none was sent.
     *
     * Counter-intuitive for a named producer: the constructor queries the
     * broker's last confirmed sequence and resumes from sequence + 1, so this
     * can return a non-null id (0 when the broker stored nothing) BEFORE the
     * first send(). An anonymous producer starts at id 1 and returns null until
     * its first send().
     *
     * @return int|null Last publishing id used, or null when nothing has been
     *                  published yet (anonymous producer before its first send()).
     */
    public function getLastPublishingId(): ?int
    {
        return $this->publishingId === 0 ? null : $this->publishingId - 1;
    }

    /**
     * Number of publishes sent but not yet confirmed or reported failed.
     *
     * Grows on each successful send/sendBatch/sendWithFilter and shrinks as
     * PublishConfirm/PublishError frames are dispatched (via waitForConfirms(),
     * readLoop(), the maxPendingConfirms back-pressure drain or close()). After
     * close(), ids stranded by the bounded drain are still counted here (see
     * getLostConfirmCount()).
     *
     * @return int Current number of outstanding (unconfirmed) publishes.
     */
    public function getPendingConfirms(): int
    {
        return count($this->pendingConfirms);
    }

    /**
     * Cumulative number of publishes whose confirms were abandoned by a
     * bounded close() drain timeout (GitHub #522).
     *
     * close() waits up to CLOSE_CONFIRM_DRAIN_TIMEOUT for in-flight
     * PublishConfirm/PublishError frames; anything still outstanding when that
     * expires can never be confirmed because the publisher id is released.
     * Each such publish is logged at warning level and counted here, so an
     * operator can tell "the broker stopped confirming" from "everything
     * drained". The set of stranded ids is also still reported by
     * getPendingConfirms() after close().
     *
     * @return int Cumulative number of publishes whose confirms were lost to a
     *             close() drain timeout (0 in normal operation).
     */
    public function getLostConfirmCount(): int
    {
        return $this->lostConfirmCount;
    }

    /**
     * Query the broker for this named producer's last confirmed publishing id.
     *
     * Used for deduplication: on reconnect a named producer resumes from the
     * returned id + 1, and the broker drops any publish whose id is <= the
     * stored sequence. Also called during construction
     * (initializePublishingId()), which is why a named producer's publishing id
     * — and therefore getLastPublishingId() — is set before the first send().
     *
     * @return int Highest publishing id the broker has confirmed for this
     *             producer's name on its stream (0 when nothing was stored).
     * @throws InvalidArgumentException If this is an anonymous producer (no
     *                          name), for which there is no sequence to query.
     * @throws ConnectionException If the socket is not connected or the
     *                          QueryPublisherSequence exchange fails.
     * @throws DeserializationException If the response frame cannot be deserialized.
     * @throws ProtocolException If the broker answers with a non-OK response
     *                          code, or a frame has an unexpected version or command.
     * @throws TimeoutException If the response does not arrive in time.
     * @throws UnexpectedResponseException If the reply is not a
     *                          QueryPublisherSequenceResponseV1.
     */
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
