<?php

declare(strict_types=1);

namespace CrazyGoat\RabbitStream\Client;

use CrazyGoat\RabbitStream\Contract\ConsumerInterface;
use CrazyGoat\RabbitStream\Enum\ResponseCodeEnum;
use CrazyGoat\RabbitStream\Exception\ConnectionException;
use CrazyGoat\RabbitStream\Exception\DeserializationException;
use CrazyGoat\RabbitStream\Exception\InvalidArgumentException;
use CrazyGoat\RabbitStream\Exception\ProtocolException;
use CrazyGoat\RabbitStream\Exception\TimeoutException;
use CrazyGoat\RabbitStream\Exception\UnexpectedResponseException;
use CrazyGoat\RabbitStream\Request\CreditRequestV1;
use CrazyGoat\RabbitStream\Request\QueryOffsetRequestV1;
use CrazyGoat\RabbitStream\Request\StoreOffsetRequestV1;
use CrazyGoat\RabbitStream\Request\StreamStatsRequestV1;
use CrazyGoat\RabbitStream\Request\SubscribeRequestV1;
use CrazyGoat\RabbitStream\Request\UnsubscribeRequestV1;
use CrazyGoat\RabbitStream\Response\ConsumerUpdateResponseV1;
use CrazyGoat\RabbitStream\Response\QueryOffsetResponseV1;
use CrazyGoat\RabbitStream\Response\StreamStatsResponseV1;
use CrazyGoat\RabbitStream\StreamConnection;
use CrazyGoat\RabbitStream\VO\OffsetSpec;

/**
 * Consumes messages from a stream subscription.
 *
 * Chunk-vs-message semantics: the server delivers whole chunks (one Deliver
 * frame = one chunk, atomic on the wire, containing anywhere from 1 to
 * thousands of messages) and its credit system is chunk-granular — 1 credit
 * grants exactly 1 future chunk delivery. `maxBufferSize`, by contrast, is a
 * MESSAGE bound: the target ceiling on unread messages held in memory. Because
 * a delivered chunk is never split or dropped (at-least-once delivery, no
 * message is ever discarded once it arrives), the buffer can transiently hold
 * more than `maxBufferSize` messages: no new credit is granted once the buffer
 * is full, but every chunk already granted (in flight) still arrives in full,
 * so the overshoot is bounded by the chunks in flight — at most `creditsInFlight`
 * chunks, each granted while it was within the target in force at grant time
 * (≤ `MAX_CREDIT`) — not by a single chunk. What `maxBufferSize` actually
 * controls is credit: once the unread count reaches or exceeds it, no further
 * credit is granted, so the server stops delivering new chunks until the buffer
 * drains back below the limit. Credits withheld this way are remembered
 * (`pendingCredits`, itself a chunk-granular counter) and granted back — one
 * credit per chunk's worth of headroom that reopens — as the application drains
 * the buffer via read()/readOne(). Outstanding (in-flight, i.e.
 * sent-but-not-yet-consumed) credit is bounded by the adaptive `creditTarget`
 * only at the moment each chunk's credit is granted: `observeChunkSize()` may
 * later shrink `creditTarget` below `creditsInFlight` (a larger chunk raises the
 * average and lowers the target), and granted credit is not revocable, so
 * in-flight can transiently exceed the current target (it stays ≤ `MAX_CREDIT`).
 * `initialCredit` is the floor of the target.
 */
class Consumer implements ConsumerInterface
{
    /**
     * Largest credit value that is safe to put in a Credit frame. The protocol
     * documents the field as uint16, but RabbitMQ (verified on 4.3.5) decodes
     * it as a signed 16-bit integer: 32768 and above become negative, the
     * subscription's credit goes below zero and it silently stops delivering.
     * Subscribe's initial credit does not have this problem, but the same cap
     * is applied everywhere for one consistent limit.
     */
    public const MAX_CREDIT = 32767;

    /**
     * Default adaptive credit window: keep roughly this many bytes of chunks in
     * flight, converted to a chunk count using the observed chunk size (#500).
     */
    public const DEFAULT_CREDIT_WINDOW_BYTES = 8 * 1024 * 1024;

    /** @var Message[] */
    private array $buffer = [];
    private int $bufferHead = 0;
    private int $unreadCount = 0;
    private int $messagesProcessed = 0;
    private int $lastOffset = 0;
    private bool $hasProcessedMessage = false;

    /** Credit units (1 unit = 1 chunk) withheld because the buffer had no room when a chunk arrived. */
    private int $pendingCredits = 0;

