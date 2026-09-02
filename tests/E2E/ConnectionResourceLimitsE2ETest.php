<?php

declare(strict_types=1);

namespace CrazyGoat\RabbitStream\Tests\E2E;

use CrazyGoat\RabbitStream\Client\Connection;
use CrazyGoat\RabbitStream\Client\Consumer;
use CrazyGoat\RabbitStream\Client\Message;
use CrazyGoat\RabbitStream\Client\Producer;
use CrazyGoat\RabbitStream\VO\OffsetSpec;

/**
 * Publisher and subscription ids are uint8 on the wire, so a connection has
 * 256 of each. They used to be handed out by an ever-growing counter and never
 * reclaimed, which killed any long-lived worker that creates and closes one
 * producer (or consumer) per work unit on the 257th one (#388).
 *
 * @group slow
 */
class ConnectionResourceLimitsE2ETest extends E2ETestCase
{
    private const CYCLES = 300;

    private ?Connection $connection = null;
    private string $streamName = '';

    protected function setUp(): void
    {
        $this->connection = $this->createConnection();
        $this->streamName = 'test-ids-' . uniqid();
        $this->connection->createStream($this->streamName);
    }

    protected function tearDown(): void
    {
        $connection = $this->connection;
        if ($connection instanceof Connection) {
            try {
                $connection->deleteStream($this->streamName);
            } catch (\Exception) {
                // Already gone.
            }
            $connection->close();
        }
        $this->connection = null;
    }

    public function testMoreProducersThanPublisherIdsCanBeCreatedOneAfterAnother(): void
    {
        $connection = $this->connection;
        $this->assertInstanceOf(Connection::class, $connection);

        for ($i = 0; $i < self::CYCLES; $i++) {
            $producer = $connection->createProducer($this->streamName);
            $this->assertInstanceOf(Producer::class, $producer);
            $producer->send("message-{$i}");
            $producer->waitForConfirms(timeout: 5.0);
            $producer->close();
        }

        // Every message really made it: the ids were reused, not silently
        // colliding with a publisher the broker still had open.
        $consumer = $connection->createConsumer($this->streamName, OffsetSpec::first());
        $received = [];
        $deadline = microtime(true) + 15.0;
        while (count($received) < self::CYCLES && microtime(true) < $deadline) {
            foreach ($consumer->read(timeout: 1.0) as $message) {
                $received[] = $message;
            }
        }
        $consumer->close();

        $this->assertCount(self::CYCLES, $received);
    }

    public function testMoreConsumersThanSubscriptionIdsCanBeCreatedOneAfterAnother(): void
    {
        $connection = $this->connection;
        $this->assertInstanceOf(Connection::class, $connection);

        $producer = $connection->createProducer($this->streamName);
        $producer->send('only-message');
        $producer->waitForConfirms(timeout: 5.0);
        $producer->close();

        for ($i = 0; $i < self::CYCLES; $i++) {
            $consumer = $connection->createConsumer($this->streamName, OffsetSpec::first());
            $this->assertInstanceOf(Consumer::class, $consumer);
            $messages = $consumer->read(timeout: 5.0);
            $consumer->close();

            $this->assertCount(1, $messages, "Subscription {$i} must deliver the message");
            $this->assertInstanceOf(Message::class, $messages[0]);
        }
    }

    public function testTooManySimultaneousProducersFailsWithAClearError(): void
    {
        $connection = $this->connection;
        $this->assertInstanceOf(Connection::class, $connection);

        $producers = [];
        for ($i = 0; $i < Connection::MAX_CONCURRENT_PUBLISHERS; $i++) {
            $producers[] = $connection->createProducer($this->streamName);
        }

        try {
            $connection->createProducer($this->streamName);
            $this->fail('The 257th simultaneous publisher must be refused');
        } catch (\CrazyGoat\RabbitStream\Exception\ConnectionException $e) {
            $this->assertStringContainsString('all 256 ids of this connection are in use', $e->getMessage());
        } finally {
            foreach ($producers as $producer) {
                $producer->close();
            }
        }

        // Closing them frees the ids again.
        $producer = $connection->createProducer($this->streamName);
        $this->assertInstanceOf(Producer::class, $producer);
        $producer->close();
    }
}
