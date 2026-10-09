<?php

declare(strict_types=1);

namespace CrazyGoat\RabbitStream\Tests\E2E;

use CrazyGoat\RabbitStream\Client\Connection;
use CrazyGoat\RabbitStream\Client\Message;
use CrazyGoat\RabbitStream\VO\OffsetSpec;

class ProducerConsumerOffsetResumeTest extends E2ETestCase
{
    private ?Connection $connection = null;
    private string $streamName;

    protected function setUp(): void
    {
        $this->connection = $this->createConnection();
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
            $messages[] = "message-{$i}";
        }
        $producer->sendBatch($messages);
        $producer->waitForConfirms(timeout: 5);
        $producer->close();

        $consumerName = 'test-consumer-ref-' . uniqid();
        $consumer1 = $this->connection->createConsumer(
            $this->streamName,
            OffsetSpec::first(),
            name: $consumerName,
            autoCommit: 5,
        );

        $received1 = [];
        $deadline = time() + 10;
        while (count($received1) < 5 && time() < $deadline) {
            $message = $consumer1->readOne(timeout: 0.5);
            if ($message instanceof Message) {
                $received1[] = $message;
            }
        }

        $this->assertCount(5, $received1, 'First consumer should receive exactly 5 messages');
        $consumer1->close();

        $targetOffset = $received1[4]->getOffset() + 1;
        $queriedOffset = $this->connection->queryOffset($consumerName, $this->streamName);
        $this->assertSame($targetOffset, $queriedOffset, 'Auto-commit stores the next offset to consume');

        $consumer2 = $this->connection->createConsumer(
            $this->streamName,
            OffsetSpec::offset($queriedOffset),
            name: $consumerName,
        );

        $received2 = [];
        $deadline = time() + 10;

        while (count($received2) < 5 && time() < $deadline) {
            $message = $consumer2->readOne(timeout: 0.5);
            if ($message instanceof Message) {
                $received2[] = $message;
            }
        }

        $this->assertCount(5, $received2, 'Second consumer should receive exactly 5 messages');
        $this->assertSame(
            $targetOffset,
            $received2[0]->getOffset(),
            'Restarted consumer must not redeliver any message below the committed next offset'
        );

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

        $consumer2->close();
    }
}