    /** Credit units (1 unit = 1 chunk) already sent to the server but not yet consumed by a delivered chunk. */
    private int $creditsInFlight = 0;

    /**
     * Chunks the consumer currently wants in flight. Starts at initialCredit and,
     * when creditWindowBytes > 0, follows creditWindowBytes / observed chunk size
     * (never below initialCredit, never above MAX_CREDIT).
     */
    private int $creditTarget;

    /** Exponential moving average of delivered chunk sizes in bytes (0 until the first chunk). */
    private float $avgChunkBytes = 0.0;

    /**
     * Whether this consumer is currently allowed to receive messages. Always
     * true for a non-single-active-consumer subscription. A single active
     * consumer subscription starts as inactive and flips per the broker's
     * ConsumerUpdate query (see subscribe()).
     */
    private bool $active;

    private ?\Closure $consumerUpdateCallback = null;

    /**
     * Set by a MetadataUpdate for our stream: the broker dropped the
     * subscription. read()/readOne() re-subscribe transparently (with back-off
     * while the stream is being recreated) before waiting for messages.
     */
    private bool $subscriptionLost = false;
    private float $nextResubscribeAt = 0.0;
    private float $resubscribeBackoff = self::RESUBSCRIBE_INITIAL_BACKOFF;
    private int $resubscribeCount = 0;

    private const RESUBSCRIBE_INITIAL_BACKOFF = 0.05;
    private const RESUBSCRIBE_MAX_BACKOFF = 1.0;

    /** Called with the subscription id once close() has run, so the owning Connection can reclaim it (#388). */
    private readonly ?\Closure $onClose;
    private bool $closed = false;

