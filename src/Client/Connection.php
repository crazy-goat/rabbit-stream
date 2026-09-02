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
use CrazyGoat\RabbitStream\Exception\ProtocolException;
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
    ): self {
        if ($requestedFrameMax !== null && $requestedFrameMax < 0) {
            throw new \InvalidArgumentException('requestedFrameMax must not be negative');
        }
        if ($requestedHeartbeat !== null && $requestedHeartbeat < 0) {
            throw new \InvalidArgumentException('requestedHeartbeat must not be negative');
        }
        if ($maxDeliverFrameSize !== null && $maxDeliverFrameSize < 0) {
            throw new \InvalidArgumentException('maxDeliverFrameSize must not be negative');
        }
        if ($socketTimeout !== null && $socketTimeout <= 0) {
            throw new \InvalidArgumentException('socketTimeout must be greater than 0');
        }

        $logger ??= new NullLogger();
        $serializer ??= new PhpBinarySerializer();

        if (!$streamConnection instanceof \CrazyGoat\RabbitStream\StreamConnection) {
            $streamConnection = new StreamConnection(
                $host,
                $port,
                $logger,
                $serializer,
                $socketTimeout ?? StreamConnection::DEFAULT_SOCKET_TIMEOUT
            );
            $streamConnection->connect();
        }

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

        // Frames we send are bound by the actual negotiated frame_max: writing a
        // larger frame would just get the connection closed by the broker, so
        // reject it fast and clearly instead (see sendFrame()).
        $streamConnection->setOutgoingMaxFrameSize($negotiatedFrameMax);

        // 6. Open
        $streamConnection->sendMessage(new OpenRequestV1($vhost));
        $openResponse = $streamConnection->readMessage();
        if (!$openResponse instanceof OpenResponseV1) {
            throw UnexpectedResponseException::create(OpenResponseV1::class, $openResponse);
        }

        return new self($streamConnection, $logger);
    }

    private static function negotiatedMaxValue(int $clientValue, int $serverValue): int
    {
        return match (true) {
            $clientValue === 0 || $serverValue === 0 => max($clientValue, $serverValue),
            default => min($clientValue, $serverValue),
        };
    }

    /** @param array<string, string> $arguments */
    public function createStream(string $name, array $arguments = []): void
    {
        $this->streamConnection->sendMessage(new CreateRequestV1($name, $arguments));
        $response = $this->streamConnection->readMessage();
        if (!$response instanceof CreateResponseV1) {
            throw UnexpectedResponseException::create(CreateResponseV1::class, $response);
        }
    }

    public function deleteStream(string $name): void
    {
        $this->streamConnection->sendMessage(new DeleteStreamRequestV1($name));
        $response = $this->streamConnection->readMessage();
        if (!$response instanceof DeleteStreamResponseV1) {
            throw UnexpectedResponseException::create(DeleteStreamResponseV1::class, $response);
        }
    }

    /**
     * @param string[] $partitions
     * @param string[] $bindingKeys
     * @param array<string, string> $arguments
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

    public function deleteSuperStream(string $name): void
    {
        $this->streamConnection->sendMessage(new DeleteSuperStreamRequestV1($name));
        $response = $this->streamConnection->readMessage();
        if (!$response instanceof DeleteSuperStreamResponseV1) {
            throw UnexpectedResponseException::create(DeleteSuperStreamResponseV1::class, $response);
        }
    }

    /** @return string[] */
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
     * @return list<string>
     * @throws ProtocolException if the super stream does not exist (the broker's
     *                           Partitions response code is asserted OK before this
     *                           method is ever reached — see PartitionsResponseV1)
     *                           or exists but currently has zero partitions.
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

    /** @return array<string, int> */
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

    /** @param array<int, string> $streams */
    public function getMetadata(array $streams): MetadataResponseV1
    {
        $this->streamConnection->sendMessage(new MetadataRequestV1($streams));
        $response = $this->streamConnection->readMessage();
        if (!$response instanceof MetadataResponseV1) {
            throw UnexpectedResponseException::create(MetadataResponseV1::class, $response);
        }
        return $response;
    }

    public function queryOffset(string $reference, string $stream): int
    {
        $this->streamConnection->sendMessage(new QueryOffsetRequestV1($reference, $stream));
        $response = $this->streamConnection->readMessage();
        if (!$response instanceof QueryOffsetResponseV1) {
            throw UnexpectedResponseException::create(QueryOffsetResponseV1::class, $response);
        }
        return $response->getOffset();
    }

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

    public function isConnected(): bool
    {
        return $this->streamConnection->isConnected();
    }

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
     * @param array<int, string> $filterValues Stream filtering values, sent as
     *                            `filter.0`, `filter.1`, ... properties. Filtering
     *                            is broker-side and chunk-granular (a bloom filter
     *                            per chunk) — see Producer::sendWithFilter() and
     *                            Consumer's class docblock for the caveats.
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
        );
        $this->consumers[$subscriptionId] = $consumer;
        return $consumer;
    }

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

    public function createSuperStreamConsumer(
        string $superStream,
        OffsetSpec $offset,
        ?string $name = null,
        int $autoCommit = 0,
        int $initialCredit = 10,
        bool $singleActiveConsumer = false,
        int $creditWindowBytes = Consumer::DEFAULT_CREDIT_WINDOW_BYTES,
        int $maxDecodeDepth = AmqpDecoder::MAX_RECURSION_DEPTH,
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
            );
        }

        $readLoop = fn(float $timeout): int => $this->streamConnection->readLoop(maxFrames: 1, timeout: $timeout);

        return new SuperStreamConsumer($partitions, $consumers, \Closure::fromCallable($readLoop));
    }

    public function readLoop(?int $maxFrames = null, ?float $timeout = null): int
    {
        return $this->streamConnection->readLoop($maxFrames, $timeout);
    }

    public function storeOffset(string $reference, string $stream, int $offset): void
    {
        $this->streamConnection->sendMessage(new StoreOffsetRequestV1($reference, $stream, $offset));
    }
}
