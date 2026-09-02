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
    private int $publisherIdCounter = 0;
    private int $subscriptionIdCounter = 0;
    private bool $closed = false;

    /** @var array<int, Producer> */
    private array $producers = [];

    /** @var array<int, Consumer> */
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

        $logger ??= new NullLogger();
        $serializer ??= new PhpBinarySerializer();

        if (!$streamConnection instanceof \CrazyGoat\RabbitStream\StreamConnection) {
            $streamConnection = new StreamConnection($host, $port, $logger, $serializer);
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
    ): ProducerInterface {
        $publisherId = $this->publisherIdCounter++;
        $producer = new Producer(
            $this->streamConnection,
            $stream,
            $publisherId,
            $name,
            $onConfirm,
            $maxPendingConfirms
        );
        $this->producers[$publisherId] = $producer;
        return $producer;
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
    ): ConsumerInterface {
        $subscriptionId = $this->subscriptionIdCounter++;
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
    ): SuperStreamProducerInterface {
        $partitions = $this->partitions($superStream);
        $strategy ??= new HashRoutingStrategy();

        $factory = function (string $partition) use ($name, $onConfirm, $maxPendingConfirms): ProducerInterface {
            // Per-partition publisher name so name-based dedup/sequence-query
            // (Producer::querySequence()) still works per partition.
            $partitionName = $name !== null ? "{$name}-{$partition}" : null;
            $publisherId = $this->publisherIdCounter++;
            $producer = new Producer(
                $this->streamConnection,
                $partition,
                $publisherId,
                $partitionName,
                $onConfirm,
                $maxPendingConfirms
            );
            $this->producers[$publisherId] = $producer;
            return $producer;
        };

        return new SuperStreamProducer($partitions, $strategy, \Closure::fromCallable($factory));
    }

    public function createSuperStreamConsumer(
        string $superStream,
        OffsetSpec $offset,
        ?string $name = null,
        int $autoCommit = 0,
        int $initialCredit = 10,
        bool $singleActiveConsumer = false,
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
            );
        }

        $readLoop = function (float $timeout): void {
            $this->streamConnection->readLoop(maxFrames: 1, timeout: $timeout);
        };

        return new SuperStreamConsumer($partitions, $consumers, \Closure::fromCallable($readLoop));
    }

    public function readLoop(?int $maxFrames = null, ?float $timeout = null): void
    {
        $this->streamConnection->readLoop($maxFrames, $timeout);
    }

    public function storeOffset(string $reference, string $stream, int $offset): void
    {
        $this->streamConnection->sendMessage(new StoreOffsetRequestV1($reference, $stream, $offset));
    }
}
