<?php

declare(strict_types=1);

namespace CrazyGoat\RabbitStream\Tests\E2E;

use CrazyGoat\RabbitStream\Client\ConfirmationStatus;
use CrazyGoat\RabbitStream\Client\Connection;

class MultiplePublishersTest extends E2ETestCase
{
    private ?Connection $connection = null;

    /** @var string[] */
    private array $streamNames = [];

    protected function setUp(): void
    {
        $this->connection = $this->createConnection();
    }

    protected function tearDown(): void
    {
        if ($this->connection instanceof Connection) {
            foreach ($this->streamNames as $stream) {
                try {
                    $this->connection->deleteStream($stream);
                } catch (\Exception) {
                    // Ignore cleanup errors — stream may already be deleted
                }
            }
            $this->connection->close();
        }
    }

    private function createStream(): string
    {
        $name = 'test-multi-pub-' . uniqid();
        $this->assertNotNull($this->connection);
        $this->connection->createStream($name);
        $this->streamNames[] = $name;
        return $name;
    }

    public function testTwoPublishersOnDifferentStreamsGetIndependentConfirmations(): void
    {
        $this->assertNotNull($this->connection);
        $stream1 = $this->createStream();
        $stream2 = $this->createStream();

        $confirmed1 = [];
        $confirmed2 = [];

        $producer1 = $this->connection->createProducer(
            $stream1,
            onConfirm: function (ConfirmationStatus $status) use (&$confirmed1): void {
                $confirmed1[] = $status;
            }
        );

        $producer2 = $this->connection->createProducer(
            $stream2,
            onConfirm: function (ConfirmationStatus $status) use (&$confirmed2): void {
                $confirmed2[] = $status;
            }
        );

        $producer1->send('msg-for-stream-1');
        $producer2->send('msg-for-stream-2');

        $producer1->waitForConfirms(timeout: 5.0);
        $producer2->waitForConfirms(timeout: 5.0);

        $this->assertCount(1, $confirmed1);
        $this->assertCount(1, $confirmed2);

        $this->assertTrue($confirmed1[0]->isConfirmed());
        $this->assertTrue($confirmed2[0]->isConfirmed());

        // Each producer should have its own independent publishing ID
        $this->assertSame(0, $confirmed1[0]->getPublishingId());
        $this->assertSame(0, $confirmed2[0]->getPublishingId());

        $producer1->close();
        $producer2->close();
    }

    public function testClosingOnePublisherDoesNotAffectTheOther(): void
    {
        $this->assertNotNull($this->connection);
        $stream1 = $this->createStream();
        $stream2 = $this->createStream();

        $confirmed1 = [];
        $confirmed2 = [];

        $producer1 = $this->connection->createProducer(
            $stream1,
            onConfirm: function (ConfirmationStatus $status) use (&$confirmed1): void {
                $confirmed1[] = $status;
            }
        );

        $producer2 = $this->connection->createProducer(
            $stream2,
            onConfirm: function (ConfirmationStatus $status) use (&$confirmed2): void {
                $confirmed2[] = $status;
            }
        );

        // Send messages through both
        $producer1->send('msg1-from-p1');
        $producer2->send('msg1-from-p2');
        $producer1->waitForConfirms(timeout: 5.0);
        $producer2->waitForConfirms(timeout: 5.0);

        $this->assertCount(1, $confirmed1);
        $this->assertCount(1, $confirmed2);

        // Close the first publisher
        $producer1->close();

        // Producer 2 should still be usable for publishing
        $producer2->send('msg2-from-p2');
        $producer2->waitForConfirms(timeout: 5.0);

        $this->assertCount(1, $confirmed1);
        $this->assertCount(2, $confirmed2);
        $this->assertTrue($confirmed2[1]->isConfirmed());
        $this->assertSame(1, $confirmed2[1]->getPublishingId());

        $producer2->close();
    }

