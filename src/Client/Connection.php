<?php

declare(strict_types=1);

namespace CrazyGoat\RabbitStream\Client;

use CrazyGoat\RabbitStream\Client\Routing\HashRoutingStrategy;
use CrazyGoat\RabbitStream\Client\Routing\RoutingStrategy;
use CrazyGoat\RabbitStream\Contract\ConnectionInterface;
use CrazyGoat\RabbitStream\Contract\ConsumerInterface;
use CrazyGoat\RabbitStream\Contract\ProducerInterface;
use CrazyGoat\RabbitStream\Contract\SuperStreamConsumerInterface;
use CrazyGoat\RabbitStream\Contract\SuperStreamProducerInterface;
use CrazyGoat\RabbitStream\Enum\ResponseCodeEnum;
use CrazyGoat\RabbitStream\Exception\AuthenticationException;
use CrazyGoat\RabbitStream\Exception\ConnectionException;
use CrazyGoat\RabbitStream\Exception\DeserializationException;
use CrazyGoat\RabbitStream\Exception\InvalidArgumentException;
use CrazyGoat\RabbitStream\Exception\ProtocolException;
use CrazyGoat\RabbitStream\Exception\TimeoutException;
use CrazyGoat\RabbitStream\Exception\UnexpectedResponseException;
use CrazyGoat\RabbitStream\Request\CloseRequestV1;
use CrazyGoat\RabbitStream\Request\CreateRequestV1;
use CrazyGoat\RabbitStream\Request\CreateSuperStreamRequestV1;
use CrazyGoat\RabbitStream\Request\DeleteStreamRequestV1;
use CrazyGoat\RabbitStream\Request\DeleteSuperStreamRequestV1;
use CrazyGoat\RabbitStream\Request\MetadataRequestV1;
use CrazyGoat\RabbitStream\Request\OpenRequestV1;
use CrazyGoat\RabbitStream\Request\PartitionsRequestV1;
use CrazyGoat\RabbitStream\Request\PeerPropertiesRequestV1;
use CrazyGoat\RabbitStream\Request\QueryOffsetRequestV1;
use CrazyGoat\RabbitStream\Request\RouteRequestV1;
use CrazyGoat\RabbitStream\Request\SaslAuthenticateRequestV1;
use CrazyGoat\RabbitStream\Request\SaslHandshakeRequestV1;
use CrazyGoat\RabbitStream\Request\StoreOffsetRequestV1;
use CrazyGoat\RabbitStream\Request\StreamStatsRequestV1;
use CrazyGoat\RabbitStream\Request\TuneRequestV1;
use CrazyGoat\RabbitStream\Response\CloseResponseV1;
use CrazyGoat\RabbitStream\Response\CreateResponseV1;
use CrazyGoat\RabbitStream\Response\CreateSuperStreamResponseV1;
use CrazyGoat\RabbitStream\Response\DeleteStreamResponseV1;
use CrazyGoat\RabbitStream\Response\DeleteSuperStreamResponseV1;
use CrazyGoat\RabbitStream\Response\MetadataResponseV1;
use CrazyGoat\RabbitStream\Response\OpenResponseV1;
use CrazyGoat\RabbitStream\Response\PartitionsResponseV1;
use CrazyGoat\RabbitStream\Response\PeerPropertiesResponseV1;
use CrazyGoat\RabbitStream\Response\QueryOffsetResponseV1;
use CrazyGoat\RabbitStream\Response\RouteResponseV1;
use CrazyGoat\RabbitStream\Response\SaslAuthenticateResponseV1;
use CrazyGoat\RabbitStream\Response\SaslHandshakeResponseV1;
use CrazyGoat\RabbitStream\Response\StreamStatsResponseV1;
use CrazyGoat\RabbitStream\Response\TuneResponseV1;
use CrazyGoat\RabbitStream\Serializer\BinarySerializerInterface;
use CrazyGoat\RabbitStream\Serializer\PhpBinarySerializer;
use CrazyGoat\RabbitStream\StreamConnection;
use CrazyGoat\RabbitStream\VO\OffsetSpec;
use CrazyGoat\RabbitStream\VO\TlsConfig;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

