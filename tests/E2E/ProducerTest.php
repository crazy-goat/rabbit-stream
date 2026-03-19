<?php

namespace CrazyGoat\RabbitStream\Tests\E2E;

use CrazyGoat\RabbitStream\Client\Connection;
use PHPUnit\Framework\TestCase;

class ProducerTest extends TestCase
{
    private ?Connection $connection = null;
    private string $streamName;

    protected function setUp(): void
    {
        $host = $_ENV['RABBITMQ_HOST'] ?? '127.0.0.1';
        $port = (int) ($_ENV['RABBITMQ_PORT'] ?? 5552);
        
        $this->connection = Connection::create($host, $port);
        $this->streamName = 'test-producer-' . uniqid();
        $this->connection->createStream($this->streamName);
    }

    protected function tearDown(): void
    {
        if ($this->connection !== null) {
            try {
                $this->connection->deleteStream($this->streamName);
            } catch (\Exception $e) {
                // Ignore cleanup errors
            }
            $this->connection->close();
        }
    }

    public function testSendAndWaitForConfirms(): void
    {
        $confirmed = [];
        $producer = $this->connection->createProducer(
            $this->streamName,
            onConfirm: function ($status) use (&$confirmed) {
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
        $confirmed = [];
        $producer = $this->connection->createProducer(
            $this->streamName,
            onConfirm: function ($status) use (&$confirmed) {
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
        $producer = $this->connection->createProducer($this->streamName);
        
        $this->assertEquals(-1, $producer->getLastPublishingId());
        
        $producer->send('msg1');
        $this->assertEquals(0, $producer->getLastPublishingId());
        
        $producer->sendBatch(['msg2', 'msg3']);
        $this->assertEquals(2, $producer->getLastPublishingId());
        
        $producer->close();
    }

    public function testQuerySequenceForNamedProducer(): void
    {
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
}