    public function testMultiplePublishersOnSameStreamHaveIndependentPublishingIds(): void
    {
        $this->assertNotNull($this->connection);
        $stream = $this->createStream();

        $confirmed1 = [];
        $confirmed2 = [];

        $producer1 = $this->connection->createProducer(
            $stream,
            onConfirm: function (ConfirmationStatus $status) use (&$confirmed1): void {
                $confirmed1[] = $status;
            }
        );

        $producer2 = $this->connection->createProducer(
            $stream,
            onConfirm: function (ConfirmationStatus $status) use (&$confirmed2): void {
                $confirmed2[] = $status;
            }
        );

        // Send one message through each producer
        $producer1->send('p1-message');
        $producer2->send('p2-message');

        $producer1->waitForConfirms(timeout: 5.0);
        $producer2->waitForConfirms(timeout: 5.0);

        $this->assertCount(1, $confirmed1);
        $this->assertCount(1, $confirmed2);

        // Both should have publishing ID 0 (independent counters)
        $this->assertSame(0, $confirmed1[0]->getPublishingId());
        $this->assertSame(0, $confirmed2[0]->getPublishingId());

        $producer1->close();
        $producer2->close();
    }

    public function testPublishingIdsAreIndependentAcrossProducers(): void
    {
        $this->assertNotNull($this->connection);
        $stream1 = $this->createStream();
        $stream2 = $this->createStream();

        $confirmed1 = [];
        $confirmed2 = [];

        $producer1 = $this->connection->createProducer(
            $stream1,
            onConfirm: function (ConfirmationStatus $status) use (&$confirmed1): void {
                $confirmed1[] = $status;
            }
        );

        $producer2 = $this->connection->createProducer(
            $stream2,
            onConfirm: function (ConfirmationStatus $status) use (&$confirmed2): void {
                $confirmed2[] = $status;
            }
        );

        // Send batches of messages
        $producer1->sendBatch(['a', 'b', 'c']);
        $producer2->sendBatch(['x', 'y']);

        $producer1->waitForConfirms(timeout: 5.0);
        $producer2->waitForConfirms(timeout: 5.0);

        // Producer 1: publishing IDs 0, 1, 2
        $this->assertCount(3, $confirmed1);
        $this->assertSame(0, $confirmed1[0]->getPublishingId());
        $this->assertSame(1, $confirmed1[1]->getPublishingId());
        $this->assertSame(2, $confirmed1[2]->getPublishingId());

        // Producer 2: publishing IDs 0, 1 (independent from producer 1)
        $this->assertCount(2, $confirmed2);
        $this->assertSame(0, $confirmed2[0]->getPublishingId());
        $this->assertSame(1, $confirmed2[1]->getPublishingId());

        foreach ($confirmed1 as $status) {
            $this->assertTrue($status->isConfirmed());
        }
        foreach ($confirmed2 as $status) {
            $this->assertTrue($status->isConfirmed());
        }

        $producer1->close();
        $producer2->close();
    }

    public function testSequentialSendAndWaitPerProducerMaintainsIsolation(): void
    {
        $this->assertNotNull($this->connection);
        $stream1 = $this->createStream();
        $stream2 = $this->createStream();

        $confirmed1 = [];
        $confirmed2 = [];

        $producer1 = $this->connection->createProducer(
            $stream1,
            onConfirm: function (ConfirmationStatus $status) use (&$confirmed1): void {
                $confirmed1[] = $status;
            }
        );

        $producer2 = $this->connection->createProducer(
            $stream2,
            onConfirm: function (ConfirmationStatus $status) use (&$confirmed2): void {
                $confirmed2[] = $status;
            }
        );

        // Send and wait on producer 1 first (isolated)
        $producer1->send('seq-msg-1');
        $producer1->waitForConfirms(timeout: 5.0);

        $this->assertCount(1, $confirmed1);
        $this->assertCount(0, $confirmed2, 'Producer 2 should not receive confirmations for producer 1');
        $this->assertTrue($confirmed1[0]->isConfirmed());

        // Then send and wait on producer 2 (isolated)
        $producer2->send('seq-msg-2');
        $producer2->waitForConfirms(timeout: 5.0);

        $this->assertCount(1, $confirmed1, 'Producer 1 confirms should not change');
        $this->assertCount(1, $confirmed2);
        $this->assertTrue($confirmed2[0]->isConfirmed());

        // Both started from publishing ID 0
        $this->assertSame(0, $confirmed1[0]->getPublishingId());
        $this->assertSame(0, $confirmed2[0]->getPublishingId());

        $producer1->close();
        $producer2->close();
    }
}