class Connection implements ConnectionInterface
{
    /**
     * DeclarePublisher and Subscribe encode their id as a uint8, so a connection
     * can hold at most 256 publishers and 256 subscriptions at a time. Ids of
     * closed producers/consumers are handed back (GitHub #388).
     */
    public const MAX_CONCURRENT_PUBLISHERS = 256;
    public const MAX_CONCURRENT_SUBSCRIPTIONS = 256;

    /**
     * Next id to try. Allocation walks forward from here and wraps, so a freed
     * id is only reused after the whole range has been handed out once — that
     * keeps a late PublishConfirm/Deliver for a closed publisher/subscription
     * from landing on a fresh one that happens to share its id.
     */
    private int $publisherIdCursor = 0;
    private int $subscriptionIdCursor = 0;
    private bool $closed = false;

    /** @var array<int, Producer> Live producers, keyed by publisher id — also the id allocation map */
    private array $producers = [];

    /** @var array<int, Consumer> Live consumers, keyed by subscription id — also the id allocation map */
    private array $consumers = [];

    private function __construct(
        private readonly StreamConnection $streamConnection,
        private readonly LoggerInterface $logger,
    ) {
    }

    /**
     * Connect to a broker, run the full handshake and return a ready connection.
     *
     * The handshake exchanges PeerProperties, negotiates SASL (PLAIN only),
     * authenticates, negotiates frame max and heartbeat at Tune, and opens the
     * virtual host. It is kept out of the constructor so a connection is never
     * observable half-initialised.
     *
     * @param string $host Broker hostname or IP.
     * @param int $port Broker stream-protocol port.
     * @param string $user SASL username.
     * @param string $password SASL password.
     * @param string $vhost Virtual host to open.
     * @param BinarySerializerInterface|null $serializer Frame serializer; defaults to
     *                                 PhpBinarySerializer.
     * @param LoggerInterface|null $logger PSR-3 logger; defaults to NullLogger.
     * @param int|null $requestedFrameMax Requested control-frame max in bytes. `null`
     *                                 (default) accepts the server value but never raises the
     *                                 effective cap above StreamConnection::DEFAULT_MAX_FRAME_SIZE;
     *                                 `0` expresses no client preference, so the server value is
     *                                 used without that safety cap; a non-zero value is a
     *                                 deliberate raise or lower.
     * @param int|null $requestedHeartbeat Requested heartbeat interval in seconds. `null`
     *                                 (default) accepts the server value; `0` expresses no client
     *                                 preference, so the server value is used as well.
     * @param int|null $maxDeliverFrameSize Max bytes for incoming Deliver frames, which the
     *                                 broker does not bound by frame_max; `null` uses
     *                                 StreamConnection::DEFAULT_MAX_DELIVER_FRAME_SIZE.
     * @param StreamConnection|null $streamConnection Pre-connected connection to reuse,
     *                                 principally an injection seam for tests. When supplied,
     *                                 $host/$port/$serializer/$socketTimeout/$tls are not used
     *                                 to build one and connect() is not called, but this
     *                                 Connection still closes it in close(). The caller owns
     *                                 how it is constructed and connected.
     * @param float|null $socketTimeout Per-I/O-call timeout in seconds (> 0); `null` uses
     *                                 StreamConnection::DEFAULT_SOCKET_TIMEOUT. Not used to
     *                                 build a connection when $streamConnection is supplied,
     *                                 but still validated.
     * @param TlsConfig|null $tls TLS options; a non-null value selects the encrypted `ssl://`
     *                                 transport. Ignored when $streamConnection is supplied.
     * @param int|null $initialFrameMax Max frame size allowed before Open completes; `null`
     *                                 uses StreamConnection::DEFAULT_INITIAL_FRAME_SIZE, `0`
     *                                 disables the client-side pre-Open check.
     * @return self A fully established and authenticated connection.
     * @throws InvalidArgumentException If $requestedFrameMax, $requestedHeartbeat,
     *                                 $maxDeliverFrameSize or $initialFrameMax is negative, or
     *                                 $socketTimeout is not greater than 0.
     * @throws ConnectionException If the TCP connection cannot be established, or a read
     *                                 or write fails during the handshake.
     * @throws AuthenticationException If the server does not offer the PLAIN mechanism.
     * @throws ProtocolException If a pre-Open request exceeds $initialFrameMax, a handshake
     *                                 response carries a non-OK code (for example invalid
     *                                 credentials), or a frame has an unexpected command or
     *                                 version.
     * @throws DeserializationException If a handshake response frame cannot be deserialized.
     * @throws UnexpectedResponseException If a handshake step receives an unexpected response
     *                                 type.
     * @throws TimeoutException If a handshake write does not complete within
     *                                 $socketTimeout, or a handshake response does not arrive
     *                                 within the read timeout (30 s).
     */
    public static function create(
        string $host = '127.0.0.1',
        int $port = 5552,
        string $user = 'guest',
        string $password = 'guest',
        string $vhost = '/',
        ?BinarySerializerInterface $serializer = null,
        ?LoggerInterface $logger = null,
        ?int $requestedFrameMax = null,
        ?int $requestedHeartbeat = null,
        ?int $maxDeliverFrameSize = null,
        ?StreamConnection $streamConnection = null,
        ?float $socketTimeout = null,
        ?TlsConfig $tls = null,
        ?int $initialFrameMax = null,
    ): self {
        if ($requestedFrameMax !== null && $requestedFrameMax < 0) {
            throw new InvalidArgumentException('requestedFrameMax must not be negative');
        }
        if ($requestedHeartbeat !== null && $requestedHeartbeat < 0) {
            throw new InvalidArgumentException('requestedHeartbeat must not be negative');
        }
        if ($maxDeliverFrameSize !== null && $maxDeliverFrameSize < 0) {
            throw new InvalidArgumentException('maxDeliverFrameSize must not be negative');
        }
        if ($socketTimeout !== null && $socketTimeout <= 0) {
            throw new InvalidArgumentException('socketTimeout must be greater than 0');
        }
        if ($initialFrameMax !== null && $initialFrameMax < 0) {
            throw new InvalidArgumentException('initialFrameMax must not be negative');
        }

        $logger ??= new NullLogger();
        $serializer ??= new PhpBinarySerializer();

        if (!$streamConnection instanceof \CrazyGoat\RabbitStream\StreamConnection) {
            $streamConnection = new StreamConnection(
                $host,
                $port,
                $logger,
                $serializer,
                $socketTimeout ?? StreamConnection::DEFAULT_SOCKET_TIMEOUT,
                $tls,
            );
            $streamConnection->connect();
        }

        // Until Open completes, RabbitMQ 4.3 caps incoming frames at
        // stream.initial_frame_max (8192 by default) regardless of the value
        // later negotiated at Tune, so seed the pre-Open ceiling before the first
        // handshake frame is sent (see #379). It is lifted once OpenResponseV1
        // succeeds.
        $streamConnection->setPreOpenMaxFrameSize(
            $initialFrameMax ?? StreamConnection::DEFAULT_INITIAL_FRAME_SIZE
        );

        // 1. PeerProperties
        $streamConnection->sendMessage(new PeerPropertiesRequestV1());
        $peerResponse = $streamConnection->readMessage();
        if (!$peerResponse instanceof PeerPropertiesResponseV1) {
            throw UnexpectedResponseException::create(PeerPropertiesResponseV1::class, $peerResponse);
        }

        // 2. SaslHandshake
        $streamConnection->sendMessage(new SaslHandshakeRequestV1());
        $handshakeResponse = $streamConnection->readMessage();
        if (!$handshakeResponse instanceof SaslHandshakeResponseV1) {
            throw UnexpectedResponseException::create(SaslHandshakeResponseV1::class, $handshakeResponse);
        }
        // Verify PLAIN mechanism is available
        $mechanisms = $handshakeResponse->getMechanisms();
        if (!in_array('PLAIN', $mechanisms, true)) {
            throw new AuthenticationException("PLAIN SASL mechanism not supported by server");
        }

        // 3. SaslAuthenticate
        $streamConnection->sendMessage(new SaslAuthenticateRequestV1('PLAIN', $user, $password));
        $authResponse = $streamConnection->readMessage();
        if (!$authResponse instanceof SaslAuthenticateResponseV1) {
            throw UnexpectedResponseException::create(SaslAuthenticateResponseV1::class, $authResponse);
        }

        // 4. Tune (server sends TuneRequestV1)
        $tune = $streamConnection->readMessage();
        if (!$tune instanceof TuneRequestV1) {
            throw UnexpectedResponseException::create(TuneRequestV1::class, $tune);
        }

        // 5. TuneResponse (negotiate values with server)
        $negotiatedFrameMax = self::negotiatedMaxValue(
            $requestedFrameMax ?? $tune->getFrameMax(),
            $tune->getFrameMax()
        );
        $negotiatedHeartbeat = self::negotiatedMaxValue(
            $requestedHeartbeat ?? $tune->getHeartbeat(),
            $tune->getHeartbeat()
        );
        $streamConnection->sendMessage(new TuneResponseV1($negotiatedFrameMax, $negotiatedHeartbeat));

        // Negotiation must only ever LOWER the incoming control-frame cap from its
        // safe default: a broker sending frameMax = 0xFFFFFFFF (or any huge value)
        // when the caller didn't explicitly request one must not blow the cap open
        // (see GH #398). If the caller explicitly passed requestedFrameMax, that is
        // a deliberate raise (or lower) and is honored as-is.
        if ($negotiatedFrameMax > 0) {
            $streamConnection->setMaxFrameSize(
                $requestedFrameMax !== null
                    ? $negotiatedFrameMax
                    : min($negotiatedFrameMax, StreamConnection::DEFAULT_MAX_FRAME_SIZE)
            );
        }

        // The broker does not enforce frame_max on Deliver frames (0x0008) — a
        // stream chunk is sent whole — so Deliver frames get their own, separately
        // sized cap rather than being bound by the negotiated control-frame max.
        $streamConnection->setMaxDeliverFrameSize(
            $maxDeliverFrameSize ?? StreamConnection::DEFAULT_MAX_DELIVER_FRAME_SIZE
        );

        // 6. Open
        $streamConnection->sendMessage(new OpenRequestV1($vhost));
        $openResponse = $streamConnection->readMessage();
        if (!$openResponse instanceof OpenResponseV1) {
            throw UnexpectedResponseException::create(OpenResponseV1::class, $openResponse);
        }

        // Open completed, so the broker's pre-Open ceiling no longer applies:
        // frames we send are now bound by the actual negotiated frame_max.
        // Writing a larger frame would just get the connection closed by the
        // broker, so reject it fast and clearly instead (see sendFrame()).
        $streamConnection->setOutgoingMaxFrameSize($negotiatedFrameMax);
        // The pre-Open window has ended; lift its ceiling explicitly.
        $streamConnection->setPreOpenMaxFrameSize(0);

        return new self($streamConnection, $logger);
    }

