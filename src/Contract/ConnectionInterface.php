<?php

declare(strict_types=1);

namespace CrazyGoat\RabbitStream\Contract;

use CrazyGoat\RabbitStream\Client\AmqpDecoder;
use CrazyGoat\RabbitStream\Client\Consumer;
use CrazyGoat\RabbitStream\Client\Producer;
use CrazyGoat\RabbitStream\Client\Routing\RoutingStrategy;
use CrazyGoat\RabbitStream\Enum\KeyEnum;
use CrazyGoat\RabbitStream\Exception\ConnectionException;
use CrazyGoat\RabbitStream\Exception\DeserializationException;
use CrazyGoat\RabbitStream\Exception\InvalidArgumentException;
use CrazyGoat\RabbitStream\Exception\ProtocolException;
use CrazyGoat\RabbitStream\Exception\TimeoutException;
use CrazyGoat\RabbitStream\Exception\UnexpectedResponseException;
use CrazyGoat\RabbitStream\Response\MetadataResponseV1;
use CrazyGoat\RabbitStream\VO\CommandVersion;
use CrazyGoat\RabbitStream\VO\OffsetSpec;

/**
 * A connection to a RabbitMQ stream broker: stream and super-stream
 * management, offset tracking, and factories for producers and consumers.
 *
 * This is the seam {@see \CrazyGoat\RabbitStream\Client\Connection}
 * implements; it is the public API of the library, so a test double only has
 * to satisfy this contract.
 *
 * Unless a method says otherwise, a correlated response carrying a non-OK
 * response code is asserted during deserialization and raises a
 * {@see ProtocolException} rather than being returned (see #424); inspect
 * `ProtocolException::getResponseCode()` to branch on the exact code.
 */
interface ConnectionInterface
{
    /**
     * Create a stream on the broker.
     *
     * @param string $name Stream name.
     * @param array<string, string> $arguments Optional stream arguments such as
     *                                 `max-length-bytes` or `max-age`.
     * @throws ProtocolException If the broker returns a non-OK response code (for example
     *                                 the stream already exists), or the response has an
     *                                 unexpected command or version.
     * @throws UnexpectedResponseException If the server replies with something other than a
     *                                 Create response.
     * @throws InvalidArgumentException If the serialized request exceeds the negotiated
     *                                 outgoing frame size.
     * @throws ConnectionException If the socket is not connected or a write or read fails.
     * @throws DeserializationException If the response frame cannot be deserialized.
     * @throws TimeoutException If the response does not arrive in time.
     */
    public function createStream(string $name, array $arguments = []): void;

    /**
     * Delete a stream from the broker.
     *
     * A stream that does not exist is reported as a {@see ProtocolException}
     * (the broker's non-OK response code is asserted during deserialization),
     * not as a return value.
     *
     * @param string $name Stream name.
     * @throws ProtocolException If the broker returns a non-OK response code (for example
     *                                 the stream does not exist), or the response has an
     *                                 unexpected command or version.
     * @throws UnexpectedResponseException If the server replies with something other than a
     *                                 DeleteStream response.
     * @throws InvalidArgumentException If the serialized request exceeds the negotiated
     *                                 outgoing frame size.
     * @throws ConnectionException If the socket is not connected or a write or read fails.
     * @throws DeserializationException If the response frame cannot be deserialized.
     * @throws TimeoutException If the response does not arrive in time.
     */
    public function deleteStream(string $name): void;

    /**
     * Create a super stream: a logical stream backed by physical partition
     * streams, with broker-side exchange bindings between them.
     *
     * The broker applies $arguments to every partition stream it creates.
     *
     * @param string $name Super stream name.
     * @param string[] $partitions Partition (physical stream) names to create.
     * @param string[] $bindingKeys Exchange binding key per partition, same order as
     *                                 $partitions, matched by route().
     * @param array<string, string> $arguments Per-partition stream arguments, same keys as
     *                                 createStream().
     * @throws ProtocolException If the broker returns a non-OK response code (for example
     *                                 the super stream already exists or an argument is
     *                                 invalid), or the response has an unexpected command or
     *                                 version.
     * @throws UnexpectedResponseException If the server replies with something other than a
     *                                 CreateSuperStream response.
     * @throws InvalidArgumentException If the serialized request exceeds the negotiated
     *                                 outgoing frame size.
     * @throws ConnectionException If the socket is not connected or a write or read fails.
     * @throws DeserializationException If the response frame cannot be deserialized.
     * @throws TimeoutException If the response does not arrive in time.
     */
    public function createSuperStream(
        string $name,
        array $partitions = [],
        array $bindingKeys = [],
        array $arguments = []
    ): void;