    /**
     * Create a consumer and immediately subscribe it.
     *
     * The Subscribe request is sent from the constructor, so a broker rejection
     * or a transport failure surfaces here rather than on the first read(). The
     * constructor only completes the Subscribe round trip; it never waits for
     * messages.
     *
     * @param StreamConnection $connection Connection this consumer subscribes on.
     * @param string $stream Stream to consume from.
     * @param int $subscriptionId Subscription id already reserved on $connection.
     * @param OffsetSpec $offset Initial offset to consume from (inclusive; see
     *                            OffsetSpec::offset()).
     * @param string|null $name Consumer name. Required by storeOffset()/queryOffset()
     *                            and by single-active-consumer grouping; null for an
     *                            anonymous consumer.
     * @param int $autoCommit Store the next offset automatically after this many
     *                            processed messages; `0` disables auto-commit.
     * @param int $initialCredit Initial (and minimum) number of chunks in flight,
     *                            1..MAX_CREDIT. It is the floor of the adaptive
     *                            credit target, not a cap on outstanding credit:
     *                            the target may grow above it via $creditWindowBytes.
     * @param int $maxBufferSize Message-bound back-pressure ceiling: the target
     *                            maximum number of unread messages held in memory. A
     *                            delivered chunk is atomic and is never split or
     *                            dropped, so no new credit is granted once the
     *                            buffer is full, yet every chunk already granted
     *                            (in flight) still lands in full: the buffer may
     *                            exceed this by the chunks in flight (each granted
     *                            within the target in force then, ≤ MAX_CREDIT; a
     *                            later shrink of creditTarget does not revoke them),
     *                            not by a single chunk. When the unread count reaches
     *                            or exceeds this value no further credit is granted,
     *                            so the server stops delivering new chunks until
     *                            read()/readOne() drains the buffer below the limit.
     *                            Withheld credits are remembered (pendingCredits, a
     *                            chunk-granular counter) and granted back one credit
     *                            per re-opened chunk's worth of headroom. Must be
     *                            positive; see the class docblock for the full
     *                            chunk-vs-message and credit interaction.
     * @param array<int, string> $filterValues Stream filtering values (protocol
     *                            keys `filter.0`, `filter.1`, ... — broker-side,
     *                            chunk-granular; see Producer::sendWithFilter()).
     * @param bool $matchUnfiltered When $filterValues is non-empty, also deliver
     *                            messages published with no filter value.
     * @param bool $singleActiveConsumer Join the broker's single-active-consumer
     *                            group for $name (which is then required).
     * @param string|null $superStream Name of the super stream this stream is a
     *                            partition of, if any.
     * @param int $creditWindowBytes Target bytes in flight. Credit is chunk-granular
     *                            on the wire, and chunk size depends on how the
     *                            producer published (thousands of messages per chunk
     *                            for a batching producer on a plain stream, a handful
     *                            for a super-stream partition fed one message at a
     *                            time). The consumer measures the chunk size it sees
     *                            and grants ceil(creditWindowBytes / avgChunk) credits
     *                            (at least initialCredit), so small chunks over a
     *                            network do not collapse the window to a few messages
     *                            per round trip (#500). The target never exceeds
     *                            MAX_CREDIT. 0 disables the adaptation and keeps
     *                            exactly initialCredit chunks in flight.
     * @param int $maxDecodeDepth Maximum AMQP nesting depth accepted when a delivered
     *                            message is decoded (#397's limit, made configurable from
     *                            here by #450). Decoding is lazy — it happens inside
     *                            Message on the first accessor call — so the limit travels
     *                            with each Message instead of being applied in read().
     *                            The default of 32 is ample for real messages; raise it
     *                            only for a producer that legitimately nests deeper, and
     *                            keep in mind that a deeply nested frame costs one PHP
     *                            stack frame per level.
     * @param callable|null $onClose Called with the subscription id once close()
     *                            has run, so the owning Connection can reclaim it.
     * @param bool $verifyCrc Whether every delivered chunk's CRC-32 is verified
     *                            against its data section (#403). On by default;
     *                            disable only in throughput-critical deployments
     *                            that accept the risk of silently consuming
     *                            corrupted chunks.
     * @throws InvalidArgumentException If $maxBufferSize is not positive, $initialCredit
     *                            is outside 1..MAX_CREDIT, $creditWindowBytes is
     *                            negative, $maxDecodeDepth is below 1,
     *                            $singleActiveConsumer is set without $name, or the
     *                            serialized Subscribe request exceeds the negotiated
     *                            outgoing frame size.
     * @throws ProtocolException If the broker rejects the Subscribe with a non-OK
     *                            response code.
     * @throws ConnectionException If the socket is not connected or the Subscribe
     *                            write/read fails.
     * @throws DeserializationException If the Subscribe response frame cannot be
     *                            deserialized.
     * @throws TimeoutException If the Subscribe response does not arrive in time.
     */
    public function __construct(
        private readonly StreamConnection $connection,
        private readonly string $stream,
        private readonly int $subscriptionId,
        private readonly OffsetSpec $offset,
        private readonly ?string $name = null,
        private readonly int $autoCommit = 0,
        private readonly int $initialCredit = 10,
        private readonly int $maxBufferSize = 1000,
        private readonly array $filterValues = [],
        private readonly bool $matchUnfiltered = false,
        private readonly bool $singleActiveConsumer = false,
        private readonly ?string $superStream = null,
        private readonly int $creditWindowBytes = self::DEFAULT_CREDIT_WINDOW_BYTES,
        private readonly int $maxDecodeDepth = AmqpDecoder::MAX_RECURSION_DEPTH,
        ?callable $onClose = null,
        private readonly bool $verifyCrc = true,
    ) {
        $this->onClose = $onClose !== null ? \Closure::fromCallable($onClose) : null;
        if ($this->maxBufferSize <= 0) {
            throw new InvalidArgumentException('maxBufferSize must be greater than 0');
        }
        if ($this->initialCredit <= 0 || $this->initialCredit > self::MAX_CREDIT) {
            throw new InvalidArgumentException('initialCredit must be between 1 and ' . self::MAX_CREDIT);
        }
        if ($this->creditWindowBytes < 0) {
            throw new InvalidArgumentException('creditWindowBytes must be >= 0');
        }
        if ($this->maxDecodeDepth < 1) {
            throw new InvalidArgumentException('maxDecodeDepth must be greater than 0');
        }
        $this->creditTarget = $this->initialCredit;
        if ($this->singleActiveConsumer && $this->name === null) {
            throw new InvalidArgumentException(
                'singleActiveConsumer requires a consumer name (the broker groups '
                . 'single active consumers by reference/name)'
            );
        }
        $this->active = !$this->singleActiveConsumer;
        $this->subscribe();
    }

    /**
     * Override the default single-active-consumer resume logic.
     *
     * @param callable $callback Called with (bool $active, Consumer $this): ?OffsetSpec.
     *                           Return null to keep the current position (offsetType 0).
     */
    public function onConsumerUpdate(callable $callback): void
    {
        $this->consumerUpdateCallback = \Closure::fromCallable($callback);
    }