    private static function negotiatedMaxValue(int $clientValue, int $serverValue): int
    {
        return match (true) {
            $clientValue === 0 || $serverValue === 0 => max($clientValue, $serverValue),
            default => min($clientValue, $serverValue),
        };
    }

    /**
     * Create a stream on the broker.
     *
     * A stream that already exists, or invalid $arguments, is not reported
     * through a return value: the broker's non-OK response code is asserted
     * while the response is deserialized and surfaces as a ProtocolException.
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
    public function createStream(string $name, array $arguments = []): void
    {
        $this->streamConnection->sendMessage(new CreateRequestV1($name, $arguments));
        $response = $this->streamConnection->readMessage();
        if (!$response instanceof CreateResponseV1) {
            throw UnexpectedResponseException::create(CreateResponseV1::class, $response);
        }
    }

    /**
     * Delete a stream from the broker.
     *
     * A stream that does not exist is reported as a ProtocolException (the
     * broker's non-OK response code is asserted during deserialization), not as
     * a return value.
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
    public function deleteStream(string $name): void
    {
        $this->streamConnection->sendMessage(new DeleteStreamRequestV1($name));
        $response = $this->streamConnection->readMessage();
        if (!$response instanceof DeleteStreamResponseV1) {
            throw UnexpectedResponseException::create(DeleteStreamResponseV1::class, $response);
        }
    }

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
    ): void {
        $this->streamConnection->sendMessage(new CreateSuperStreamRequestV1(
            $name,
            $partitions,
            $bindingKeys,
            $arguments
        ));
        $response = $this->streamConnection->readMessage();
        if (!$response instanceof CreateSuperStreamResponseV1) {
            throw UnexpectedResponseException::create(CreateSuperStreamResponseV1::class, $response);
        }
    }

    /**
     * Delete a super stream and all of its partition streams.
     *
     * A super stream that does not exist is reported as a ProtocolException (the
     * broker's non-OK response code is asserted during deserialization), not as
     * a return value.
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
    public function deleteSuperStream(string $name): void
    {
        $this->streamConnection->sendMessage(new DeleteSuperStreamRequestV1($name));
        $response = $this->streamConnection->readMessage();
        if (!$response instanceof DeleteSuperStreamResponseV1) {
            throw UnexpectedResponseException::create(DeleteSuperStreamResponseV1::class, $response);
        }
    }

    /**
     * Ask the broker which partition stream(s) a routing key maps to, using the
     * exchange bindings created by createSuperStream().
     *
     * More than one partition can legitimately match a single key when binding
     * keys overlap. A broker error does not produce an empty array; it raises a
     * ProtocolException.
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
    public function route(string $routingKey, string $superStream): array
    {
        $this->streamConnection->sendMessage(new RouteRequestV1($routingKey, $superStream));
        $response = $this->streamConnection->readMessage();
        if (!$response instanceof RouteResponseV1) {
            throw UnexpectedResponseException::create(RouteResponseV1::class, $response);
        }
        return $response->getStreams();
    }

    /**
     * Resolve a super stream's partition (physical stream) names.
     *
     * @param string $superStream Super stream name.
     * @return list<string> Partition stream names.
     * @throws ProtocolException If the super stream does not exist (the broker's Partitions
     *                                 response code is asserted before this method is reached
     *                                 — see PartitionsResponseV1), exists but currently has
     *                                 zero partitions, or the response has an unexpected
     *                                 command or version.
     * @throws UnexpectedResponseException If the server replies with something other than a
     *                                 Partitions response.
     * @throws InvalidArgumentException If the serialized request exceeds the negotiated
     *                                 outgoing frame size.
     * @throws ConnectionException If the socket is not connected or a write or read fails.
     * @throws DeserializationException If the response frame cannot be deserialized.
     * @throws TimeoutException If the response does not arrive in time.
     */
    public function partitions(string $superStream): array
    {
        $this->streamConnection->sendMessage(new PartitionsRequestV1($superStream));
        $response = $this->streamConnection->readMessage();
        if (!$response instanceof PartitionsResponseV1) {
            throw UnexpectedResponseException::create(PartitionsResponseV1::class, $response);
        }
        $streams = $response->getStreams();
        if ($streams === []) {
            throw new ProtocolException("Super stream \"{$superStream}\" has no partitions");
        }
        return array_values($streams);
    }

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
    public function streamExists(string $name): bool
    {
        $this->streamConnection->sendMessage(new MetadataRequestV1([$name]));
        $response = $this->streamConnection->readMessage();
        if (!$response instanceof MetadataResponseV1) {
            throw UnexpectedResponseException::create(MetadataResponseV1::class, $response);
        }
        foreach ($response->getStreamMetadata() as $meta) {
            if ($meta->getStreamName() === $name) {
                return $meta->getResponseCode() === ResponseCodeEnum::OK->value;
            }
        }
        return false;
    }

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
    public function getStreamStats(string $name): array
    {
        $this->streamConnection->sendMessage(new StreamStatsRequestV1($name));
        $response = $this->streamConnection->readMessage();
        if (!$response instanceof StreamStatsResponseV1) {
            throw UnexpectedResponseException::create(StreamStatsResponseV1::class, $response);
        }
        $result = [];
        foreach ($response->getStats() as $stat) {
            $result[$stat->getKey()] = $stat->getValue();
        }
        return $result;
    }

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
    public function getMetadata(array $streams): MetadataResponseV1
    {
        $this->streamConnection->sendMessage(new MetadataRequestV1($streams));
        $response = $this->streamConnection->readMessage();
        if (!$response instanceof MetadataResponseV1) {
            throw UnexpectedResponseException::create(MetadataResponseV1::class, $response);
        }
        return $response;
    }