    /**
     * Delete a super stream and all of its partition streams.
     *
     * A super stream that does not exist is reported as a {@see ProtocolException}
     * (the broker's non-OK response code is asserted during deserialization), not
     * as a return value.
     *
     * @param string $name Super stream name.
     * @throws ProtocolException If the broker returns a non-OK response code (for example
     *                                 the super stream does not exist), or the response has
     *                                 an unexpected command or version.
     * @throws UnexpectedResponseException If the server replies with something other than a
     *                                 DeleteSuperStream response.
     * @throws InvalidArgumentException If the serialized request exceeds the negotiated
     *                                 outgoing frame size.
     * @throws ConnectionException If the socket is not connected or a write or read fails.
     * @throws DeserializationException If the response frame cannot be deserialized.
     * @throws TimeoutException If the response does not arrive in time.
     */
    public function deleteSuperStream(string $name): void;

    /**
     * Ask the broker which partition stream(s) a routing key maps to, using the
     * exchange bindings created by createSuperStream().
     *
     * More than one partition can legitimately match a single key when binding
     * keys overlap. A broker error does not produce an empty array; it raises a
     * {@see ProtocolException}.
     *
     * @param string $routingKey Routing key to resolve.
     * @param string $superStream Super stream name.
     * @return string[] Matching partition stream names.
     * @throws ProtocolException If the broker returns a non-OK response code, or the
     *                                 response has an unexpected command or version.
     * @throws UnexpectedResponseException If the server replies with something other than a
     *                                 Route response.
     * @throws InvalidArgumentException If the serialized request exceeds the negotiated
     *                                 outgoing frame size.
     * @throws ConnectionException If the socket is not connected or a write or read fails.
     * @throws DeserializationException If the response frame cannot be deserialized.
     * @throws TimeoutException If the response does not arrive in time.
     */
    public function route(string $routingKey, string $superStream): array;

    /**
     * Resolve a super stream's partition (physical stream) names.
     *
     * @param string $superStream Super stream name.
     * @return list<string> Partition stream names.
     * @throws ProtocolException If the super stream does not exist (the broker's Partitions
     *                                 response code is asserted during deserialization), exists
     *                                 but currently has zero partitions, or the response has an
     *                                 unexpected command or version.
     * @throws UnexpectedResponseException If the server replies with something other than a
     *                                 Partitions response.
     * @throws InvalidArgumentException If the serialized request exceeds the negotiated
     *                                 outgoing frame size.
     * @throws ConnectionException If the socket is not connected or a write or read fails.
     * @throws DeserializationException If the response frame cannot be deserialized.
     * @throws TimeoutException If the response does not arrive in time.
     */
    public function partitions(string $superStream): array;

    /**
     * Check whether a stream exists on the broker.
     *
     * Absence is a normal result (`false`), not an exception: the Metadata
     * response carries a per-stream response code rather than a single top-level
     * one, so a missing stream is not asserted.
     *
     * @param string $name Stream name.
     * @return bool True if the broker reports the stream with an OK response code.
     * @throws ProtocolException If the response has an unexpected command or version.
     * @throws UnexpectedResponseException If the server replies with something other than a
     *                                 Metadata response.
     * @throws InvalidArgumentException If the serialized request exceeds the negotiated
     *                                 outgoing frame size.
     * @throws ConnectionException If the socket is not connected or a write or read fails.
     * @throws DeserializationException If the response frame cannot be deserialized.
     * @throws TimeoutException If the response does not arrive in time.
     */
    public function streamExists(string $name): bool;

