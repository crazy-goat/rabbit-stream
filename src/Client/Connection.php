<?php

namespace CrazyGoat\RabbitStream\Client;

use CrazyGoat\RabbitStream\Enum\ResponseCodeEnum;
use CrazyGoat\RabbitStream\Request\CloseRequestV1;
use CrazyGoat\RabbitStream\Request\CreateRequestV1;
use CrazyGoat\RabbitStream\Request\DeleteStreamRequestV1;
use CrazyGoat\RabbitStream\Request\MetadataRequestV1;
use CrazyGoat\RabbitStream\Request\OpenRequest;
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
use CrazyGoat\RabbitStream\Response\QueryOffsetResponseV1;
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

    private function __construct(
        private readonly StreamConnection $streamConnection,
    ) {}

    static public function create(
        string $host = '127.0.0.1',
        int $port = 5552,
        string $user = 'guest',
        string $password = 'guest',
        string $vhost = '/',
        ?BinarySerializerInterface $serializer = null,
        ?LoggerInterface $logger = null,
    ): self {
        $logger ??= new NullLogger();
        $serializer ??= new PhpBinarySerializer();

        $streamConnection = new StreamConnection($host, $port, $logger, $serializer);
        $streamConnection->connect();

        // 1. PeerProperties
        $streamConnection->sendMessage(new PeerPropertiesToStreamBufferV1());
        $streamConnection->readMessage();

        // 2. SaslHandshake
        $streamConnection->sendMessage(new SaslHandshakeRequestV1());
        $handshakeResponse = $streamConnection->readMessage();
        if (!$handshakeResponse instanceof SaslHandshakeResponseV1) {
            throw new \Exception("Expected SaslHandshakeResponseV1, got " . get_class($handshakeResponse));
        }
        // Verify PLAIN mechanism is available
        $mechanisms = $handshakeResponse->getMechanisms();
        if (!in_array('PLAIN', $mechanisms, true)) {
            throw new \Exception("PLAIN SASL mechanism not supported by server");
        }

        // 3. SaslAuthenticate
        $streamConnection->sendMessage(new SaslAuthenticateRequestV1('PLAIN', $user, $password));
        $streamConnection->readMessage();

        // 4. Tune (server sends TuneRequestV1)
        $tune = $streamConnection->readMessage();
        if (!$tune instanceof TuneRequestV1) {
            throw new \Exception("Expected TuneRequestV1, got " . get_class($tune));
        }

        // 5. TuneResponse (echo back server's values)
        $streamConnection->sendMessage(new TuneResponseV1($tune->getFrameMax(), $tune->getHeartbeat()));

        // 6. Open
        $streamConnection->sendMessage(new OpenRequest($vhost));
        $streamConnection->readMessage();

        return new self($streamConnection);
    }

    public function createStream(string $name, array $arguments = []): void
    {
        $this->streamConnection->sendMessage(new CreateRequestV1($name, $arguments));
        $response = $this->streamConnection->readMessage();
        if (!$response instanceof CreateResponseV1) {
            throw new \Exception("Expected CreateResponseV1, got " . get_class($response));
        }
    }

    public function deleteStream(string $name): void
    {
        $this->streamConnection->sendMessage(new DeleteStreamRequestV1($name));
        $response = $this->streamConnection->readMessage();
        if (!$response instanceof DeleteStreamResponseV1) {
            throw new \Exception("Expected DeleteStreamResponseV1, got " . get_class($response));
        }
    }

    public function streamExists(string $name): bool
    {
        $this->streamConnection->sendMessage(new MetadataRequestV1([$name]));
        $response = $this->streamConnection->readMessage();
        if (!$response instanceof MetadataResponseV1) {
            throw new \Exception("Expected MetadataResponseV1, got " . get_class($response));
        }
        foreach ($response->getStreamMetadata() as $meta) {
            if ($meta->getStreamName() === $name) {
                return $meta->getResponseCode() === ResponseCodeEnum::OK->value;
            }
        }
        return false;
    }

    public function getStreamStats(string $name): array
    {
        $this->streamConnection->sendMessage(new StreamStatsRequestV1($name));
        $response = $this->streamConnection->readMessage();
        if (!$response instanceof StreamStatsResponseV1) {
            throw new \Exception("Expected StreamStatsResponseV1, got " . get_class($response));
        }
        $result = [];
        foreach ($response->getStats() as $stat) {
            $result[$stat->getKey()] = $stat->getValue();
        }
        return $result;
    }

    public function getMetadata(array $streams): MetadataResponseV1
    {
        $this->streamConnection->sendMessage(new MetadataRequestV1($streams));
        $response = $this->streamConnection->readMessage();
        if (!$response instanceof MetadataResponseV1) {
            throw new \Exception("Expected MetadataResponseV1, got " . get_class($response));
        }
        return $response;
    }

    public function queryOffset(string $reference, string $stream): int
    {
        $this->streamConnection->sendMessage(new QueryOffsetRequestV1($reference, $stream));
        $response = $this->streamConnection->readMessage();
        if (!$response instanceof QueryOffsetResponseV1) {
            throw new \Exception("Expected QueryOffsetResponseV1, got " . get_class($response));
        }
        return $response->getOffset();
    }

    public function close(): void
    {
        try {
            $this->streamConnection->sendMessage(new CloseRequestV1(0, 'OK'));
            $response = $this->streamConnection->readMessage();
            if (!$response instanceof CloseResponseV1) {
                throw new \Exception("Expected CloseResponseV1, got " . get_class($response));
            }
        } finally {
            $this->streamConnection->close();
        }
    }

    public function createProducer(
        string $stream,
        ?string $name = null,
        ?callable $onConfirm = null,
    ): Producer {
        $publisherId = $this->publisherIdCounter++;
        return new Producer($this->streamConnection, $stream, $publisherId, $name, $onConfirm);
    }

    public function createConsumer(
        string $stream,
        OffsetSpec $offset,
        ?string $name = null,
        int $autoCommit = 0,
        int $initialCredit = 10,
    ): Consumer {
        $subscriptionId = $this->subscriptionIdCounter++;
        return new Consumer($this->streamConnection, $stream, $subscriptionId, $offset, $name, $autoCommit, $initialCredit);
    }

    public function readLoop(?int $maxFrames = null): void
    {
        $this->streamConnection->readLoop($maxFrames);
    }

    public function storeOffset(string $reference, string $stream, int $offset): void
    {
        $this->streamConnection->sendMessage(new StoreOffsetRequestV1($reference, $stream, $offset));
    }
}