    /**
     * Query the offset last stored for a named consumer on a stream.
     *
     * The returned value is the next offset to consume (last processed + 1).
     * Having no stored offset is reported as a ProtocolException, not as a
     * sentinel value.
     *
     * @param string $reference Consumer name the offset was stored under.
     * @param string $stream Stream name.
     * @return int The stored offset.
     * @throws ProtocolException If the broker returns a non-OK response code (for example no
     *                                 offset is stored for this pair), or the response has an
     *                                 unexpected command or version.
     * @throws UnexpectedResponseException If the server replies with something other than a
     *                                 QueryOffset response.
     * @throws InvalidArgumentException If the serialized request exceeds the negotiated
     *                                 outgoing frame size.
     * @throws ConnectionException If the socket is not connected or a write or read fails.
     * @throws DeserializationException If the response frame cannot be deserialized.
     * @throws TimeoutException If the response does not arrive in time.
     */
    public function queryOffset(string $reference, string $stream): int
    {
        $this->streamConnection->sendMessage(new QueryOffsetRequestV1($reference, $stream));
        $response = $this->streamConnection->readMessage();
        if (!$response instanceof QueryOffsetResponseV1) {
            throw UnexpectedResponseException::create(QueryOffsetResponseV1::class, $response);
        }
        return $response->getOffset();
    }