    /**
     * Fetch the broker's statistics for a stream.
     *
     * @param string $name Stream name.
     * @return array<string, int> Statistic key => value (for example `messages`,
     *                                 `bytes`, `publishers`, `consumers`).
     * @throws ProtocolException If the broker returns a non-OK response code (for example
     *                                 the stream does not exist), or the response has an
     *                                 unexpected command or version.
     * @throws UnexpectedResponseException If the server replies with something other than a
     *                                 StreamStats response.
     * @throws InvalidArgumentException If the serialized request exceeds the negotiated
     *                                 outgoing frame size.
     * @throws ConnectionException If the socket is not connected or a write or read fails.
     * @throws DeserializationException If the response frame cannot be deserialized.
     * @throws TimeoutException If the response does not arrive in time.
     */
    public function getStreamStats(string $name): array;

    /**
     * Fetch metadata for one or more streams in a single round trip.
     *
     * The Metadata response has no top-level response code; each returned
     * StreamMetadata carries its own, so a non-existent stream is represented in
     * the result rather than raised.
     *
     * @param array<int, string> $streams Stream names to query.
     * @return MetadataResponseV1 Brokers and per-stream metadata.
     * @throws ProtocolException If the response has an unexpected command or version.
     * @throws UnexpectedResponseException If the server replies with something other than a
     *                                 Metadata response.
     * @throws InvalidArgumentException If the serialized request exceeds the negotiated
     *                                 outgoing frame size.
     * @throws ConnectionException If the socket is not connected or a write or read fails.
     * @throws DeserializationException If the response frame cannot be deserialized.
     * @throws TimeoutException If the response does not arrive in time.
     */
    public function getMetadata(array $streams): MetadataResponseV1;

    /**
     * Query the offset last stored for a named consumer on a stream.
     *
     * The returned value is the next offset to consume (last processed + 1).
     * `null` means nothing has been stored for this reference/stream pair yet
     * (the broker answered `NO_OFFSET`, `0x13`) — a normal first-run outcome,
     * not an error (#467). Any other non-OK response code still raises a
     * {@see ProtocolException}.
     *
     * @param string $reference Consumer name the offset was stored under.
     * @param string $stream Stream name.
     * @return int|null The stored next offset to consume, or null when no offset is stored.
     * @throws ProtocolException If the broker returns a non-OK response code other than
     *                                 NO_OFFSET, or the response has an unexpected command or
     *                                 version.
     * @throws UnexpectedResponseException If the server replies with something other than a
     *                                 QueryOffset response.
     * @throws InvalidArgumentException If the serialized request exceeds the negotiated
     *                                 outgoing frame size.
     * @throws ConnectionException If the socket is not connected or a write or read fails.
     * @throws DeserializationException If the response frame cannot be deserialized.
     * @throws TimeoutException If the response does not arrive in time.
     */
    public function queryOffset(string $reference, string $stream): ?int;

    /**
     * Close the connection and every producer and consumer it owns.
     *
     * Idempotent: after the first call the method returns immediately. A
     * failure while closing an individual producer or consumer is logged and
     * does not abort the shutdown; the broker Close exchange is still performed,
     * and the underlying socket is closed even if that exchange fails.
     *
     * @throws ProtocolException If the broker returns a non-OK Close response code, or the
     *                                 response has an unexpected command or version.
     * @throws UnexpectedResponseException If the server replies with something other than a
     *                                 Close response.
     * @throws ConnectionException If the socket is not connected or the Close exchange fails.
     * @throws DeserializationException If the Close response frame cannot be deserialized.
     * @throws TimeoutException If the Close response does not arrive in time.
     */
    public function close(): void;

