<?php

declare(strict_types=1);

namespace CrazyGoat\RabbitStream\Tests\E2E;

use CrazyGoat\RabbitStream\Client\Connection;
use CrazyGoat\RabbitStream\VO\OffsetSpec;

/**
 * Single active consumer (#496): two consumers sharing the same `name`
 * (reference) on the same stream are grouped by the broker, which activates
 * exactly one of them at a time. The broker groups by reference ACROSS
 * connections, so the two consumers here are deliberately opened on two
 * separate StreamConnection-backed Connection instances.
 */
class SingleActiveConsumerE2ETest extends E2ETestCase
{
    private ?Connection $connectionA = null;
    private ?Connection $connectionB = null;
    private string $streamName;
    private string $consumerName;

    protected function setUp(): void
    {
        $this->connectionA = $this->createConnection();
        $this->connectionB = $this->createConnection();
        $this->streamName = 'test-sac-' . uniqid();
        $this->consumerName = 'sac-consumer-' . uniqid();
        $this->connectionA->createStream($this->streamName);
    }

    protected function tearDown(): void
    {
        if ($this->connectionA instanceof Connection) {
            try {
                $this->connectionA->deleteStream($this->streamName);
            } catch (\Exception) {
            }
            $this->connectionA->close();
        }
        if ($this->connectionB instanceof Connection) {
            try {
                $this->connectionB->close();
            } catch (\Exception) {
            }
        }
    }

    public function testSecondConsumerTakesOverAfterFirstCloses(): void
    {
        $this->assertNotNull($this->connectionA);
        $this->assertNotNull($this->connectionB);

        $producer = $this->connectionA->createProducer($this->streamName);
        for ($i = 0; $i < 20; $i++) {
            $producer->send("first-batch-{$i}");
        }
        $producer->waitForConfirms(timeout: 5);
        $producer->close();

        $consumerA = $this->connectionA->createConsumer(
            $this->streamName,
            OffsetSpec::first(),
            name: $this->consumerName,
            autoCommit: 1,
            singleActiveConsumer: true,
        );
        $consumerB = $this->connectionB->createConsumer(
            $this->streamName,
            OffsetSpec::first(),
            name: $this->consumerName,
            autoCommit: 1,
            singleActiveConsumer: true,
        );

        // Consumer A should become (or already be) active and receive the 20
        // messages; drive both connections' read loops so ConsumerUpdate can
        // be dispatched and answered on either side.
        $receivedA = [];
        $deadline = time() + 5;
        while (count($receivedA) < 20 && time() < $deadline) {
            foreach ($consumerA->read(timeout: 0.3) as $msg) {
                $receivedA[] = $msg->getBody();
            }
            $consumerB->read(timeout: 0.1);
        }

        $this->assertCount(20, $receivedA, 'The active consumer should receive all 20 messages');
        $this->assertTrue($consumerA->isActive());
        $this->assertFalse($consumerB->isActive());

        // B should not receive anything while inactive.
        $receivedB = $consumerB->read(timeout: 1.0);
        $this->assertSame([], $receivedB, 'The inactive consumer must not receive any messages');

        // Closing A hands activation to B. Auto-commit (every message) means A
        // already stored offset 19 as messages were read.
        $consumerA->close();

        // Publish 10 more messages once B has (or will) become active.
        $producer2 = $this->connectionA->createProducer($this->streamName);
        for ($i = 0; $i < 10; $i++) {
            $producer2->send("second-batch-{$i}");
        }
        $producer2->waitForConfirms(timeout: 5);
        $producer2->close();

        /** @var list<string> $receivedB2 */
        $receivedB2 = [];
        $deadline = time() + 5;
        while (count($receivedB2) < 10 && time() < $deadline) {
            foreach ($consumerB->read(timeout: 0.3) as $msg) {
                $receivedB2[] = $msg->getBody();
            }
        }

        $this->assertTrue($consumerB->isActive(), 'B should have become active after A closed');
        $this->assertCount(10, $receivedB2, 'B should receive only the new 10 messages, no replay');
        foreach ($receivedB2 as $body) {
            $this->assertIsString($body);
            $this->assertStringStartsWith('second-batch-', $body, "Unexpected replayed message: {$body}");
        }

        $consumerB->close();
    }
}
