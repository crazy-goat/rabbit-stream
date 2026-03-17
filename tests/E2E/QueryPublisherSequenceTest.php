<?php

namespace CrazyGoat\RabbitStream\Tests\E2E;

use CrazyGoat\RabbitStream\Request\CreateRequestV1;
use CrazyGoat\RabbitStream\Request\DeclarePublisherRequestV1;
use CrazyGoat\RabbitStream\Request\OpenRequest;
use CrazyGoat\RabbitStream\Request\PeerPropertiesToStreamBufferV1;
use CrazyGoat\RabbitStream\Request\PublishRequestV1;
use CrazyGoat\RabbitStream\Request\QueryPublisherSequenceRequestV1;
use CrazyGoat\RabbitStream\Request\SaslAuthenticateRequestV1;
use CrazyGoat\RabbitStream\Request\SaslHandshakeRequestV1;
use CrazyGoat\RabbitStream\Request\TuneRequestV1;
use CrazyGoat\RabbitStream\Response\CreateResponseV1;
use CrazyGoat\RabbitStream\Response\DeclarePublisherResponseV1;
use CrazyGoat\RabbitStream\Response\QueryPublisherSequenceResponseV1;
use CrazyGoat\RabbitStream\Response\TuneResponseV1;
use CrazyGoat\RabbitStream\StreamConnection;
use CrazyGoat\RabbitStream\VO\PublishedMessage;
use PHPUnit\Framework\TestCase;

class QueryPublisherSequenceTest extends TestCase
{
    private static string $host = '127.0.0.1';
    private static int $port = 5552;

    public static function setUpBeforeClass(): void
    {
        self::$host = getenv('RABBITMQ_HOST') ?: self::$host;
        self::$port = (int)(getenv('RABBITMQ_PORT') ?: self::$port);
    }

    private function connectAndOpen(): StreamConnection
    {
        $connection = new StreamConnection(self::$host, self::$port);
        $connection->connect();

        $connection->sendMessage(new PeerPropertiesToStreamBufferV1());
        $connection->readMessage();

        $connection->sendMessage(new SaslHandshakeRequestV1());
        $connection->readMessage();

        $connection->sendMessage(new SaslAuthenticateRequestV1('PLAIN', 'guest', 'guest'));
        $connection->readMessage();

        $tune = $connection->readMessage();
        $this->assertInstanceOf(TuneRequestV1::class, $tune);
        $connection->sendMessage(new TuneResponseV1($tune->getFrameMax(), $tune->getHeartbeat()));

        $connection->sendMessage(new OpenRequest('/'));
        $connection->readMessage();

        return $connection;
    }

    public function testQueryPublisherSequenceReturnsZeroForNewPublisher(): void
    {
        $connection = $this->connectAndOpen();
        $stream = 'test-query-pub-seq-stream-1';
        $publisherRef = 'test-publisher-ref-1';

        // Create stream
        $connection->sendMessage(new CreateRequestV1($stream, []));
        $createResponse = $connection->readMessage();
        $this->assertInstanceOf(CreateResponseV1::class, $createResponse);

        // Declare publisher with reference
        $connection->sendMessage(new DeclarePublisherRequestV1(1, $publisherRef, $stream));
        $declareResponse = $connection->readMessage();
        $this->assertInstanceOf(DeclarePublisherResponseV1::class, $declareResponse);

        // Query sequence before publishing
        $connection->sendMessage(new QueryPublisherSequenceRequestV1($publisherRef, $stream));
        $response = $connection->readMessage();

        $this->assertInstanceOf(QueryPublisherSequenceResponseV1::class, $response);
        $this->assertSame(0, $response->getSequence());

        $connection->close();
    }

    public function testQueryPublisherSequenceReturnsLastPublishedId(): void
    {
        $connection = $this->connectAndOpen();
        $stream = 'test-query-pub-seq-stream-2';
        $publisherRef = 'test-publisher-ref-2';

        // Create stream
        $connection->sendMessage(new CreateRequestV1($stream, []));
        $createResponse = $connection->readMessage();
        $this->assertInstanceOf(CreateResponseV1::class, $createResponse);

        // Declare publisher
        $connection->sendMessage(new DeclarePublisherRequestV1(1, $publisherRef, $stream));
        $declareResponse = $connection->readMessage();
        $this->assertInstanceOf(DeclarePublisherResponseV1::class, $declareResponse);

        // Register publisher callback to handle PublishConfirm
        $confirmed = false;
        $connection->registerPublisher(1, function () use (&$confirmed) {
            $confirmed = true;
        }, function () {});

        // Publish a message with publishingId = 5
        $connection->sendMessage(new PublishRequestV1(1, new PublishedMessage(5, 'test message')));
        $connection->readLoop(maxFrames: 1); // Wait for PublishConfirm

        // Query sequence - should return 5
        $connection->sendMessage(new QueryPublisherSequenceRequestV1($publisherRef, $stream));
        $response = $connection->readMessage();

        $this->assertInstanceOf(QueryPublisherSequenceResponseV1::class, $response);
        $this->assertSame(5, $response->getSequence());

        $connection->close();
    }
}