    /**
     * Whether this consumer is currently allowed to receive messages.
     *
     * Always true for a non-single-active-consumer subscription; for a
     * single-active-consumer subscription it follows the broker's ConsumerUpdate
     * and may be false while another group member holds the active slot.
     *
     * @return bool True when the broker has this consumer active, false while a
     *                            single-active-consumer handover has it paused.
     */
    public function isActive(): bool
    {
        return $this->active;
    }

    /**
     * Whether the broker dropped this subscription (MetadataUpdate: stream
     * deleted or leader moved) and it has not been re-established yet. The
     * next read()/readOne() keeps trying to re-subscribe.
     *
     * @return bool True while the subscription is lost, false once it has been
     *                            re-established (or was never lost).
     */
    public function isSubscriptionLost(): bool
    {
        return $this->subscriptionLost;
    }

    /**
     * Number of successful re-subscriptions after a MetadataUpdate.
     *
     * @return int Count of times this consumer has re-established its subscription.
     */
    public function getResubscribeCount(): int
    {
        return $this->resubscribeCount;
    }

    private function onStreamUnavailable(): void
    {
        $this->subscriptionLost = true;
        // Nothing is in flight any more: the broker forgot the subscription and
        // with it every outstanding credit.
        $this->creditsInFlight = 0;
        $this->pendingCredits = 0;
        $this->active = !$this->singleActiveConsumer;
        $this->resubscribeBackoff = self::RESUBSCRIBE_INITIAL_BACKOFF;
        $this->nextResubscribeAt = microtime(true);
    }

    /**
     * Try once to re-establish a lost subscription. Returns true when the
     * subscription is live again (or was never lost), false when the stream is
     * still missing/unavailable and the next attempt is scheduled (back-off).
     *
     * @return bool True when the subscription is live again (or was never lost),
     *                            false when the stream is still unavailable and a
     *                            retry has been scheduled.
     * @throws ProtocolException If the broker rejects the re-subscribe with any
     *                            error other than STREAM_NOT_EXIST / STREAM_NOT_AVAILABLE.
     * @throws UnexpectedResponseException If the StreamStats reply used to pick the
     *                            resume offset is not a StreamStats response.
     * @throws ConnectionException If the socket is not connected or a re-subscribe
     *                            write/read fails.
     * @throws DeserializationException If a re-subscribe response frame cannot be
     *                            deserialized.
     * @throws TimeoutException If a re-subscribe response does not arrive in time.
     * @throws InvalidArgumentException If the re-subscribe Subscribe frame exceeds the
     *                            negotiated outgoing frame size.
     */
    public function resubscribeIfLost(): bool
    {
        if (!$this->subscriptionLost) {
            return true;
        }
        if (microtime(true) < $this->nextResubscribeAt) {
            return false;
        }

        try {
            $this->sendSubscribe($this->resumeOffset());
        } catch (ProtocolException $e) {
            $retryable = in_array(
                $e->getResponseCode(),
                [ResponseCodeEnum::STREAM_NOT_EXIST, ResponseCodeEnum::STREAM_NOT_AVAILABLE],
                true
            );
            if (!$retryable) {
                throw $e;
            }
            $this->nextResubscribeAt = microtime(true) + $this->resubscribeBackoff;
            $this->resubscribeBackoff = min($this->resubscribeBackoff * 2, self::RESUBSCRIBE_MAX_BACKOFF);
            return false;
        }

        $this->subscriptionLost = false;
        $this->resubscribeCount++;
        // Grow back to the adaptive target the previous subscription had reached.
        if ($this->creditTarget > $this->initialCredit) {
            $this->pendingCredits = $this->creditTarget - $this->initialCredit;
            $this->sendPendingCredits();
        }
        return true;
    }

    /**
     * Where to resume after the broker dropped the subscription.
     *
     * If the stream was merely unavailable for a moment (leader move) we
     * continue right after the last message we processed. If it was deleted
     * and recreated its offsets start over: continuing at our old offset would
     * silently wait until the new stream grows past it, so we fall back to the
     * initial OffsetSpec. StreamStats tells the two apart: the committed offset
     * of a recreated stream is below what we already consumed.
     */
    private function resumeOffset(): OffsetSpec
    {
        if (!$this->hasProcessedMessage) {
            return $this->offset;
        }
        $response = $this->connection->request(new StreamStatsRequestV1($this->stream));
        if (!$response instanceof StreamStatsResponseV1) {
            throw UnexpectedResponseException::create(StreamStatsResponseV1::class, $response);
        }
        foreach ($response->getStats() as $stat) {
            if ($stat->getKey() === 'committed_chunk_id') {
                return $stat->getValue() < $this->lastOffset
                    ? $this->offset
                    : OffsetSpec::offset($this->lastOffset + 1);
            }
        }
        return OffsetSpec::offset($this->lastOffset + 1);
    }

