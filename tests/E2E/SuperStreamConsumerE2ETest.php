<?php

declare(strict_types=1);

namespace CrazyGoat\RabbitStream\Tests\E2E;

use CrazyGoat\RabbitStream\Client\Connection;
use CrazyGoat\RabbitStream\VO\OffsetSpec;

class SuperStreamConsumerE2ETest extends E2ETestCase
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
            try {
                $this->connection->close();
            } catch (\Exception) {
                // Ignore cleanup errors — see the SAC test's close() comment: a
                // partition close() race can occasionally leave this connection's
                // frame stream desynced for its own final Close round trip too.
            }
        }
    }

    /** @return list<string> partition names */
    private function createSuperStreamWithPartitions(string $name): array
    {
        $this->assertNotNull($this->connection);
        $partitions = [$name . '-0', $name . '-1', $name . '-2'];
        $this->connection->createSuperStream($name, $partitions, ['0', '1', '2']);
        return $partitions;
    }

    public function testReadReceivesAllMessagesAcrossPartitionsWithCorrectStream(): void
    {
        $this->assertNotNull($this->connection);

        $this->superStreamName = 'test-ssc-read-' . uniqid();
        $this->createSuperStreamWithPartitions($this->superStreamName);

        $producer = $this->connection->createSuperStreamProducer($this->superStreamName);
        for ($i = 0; $i < 60; $i++) {
            $producer->send("message-{$i}", "key-{$i}");
        }
        $producer->waitForConfirms(timeout: 5.0);
        $producer->close();

        $consumer = $this->connection->createSuperStreamConsumer($this->superStreamName, OffsetSpec::first());

        $received = [];
        $deadline = time() + 10;
        while (count($received) < 60 && time() < $deadline) {
            foreach ($consumer->read(timeout: 0.5) as $msg) {
                $received[] = $msg;
            }
        }
        $consumer->close();

        $this->assertCount(60, $received);
        foreach ($received as $msg) {
            $this->assertNotNull($msg->getStream());
            $this->assertStringStartsWith($this->superStreamName . '-', (string) $msg->getStream());
        }
    }

    public function testSingleActiveConsumerAcrossPartitions(): void
    {
        $this->assertNotNull($this->connection);

        $this->superStreamName = 'test-ssc-sac-' . uniqid();
        $partitions = $this->createSuperStreamWithPartitions($this->superStreamName);

        $producer = $this->connection->createSuperStreamProducer($this->superStreamName);
        $expectedBodies = [];
        for ($i = 0; $i < 60; $i++) {
            $body = "message-{$i}";
            $producer->send($body, "key-{$i}");
            $expectedBodies[] = $body;
        }
        $producer->waitForConfirms(timeout: 5.0);
        $producer->close();

        $connectionB = $this->createConnection();
        $consumerName = 'ssc-sac-' . uniqid();

        $consumerA = $this->connection->createSuperStreamConsumer(
            $this->superStreamName,
            OffsetSpec::first(),
            name: $consumerName,
            autoCommit: 1,
            singleActiveConsumer: true,
        );
        $consumerB = $connectionB->createSuperStreamConsumer(
            $this->superStreamName,
            OffsetSpec::first(),
            name: $consumerName,
            autoCommit: 1,
            singleActiveConsumer: true,
        );

        $receivedBodies = [];
        $deadline = time() + 10;
        while (count($receivedBodies) < 60 && time() < $deadline) {
            foreach ($consumerA->read(timeout: 0.3) as $msg) {
                $body = $msg->getBody();
                if (is_string($body)) {
                    $receivedBodies[$body] = true;
                }
            }
            foreach ($consumerB->read(timeout: 0.3) as $msg) {
                $body = $msg->getBody();
                if (is_string($body)) {
                    $receivedBodies[$body] = true;
                }
            }
        }

        // Let activation settle across both connections/partitions.
        $settleDeadline = time() + 3;
        while (time() < $settleDeadline) {
            $consumerA->read(timeout: 0.2);
            $consumerB->read(timeout: 0.2);
        }

        foreach ($partitions as $partition) {
            $activeA = $consumerA->isActive($partition);
            $activeB = $consumerB->isActive($partition);
            $this->assertTrue(
                $activeA xor $activeB,
                "Expected exactly one of the two SAC consumers to be active for partition {$partition}"
            );
        }

        $this->assertEqualsCanonicalizing(
            $expectedBodies,
            array_keys($receivedBodies),
            'The union of both consumers must equal the full published set, with no message lost'
        );

        // SuperStreamConsumer::close() already tolerates a single partition's
        // close() racing with a ConsumerUpdate activation push for another
        // partition (a pre-existing, documented limitation of Consumer's
        // non-correlated response dispatch — see SuperStreamConsumer::close()).
        $consumerA->close();
        $consumerB->close();
        try {
            $connectionB->close();
        } catch (\Exception) {
            // See tearDown()'s comment: the same race can leave a connection's
            // frame stream desynced for its own final Close round trip.
        }
    }
}
