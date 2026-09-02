<?php

declare(strict_types=1);

namespace CrazyGoat\RabbitStream\Tests\E2E;

use CrazyGoat\RabbitStream\Client\Connection;
use CrazyGoat\RabbitStream\Client\Routing\HashRoutingStrategy;
use CrazyGoat\RabbitStream\Client\Routing\KeyRoutingStrategy;
use CrazyGoat\RabbitStream\Client\Routing\Murmur3;
use CrazyGoat\RabbitStream\Exception\NoRouteForKeyException;
use CrazyGoat\RabbitStream\VO\OffsetSpec;

class SuperStreamProducerE2ETest extends E2ETestCase
{
    private ?Connection $connection = null;
    private string $superStreamName = '';

    protected function setUp(): void
    {
        $this->connection = $this->createConnection();
    }

    protected function tearDown(): void
    {
        if ($this->connection instanceof Connection) {
            try {
                if ($this->superStreamName !== '') {
                    $this->connection->deleteSuperStream($this->superStreamName);
                }
            } catch (\Exception) {
                // Ignore cleanup errors — super stream may already be deleted
            }
            $this->connection->close();
        }
    }

    public function testHashRoutingPublishesToTheCorrectPartitions(): void
    {
        $this->assertNotNull($this->connection);

        $this->superStreamName = 'test-ssp-hash-' . uniqid();
        $partitions = [
            $this->superStreamName . '-0',
            $this->superStreamName . '-1',
            $this->superStreamName . '-2',
        ];
        $this->connection->createSuperStream($this->superStreamName, $partitions, ['0', '1', '2']);

        $producer = $this->connection->createSuperStreamProducer($this->superStreamName);

        for ($i = 0; $i < 60; $i++) {
            $producer->send("message-{$i}", "key-{$i}");
        }
        $producer->waitForConfirms(timeout: 5.0);
        $producer->close();

        $totalReceived = 0;
        foreach ($partitions as $partitionIndex => $partition) {
            $consumer = $this->connection->createConsumer($partition, OffsetSpec::first());
            $received = [];
            $deadline = time() + 5;
            while (time() < $deadline) {
                $messages = $consumer->read(timeout: 0.5);
                if ($messages === []) {
                    break;
                }
                foreach ($messages as $msg) {
                    $received[] = $msg->getBody();
                }
            }
            $consumer->close();

            foreach ($received as $body) {
                $this->assertIsString($body);
                $this->assertStringStartsWith('message-', $body);
                $key = 'key-' . substr($body, strlen('message-'));
                $expectedPartitionIndex = Murmur3::hash32($key, HashRoutingStrategy::SEED) % 3;
                $this->assertSame(
                    $expectedPartitionIndex,
                    $partitionIndex,
                    "Message routed with key '{$key}' landed on partition {$partitionIndex}, " .
                    "expected partition {$expectedPartitionIndex}"
                );
            }
            $totalReceived += count($received);
        }

        $this->assertSame(60, $totalReceived);
    }

    public function testKeyRoutingUsesBrokerBindings(): void
    {
        $this->assertNotNull($this->connection);

        $this->superStreamName = 'test-ssp-key-' . uniqid();
        $partitions = [
            $this->superStreamName . '-0',
            $this->superStreamName . '-1',
            $this->superStreamName . '-2',
        ];
        $this->connection->createSuperStream($this->superStreamName, $partitions, ['0', '1', '2']);

        $strategy = new KeyRoutingStrategy($this->connection, $this->superStreamName);
        $producer = $this->connection->createSuperStreamProducer($this->superStreamName, $strategy);

        $producer->send('for-0', '0');
        $producer->send('for-1', '1');
        $producer->send('for-2', '2');
        $producer->waitForConfirms(timeout: 5.0);
        $producer->close();

        foreach (['0', '1', '2'] as $index => $bindingKey) {
            $partition = $this->superStreamName . '-' . $index;
            $consumer = $this->connection->createConsumer($partition, OffsetSpec::first());
            $received = [];
            $deadline = time() + 5;
            while (count($received) < 1 && time() < $deadline) {
                foreach ($consumer->read(timeout: 0.5) as $msg) {
                    $received[] = $msg->getBody();
                }
            }
            $consumer->close();

            $this->assertCount(1, $received, "Expected exactly one message on partition {$partition}");
            $this->assertSame("for-{$bindingKey}", $received[0]);
        }

        $this->expectException(NoRouteForKeyException::class);
        $producer2 = $this->connection->createSuperStreamProducer($this->superStreamName, $strategy);
        $producer2->send('unbound', 'no-such-binding-key');
    }
}