    /** @return array<string, string> */
    private function buildSubscribeProperties(): array
    {
        $properties = [];
        foreach ($this->filterValues as $index => $value) {
            $properties["filter.{$index}"] = $value;
        }
        if ($this->filterValues !== []) {
            $properties['match-unfiltered'] = $this->matchUnfiltered ? 'true' : 'false';
        }
        if ($this->singleActiveConsumer) {
            // The broker groups single active consumers by this "name" property
            // (the same reference used for StoreOffset/QueryOffset) — it rejects
            // single-active-consumer=true without it.
            $properties['single-active-consumer'] = 'true';
            $properties['name'] = (string) $this->name;
        }
        if ($this->superStream !== null) {
            $properties['super-stream'] = $this->superStream;
        }
        return $properties;
    }

    /**
     * Default ConsumerUpdate resume logic for a single active consumer:
     *  - activation (active=true): resume at the stored offset (which is the
     *    next offset to consume, see storeOffset()), or at the consumer's
     *    initial OffsetSpec when nothing is stored yet.
     *  - deactivation (active=false): store the last processed offset (if
     *    auto-commit is enabled and at least one message was processed) so the
     *    successor resumes without gaps, then reply "none" (keep position,
     *    irrelevant once inactive).
     *
     * Re-entrancy: this runs inside StreamConnection::readLoop()'s server-push
     * dispatch and queryOffset() performs a nested round trip. That is safe
     * because Consumer uses StreamConnection::request(), which matches replies
     * by correlation ID and parks responses that belong to an outer request.
     */
    private function defaultConsumerUpdateHandler(ConsumerUpdateResponseV1 $query): ?OffsetSpec
    {
        $this->active = $query->isActive();

        if ($this->consumerUpdateCallback instanceof \Closure) {
            return ($this->consumerUpdateCallback)($this->active, $this);
        }

        if (!$this->active) {
            if ($this->autoCommit > 0 && $this->hasProcessedMessage) {
                $this->storeOffset($this->lastOffset + 1);
            }
            return null;
        }

        try {
            // The stored value is the next offset to consume, so it is used as
            // is — OffsetSpec::offset() is inclusive (#396).
            return OffsetSpec::offset($this->queryOffset());
        } catch (ProtocolException $e) {
            if ($e->getResponseCode() === ResponseCodeEnum::NO_OFFSET) {
                return $this->offset;
            }
            throw $e;
        }
    }

    private function subscribe(): void
    {
        if ($this->singleActiveConsumer) {
            // Registered before the subscribe request is sent: the broker may push
            // ConsumerUpdate before or immediately after the SubscribeResponse.
            $this->connection->registerConsumerUpdateHandler(
                $this->subscriptionId,
                fn(ConsumerUpdateResponseV1 $query): ?OffsetSpec => $this->defaultConsumerUpdateHandler($query)
            );
        }

        $this->connection->registerSubscriber(
            $this->subscriptionId,
            function ($deliverResponse): void {
                // The chunk is atomic on the wire and is always accepted in
                // full — messages are never dropped, even past maxBufferSize.
                // parseMessages() yields Message objects directly, without an
                // intermediate ChunkEntry allocation per entry. getChunkView()
                // hands back the frame buffer plus offset/length instead of a
                // getChunkBytes() copy, so the chunk is parsed straight out of
                // the frame with no full-chunk copy anywhere on this path
                // (#412, #484).
                [$frameBuffer, $chunkOffset, $chunkLength] = $deliverResponse->getChunkView();
                $messages = OsirisChunkParser::parseMessages(
                    $frameBuffer,
                    offset: $chunkOffset,
                    length: $chunkLength,
                    stream: $this->stream,
                    maxDepth: $this->maxDecodeDepth,
                    verifyCrc: $this->verifyCrc,
                );
                foreach ($messages as $message) {
                    $this->buffer[] = $message;
                    $this->unreadCount++;
                }

                $this->creditsInFlight--;
                if ($this->pendingCredits < self::MAX_CREDIT) {
                    $this->pendingCredits++;
                }
                $this->observeChunkSize($chunkLength);
                $this->sendPendingCredits();
            },
        );

        $this->connection->registerMetadataUpdateHandler(
            $this->stream,
            "subscription-{$this->subscriptionId}",
            function (): void {
                $this->onStreamUnavailable();
            }
        );

        $this->sendSubscribe($this->offset);
    }

