<?php

declare(strict_types=1);

namespace CrazyGoat\RabbitStream\Tests\E2E;

use CrazyGoat\RabbitStream\Client\Connection;
use CrazyGoat\RabbitStream\VO\OffsetSpec;
use PHPUnit\Framework\TestCase;

class MultipleConcurrentSubscriptionsTest extends TestCase
{
    private static string $host = '127.0.0.1';
    private static int $port = 5552;

    private ?Connection $connection = null;
    private string $streamName1;
    private string $streamName2;

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
        $this->connection = Connection::create(
            host: self::$host,
            port: self::$port,
            user: 'guest',
            password: 'guest',
            vhost: '/'
        );
        $this->streamName1 = 'test-multi-sub-1-' . uniqid();
        $this->streamName2 = 'test-multi-sub-2-' . uniqid();
        $this->connection->createStream($this->streamName1);
        $this->connection->createStream($this->streamName2);
    }

    protected function tearDown(): void
    {
        if ($this->connection instanceof Connection) {
            try {
                $this->connection->deleteStream($this->streamName1);
            } catch (\Exception) {
            }
            try {
                $this->connection->deleteStream($this->streamName2);
            } catch (\Exception) {
            }
            $this->connection->close();
        }
    }

    public function testMultipleConcurrentSubscriptionsReceiveCorrectMessages(): void
    {
        $this->assertNotNull($this->connection);

        // 1. Publish different messages to each stream
        $producer1 = $this->connection->createProducer($this->streamName1);
        $producer1->sendBatch([
            $this->amqp('stream1-msg-1'),
            $this->amqp('stream1-msg-2'),
            $this->amqp('stream1-msg-3'),
        ]);
        $producer1->waitForConfirms(timeout: 5);
        $producer1->close();

        $producer2 = $this->connection->createProducer($this->streamName2);
        $producer2->sendBatch([
            $this->amqp('stream2-msg-1'),
            $this->amqp('stream2-msg-2'),
            $this->amqp('stream2-msg-3'),
        ]);
        $producer2->waitForConfirms(timeout: 5);
        $producer2->close();

        // 2. Subscribe to both streams with different subscription IDs
        $consumer1 = $this->connection->createConsumer(
            $this->streamName1,
            OffsetSpec::first(),
        );

        $consumer2 = $this->connection->createConsumer(
            $this->streamName2,
            OffsetSpec::first(),
        );

        // 3. Verify each subscription receives only its own messages
        $received1 = [];
        $received2 = [];
        $deadline = time() + 10;

        while ((count($received1) < 3 || count($received2) < 3) && time() < $deadline) {
            if (count($received1) < 3) {
                $msgs = $consumer1->read(timeout: 0.5);
                foreach ($msgs as $msg) {
                    $received1[] = $msg->getBody();
                }
            }
            if (count($received2) < 3) {
                $msgs = $consumer2->read(timeout: 0.5);
                foreach ($msgs as $msg) {
                    $received2[] = $msg->getBody();
                }
            }
        }

        // Assert consumer1 only received stream1 messages
        $this->assertCount(3, $received1, 'Consumer 1 should receive exactly 3 messages');
        $this->assertContains('stream1-msg-1', $received1);
        $this->assertContains('stream1-msg-2', $received1);
        $this->assertContains('stream1-msg-3', $received1);
        $this->assertNotContains('stream2-msg-1', $received1);
        $this->assertNotContains('stream2-msg-2', $received1);
        $this->assertNotContains('stream2-msg-3', $received1);

        // Assert consumer2 only received stream2 messages
        $this->assertCount(3, $received2, 'Consumer 2 should receive exactly 3 messages');
        $this->assertContains('stream2-msg-1', $received2);
        $this->assertContains('stream2-msg-2', $received2);
        $this->assertContains('stream2-msg-3', $received2);
        $this->assertNotContains('stream1-msg-1', $received2);
        $this->assertNotContains('stream1-msg-2', $received2);
        $this->assertNotContains('stream1-msg-3', $received2);

        // 4. Unsubscribe from one, verify the other still works
        $consumer1->close();

        // Publish more messages to stream2
        $producer2 = $this->connection->createProducer($this->streamName2);
        $producer2->send($this->amqp('stream2-msg-4'));
        $producer2->waitForConfirms(timeout: 5);
        $producer2->close();

        // Consumer2 should still receive the new message
        $newMessages = [];
        $deadline = time() + 5;
        while (count($newMessages) < 1 && time() < $deadline) {
            $msgs = $consumer2->read(timeout: 0.5);
            foreach ($msgs as $msg) {
                $newMessages[] = $msg->getBody();
            }
        }

        $this->assertCount(1, $newMessages, 'Consumer 2 should still receive messages after consumer 1 unsubscribed');
        $this->assertContains('stream2-msg-4', $newMessages);

        $consumer2->close();
    }
}
