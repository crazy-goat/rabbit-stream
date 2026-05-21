<?php

declare(strict_types=1);

namespace CrazyGoat\RabbitStream\Tests\E2E;

use CrazyGoat\RabbitStream\Request\CreateRequestV1;
use CrazyGoat\RabbitStream\Request\DeclarePublisherRequestV1;
use CrazyGoat\RabbitStream\Request\DeleteStreamRequestV1;
use CrazyGoat\RabbitStream\Request\OpenRequestV1;
use CrazyGoat\RabbitStream\Request\PeerPropertiesRequestV1;
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
    private ?StreamConnection $connection = null;
    private string $streamName = '';

    public static function setUpBeforeClass(): void
    {
        self::$host = getenv('RABBITMQ_HOST') ?: self::$host;
        self::$port = (int)(getenv('RABBITMQ_PORT') ?: self::$port);
    }

    protected function tearDown(): void
    {
        if (!$this->connection instanceof StreamConnection) {
            return;
        }
        try {
            if ($this->connection->isConnected() && $this->streamName !== '') {
                $this->connection->sendMessage(new DeleteStreamRequestV1($this->streamName));
                $this->connection->readMessage();
            }
        } catch (\Exception) {
            // Ignore cleanup errors — stream may already be deleted
        } finally {
            $this->connection->close();
        }
    }

    private function connectAndOpen(): StreamConnection
    {
        $connection = new StreamConnection(self::$host, self::$port);
        $connection->connect();

        $connection->sendMessage(new PeerPropertiesRequestV1());
        $connection->readMessage();

        $connection->sendMessage(new SaslHandshakeRequestV1());
        $connection->readMessage();

        $connection->sendMessage(new SaslAuthenticateRequestV1('PLAIN', 'guest', 'guest'));
        $connection->readMessage();

        $tune = $connection->readMessage();
        $this->assertInstanceOf(TuneRequestV1::class, $tune);
        $connection->sendMessage(new TuneResponseV1($tune->getFrameMax(), $tune->getHeartbeat()));

        $connection->sendMessage(new OpenRequestV1('/'));
        $connection->readMessage();

        return $connection;
    }

    public function testQueryPublisherSequenceReturnsZeroForNewPublisher(): void
    {
        $this->connection = $this->connectAndOpen();
        $this->streamName = 'test-query-pub-seq-stream-' . uniqid();
        $publisherRef = 'test-publisher-ref-1';

        // Create stream
        $this->connection->sendMessage(new CreateRequestV1($this->streamName, []));
        $createResponse = $this->connection->readMessage();
        $this->assertInstanceOf(CreateResponseV1::class, $createResponse);

        // Declare publisher with reference
        $this->connection->sendMessage(new DeclarePublisherRequestV1(1, $publisherRef, $this->streamName));
        $declareResponse = $this->connection->readMessage();
        $this->assertInstanceOf(DeclarePublisherResponseV1::class, $declareResponse);

        // Query sequence before publishing
        $this->connection->sendMessage(new QueryPublisherSequenceRequestV1($publisherRef, $this->streamName));
        $response = $this->connection->readMessage();

        $this->assertInstanceOf(QueryPublisherSequenceResponseV1::class, $response);
        $this->assertSame(0, $response->getSequence());
    }

    public function testQueryPublisherSequenceReturnsLastPublishedId(): void
    {
        $this->connection = $this->connectAndOpen();
        $this->streamName = 'test-query-pub-seq-stream-' . uniqid();
        $publisherRef = 'test-publisher-ref-2';

        // Create stream
        $this->connection->sendMessage(new CreateRequestV1($this->streamName, []));
        $createResponse = $this->connection->readMessage();
        $this->assertInstanceOf(CreateResponseV1::class, $createResponse);

        // Declare publisher
        $this->connection->sendMessage(new DeclarePublisherRequestV1(1, $publisherRef, $this->streamName));
        $declareResponse = $this->connection->readMessage();
        $this->assertInstanceOf(DeclarePublisherResponseV1::class, $declareResponse);

        // Register publisher callback to handle PublishConfirm
        $confirmed = false;
        $this->connection->registerPublisher(1, function () use (&$confirmed): void {
            $confirmed = true;
        }, function (): void {
        });

        // Publish a message with publishingId = 5
        $this->connection->sendMessage(new PublishRequestV1(1, new PublishedMessage(5, 'test message')));
        $this->connection->readLoop(maxFrames: 1); // Wait for PublishConfirm

        // Query sequence - should return 5
        $this->connection->sendMessage(new QueryPublisherSequenceRequestV1($publisherRef, $this->streamName));
        $response = $this->connection->readMessage();

        $this->assertInstanceOf(QueryPublisherSequenceResponseV1::class, $response);
        $this->assertSame(5, $response->getSequence());
    }
}
