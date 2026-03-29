<?php

declare(strict_types=1);

namespace CrazyGoat\RabbitStream\Tests\E2E;

use CrazyGoat\RabbitStream\Client\ConfirmationStatus;
use CrazyGoat\RabbitStream\Client\Connection;
use CrazyGoat\RabbitStream\Exception\TimeoutException;
use PHPUnit\Framework\TestCase;

class ProducerTest extends TestCase
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
        $this->connection = Connection::create(self::$host, self::$port);
        $this->streamName = 'test-producer-' . uniqid();
        $this->connection->createStream($this->streamName);
    }

    protected function tearDown(): void
    {
        if ($this->connection instanceof Connection) {
            try {
                $this->connection->deleteStream($this->streamName);
            } catch (\Exception) {
                // Ignore cleanup errors
            }
            $this->connection->close();
        }
    }

    public function testSendAndWaitForConfirms(): void
    {
        $this->assertNotNull($this->connection);
        $confirmed = [];
        $producer = $this->connection->createProducer(
            $this->streamName,
            onConfirm: function (ConfirmationStatus $status) use (&$confirmed): void {
                $confirmed[] = $status;
            }
        );

        $producer->send('test message');
        $producer->waitForConfirms(timeout: 5);

        $this->assertCount(1, $confirmed);
        $this->assertTrue($confirmed[0]->isConfirmed());

        $producer->close();
    }

    public function testSendBatchAndWaitForConfirms(): void
    {
        $this->assertNotNull($this->connection);
        $confirmed = [];
        $producer = $this->connection->createProducer(
            $this->streamName,
            onConfirm: function (ConfirmationStatus $status) use (&$confirmed): void {
                $confirmed[] = $status;
            }
        );

        $producer->sendBatch(['msg1', 'msg2', 'msg3']);
        $producer->waitForConfirms(timeout: 5);

        $this->assertCount(3, $confirmed);
        foreach ($confirmed as $status) {
            $this->assertTrue($status->isConfirmed());
        }

        $producer->close();
    }

    public function testGetLastPublishingId(): void
    {
        $this->assertNotNull($this->connection);
        $producer = $this->connection->createProducer($this->streamName);

        $this->assertNull($producer->getLastPublishingId());

        $producer->send('msg1');
        $this->assertEquals(0, $producer->getLastPublishingId());

        $producer->sendBatch(['msg2', 'msg3']);
        $this->assertEquals(2, $producer->getLastPublishingId());

        $producer->close();
    }

    public function testQuerySequenceForNamedProducer(): void
    {
        $this->assertNotNull($this->connection);
        $producer = $this->connection->createProducer(
            $this->streamName,
            name: 'test-producer-ref'
        );

        // Send some messages
        $producer->sendBatch(['msg1', 'msg2', 'msg3']);
        $producer->waitForConfirms(timeout: 5);

        // Query sequence
        $sequence = $producer->querySequence();
        $this->assertGreaterThanOrEqual(2, $sequence); // Should be at least 2 (0-indexed)

        $producer->close();
    }

    public function testWaitForConfirmsTimeoutThrows(): void
    {
        $this->assertNotNull($this->connection);
        $producer = $this->connection->createProducer($this->streamName);

        // Publish a message - this increments pendingConfirms
        $producer->send('test message');

        // Wait with zero timeout to force immediate timeout
        // This should timeout before RabbitMQ can send the confirm
        $this->expectException(TimeoutException::class);
        $this->expectExceptionMessage('Timed out waiting for');
        $producer->waitForConfirms(timeout: 0);
    }

    public function testProducerRemainsUsableAfterTimeout(): void
    {
        $this->assertNotNull($this->connection);
        $producer = $this->connection->createProducer($this->streamName);

        // First send that will timeout
        $producer->send('msg1');
        try {
            $producer->waitForConfirms(timeout: 0.001);
        } catch (TimeoutException) {
            // Expected
        }

        // Second send with longer timeout should succeed
        $producer->send('msg2');
        $producer->waitForConfirms(timeout: 5.0); // Should not throw

        $producer->close();
    }
}