    private function sendSubscribe(OffsetSpec $offset): void
    {
        // Set before sending the subscribe request: a Deliver frame (and thus the
        // deliver callback, which decrements creditsInFlight) can arrive while we
        // are still waiting for the SubscribeResponse below.
        $this->creditsInFlight = $this->initialCredit;

        // request() correlates the reply: with single-active-consumer the broker
        // may push ConsumerUpdate before the SubscribeResponse, and the handler's
        // nested queryOffset() must not swallow our response.
        try {
            $this->connection->request(
                new SubscribeRequestV1(
                    $this->subscriptionId,
                    $this->stream,
                    $offset,
                    $this->initialCredit,
                    $this->buildSubscribeProperties(),
                )
            );
        } catch (ProtocolException $e) {
            $this->creditsInFlight = 0;
            throw $e;
        }
    }

    /**
     * Wait for messages and return everything received as a batch.
     *
     * Blocks until at least one message is buffered or $timeout elapses, then
     * drains the whole in-memory buffer in one call. Frames other than Deliver
     * (heartbeats, a producer's publish confirms on the same connection,
     * ConsumerUpdate) are handled transparently and do not end the wait. When
     * nothing arrived within $timeout it returns an empty array — never null;
     * use readOne() for the single-message form.
     *
     * @param float $timeout Seconds to wait for at least one message before
     *                            returning whatever the buffer holds; `0` returns
     *                            immediately with whatever is already buffered — it
     *                            does not poll the socket.
     * @return Message[] Every buffered unread message, oldest first; an empty array
     *                            when none arrived within $timeout.
     * @throws ProtocolException If re-establishing a lost subscription fails with a
     *                            non-retryable broker error.
     * @throws UnexpectedResponseException If the StreamStats reply used while
     *                            re-subscribing is not a StreamStats response.
     * @throws ConnectionException If the socket is not connected or a read/write fails.
     * @throws DeserializationException If a delivered chunk or server-push frame cannot
     *                            be deserialized.
     * @throws TimeoutException If a credit or heartbeat frame cannot be written within
     *                            the socket timeout, or a re-subscribe Subscribe/StreamStats
     *                            request does not get a reply in time.
     * @throws InvalidArgumentException If re-establishing a lost subscription builds a
     *                            Subscribe frame that exceeds the negotiated outgoing frame size.
     */
    public function read(float $timeout = 5.0): array
    {
        $this->waitForMessages($timeout);

        return $this->drain();
    }

    /**
     * Block until at least one message is buffered or $timeout elapses.
     *
     * Frames other than Deliver (heartbeats, publish confirms of a producer on
     * the same connection, ConsumerUpdate of a single-active-consumer handover)
     * are dispatched to their handlers and do NOT end the wait: an empty result
     * from read()/readOne() therefore means "no message within $timeout", not
     * "some other frame arrived first".
     */
    private function waitForMessages(float $timeout): void
    {
        $deadline = microtime(true) + $timeout;
        while (!$this->hasUnread() && $timeout > 0) {
            if (!$this->resubscribeIfLost()) {
                // Stream still gone: keep servicing the socket until the next
                // attempt is due (or the caller's deadline passes).
                $slice = min($timeout, max(0.0, $this->nextResubscribeAt - microtime(true)));
                if ($slice > 0) {
                    $this->connection->readLoop(maxFrames: 1, timeout: $slice);
                }
                $timeout = $deadline - microtime(true);
                continue;
            }
            // 0 dispatched frames = timeout, stop() or disconnect: nothing more to wait for.
            if ($this->connection->readLoop(maxFrames: 1, timeout: $timeout) === 0) {
                return;
            }
            $timeout = $deadline - microtime(true);
        }
    }

    /**
     * Whether at least one already-buffered, not-yet-read message is currently
     * held in memory (no I/O — purely a check against the in-process buffer).
     *
     * @return bool True when read()/readOne() can return a message without
     *                            blocking or touching the socket.
     */
    public function hasUnread(): bool
    {
        return $this->unreadCount > 0;
    }

