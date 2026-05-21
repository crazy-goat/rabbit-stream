<?php

declare(strict_types=1);

namespace CrazyGoat\RabbitStream\Tests\E2E;

use CrazyGoat\RabbitStream\Exception\ProtocolException;
use CrazyGoat\RabbitStream\Request\CreateRequestV1;
use CrazyGoat\RabbitStream\Request\DeleteStreamRequestV1;
use CrazyGoat\RabbitStream\Request\OpenRequestV1;
use CrazyGoat\RabbitStream\Request\PeerPropertiesRequestV1;
use CrazyGoat\RabbitStream\Request\SaslAuthenticateRequestV1;
use CrazyGoat\RabbitStream\Request\SaslHandshakeRequestV1;
use CrazyGoat\RabbitStream\Request\SubscribeRequestV1;
use CrazyGoat\RabbitStream\Request\TuneRequestV1;
use CrazyGoat\RabbitStream\Request\UnsubscribeRequestV1;
use CrazyGoat\RabbitStream\Response\CreateResponseV1;
use CrazyGoat\RabbitStream\Response\SubscribeResponseV1;
use CrazyGoat\RabbitStream\Response\TuneResponseV1;
use CrazyGoat\RabbitStream\Response\UnsubscribeResponseV1;
use CrazyGoat\RabbitStream\StreamConnection;
use CrazyGoat\RabbitStream\VO\OffsetSpec;
use PHPUnit\Framework\TestCase;

class UnsubscribeTest extends TestCase
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

    public function testUnsubscribeFromStream(): void
    {
        $this->connection = $this->connectAndOpen();
        $this->streamName = 'test-unsubscribe-stream-' . uniqid();

        // Create a test stream
        $this->connection->sendMessage(new CreateRequestV1($this->streamName));
        $createResponse = $this->connection->readMessage();
        $this->assertInstanceOf(CreateResponseV1::class, $createResponse);

        // Subscribe to the stream
        $this->connection->sendMessage(new SubscribeRequestV1(1, $this->streamName, OffsetSpec::first(), 10));
        $subscribeResponse = $this->connection->readMessage();
        $this->assertInstanceOf(SubscribeResponseV1::class, $subscribeResponse);

        // Unsubscribe from the stream
        $this->connection->sendMessage(new UnsubscribeRequestV1(1));
        $response = $this->connection->readMessage();

        $this->assertInstanceOf(UnsubscribeResponseV1::class, $response);
    }

    public function testUnsubscribeNonExistentSubscriptionThrows(): void
    {
        $this->connection = $this->connectAndOpen();
        $this->streamName = 'test-unsubscribe-non-existent-' . uniqid();

        // Create a test stream
        $this->connection->sendMessage(new CreateRequestV1($this->streamName));
        $this->connection->readMessage();

        // Try to unsubscribe without subscribing first — should get SUBSCRIPTION_ID_NOT_EXIST (0x04)
        $this->expectException(ProtocolException::class);
        $this->expectExceptionMessage('SUBSCRIPTION_ID_NOT_EXIST');
        $this->connection->sendMessage(new UnsubscribeRequestV1(99));
        $this->connection->readMessage();
    }

    public function testUnsubscribeAlreadyUnsubscribedThrows(): void
    {
        $this->connection = $this->connectAndOpen();
        $this->streamName = 'test-unsubscribe-already-' . uniqid();

        // Create a test stream
        $this->connection->sendMessage(new CreateRequestV1($this->streamName));
        $this->connection->readMessage();

        // Subscribe
        $this->connection->sendMessage(new SubscribeRequestV1(1, $this->streamName, OffsetSpec::first(), 10));
        $subscribeResponse = $this->connection->readMessage();
        $this->assertInstanceOf(SubscribeResponseV1::class, $subscribeResponse);

        // First unsubscribe should succeed
        $this->connection->sendMessage(new UnsubscribeRequestV1(1));
        $response = $this->connection->readMessage();
        $this->assertInstanceOf(UnsubscribeResponseV1::class, $response);

        // Second unsubscribe with same ID should fail with SUBSCRIPTION_ID_NOT_EXIST (0x04)
        $this->expectException(ProtocolException::class);
        $this->expectExceptionMessage('SUBSCRIPTION_ID_NOT_EXIST');
        $this->connection->sendMessage(new UnsubscribeRequestV1(1));
        $this->connection->readMessage();
    }
}
