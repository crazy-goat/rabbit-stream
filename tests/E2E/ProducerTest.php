<?php

declare(strict_types=1);

namespace CrazyGoat\RabbitStream\Tests\E2E;

use CrazyGoat\RabbitStream\Client\ConfirmationStatus;
use CrazyGoat\RabbitStream\Client\Connection;
use CrazyGoat\RabbitStream\Client\Producer;
use CrazyGoat\RabbitStream\Exception\TimeoutException;

class ProducerTest extends E2ETestCase
{
    private ?Connection $connection = null;
    private string $streamName;

    protected function setUp(): void
    {
        $this->connection = $this->createConnection();
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

    public function testWaitForConfirmsReturnsAsSoonAsConfirmArrives(): void
    {
        $this->assertNotNull($this->connection);

        /** @var \CrazyGoat\RabbitStream\Client\ConfirmationStatus[] $confirmations */
        $confirmations = [];
        $producer = $this->connection->createProducer(
            $this->streamName,
            onConfirm: function ($status) use (&$confirmations): void {
                $confirmations[] = $status;
            }
        );

        $producer->send('test message');

        // The timeout is deliberately generous (5s) so the assertion isn't
        // flaky on a slow CI box. Before the fix for #385, waitForConfirms()
        // always blocked for the *entire* timeout regardless of how quickly
        // the broker actually confirmed, because readLoop() was called
        // without maxFrames and only ever returns on deadline expiry. With
        // the fix, readLoop(maxFrames: 1, ...) hands control back to the
        // outer loop after each dispatched frame, so the call returns right
        // after the confirm is processed. A real confirm round-trip over a
        // local Docker broker is observed around 0.03s; 1.0s leaves ~30x
        // headroom for CI slowness while still being 5x below the 5.0s
        // timeout, so it reliably distinguishes "returned fast" from
        // "blocked for the full timeout" without letting a ~2s regression
        // through unnoticed.
        $start = microtime(true);
        $producer->waitForConfirms(timeout: 5.0);
        $elapsed = microtime(true) - $start;

        $message = "waitForConfirms() took {$elapsed}s; expected it to return shortly "
            . "after the confirm arrived, not block for the full timeout";
        $this->assertLessThan(1.0, $elapsed, $message);

        // Guard against a future regression where send() stops incrementing
        // pendingConfirms: waitForConfirms() would then return instantly
        // without ever waiting for a real confirm, and an elapsed-time
        // assertion alone would still pass vacuously. Assert the confirm
        // actually arrived.
        $this->assertCount(1, $confirmations, 'Exactly one confirm should have been received');
        $this->assertTrue($confirmations[0]->isConfirmed(), 'The confirm should report success');

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

    public function testLargeBatchPublish(): void
    {
        $this->assertNotNull($this->connection);
        $this->publishBatchAndVerifyConfirms(
            500,
            fn(int $i): string => "message-{$i}",
            30.0
        );
    }

    public function testBatchPublishWith1KbMessages(): void
    {
        $this->assertNotNull($this->connection);
        $this->publishBatchAndVerifyConfirms(
            100,
            fn(): string => str_repeat('X', 1024),
            30.0
        );
    }

    public function testMaxPendingConfirmsBoundsInFlightMessages(): void
    {
        // Regression coverage for the deliver-frame-cap flow-control work:
        // Producer::send() historically never read the socket, so a long
        // run of send() calls without waitForConfirms() left an unbounded
        // number of frames in flight. maxPendingConfirms enforces back-
        // pressure by draining confirms off the socket once the window
        // fills, so getPendingConfirms() must never exceed the configured
        // cap and every message must still end up confirmed.
        $streamConnection = $this->connectAndOpen();

        $messageCount = 50000;
        $maxPendingConfirms = 1000;
        $observedMax = 0;
        $confirmedCount = 0;

        $producer = new Producer(
            $streamConnection,
            $this->streamName,
            1,
            onConfirm: function (ConfirmationStatus $status) use (&$confirmedCount): void {
                if ($status->isConfirmed()) {
                    $confirmedCount++;
                }
            },
            maxPendingConfirms: $maxPendingConfirms,
        );

        for ($i = 0; $i < $messageCount; $i++) {
            $producer->send("message-{$i}");
            $observedMax = max($observedMax, $producer->getPendingConfirms());
        }

        $producer->waitForConfirms(timeout: 60.0);

        $this->assertLessThanOrEqual(
            $maxPendingConfirms,
            $observedMax,
            'pendingConfirms must never exceed maxPendingConfirms'
        );
        $this->assertSame($messageCount, $confirmedCount, 'All messages must eventually be confirmed');

        $producer->close();
        $streamConnection->close();
    }

    /**
     * @param callable(int): string $messageFactory
     */
    private function publishBatchAndVerifyConfirms(int $count, callable $messageFactory, float $timeout = 5.0): void
    {
        $this->assertNotNull($this->connection);
        $confirmed = [];
        $producer = $this->connection->createProducer(
            $this->streamName,
            onConfirm: function (ConfirmationStatus $status) use (&$confirmed): void {
                $confirmed[] = $status;
            }
        );

        $messages = [];
        for ($i = 0; $i < $count; $i++) {
            $messages[] = $messageFactory($i);
        }

        $producer->sendBatch($messages);
        $producer->waitForConfirms(timeout: $timeout);

        $this->assertCount($count, $confirmed);

        $publishingIds = [];
        foreach ($confirmed as $status) {
            $this->assertTrue($status->isConfirmed());
            $id = $status->getPublishingId();
            if ($id !== null) {
                $publishingIds[] = $id;
            }
        }

        // Verify no gaps in publishing IDs
        $this->assertCount($count, $publishingIds, 'All confirms should have a publishing ID');
        sort($publishingIds);
        $this->assertSame(0, $publishingIds[0], 'First publishing ID should be 0');
        $this->assertSame($count - 1, $publishingIds[$count - 1], 'Last publishing ID should be ' . ($count - 1));
        $this->assertCount($count, array_unique($publishingIds), 'All publishing IDs should be unique');

        $producer->close();
    }
}
