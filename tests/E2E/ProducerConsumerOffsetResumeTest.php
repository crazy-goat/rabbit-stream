<?php

declare(strict_types=1);

namespace CrazyGoat\RabbitStream\Tests\E2E;

use CrazyGoat\RabbitStream\Client\Connection;
use CrazyGoat\RabbitStream\Client\Message;
use CrazyGoat\RabbitStream\VO\OffsetSpec;
use PHPUnit\Framework\TestCase;

class ProducerConsumerOffsetResumeTest extends TestCase
{
    private static string $host = '127.0.0.1';
    private static int $port = 5552;

    private ?Connection $connection = null;
    private string $streamName;

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
        $this->streamName = 'test-producer-consumer-offset-' . uniqid();
        $this->connection->createStream($this->streamName);
    }

    protected function tearDown(): void
    {
        if ($this->connection instanceof Connection) {
            try {
                $this->connection->deleteStream($this->streamName);
            } catch (\Exception) {
            }
            $this->connection->close();
        }
    }

    public function testFullLifecycleWithOffsetResume(): void
    {
        $this->assertNotNull($this->connection);

        $producer = $this->connection->createProducer($this->streamName);
        $messages = [];
        for ($i = 0; $i < 10; $i++) {
            $messages[] = $this->amqp("message-{$i}");
        }
        $producer->sendBatch($messages);
        $producer->waitForConfirms(timeout: 5);
        $producer->close();

        $consumerName = 'test-consumer-ref-' . uniqid();
        $consumer1 = $this->connection->createConsumer(
            $this->streamName,
            OffsetSpec::first(),
            name: $consumerName,
        );

        $received1 = [];
        $deadline = time() + 10;
        while (count($received1) < 5 && time() < $deadline) {
            $msgs = $consumer1->read(timeout: 0.5);
            foreach ($msgs as $msg) {
                $received1[] = $msg;
                if (count($received1) >= 5) {
                    break;
                }
            }
        }

        $this->assertCount(5, $received1, 'First consumer should receive exactly 5 messages');

        $fifthMessage = $received1[4];
        $this->assertInstanceOf(Message::class, $fifthMessage);
        $storedOffset = $fifthMessage->getOffset();
        $consumer1->storeOffset($storedOffset);
        $consumer1->close();

        $queriedOffset = $this->connection->queryOffset($consumerName, $this->streamName);
        $this->assertSame($storedOffset, $queriedOffset, 'Queried offset should match stored offset');

        $targetOffset = $storedOffset + 1;
        $consumer2 = $this->connection->createConsumer(
            $this->streamName,
            OffsetSpec::first(),
            name: $consumerName,
        );

        $received2 = [];
        $deadline = time() + 10;

        while (count($received2) < 5 && time() < $deadline) {
            $msgs = $consumer2->read(timeout: 0.5);
            foreach ($msgs as $msg) {
                if ($msg->getOffset() >= $targetOffset) {
                    $received2[] = $msg;
                    if (count($received2) >= 5) {
                        break;
                    }
                }
            }
        }

        $this->assertCount(5, $received2, 'Second consumer should receive exactly 5 messages');

        $allReceived = array_merge($received1, $received2);
        $this->assertCount(10, $allReceived, 'Total messages received should be 10');

        foreach ($allReceived as $index => $msg) {
            $this->assertInstanceOf(Message::class, $msg);
            $expectedBody = "message-{$index}";
            $this->assertSame($expectedBody, $msg->getBody(), "Message at index {$index} should have correct body");
        }

        for ($i = 0; $i < count($allReceived) - 1; $i++) {
            $currentOffset = $allReceived[$i]->getOffset();
            $nextOffset = $allReceived[$i + 1]->getOffset();
            $this->assertGreaterThan($currentOffset, $nextOffset, 'Offsets should be sequential');
        }

        $this->assertSame(
            $targetOffset,
            $received2[0]->getOffset(),
            'Second consumer should start from offset ' . $targetOffset
        );

        $consumer2->close();
    }
}
