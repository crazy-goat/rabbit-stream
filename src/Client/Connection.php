<?php

declare(strict_types=1);

namespace CrazyGoat\RabbitStream\Client;

use CrazyGoat\RabbitStream\Enum\ResponseCodeEnum;
use CrazyGoat\RabbitStream\Exception\AuthenticationException;
use CrazyGoat\RabbitStream\Exception\UnexpectedResponseException;
use CrazyGoat\RabbitStream\Request\CloseRequestV1;
use CrazyGoat\RabbitStream\Request\CreateRequestV1;
use CrazyGoat\RabbitStream\Request\DeleteStreamRequestV1;
use CrazyGoat\RabbitStream\Request\MetadataRequestV1;
use CrazyGoat\RabbitStream\Request\OpenRequestV1;
use CrazyGoat\RabbitStream\Request\PeerPropertiesToStreamBufferV1;
use CrazyGoat\RabbitStream\Request\QueryOffsetRequestV1;
use CrazyGoat\RabbitStream\Request\SaslAuthenticateRequestV1;
use CrazyGoat\RabbitStream\Request\SaslHandshakeRequestV1;
use CrazyGoat\RabbitStream\Request\StoreOffsetRequestV1;
use CrazyGoat\RabbitStream\Request\StreamStatsRequestV1;
use CrazyGoat\RabbitStream\Request\TuneRequestV1;
use CrazyGoat\RabbitStream\Response\CloseResponseV1;
use CrazyGoat\RabbitStream\Response\CreateResponseV1;
use CrazyGoat\RabbitStream\Response\DeleteStreamResponseV1;
use CrazyGoat\RabbitStream\Response\MetadataResponseV1;
use CrazyGoat\RabbitStream\Response\OpenResponseV1;
use CrazyGoat\RabbitStream\Response\PeerPropertiesResponseV1;
use CrazyGoat\RabbitStream\Response\QueryOffsetResponseV1;
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

class Connection
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
    ): self {
        if ($requestedFrameMax !== null && $requestedFrameMax < 0) {
            throw new \InvalidArgumentException('requestedFrameMax must not be negative');
        }
        if ($requestedHeartbeat !== null && $requestedHeartbeat < 0) {
            throw new \InvalidArgumentException('requestedHeartbeat must not be negative');
        }

        $logger ??= new NullLogger();
        $serializer ??= new PhpBinarySerializer();

        $streamConnection = new StreamConnection($host, $port, $logger, $serializer);
        $streamConnection->connect();

        // 1. PeerProperties
        $streamConnection->sendMessage(new PeerPropertiesToStreamBufferV1());
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

        if ($negotiatedFrameMax > 0) {
            $streamConnection->setMaxFrameSize($negotiatedFrameMax);
        }

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
    ): Producer {
        $publisherId = $this->publisherIdCounter++;
        $producer = new Producer($this->streamConnection, $stream, $publisherId, $name, $onConfirm);
        $this->producers[$publisherId] = $producer;
        return $producer;
    }

    public function createConsumer(
        string $stream,
        OffsetSpec $offset,
        ?string $name = null,
        int $autoCommit = 0,
        int $initialCredit = 10,
    ): Consumer {
        $subscriptionId = $this->subscriptionIdCounter++;
        $consumer = new Consumer(
            $this->streamConnection,
            $stream,
            $subscriptionId,
            $offset,
            $name,
            $autoCommit,
            $initialCredit
        );
        $this->consumers[$subscriptionId] = $consumer;
        return $consumer;
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
