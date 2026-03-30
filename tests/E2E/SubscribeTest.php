<?php

declare(strict_types=1);

namespace CrazyGoat\RabbitStream\Tests\E2E;

use CrazyGoat\RabbitStream\Client\Connection;
use CrazyGoat\RabbitStream\Request\CreateRequestV1;
use CrazyGoat\RabbitStream\Request\OpenRequestV1;
use CrazyGoat\RabbitStream\Request\PeerPropertiesRequestV1;
use CrazyGoat\RabbitStream\Request\SaslAuthenticateRequestV1;
use CrazyGoat\RabbitStream\Request\SaslHandshakeRequestV1;
use CrazyGoat\RabbitStream\Request\SubscribeRequestV1;
use CrazyGoat\RabbitStream\Request\TuneRequestV1;
use CrazyGoat\RabbitStream\Response\CreateResponseV1;
use CrazyGoat\RabbitStream\Response\SubscribeResponseV1;
use CrazyGoat\RabbitStream\Response\TuneResponseV1;
use CrazyGoat\RabbitStream\StreamConnection;
use CrazyGoat\RabbitStream\VO\OffsetSpec;
use PHPUnit\Framework\TestCase;

class SubscribeTest extends TestCase
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

    private function amqp(string $body): string
    {
        return "\x00\x53\x75\xb0" . pack('N', strlen($body)) . $body;
    }

    public function testSubscribeToStream(): void
    {
        $connection = $this->connectAndOpen();

        // Create a test stream
        $streamName = 'test-subscribe-stream-' . uniqid();
        $connection->sendMessage(new CreateRequestV1($streamName));
        $createResponse = $connection->readMessage();
        $this->assertInstanceOf(CreateResponseV1::class, $createResponse);

        // Subscribe to the stream
        $connection->sendMessage(new SubscribeRequestV1(1, $streamName, OffsetSpec::first(), 10));
        $response = $connection->readMessage();

        $this->assertInstanceOf(SubscribeResponseV1::class, $response);

        $connection->close();
    }

    public function testSubscribeWithOffsetLast(): void
    {
        $connection = $this->connectAndOpen();

        $streamName = 'test-subscribe-last-' . uniqid();
        $connection->sendMessage(new CreateRequestV1($streamName));
        $connection->readMessage();

        $connection->sendMessage(new SubscribeRequestV1(1, $streamName, OffsetSpec::last(), 5));
        $response = $connection->readMessage();

        $this->assertInstanceOf(SubscribeResponseV1::class, $response);

        $connection->close();
    }

    public function testSubscribeWithOffsetNext(): void
    {
        $connection = $this->connectAndOpen();

        $streamName = 'test-subscribe-next-' . uniqid();
        $connection->sendMessage(new CreateRequestV1($streamName));
        $connection->readMessage();

        $connection->sendMessage(new SubscribeRequestV1(1, $streamName, OffsetSpec::next(), 20));
        $response = $connection->readMessage();

        $this->assertInstanceOf(SubscribeResponseV1::class, $response);

        $connection->close();
    }

    public function testSubscribeToNonExistentStreamThrows(): void
    {
        $connection = $this->connectAndOpen();

        $this->expectException(\Exception::class);
        $connection->sendMessage(new SubscribeRequestV1(1, 'non-existent-stream-' . uniqid(), OffsetSpec::first(), 10));
        $connection->readMessage();

        $connection->close();
    }

    public function testDuplicateSubscriptionIdThrows(): void
    {
        $connection = $this->connectAndOpen();

        $streamName = 'test-subscribe-duplicate-' . uniqid();
        $connection->sendMessage(new CreateRequestV1($streamName));
        $connection->readMessage();

        // First subscription should succeed
        $connection->sendMessage(new SubscribeRequestV1(1, $streamName, OffsetSpec::first(), 10));
        $response = $connection->readMessage();
        $this->assertInstanceOf(SubscribeResponseV1::class, $response);

        // Second subscription with same ID should fail
        $this->expectException(\Exception::class);
        $connection->sendMessage(new SubscribeRequestV1(1, $streamName, OffsetSpec::first(), 10));
        $connection->readMessage();

        $connection->close();
    }

    public function testSubscribeFromTimestamp(): void
    {
        // Use higher-level Connection API for proper async handling
        $connection = Connection::create(
            host: self::$host,
            port: self::$port,
            user: 'guest',
            password: 'guest',
            vhost: '/'
        );

        $streamName = 'test-subscribe-timestamp-' . uniqid();
        $connection->createStream($streamName);

        // Publish first batch of messages
        $producer = $connection->createProducer($streamName);
        $producer->sendBatch([
            $this->amqp('message-before-1'),
            $this->amqp('message-before-2'),
        ]);
        $producer->waitForConfirms(timeout: 5);

        // Record timestamp after first batch
        usleep(100000); // 100ms delay to ensure clean separation
        $timestamp = (int)(microtime(true) * 1000);
        usleep(100000); // Another 100ms delay

        // Publish second batch of messages
        $producer->sendBatch([
            $this->amqp('message-after-1'),
            $this->amqp('message-after-2'),
            $this->amqp('message-after-3'),
        ]);
        $producer->waitForConfirms(timeout: 5);
        $producer->close();

        // Subscribe from the recorded timestamp
        $consumer = $connection->createConsumer(
            $streamName,
            OffsetSpec::timestamp($timestamp)
        );

        // Read messages - should only get the 3 messages published after timestamp
        $received = [];
        $deadline = time() + 5;
        while (count($received) < 3 && time() < $deadline) {
            $messages = $consumer->read(timeout: 0.5);
            foreach ($messages as $msg) {
                $received[] = $msg->getBody();
            }
        }

        $consumer->close();

        // Cleanup
        try {
            $connection->deleteStream($streamName);
        } catch (\Exception) {
            // Ignore cleanup errors
        }
        $connection->close();

        // Verify we got exactly the 3 messages published after the timestamp
        $this->assertCount(3, $received, 'Should receive only messages published after timestamp');
        $this->assertContains('message-after-1', $received);
        $this->assertContains('message-after-2', $received);
        $this->assertContains('message-after-3', $received);
        $this->assertNotContains('message-before-1', $received);
        $this->assertNotContains('message-before-2', $received);
    }

    public function testSubscribeFromFutureTimestamp(): void
    {
        // Use higher-level Connection API for proper async handling
        $connection = Connection::create(
            host: self::$host,
            port: self::$port,
            user: 'guest',
            password: 'guest',
            vhost: '/'
        );

        $streamName = 'test-subscribe-future-' . uniqid();
        $connection->createStream($streamName);

        // Publish messages
        $producer = $connection->createProducer($streamName);
        $producer->sendBatch([
            $this->amqp('message-1'),
            $this->amqp('message-2'),
        ]);
        $producer->waitForConfirms(timeout: 5);
        $producer->close();

        // Subscribe with a timestamp far in the future (1 hour from now)
        $futureTimestamp = (int)(microtime(true) * 1000) + (3600 * 1000);
        $consumer = $connection->createConsumer(
            $streamName,
            OffsetSpec::timestamp($futureTimestamp)
        );

        // Try to read messages - should timeout/get empty array
        $messages = $consumer->read(timeout: 1);

        $consumer->close();

        // Cleanup
        try {
            $connection->deleteStream($streamName);
        } catch (\Exception) {
            // Ignore cleanup errors
        }
        $connection->close();

        // Verify no messages received (future timestamp means no messages yet)
        $this->assertSame([], $messages, 'Should receive no messages when subscribing from future timestamp');
    }
}
