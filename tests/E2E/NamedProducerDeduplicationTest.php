<?php

declare(strict_types=1);

namespace CrazyGoat\RabbitStream\Tests\E2E;

use CrazyGoat\RabbitStream\Client\ConfirmationStatus;
use CrazyGoat\RabbitStream\Client\Connection;
use CrazyGoat\RabbitStream\Client\Producer;
use CrazyGoat\RabbitStream\VO\OffsetSpec;
use PHPUnit\Framework\TestCase;

class NamedProducerDeduplicationTest extends TestCase
{
    private static string $host = '127.0.0.1';
    private static int $port = 5552;

    private ?Connection $connection = null;
    private ?Producer $producer = null;
    private string $streamName;
    private string $producerRef;

    private function amqp(string $body): string
    {
        return "\x00\x53\x75\xb0" . pack('N', strlen($body)) . $body;
    }

    public static function setUpBeforeClass(): void
    {
        self::$host = getenv('RABBITMQ_HOST') ?: self::$host;
        self::$port = (int)(getenv('RABBITMQ_PORT') ?: self::$port);
    }

    protected function setUp(): void
    {
        $this->streamName = 'test-dedup-stream-' . uniqid();
        $this->producerRef = 'test-dedup-producer-' . uniqid();
        $this->connection = Connection::create(
            host: self::$host,
            port: self::$port,
            user: 'guest',
            password: 'guest',
            vhost: '/'
        );
        $this->connection->createStream($this->streamName);
    }

    protected function tearDown(): void
    {
        if ($this->producer instanceof Producer) {
            try {
                $this->producer->close();
            } catch (\Exception) {
                // Ignore cleanup errors
            }
            $this->producer = null;
        }

        if ($this->connection instanceof Connection) {
            try {
                $this->connection->deleteStream($this->streamName);
            } catch (\Exception) {
                // Ignore cleanup errors
            }
            $this->connection->close();
        }
    }

    public function testNamedProducerDeduplicationAcrossReconnect(): void
    {
        $this->assertNotNull($this->connection);

        // Step 1: Create a named producer with reference and publish messages
        $confirmed = [];
        $this->producer = $this->connection->createProducer(
            stream: $this->streamName,
            name: $this->producerRef,
            onConfirm: function (ConfirmationStatus $status) use (&$confirmed): void {
                $confirmed[] = $status;
            }
        );

        // Publish first batch of messages (IDs 1-10)
        // Producer automatically starts from sequence+1 (1) when created with name
        $messages = [];
        for ($i = 1; $i <= 10; $i++) {
            $messages[] = $this->amqp("message-{$i}");
        }
        $this->producer->sendBatch($messages);
        $this->producer->waitForConfirms(timeout: 5);

        $this->assertCount(10, $confirmed, 'Should have 10 confirmed messages');
        foreach ($confirmed as $status) {
            $this->assertTrue($status->isConfirmed(), 'All messages should be confirmed');
        }

        // Step 2: Query sequence - should return 10 (last published ID)
        $sequence = $this->producer->querySequence();
        $this->assertSame(10, $sequence, 'Sequence should be 10 after publishing IDs 1-10');

        // Step 3: Close the producer and connection completely
        $this->producer->close();
        $this->producer = null;
        $this->connection->close();
        $this->connection = null;

        // Step 4: Create a NEW connection with the same producer reference
        $this->connection = Connection::create(
            host: self::$host,
            port: self::$port,
            user: 'guest',
            password: 'guest',
            vhost: '/'
        );

        // Step 5: Create new producer with same reference
        $confirmed = [];
        $this->producer = $this->connection->createProducer(
            stream: $this->streamName,
            name: $this->producerRef,
            onConfirm: function (ConfirmationStatus $status) use (&$confirmed): void {
                $confirmed[] = $status;
            }
        );

        // Step 6: Query sequence again - should still return 10
        $sequenceAfterReconnect = $this->producer->querySequence();
        $this->assertSame(
            10,
            $sequenceAfterReconnect,
            'Sequence should still be 10 after reconnect with same producer reference'
        );

        // Step 7: Publish messages with IDs 11-15 (5 new messages)
        // Producer automatically resumed from sequence+1 (11) when created with name
        $messages = [];
        for ($i = 11; $i <= 15; $i++) {
            $messages[] = $this->amqp("message-{$i}");
        }
        $this->producer->sendBatch($messages);
        $this->producer->waitForConfirms(timeout: 5);

        // All 5 messages should get confirms
        $this->assertCount(5, $confirmed, 'Should have 5 confirms for second batch (IDs 11-15)');

        // Step 8: Create consumer and read all messages
        $consumer = $this->connection->createConsumer($this->streamName, OffsetSpec::first());

        $receivedMessages = [];
        $deadline = time() + 10;

        // Read ALL messages without deduplication - let server handle it
        while (time() < $deadline) {
            $messages = $consumer->read(timeout: 0.5);
            if ($messages === []) {
                break;
            }
            foreach ($messages as $msg) {
                $receivedMessages[] = $msg;
            }
        }

        $consumer->close();

        // Step 9: Verify exactly 15 messages exist (IDs 1-15), no duplicates
        // Server-side deduplication should ensure only 15 unique messages in stream
        $this->assertCount(15, $receivedMessages, 'Should have exactly 15 messages (server deduplicated)');

        // Extract message IDs and verify they are 1-15
        $receivedIds = [];
        foreach ($receivedMessages as $msg) {
            $body = $msg->getBody();
            if (is_string($body) && preg_match('/message-(\d+)/', $body, $matches)) {
                $receivedIds[] = (int)$matches[1];
            }
        }

        sort($receivedIds);
        $expectedIds = range(1, 15);
        $this->assertSame(
            $expectedIds,
            $receivedIds,
            'Message IDs should be exactly 1-15 with no duplicates'
        );
    }
}