    /**
     * Close the connection and every producer and consumer it owns.
     *
     * Idempotent: after the first call the method returns immediately, so a
     * producer/consumer close triggered here frees its id exactly once. A
     * failure while closing an individual producer or consumer is logged and
     * does not abort the shutdown; the broker Close exchange is still performed,
     * and the underlying socket is closed in a `finally` even if that exchange
     * fails.
     *
     * @throws ProtocolException If the broker returns a non-OK Close response code, or the
     *                                 response has an unexpected command or version.
     * @throws UnexpectedResponseException If the server replies with something other than a
     *                                 Close response.
     * @throws ConnectionException If the socket is not connected or the Close exchange fails.
     * @throws DeserializationException If the Close response frame cannot be deserialized.
     * @throws TimeoutException If the Close response does not arrive in time.
     */
    public function close(): void
    {
        if ($this->closed) {
            return;
        }
        $this->closed = true;

        foreach ($this->consumers as $subscriptionId => $consumer) {
            try {
                $consumer->close();
            } catch (\Throwable $e) {
                $this->logger->warning('Failed to close consumer during connection close', [
                    'subscriptionId' => $subscriptionId,
                    'exception' => $e,
                ]);
            }
        }
        $this->consumers = [];

        foreach ($this->producers as $publisherId => $producer) {
            try {
                $producer->close();
            } catch (\Throwable $e) {
                $this->logger->warning('Failed to close producer during connection close', [
                    'publisherId' => $publisherId,
                    'exception' => $e,
                ]);
            }
        }
        $this->producers = [];

        try {
            $this->streamConnection->sendMessage(new CloseRequestV1(0, 'OK'));
            $response = $this->streamConnection->readMessage();
            if (!$response instanceof CloseResponseV1) {
                throw UnexpectedResponseException::create(CloseResponseV1::class, $response);
            }
        } finally {
            $this->streamConnection->close();
        }
    }

