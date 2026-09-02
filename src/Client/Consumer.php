<?php

declare(strict_types=1);

namespace CrazyGoat\RabbitStream\Client;

use CrazyGoat\RabbitStream\Contract\ConsumerInterface;
use CrazyGoat\RabbitStream\Enum\ResponseCodeEnum;
use CrazyGoat\RabbitStream\Exception\InvalidArgumentException;
use CrazyGoat\RabbitStream\Exception\ProtocolException;
use CrazyGoat\RabbitStream\Exception\UnexpectedResponseException;
use CrazyGoat\RabbitStream\Request\CreditRequestV1;
use CrazyGoat\RabbitStream\Request\QueryOffsetRequestV1;
use CrazyGoat\RabbitStream\Request\StoreOffsetRequestV1;
use CrazyGoat\RabbitStream\Request\SubscribeRequestV1;
use CrazyGoat\RabbitStream\Request\UnsubscribeRequestV1;
use CrazyGoat\RabbitStream\Response\ConsumerUpdateResponseV1;
use CrazyGoat\RabbitStream\Response\QueryOffsetResponseV1;
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
 * more than `maxBufferSize` messages — by at most one chunk's worth — right
 * after a chunk lands. What `maxBufferSize` actually controls is credit: once
 * the unread count reaches or exceeds it, no further credit is granted, so the
 * server stops delivering new chunks until the buffer drains back below the
 * limit. Credits withheld this way are remembered (`pendingCredits`, itself a
 * chunk-granular counter) and granted back — one credit per chunk's worth of
 * headroom that reopens — as the application drains the buffer via read()/
 * readOne(). Outstanding (in-flight, i.e. sent-but-not-yet-consumed) credit is
 * additionally capped at `initialCredit`, so the server can never have more
 * than `initialCredit` chunks in flight at once, independent of how large
 * those chunks turn out to be.
 */
class Consumer implements ConsumerInterface
{
    private const MAX_UINT16 = 65535;

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
     * Whether this consumer is currently allowed to receive messages. Always
     * true for a non-single-active-consumer subscription. A single active
     * consumer subscription starts as inactive and flips per the broker's
     * ConsumerUpdate query (see subscribe()).
     */
    private bool $active;

    private ?\Closure $consumerUpdateCallback = null;

    /**
     * @param array<int, string> $filterValues Stream filtering values (protocol
     *                            keys `filter.0`, `filter.1`, ... — broker-side,
     *                            chunk-granular; see Producer::sendWithFilter()).
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
    ) {
        if ($this->maxBufferSize <= 0) {
            throw new InvalidArgumentException('maxBufferSize must be greater than 0');
        }
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

    public function isActive(): bool
    {
        return $this->active;
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
     *  - activation (active=true): resume just after the stored offset, or at
     *    the consumer's initial OffsetSpec when nothing is stored yet.
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
                $this->storeOffset($this->lastOffset);
            }
            return null;
        }

        try {
            $stored = $this->queryOffset();
            return OffsetSpec::offset($stored + 1);
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
                );
                foreach ($messages as $message) {
                    $this->buffer[] = $message;
                    $this->unreadCount++;
                }

                $this->creditsInFlight--;
                if ($this->pendingCredits < self::MAX_UINT16) {
                    $this->pendingCredits++;
                }
                $this->sendPendingCredits();
            },
        );

        // Set before sending the subscribe request: a Deliver frame (and thus the
        // callback above, which decrements creditsInFlight) can arrive while we are
        // still waiting for the SubscribeResponse below.
        $this->creditsInFlight = $this->initialCredit;

        // request() correlates the reply: with single-active-consumer the broker
        // may push ConsumerUpdate before the SubscribeResponse, and the handler's
        // nested queryOffset() must not swallow our response.
        $this->connection->request(
            new SubscribeRequestV1(
                $this->subscriptionId,
                $this->stream,
                $this->offset,
                $this->initialCredit,
                $this->buildSubscribeProperties(),
            )
        );
    }

    /**
     * @return Message[]
     */
    public function read(float $timeout = 5.0): array
    {
        if ($this->unreadCount === 0) {
            $this->connection->readLoop(maxFrames: 1, timeout: $timeout);
        }

        return $this->drain();
    }

    /**
     * Whether at least one already-buffered, not-yet-read message is currently
     * held in memory (no I/O — purely a check against the in-process buffer).
     */
    public function hasUnread(): bool
    {
        return $this->unreadCount > 0;
    }

    /**
     * Non-blocking drain of whatever messages are already buffered, without
     * performing any connection I/O (no readLoop() call). Returns an empty
     * array if nothing is buffered — mirrors the tail of read() exactly, so
     * read() itself is defined in terms of this method.
     *
     * @return Message[]
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

    public function readOne(float $timeout = 5.0): ?Message
    {
        if ($this->unreadCount === 0) {
            $this->connection->readLoop(maxFrames: 1, timeout: $timeout);
        }

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

    public function storeOffset(int $offset): void
    {
        if ($this->name === null) {
            throw new ProtocolException('Cannot store offset for unnamed consumer');
        }
        $this->connection->sendMessage(
            new StoreOffsetRequestV1($this->name, $this->stream, $offset)
        );
    }

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

    public function close(): void
    {
        if ($this->autoCommit > 0 && $this->name !== null && $this->messagesProcessed > 0) {
            $this->storeOffset($this->lastOffset);
        }

        $this->connection->unregisterSubscriber($this->subscriptionId);

        $this->connection->request(
            new UnsubscribeRequestV1($this->subscriptionId)
        );
        $this->buffer = [];
        $this->bufferHead = 0;
        $this->unreadCount = 0;
    }

    private function maybeAutoCommit(): void
    {
        if ($this->autoCommit <= 0 || $this->name === null) {
            return;
        }
        if ($this->messagesProcessed >= $this->autoCommit) {
            $this->storeOffset($this->lastOffset);
            $this->messagesProcessed = 0;
        }
    }

    /**
     * Grant back credit (chunk units) that was previously withheld, as far as
     * buffer headroom (message units, checked as a threshold — see class docblock)
     * and the initialCredit cap on outstanding (in-flight) credit allow.
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

        $creditHeadroom = $this->initialCredit - $this->creditsInFlight;
        $creditsToSend = min($this->pendingCredits, $creditHeadroom, self::MAX_UINT16);
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
