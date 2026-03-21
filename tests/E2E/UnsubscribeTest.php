<?php

declare(strict_types=1);

namespace CrazyGoat\RabbitStream\Tests\E2E;

use CrazyGoat\RabbitStream\Request\CreateRequestV1;
use CrazyGoat\RabbitStream\Request\OpenRequestV1;
use CrazyGoat\RabbitStream\Request\PeerPropertiesToStreamBufferV1;
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

        $connection->sendMessage(new OpenRequestV1('/'));
        $connection->readMessage();

        return $connection;
    }

    public function testUnsubscribeFromStream(): void
    {
        $connection = $this->connectAndOpen();

        // Create a test stream
        $streamName = 'test-unsubscribe-stream-' . uniqid();
        $connection->sendMessage(new CreateRequestV1($streamName));
        $createResponse = $connection->readMessage();
        $this->assertInstanceOf(CreateResponseV1::class, $createResponse);

        // Subscribe to the stream
        $connection->sendMessage(new SubscribeRequestV1(1, $streamName, OffsetSpec::first(), 10));
        $subscribeResponse = $connection->readMessage();
        $this->assertInstanceOf(SubscribeResponseV1::class, $subscribeResponse);

        // Unsubscribe from the stream
        $connection->sendMessage(new UnsubscribeRequestV1(1));
        $response = $connection->readMessage();

        $this->assertInstanceOf(UnsubscribeResponseV1::class, $response);

        $connection->close();
    }

    public function testUnsubscribeNonExistentSubscriptionThrows(): void
    {
        $connection = $this->connectAndOpen();

        // Create a test stream
        $streamName = 'test-unsubscribe-non-existent-' . uniqid();
        $connection->sendMessage(new CreateRequestV1($streamName));
        $connection->readMessage();

        // Try to unsubscribe without subscribing first
        $this->expectException(\Exception::class);
        $connection->sendMessage(new UnsubscribeRequestV1(99));
        $connection->readMessage();

        $connection->close();
    }

    public function testUnsubscribeAlreadyUnsubscribedThrows(): void
    {
        $connection = $this->connectAndOpen();

        // Create a test stream
        $streamName = 'test-unsubscribe-already-' . uniqid();
        $connection->sendMessage(new CreateRequestV1($streamName));
        $connection->readMessage();

        // Subscribe
        $connection->sendMessage(new SubscribeRequestV1(1, $streamName, OffsetSpec::first(), 10));
        $subscribeResponse = $connection->readMessage();
        $this->assertInstanceOf(SubscribeResponseV1::class, $subscribeResponse);

        // First unsubscribe should succeed
        $connection->sendMessage(new UnsubscribeRequestV1(1));
        $response = $connection->readMessage();
        $this->assertInstanceOf(UnsubscribeResponseV1::class, $response);

        // Second unsubscribe with same ID should fail
        $this->expectException(\Exception::class);
        $connection->sendMessage(new UnsubscribeRequestV1(1));
        $connection->readMessage();

        $connection->close();
    }
}