    /**
     * Create a producer for publishing to a stream.
     *
     * Declares the publisher eagerly, so a missing stream is reported here as a
     * {@see ProtocolException} rather than on the first publish. The publisher id
     * is reserved for the connection until the returned producer is closed.
     *
     * @param string $stream Stream to publish to.
     * @param string|null $name Optional producer name; assigning one enables
     *                                 server-side deduplication and makes the producer read back
     *                                 its publishing sequence on creation.
     * @param callable|null $onConfirm Optional callback invoked with a ConfirmationStatus
     *                                 for each confirmed or failed publish.
     * @param int $maxPendingConfirms Maximum outstanding unconfirmed publishes
     *                                 before send() back-pressures.
     * @param float $redeclareTimeout Seconds a stale publisher keeps retrying
     *                                 DeclarePublisher after a MetadataUpdate before
     *                                 ensureDeclared() gives up.
     * @return ProducerInterface A declared producer whose publisher id stays reserved by
     *                                 this connection until the producer is closed.
     * @throws ConnectionException If the socket is not connected, a write or read fails, or
     *                                 all publisher ids are in use.
     * @throws InvalidArgumentException If $redeclareTimeout is negative, or the serialized
     *                                 DeclarePublisher request exceeds the negotiated outgoing
     *                                 frame size.
     * @throws ProtocolException If the stream does not exist (the DeclarePublisher response
     *                                 code is asserted), or the response has an unexpected
     *                                 command or version.
     * @throws UnexpectedResponseException If a named producer's sequence query receives an
     *                                 unexpected response type.
     * @throws DeserializationException If a response frame cannot be deserialized.
     * @throws TimeoutException If a response does not arrive in time.
     */
    public function createProducer(
        string $stream,
        ?string $name = null,
        ?callable $onConfirm = null,
        int $maxPendingConfirms = Producer::DEFAULT_MAX_PENDING_CONFIRMS,
        float $redeclareTimeout = Producer::DEFAULT_REDECLARE_TIMEOUT,
    ): ProducerInterface;

    /**
     * Create a consumer that subscribes to a stream.
     *
     * Subscribes eagerly, so a missing stream is reported here as a
     * {@see ProtocolException} rather than on the first read. The subscription id
     * is reserved for the connection until the returned consumer is closed.
     *
     * @param string $stream Stream to consume from.
     * @param OffsetSpec $offset Where the subscription starts.
     * @param string|null $name Optional consumer name; required for offset tracking
     *                                 (storeOffset()/queryOffset()) and for
     *                                 $singleActiveConsumer.
     * @param int $autoCommit Number of messages between automatic offset commits;
     *                                 `0` disables auto-commit.
     * @param int $initialCredit Initial (and minimum) number of chunks in flight,
     *                                 1..Consumer::MAX_CREDIT.
     * @param array<int, string> $filterValues Stream filtering values, sent as
     *                                 `filter.0`, `filter.1`, ... properties (broker-side,
     *                                 chunk-granular filtering — see Consumer's docblock).
     * @param bool $matchUnfiltered When $filterValues is non-empty, also deliver
     *                                 messages published with no filter value.
     * @param bool $singleActiveConsumer Join the broker's single-active-consumer
     *                                 group for this $name; requires $name.
     * @param string|null $superStream Name of the super stream this partition
     *                                 belongs to, if any.
     * @param int $creditWindowBytes Target bytes in flight for the adaptive credit
     *                                 window; `0` pins the window to $initialCredit chunks.
     * @param int $maxDecodeDepth Maximum AMQP nesting depth accepted when a delivered
     *                                 message is decoded — see Consumer's constructor (#450).
     * @param bool $verifyCrc Verify every delivered chunk's CRC-32 against its header
     *                                 — see Consumer's constructor (#403).
     * @param int $maxBufferSize Message-bound back-pressure ceiling on unread
     *                                 messages; must be positive — see Consumer's constructor.
     * @return ConsumerInterface A subscribed consumer whose subscription id stays reserved
     *                                 by this connection until the consumer is closed.
     * @throws ConnectionException If the socket is not connected, a write or read fails, or
     *                                 all subscription ids are in use.
     * @throws InvalidArgumentException If $maxBufferSize is not positive, $initialCredit is
     *                                 outside 1..Consumer::MAX_CREDIT, $creditWindowBytes is
     *                                 negative, $maxDecodeDepth is below 1,
     *                                 $singleActiveConsumer is set without $name, or the
     *                                 serialized request exceeds the negotiated outgoing frame
     *                                 size.
     * @throws ProtocolException If the broker rejects the Subscribe with a non-OK response
     *                                 code, or the response has an unexpected command or version.
     * @throws DeserializationException If a response frame cannot be deserialized.
     * @throws TimeoutException If the Subscribe response does not arrive in time.
     */
    public function createConsumer(
        string $stream,
        OffsetSpec $offset,
        ?string $name = null,
        int $autoCommit = 0,
        int $initialCredit = 10,
        array $filterValues = [],
        bool $matchUnfiltered = false,
        bool $singleActiveConsumer = false,
        ?string $superStream = null,
        int $creditWindowBytes = Consumer::DEFAULT_CREDIT_WINDOW_BYTES,
        int $maxDecodeDepth = AmqpDecoder::MAX_RECURSION_DEPTH,
        bool $verifyCrc = true,
        int $maxBufferSize = Consumer::DEFAULT_MAX_BUFFER_SIZE,
    ): ConsumerInterface;

