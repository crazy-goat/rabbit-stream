<?php

namespace CrazyGoat\RabbitStream\Tests\E2E;

use CrazyGoat\RabbitStream\Client\Connection;
use CrazyGoat\RabbitStream\VO\OffsetSpec;
use PHPUnit\Framework\TestCase;

class ConsumerTest extends TestCase
{
    private static string $host = '127.0.0.1';
    private static int $port = 5552;

    private ?Connection $connection = null;
    private string $streamName;

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
        $this->streamName = 'test-consumer-' . uniqid();
        $this->connection->createStream($this->streamName);
    }

    protected function tearDown(): void
    {
        if ($this->connection !== null) {
            try {
                $this->connection->deleteStream($this->streamName);
            } catch (\Exception $e) {
            }
            $this->connection->close();
        }
    }

    public function testReadReturnsEmptyOnTimeout(): void
    {
        $consumer = $this->connection->createConsumer($this->streamName, OffsetSpec::first());

        $messages = $consumer->read(timeout: 1);

        $this->assertSame([], $messages);

        $consumer->close();
    }

    public function testProduceAndConsumeWithRead(): void
    {
        $producer = $this->connection->createProducer($this->streamName);
        $producer->sendBatch(['hello', 'world', 'foo']);
        $producer->waitForConfirms(timeout: 5);
        $producer->close();

        $consumer = $this->connection->createConsumer($this->streamName, OffsetSpec::first());

        $received = [];
        $deadline = time() + 10;
        while (count($received) < 3 && time() < $deadline) {
            $messages = $consumer->read(timeout: 2);
            foreach ($messages as $msg) {
                $received[] = $msg->getBody();
            }
        }

        $consumer->close();

        $this->assertCount(3, $received);
        $this->assertContains('hello', $received);
        $this->assertContains('world', $received);
        $this->assertContains('foo', $received);
    }

    public function testReadOneReturnsSingleMessage(): void
    {
        $producer = $this->connection->createProducer($this->streamName);
        $producer->sendBatch(['msg1', 'msg2']);
        $producer->waitForConfirms(timeout: 5);
        $producer->close();

        $consumer = $this->connection->createConsumer($this->streamName, OffsetSpec::first());

        $msg = null;
        $deadline = time() + 10;
        while ($msg === null && time() < $deadline) {
            $msg = $consumer->readOne(timeout: 2);
        }

        $consumer->close();

        $this->assertNotNull($msg);
        $this->assertSame('msg1', $msg->getBody());
    }

    public function testStoreAndQueryOffset(): void
    {
        $producer = $this->connection->createProducer($this->streamName);
        $producer->send('offset-test');
        $producer->waitForConfirms(timeout: 5);
        $producer->close();

        $consumerName = 'test-consumer-ref-' . uniqid();
        $consumer = $this->connection->createConsumer(
            $this->streamName,
            OffsetSpec::first(),
            name: $consumerName,
        );

        $messages = [];
        $deadline = time() + 10;
        while (empty($messages) && time() < $deadline) {
            $messages = $consumer->read(timeout: 2);
        }

        $this->assertNotEmpty($messages);
        $offset = $messages[0]->getOffset();

        $consumer->storeOffset($offset);

        $storedOffset = $consumer->queryOffset();
        $this->assertSame($offset, $storedOffset);

        $consumer->close();
    }
}
