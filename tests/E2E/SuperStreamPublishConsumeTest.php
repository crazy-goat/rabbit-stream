<?php

declare(strict_types=1);

namespace CrazyGoat\RabbitStream\Tests\E2E;

use CrazyGoat\RabbitStream\Client\Connection;
use CrazyGoat\RabbitStream\Client\Message;
use CrazyGoat\RabbitStream\VO\OffsetSpec;
use PHPUnit\Framework\TestCase;

class SuperStreamPublishConsumeTest extends TestCase
{
    private static string $host = '127.0.0.1';
    private static int $port = 5552;

    private ?Connection $connection = null;
    private string $superStreamName = '';

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
    }

    protected function tearDown(): void
    {
        if ($this->connection instanceof Connection) {
            try {
                if ($this->superStreamName !== '') {
                    $this->connection->deleteSuperStream($this->superStreamName);
                }
            } catch (\Exception) {
                // Ignore cleanup errors
            }
            $this->connection->close();
        }
    }

    public function testSuperStreamPublishAndConsume(): void
    {
        $this->assertNotNull($this->connection);

        $this->superStreamName = 'test-super-pub-' . uniqid();
        $partitions = [
            $this->superStreamName . '-0',
            $this->superStreamName . '-1',
            $this->superStreamName . '-2',
        ];

        // Create super stream with 3 partitions and binding keys
        $this->connection->createSuperStream(
            $this->superStreamName,
            $partitions,
            ['0', '1', '2']
        );

        // Query route for key '1' — should return partition-1
        $route = $this->connection->route('1', $this->superStreamName);
        $this->assertNotEmpty($route);
        $targetPartition = $route[0];
        $this->assertSame($this->superStreamName . '-1', $targetPartition);

        // Publish a message to the target partition
        $producer = $this->connection->createProducer($targetPartition);
        $producer->send($this->amqp('super-stream-message'));
        $producer->waitForConfirms(timeout: 5.0);
        $producer->close();

        // Consume from the target partition
        $consumer = $this->connection->createConsumer($targetPartition, OffsetSpec::first());

        $received = [];
        $deadline = time() + 5;
        while (count($received) < 1 && time() < $deadline) {
            $messages = $consumer->read(timeout: 0.5);
            foreach ($messages as $msg) {
                $received[] = $msg;
            }
        }

        $consumer->close();

        $this->assertCount(1, $received);
        $this->assertInstanceOf(Message::class, $received[0]);
        $this->assertSame('super-stream-message', $received[0]->getBody());
    }
}