    /**
     * Create a producer that publishes to a super stream's partitions,
     * routing each message via $strategy (default: hash routing — see
     * {@see \CrazyGoat\RabbitStream\Client\Routing\HashRoutingStrategy}).
     *
     * Resolves the partition list immediately (one partitions() round trip) but
     * opens each partition's Producer lazily on first publish to it. A
     * MetadataUpdate on any partition makes the next publish re-resolve the
     * topology.
     *
     * @param string $superStream Super stream to publish to.
     * @param RoutingStrategy|null $strategy Routing strategy; defaults to HashRoutingStrategy.
     * @param string|null $name Optional base producer name; each partition's
     *                                 Producer is named "{$name}-{$partition}" so per-partition
     *                                 deduplication still works.
     * @param callable|null $onConfirm Optional confirmation callback, passed through
     *                                 to every partition's Producer.
     * @param int $maxPendingConfirms Back-pressure cap, passed through to every
     *                                 partition's Producer.
     * @param float $redeclareTimeout Re-declare timeout, passed through to every
     *                                 partition's Producer.
     * @return SuperStreamProducerInterface A producer that resolves partitions up front
     *                                 and opens one underlying Producer per partition lazily.
     * @throws ProtocolException If the super stream does not exist or has zero partitions,
     *                                 or a response has an unexpected command or version.
     * @throws UnexpectedResponseException If a partitions() response has an unexpected type.
     * @throws InvalidArgumentException If a partitions() request exceeds the negotiated
     *                                 outgoing frame size.
     * @throws ConnectionException If the socket is not connected or a write or read fails.
     * @throws DeserializationException If a response frame cannot be deserialized.
     * @throws TimeoutException If a response does not arrive in time.
     */
    public function createSuperStreamProducer(
        string $superStream,
        ?RoutingStrategy $strategy = null,
        ?string $name = null,
        ?callable $onConfirm = null,
        int $maxPendingConfirms = Producer::DEFAULT_MAX_PENDING_CONFIRMS,
        float $redeclareTimeout = Producer::DEFAULT_REDECLARE_TIMEOUT,
    ): SuperStreamProducerInterface;

    /**
     * Create a consumer that subscribes to every partition of a super stream,
     * all sharing the same consumer $name (required for single active
     * consumer to group them server-side).
     *
     * Offset tracking is per-partition; there is no super-stream-wide offset.
     *
     * @param string $superStream Super stream to consume from.
     * @param OffsetSpec $offset Starting offset, applied to every partition.
     * @param string|null $name Optional shared consumer name; required for
     *                                 $singleActiveConsumer and for offset tracking.
     * @param int $autoCommit Auto-commit interval, passed through to every
     *                                 partition's Consumer.
     * @param int $initialCredit Initial credit, passed through to every
     *                                 partition's Consumer.
     * @param bool $singleActiveConsumer Enable single active consumer per partition.
     * @param int $creditWindowBytes Adaptive credit window in bytes, passed through
     *                                 to every partition's Consumer.
     * @param int $maxDecodeDepth Maximum AMQP nesting depth, passed through to
     *                                 every partition's Consumer.
     * @param bool $verifyCrc Verify delivered chunk CRCs, passed through to every
     *                                 partition's Consumer (#403).
     * @param int $maxBufferSize Message-bound back-pressure ceiling passed through
     *                                 to every partition's Consumer; must be positive.
     * @return SuperStreamConsumerInterface A consumer aggregating one plain Consumer per
     *                                 partition.
     * @throws ProtocolException If the super stream does not exist or has zero partitions,
     *                                 the broker rejects a Subscribe, or a response has an
     *                                 unexpected command or version.
     * @throws UnexpectedResponseException If a partitions() response has an unexpected type.
     * @throws ConnectionException If the socket is not connected, a write or read fails, or
     *                                 all subscription ids are in use.
     * @throws InvalidArgumentException If a Consumer argument is out of range
     *                                 (including a non-positive $maxBufferSize),
     *                                 $singleActiveConsumer is set without $name, or a
     *                                 partitions() request — or a per-partition Subscribe
     *                                 request — exceeds the negotiated outgoing frame size.
     * @throws DeserializationException If a response frame cannot be deserialized.
     * @throws TimeoutException If a response does not arrive in time.
     */
    public function createSuperStreamConsumer(
        string $superStream,
        OffsetSpec $offset,
        ?string $name = null,
        int $autoCommit = 0,
        int $initialCredit = 10,
        bool $singleActiveConsumer = false,
        int $creditWindowBytes = Consumer::DEFAULT_CREDIT_WINDOW_BYTES,
        int $maxDecodeDepth = AmqpDecoder::MAX_RECURSION_DEPTH,
        bool $verifyCrc = true,
        int $maxBufferSize = Consumer::DEFAULT_MAX_BUFFER_SIZE,
    ): SuperStreamConsumerInterface;