    /**
     * Whether the underlying socket is still valid.
     *
     * This is a local check of the stream resource; it does not probe the broker,
     * so a peer that has gone away without the socket noticing still reports
     * `true` until the next read or write fails.
     *
     * @return bool True while the underlying stream resource is still valid.
     */
    public function isConnected(): bool
    {
        return $this->streamConnection->isConnected();
    }

    /**
     * Best-effort close on garbage collection.
     *
     * Calls close() when the connection was not closed explicitly. Every
     * throwable is caught and logged, because an exception raised from a
     * destructor during PHP shutdown is fatal, so this never propagates an
     * error.
     */
    public function __destruct()
    {
        if (!$this->closed) {
            try {
                $this->close();
            } catch (\Throwable $e) {
                $this->logger->error('Failed to close connection in destructor', [
                    'exception' => $e,
                ]);
            }
        }
    }

    /**
     * Create a producer for publishing to a stream.
     *
     * Declares the publisher eagerly, so a missing stream is reported here as a
     * ProtocolException rather than on the first publish. The publisher id is
     * reserved for the connection until the returned producer is closed.
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
     *                                 all MAX_CONCURRENT_PUBLISHERS publisher ids are in use.
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
    ): ProducerInterface {
        return $this->newProducer($stream, $name, $onConfirm, $maxPendingConfirms, $redeclareTimeout);
    }

    /**
     * Allocate a publisher id, build the Producer and keep it in $producers
     * until it is closed (which frees both the id and the reference).
     *
     * @throws ConnectionException If all publisher ids are in use
     */
    private function newProducer(
        string $stream,
        ?string $name,
        ?callable $onConfirm,
        int $maxPendingConfirms,
        float $redeclareTimeout,
    ): Producer {
        $publisherId = $this->allocateId(
            $this->publisherIdCursor,
            $this->producers,
            self::MAX_CONCURRENT_PUBLISHERS,
            'publisher'
        );
        $producer = new Producer(
            $this->streamConnection,
            $stream,
            $publisherId,
            $name,
            $onConfirm,
            $maxPendingConfirms,
            $redeclareTimeout,
            onClose: function (int $id): void {
                unset($this->producers[$id]);
            },
        );
        $this->producers[$publisherId] = $producer;
        return $producer;
    }