    /**
     * Non-blocking drain of whatever messages are already buffered, without
     * reading any incoming frames (no readLoop() call; it may still send a
     * withheld-credit frame). Returns an empty array if nothing is buffered —
     * mirrors the tail of read() exactly, so read() itself is defined in terms
     * of this method.
     *
     * @return Message[] Every buffered unread message, oldest first; an empty array
     *                            when the buffer is empty.
     * @throws ConnectionException If a withheld credit frame cannot be written.
     * @throws TimeoutException If a withheld credit frame cannot be written within
     *                            the socket timeout.
     */
    public function drain(): array
    {
        if ($this->unreadCount === 0) {
            return [];
        }

        // Elements before bufferHead were already unset by readOne() (their keys
        // are gone, not just skipped), so what remains in $this->buffer already
        // IS exactly the unread subset — only re-indexing (array_values) is needed.
        $messages = $this->bufferHead === 0 ? $this->buffer : array_values($this->buffer);
        $this->buffer = [];
        $this->bufferHead = 0;
        $this->unreadCount = 0;

        $this->sendPendingCredits();

        if ($messages !== []) {
            $lastMsg = end($messages);
            $this->lastOffset = $lastMsg->getOffset();
            $this->hasProcessedMessage = true;
            $this->messagesProcessed += count($messages);
            $this->maybeAutoCommit();
        }

        return $messages;
    }

    /**
     * Wait for a single message and return it.
     *
     * Blocks until at least one message is buffered or $timeout elapses, then
     * removes and returns the oldest message. Frames other than Deliver
     * (heartbeats, a producer's publish confirms, ConsumerUpdate) are handled
     * transparently and do not end the wait. Unlike read(), which returns the
     * whole batch, this returns one message or null on timeout.
     *
     * @param float $timeout Seconds to wait for a message before giving up; `0`
     *                            returns immediately with whatever is already
     *                            buffered — it does not poll the socket.
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
     *                            the socket timeout, or a re-subscribe Subscribe/StreamStats
     *                            request does not get a reply in time.
     * @throws InvalidArgumentException If re-establishing a lost subscription builds a
     *                            Subscribe frame that exceeds the negotiated outgoing frame size.
     */
    public function readOne(float $timeout = 5.0): ?Message
    {
        $this->waitForMessages($timeout);

        if ($this->unreadCount === 0) {
            return null;
        }

        $message = $this->buffer[$this->bufferHead];
        unset($this->buffer[$this->bufferHead]);
        $this->bufferHead++;
        $this->unreadCount--;

        // Release the (now-fully-consumed) backing array rather than letting it
        // grow unboundedly across many readOne() calls.
        if ($this->unreadCount === 0) {
            $this->buffer = [];
            $this->bufferHead = 0;
        }

        $this->lastOffset = $message->getOffset();
        $this->hasProcessedMessage = true;
        $this->messagesProcessed++;
        $this->maybeAutoCommit();
        $this->sendPendingCredits();

        return $message;
    }

    /**
     * Store an offset for this consumer's name on the broker.
     *
     * Convention: the stored value is the **next** offset to consume, i.e.
     * `lastProcessedOffset + 1`, which is what auto-commit writes and what the
     * Java, Go and .NET clients store — so a stored offset can be handed to
     * `OffsetSpec::offset()` (inclusive) directly, and is portable across
     * clients. Before #396 auto-commit stored the last *consumed* offset, which
     * redelivered that message on every resume.
     *
     * @param int $offset Next offset to consume
     * @throws ProtocolException If this consumer has no name (offsets are
     *                            name-scoped on the broker).
     * @throws ConnectionException If the socket is not connected or the write fails.
     * @throws TimeoutException If the write does not complete within the socket timeout.
     */
    public function storeOffset(int $offset): void
    {
        if ($this->name === null) {
            throw new ProtocolException('Cannot store offset for unnamed consumer');
        }
        $this->connection->sendMessage(
            new StoreOffsetRequestV1($this->name, $this->stream, $offset)
        );
    }