    /**
     * Drive the incoming-frame loop, dispatching server-push frames.
     *
     * Deliveries, publish confirms/errors, heartbeats, MetadataUpdate and
     * ConsumerUpdate frames are handed to the callbacks registered by the
     * producers and consumers on this connection; heartbeats are echoed
     * automatically. Blocks until one of the stop conditions is met.
     *
     * @param int|null $maxFrames Stop after this many frames have been dispatched;
     *                                 `null` means no frame-count limit.
     * @param float|null $timeout Stop after this many seconds; `null` means no
     *                                 wall-clock limit. If both are null the loop runs until
     *                                 the connection closes or the socket is stopped.
     * @return int Number of frames dispatched; 0 means the loop ended on timeout, stop or
     *                                 disconnect without handling a frame.
     * @throws ConnectionException If the socket is not connected, `stream_select()` fails, or
     *                                 a read fails.
     * @throws DeserializationException If a server-push frame cannot be deserialized.
     * @throws ProtocolException If a registered ConsumerUpdate handler's nested offset
     *                                 query fails with a non-OK broker response.
     * @throws UnexpectedResponseException If a registered ConsumerUpdate handler's nested
     *                                 offset query gets a reply of the wrong type.
     * @throws InvalidArgumentException If a registered ConsumerUpdate handler returns
     *                                 an offset type outside the protocol's reply range (0-5).
     * @throws TimeoutException If a reply this loop must send (a heartbeat echo, a
     *                                 server-close acknowledgement or a ConsumerUpdate reply)
     *                                 cannot be written within the socket timeout.
     */
    public function readLoop(?int $maxFrames = null, ?float $timeout = null): int;

    /**
     * Whether the broker reported support for a given command version during
     * the handshake (see Connection::supportsCommandVersion()).
     *
     * @param KeyEnum $key Command to check (for example KeyEnum::PUBLISH).
     * @param int $version Version to check (1-based).
     * @return bool True when the broker's reported range includes $version.
     */
    public function supportsCommandVersion(KeyEnum $key, int $version): bool;

    /**
     * The per-command version ranges the broker reported, keyed by command key.
     *
     * Empty when ExchangeCommandVersions was rejected, unanswered or not
     * implemented by the broker — callers should then assume v1 for every
     * command (see {@see self::supportsCommandVersion()}).
     *
     * @return array<int, CommandVersion> Supported ranges keyed by protocol command key.
     */
    public function getSupportedCommandVersions(): array;

    /**
     * Store the offset for a named consumer on a stream.
     *
     * This is a one-way command: the protocol defines no StoreOffset response,
     * so no round trip is performed and no broker error is awaited here. The
     * supplied value is the next offset to consume (last processed + 1).
     *
     * @param string $reference Consumer name the offset is stored under.
     * @param string $stream Stream name.
     * @param int $offset Next offset to consume.
     * @throws ConnectionException If the socket is not connected or the write fails.
     * @throws InvalidArgumentException If the serialized request exceeds the negotiated
     *                                 outgoing frame size.
     * @throws TimeoutException If the frame cannot be written within the socket timeout.
     */
    public function storeOffset(string $reference, string $stream, int $offset): void;
}