    /**
     * Find a free id in [0, $limit), starting at $cursor and wrapping.
     *
     * The live-object map doubles as the allocation map, so the two can never
     * drift apart: an id is free exactly while no object holds it.
     *
     * @param int              $cursor Advanced past the returned id (by reference)
     * @param array<int, object> $inUse  Live objects keyed by id
     * @param int              $limit  Number of ids the protocol allows (uint8: 256)
     * @param string           $what   Noun used in the exhaustion message
     * @throws ConnectionException If every id is taken
     */
    private function allocateId(int &$cursor, array $inUse, int $limit, string $what): int
    {
        for ($i = 0; $i < $limit; $i++) {
            $id = ($cursor + $i) % $limit;
            if (!isset($inUse[$id])) {
                $cursor = ($id + 1) % $limit;
                return $id;
            }
        }

        throw new ConnectionException(sprintf(
            'Cannot allocate a %s id: all %d ids of this connection are in use. '
            . 'Close the %ss you no longer need, or open another connection.',
            $what,
            $limit,
            $what
        ));
    }

    /**
     * Create a consumer that subscribes to a stream.
     *
     * Subscribes eagerly, so a missing stream is reported here as a
     * ProtocolException rather than on the first read. The subscription id is
     * reserved for the connection until the returned consumer is closed.
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
     * @param array<int, string> $filterValues Stream filtering values, sent as `filter.0`,
     *                                 `filter.1`, ... properties. Filtering is broker-side and
     *                                 chunk-granular (a bloom filter per chunk) — see
     *                                 Producer::sendWithFilter() and Consumer's class docblock
     *                                 for the caveats.
     * @param bool $matchUnfiltered When $filterValues is non-empty, also deliver
     *                                 messages published with no filter value.
     * @param bool $singleActiveConsumer Join the broker's single-active-consumer
     *                                 group for this $name; requires $name.
     * @param string|null $superStream Name of the super stream this partition
     *                                 belongs to, if any.
     * @param int $creditWindowBytes Target bytes in flight for the adaptive credit
     *                                 window; `0` pins the window to $initialCredit chunks.
     * @param int $maxDecodeDepth Maximum AMQP nesting depth accepted when a delivered
     *                                 message is decoded.
     * @param bool $verifyCrc Verify every delivered chunk's CRC-32 against its
     *                                 header (disable only if corruption is handled upstream).
     * @return ConsumerInterface A subscribed consumer whose subscription id stays reserved
     *                                 by this connection until the consumer is closed.
     * @throws ConnectionException If the socket is not connected, a write or read fails, or
     *                                 all MAX_CONCURRENT_SUBSCRIPTIONS subscription ids are in
     *                                 use.
     * @throws InvalidArgumentException If $initialCredit is outside 1..Consumer::MAX_CREDIT,
     *                                 $creditWindowBytes is negative, $maxDecodeDepth is below
     *                                 1, $singleActiveConsumer is set without $name, or the
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
    ): ConsumerInterface {
        $subscriptionId = $this->allocateId(
            $this->subscriptionIdCursor,
            $this->consumers,
            self::MAX_CONCURRENT_SUBSCRIPTIONS,
            'subscription'
        );
        $consumer = new Consumer(
            $this->streamConnection,
            $stream,
            $subscriptionId,
            $offset,
            $name,
            $autoCommit,
            $initialCredit,
            filterValues: $filterValues,
            matchUnfiltered: $matchUnfiltered,
            singleActiveConsumer: $singleActiveConsumer,
            superStream: $superStream,
            creditWindowBytes: $creditWindowBytes,
            maxDecodeDepth: $maxDecodeDepth,
            onClose: function (int $id): void {
                unset($this->consumers[$id]);
            },
            verifyCrc: $verifyCrc,
        );
        $this->consumers[$subscriptionId] = $consumer;
        return $consumer;
    }

    /**
     * Create a producer that publishes to a super stream's partitions, routing
     * each message through $strategy.
     *
     * Resolves the partition list immediately (one partitions() round trip) but
     * opens each partition's Producer lazily on first publish to it. A
     * MetadataUpdate on any partition makes the next publish re-resolve the
     * topology.
     *
     * @param string $superStream Super stream to publish to.
     * @param RoutingStrategy|null $strategy Routing strategy; defaults to
     *                                 HashRoutingStrategy.
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
    ): SuperStreamProducerInterface {
        $partitions = $this->partitions($superStream);
        $strategy ??= new HashRoutingStrategy();

        $factory = function (string $partition) use (
            $name,
            $onConfirm,
            $maxPendingConfirms,
            $redeclareTimeout
        ): ProducerInterface {
            // Per-partition publisher name so name-based dedup/sequence-query
            // (Producer::querySequence()) still works per partition.
            $partitionName = $name !== null ? "{$name}-{$partition}" : null;
            return $this->newProducer(
                $partition,
                $partitionName,
                $onConfirm,
                $maxPendingConfirms,
                $redeclareTimeout
            );
        };

        $producer = new SuperStreamProducer(
            $partitions,
            $strategy,
            \Closure::fromCallable($factory),
            fn(): array => $this->partitions($superStream),
        );

        // A MetadataUpdate on any partition makes the producer re-resolve the
        // topology before its next publish. WeakReference: the handler must not
        // keep a closed producer alive.
        $ref = \WeakReference::create($producer);
        $handlerId = 'super-stream-producer-' . spl_object_id($producer);
        foreach ($partitions as $partition) {
            $this->streamConnection->registerMetadataUpdateHandler(
                $partition,
                $handlerId,
                static function () use ($ref): void {
                    $ref->get()?->markPartitionsStale();
                }
            );
        }

        return $producer;
    }

    /**
     * Create a consumer that subscribes to every partition of a super stream.
     *
     * Every partition's Consumer shares the same $name, which is what lets the
     * broker group the per-partition subscriptions into single-active-consumer
     * groups. Offset tracking is per-partition; there is no super-stream-wide
     * offset.
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
     *                                 partition's Consumer.
     * @return SuperStreamConsumerInterface A consumer aggregating one plain Consumer per
     *                                 partition.
     * @throws ProtocolException If the super stream does not exist or has zero partitions,
     *                                 the broker rejects a Subscribe, or a response has an
     *                                 unexpected command or version.
     * @throws UnexpectedResponseException If a partitions() response has an unexpected type.
     * @throws ConnectionException If the socket is not connected, a write or read fails, or
     *                                 all MAX_CONCURRENT_SUBSCRIPTIONS subscription ids are in
     *                                 use.
     * @throws InvalidArgumentException If a Consumer argument is out of range,
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
    ): SuperStreamConsumerInterface {
        $partitions = $this->partitions($superStream);

        /** @var array<string, ConsumerInterface> $consumers partition stream name => Consumer */
        $consumers = [];
        foreach ($partitions as $partition) {
            $consumers[$partition] = $this->createConsumer(
                $partition,
                $offset,
                $name,
                $autoCommit,
                $initialCredit,
                singleActiveConsumer: $singleActiveConsumer,
                superStream: $superStream,
                creditWindowBytes: $creditWindowBytes,
                maxDecodeDepth: $maxDecodeDepth,
                verifyCrc: $verifyCrc,
            );
        }

        $readLoop = fn(float $timeout): int => $this->streamConnection->readLoop(maxFrames: 1, timeout: $timeout);

        return new SuperStreamConsumer($partitions, $consumers, \Closure::fromCallable($readLoop));
    }

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
     * @throws InvalidArgumentException If a registered ConsumerUpdate handler returns
     *                                 an offset type outside the protocol's reply range (0-5).
     * @throws TimeoutException If a reply this loop must send (a heartbeat echo, a
     *                                 server-close acknowledgement or a ConsumerUpdate reply)
     *                                 cannot be written within the socket timeout.
     */
    public function readLoop(?int $maxFrames = null, ?float $timeout = null): int
    {
        return $this->streamConnection->readLoop($maxFrames, $timeout);
    }

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
    public function storeOffset(string $reference, string $stream, int $offset): void
    {
        $this->streamConnection->sendMessage(new StoreOffsetRequestV1($reference, $stream, $offset));
    }
}