    /**
     * Query the offset stored on the broker for this consumer's name.
     *
     * @return int The stored next offset to consume (the value storeOffset()
     *             wrote).
     * @throws ProtocolException If this consumer has no name, or the broker returns a
     *                            non-OK response code (for example NO_OFFSET when
     *                            nothing has been stored yet).
     * @throws UnexpectedResponseException If the server replies with something other
     *                            than a QueryOffset response.
     * @throws ConnectionException If the socket is not connected or the request fails.
     * @throws DeserializationException If the response frame cannot be deserialized.
     * @throws TimeoutException If the response does not arrive in time.
     */
    public function queryOffset(): int
    {
        if ($this->name === null) {
            throw new ProtocolException('Cannot query offset for unnamed consumer');
        }
        $response = $this->connection->request(
            new QueryOffsetRequestV1($this->name, $this->stream)
        );
        if (!$response instanceof QueryOffsetResponseV1) {
            throw UnexpectedResponseException::create(QueryOffsetResponseV1::class, $response);
        }
        return $response->getOffset();
    }

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
    public function close(): void
    {
        if ($this->closed) {
            return;
        }
        $this->closed = true;

        if ($this->autoCommit > 0 && $this->name !== null && $this->messagesProcessed > 0) {
            $this->storeOffset($this->lastOffset + 1);
        }

        $this->connection->unregisterSubscriber($this->subscriptionId);
        $this->connection->unregisterMetadataUpdateHandler($this->stream, "subscription-{$this->subscriptionId}");

        try {
            if (!$this->subscriptionLost) {
                // A lost subscription is already gone on the broker; Unsubscribe
                // would only earn SUBSCRIPTION_ID_NOT_EXIST.
                $this->connection->request(
                    new UnsubscribeRequestV1($this->subscriptionId)
                );
            }
        } finally {
            $this->buffer = [];
            $this->bufferHead = 0;
            $this->unreadCount = 0;
            // The id goes back to the pool even when Unsubscribe fails — this
            // consumer will never use it again either way (#388).
            if ($this->onClose instanceof \Closure) {
                ($this->onClose)($this->subscriptionId);
            }
        }
    }

    /**
     * Whether close() has already run.
     *
     * @return bool True once close() has released the subscription; later calls to
     *                            close() are then no-ops.
     */
    public function isClosed(): bool
    {
        return $this->closed;
    }

    private function maybeAutoCommit(): void
    {
        if ($this->autoCommit <= 0 || $this->name === null) {
            return;
        }
        if ($this->messagesProcessed >= $this->autoCommit) {
            // lastOffset + 1: the stored value is the next offset to consume,
            // otherwise resuming redelivers the last processed message (#396).
            $this->storeOffset($this->lastOffset + 1);
            $this->messagesProcessed = 0;
        }
    }

    /**
     * Current in-flight chunk target (see creditWindowBytes in the constructor).
     *
     * @return int Number of chunks the adaptive credit window currently wants in
     *             flight (never below initialCredit, never above MAX_CREDIT).
     */
    public function getCreditTarget(): int
    {
        return $this->creditTarget;
    }

    /**
     * Fold a delivered chunk's size into the running average and re-derive the
     * in-flight chunk target. When the target grows, the missing credit units are
     * queued as pending so sendPendingCredits() grants them; when it shrinks, the
     * replacement credit stays withheld until in-flight credit drops below it.
     */
    private function observeChunkSize(int $chunkBytes): void
    {
        if ($this->creditWindowBytes <= 0) {
            return;
        }
        $this->avgChunkBytes = $this->avgChunkBytes <= 0.0
            ? (float) $chunkBytes
            : $this->avgChunkBytes * 0.8 + $chunkBytes * 0.2;

        $wanted = (int) ceil($this->creditWindowBytes / max(1.0, $this->avgChunkBytes));
        $this->creditTarget = min(self::MAX_CREDIT, max($this->initialCredit, $wanted));

        $missing = $this->creditTarget - ($this->creditsInFlight + $this->pendingCredits);
        if ($missing > 0) {
            $this->pendingCredits = min(self::MAX_CREDIT, $this->pendingCredits + $missing);
        }
    }

    /**
     * Grant back credit (chunk units) that was previously withheld, as far as
     * buffer headroom (message units, checked as a threshold — see class docblock)
     * and the adaptive creditTarget cap on outstanding (in-flight) credit allow.
     */
    private function sendPendingCredits(): void
    {
        if ($this->pendingCredits <= 0) {
            return;
        }

        // No credit is granted at all while the buffer is at/over its message
        // bound; a chunk already in flight may still land (it's never dropped),
        // but no new one is invited until the buffer drains below the limit.
        if ($this->unreadCount >= $this->maxBufferSize) {
            return;
        }

        $creditHeadroom = $this->creditTarget - $this->creditsInFlight;
        $creditsToSend = min($this->pendingCredits, $creditHeadroom, self::MAX_CREDIT);
        if ($creditsToSend <= 0) {
            return;
        }

        $this->connection->sendMessage(
            new CreditRequestV1($this->subscriptionId, $creditsToSend)
        );
        $this->pendingCredits -= $creditsToSend;
        $this->creditsInFlight += $creditsToSend;
    }
}
