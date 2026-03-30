<?php

declare(strict_types=1);

namespace CrazyGoat\RabbitStream\Tests\E2E;

use CrazyGoat\RabbitStream\Client\Connection;
use CrazyGoat\RabbitStream\Client\Message;
use CrazyGoat\RabbitStream\Contract\ConsumerInterface;
use CrazyGoat\RabbitStream\VO\OffsetSpec;
use PHPUnit\Framework\TestCase;

class MultipleConsumersTest extends TestCase
{
    private static string $host = '127.0.0.1';
    private static int $port = 5552;

    private ?Connection $connection1 = null;
    private ?Connection $connection2 = null;
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
        $this->connection1 = Connection::create(
            host: self::$host,
            port: self::$port,
            user: 'guest',
            password: 'guest',
            vhost: '/'
        );
        $this->connection2 = Connection::create(
            host: self::$host,
            port: self::$port,
            user: 'guest',
            password: 'guest',
            vhost: '/'
        );
        $this->streamName = 'test-multiple-consumers-' . uniqid();
        $this->connection1->createStream($this->streamName);
    }

    protected function tearDown(): void
    {
        if ($this->connection1 instanceof Connection) {
            try {
                $this->connection1->deleteStream($this->streamName);
            } catch (\Exception) {
            }
            $this->connection1->close();
        }
        if ($this->connection2 instanceof Connection) {
            $this->connection2->close();
        }
    }

    /**
     * @return array<Message>
     */
    private function readAllMessages(ConsumerInterface $consumer, int $expectedCount, float $timeout = 10.0): array
    {
        $received = [];
        $deadline = microtime(true) + $timeout;

        while (count($received) < $expectedCount && microtime(true) < $deadline) {
            $messages = $consumer->read(timeout: 0.5);
            foreach ($messages as $msg) {
                $received[] = $msg;
                if (count($received) >= $expectedCount) {
                    break;
                }
            }
        }

        return $received;
    }

    public function testMultipleConsumersOnSameStream(): void
    {
        $this->assertNotNull($this->connection1);
        $this->assertNotNull($this->connection2);

        // Publish 5 messages using producer on connection1
        $producer = $this->connection1->createProducer($this->streamName);
        $messages = [];
        for ($i = 0; $i < 5; $i++) {
            $messages[] = $this->amqp("message-{$i}");
        }
        $producer->sendBatch($messages);
        $producer->waitForConfirms(timeout: 5);
        $producer->close();

        // Create consumer1 on connection1 with name 'consumer-1'
        $consumer1 = $this->connection1->createConsumer(
            $this->streamName,
            OffsetSpec::first(),
            name: 'consumer-1',
        );

        // Create consumer2 on connection2 with name 'consumer-2'
        $consumer2 = $this->connection2->createConsumer(
            $this->streamName,
            OffsetSpec::first(),
            name: 'consumer-2',
        );

        // Both consumers read all 5 messages independently
        $received1 = $this->readAllMessages($consumer1, 5);
        $received2 = $this->readAllMessages($consumer2, 5);

        // Assert both consumers received exactly 5 messages
        $this->assertCount(5, $received1, 'Consumer 1 should receive all 5 messages');
        $this->assertCount(5, $received2, 'Consumer 2 should receive all 5 messages');

        // Verify message bodies match expected values
        for ($i = 0; $i < 5; $i++) {
            $this->assertSame("message-{$i}", $received1[$i]->getBody(), "Consumer 1 message {$i} should match");
            $this->assertSame("message-{$i}", $received2[$i]->getBody(), "Consumer 2 message {$i} should match");
        }

        // Store different offsets for each consumer
        $offset1 = $received1[2]->getOffset(); // Store offset of 3rd message
        $offset2 = $received2[4]->getOffset(); // Store offset of 5th (last) message

        $consumer1->storeOffset($offset1);
        $consumer2->storeOffset($offset2);

        // Query offsets and verify they are independent
        $storedOffset1 = $this->connection1->queryOffset('consumer-1', $this->streamName);
        $storedOffset2 = $this->connection2->queryOffset('consumer-2', $this->streamName);

        $this->assertSame($offset1, $storedOffset1, 'Consumer 1 offset should match stored value');
        $this->assertSame($offset2, $storedOffset2, 'Consumer 2 offset should match stored value');
        $this->assertNotSame($storedOffset1, $storedOffset2, 'Offsets should be independent between consumers');

        // Cleanup
        $consumer1->close();
        $consumer2->close();
    }
}
