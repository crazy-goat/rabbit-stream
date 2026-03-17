<?php

namespace CrazyGoat\RabbitStream\Client;

use CrazyGoat\RabbitStream\Request\OpenRequest;
use CrazyGoat\RabbitStream\Request\PeerPropertiesToStreamBufferV1;
use CrazyGoat\RabbitStream\Request\QueryPublisherSequenceRequestV1;
use CrazyGoat\RabbitStream\Request\SaslAuthenticateRequestV1;
use CrazyGoat\RabbitStream\Request\SaslHandshakeRequestV1;
use CrazyGoat\RabbitStream\Request\TuneRequestV1;
use CrazyGoat\RabbitStream\Response\QueryPublisherSequenceResponseV1;
use CrazyGoat\RabbitStream\Response\TuneResponseV1;
use CrazyGoat\RabbitStream\StreamConnection;

class StreamClient
{
    private int $publisherIdCounter = 0;

    public function __construct(private readonly StreamConnection $connection)
    {
    }

    public static function connect(StreamClientConfig $config): self
    {
        $connection = new StreamConnection($config->host, $config->port);
        $connection->connect();

        $connection->sendMessage(new PeerPropertiesToStreamBufferV1());
        $connection->readMessage();

        $connection->sendMessage(new SaslHandshakeRequestV1());
        $connection->readMessage();

        $connection->sendMessage(new SaslAuthenticateRequestV1('PLAIN', $config->user, $config->password));
        $connection->readMessage();

        $tune = $connection->readMessage();
        if (!$tune instanceof TuneRequestV1) {
            throw new \Exception("Expected TuneRequestV1, got " . get_class($tune));
        }

        $connection->sendMessage(new TuneResponseV1($tune->getFrameMax(), $tune->getHeartbeat()));

        $connection->sendMessage(new OpenRequest($config->vhost));
        $connection->readMessage();

        return new self($connection);
    }

    public function createProducer(string $stream, ?ProducerConfig $config = null): Producer
    {
        return new Producer(
            $this->connection,
            $stream,
            $this->publisherIdCounter++,
            $config ?? new ProducerConfig()
        );
    }

    public function readLoop(?int $maxFrames = null): void
    {
        $this->connection->readLoop($maxFrames);
    }

    public function queryPublisherSequence(string $reference, string $stream): int
    {
        $this->connection->sendMessage(new QueryPublisherSequenceRequestV1($reference, $stream));
        $response = $this->connection->readMessage();

        if (!$response instanceof QueryPublisherSequenceResponseV1) {
            throw new \Exception("Expected QueryPublisherSequenceResponseV1, got " . get_class($response));
        }

        return $response->getSequence();
    }

    public function close(): void
    {
        $this->connection->close();
    }
}
