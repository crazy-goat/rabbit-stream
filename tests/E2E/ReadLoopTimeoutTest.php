<?php

declare(strict_types=1);

namespace CrazyGoat\RabbitStream\Tests\E2E;

use CrazyGoat\RabbitStream\Request\CloseRequestV1;
use CrazyGoat\RabbitStream\Request\CreateRequestV1;
use CrazyGoat\RabbitStream\Request\DeclarePublisherRequestV1;
use CrazyGoat\RabbitStream\Request\DeleteStreamRequestV1;
use CrazyGoat\RabbitStream\Request\PublishRequestV1;
use CrazyGoat\RabbitStream\Request\SubscribeRequestV1;
use CrazyGoat\RabbitStream\Response\CloseResponseV1;
use CrazyGoat\RabbitStream\Response\CreateResponseV1;
use CrazyGoat\RabbitStream\Response\DeclarePublisherResponseV1;
use CrazyGoat\RabbitStream\Response\SubscribeResponseV1;
use CrazyGoat\RabbitStream\StreamConnection;
use CrazyGoat\RabbitStream\VO\OffsetSpec;
use CrazyGoat\RabbitStream\VO\PublishedMessage;

/**
 * E2E tests for StreamConnection::readLoop() timeout and maxFrames behavior.
 *
 * Note about stop(): StreamConnection::stop() is designed to be called asynchronously
 * (from signal handler or other thread) while readLoop() is executing. Since readLoop()
 * resets $this->running = true on entry, calling stop() before readLoop() does NOT cause
 * early exit. This behavior is tested in unit tests (StreamConnectionTest).
 *
 * @group slow
 */
class ReadLoopTimeoutTest extends E2ETestCase
{
    private ?StreamConnection $connection = null;
    private string $streamName = '';

    protected function tearDown(): void
    {
        try {
            $isCleanupNeeded = $this->connection instanceof StreamConnection
                && $this->connection->isConnected()
                && $this->streamName !== '';
            if ($isCleanupNeeded) {
                try {
                    $this->connection->sendMessage(new DeleteStreamRequestV1($this->streamName));
                    $this->connection->readMessage();
                } catch (\Exception) {
                    // Ignore cleanup errors
                }
            }
        } finally {
            if ($this->connection instanceof StreamConnection && $this->connection->isConnected()) {
                $this->connection->close();
            }
            $this->connection = null;
            $this->streamName = '';
        }
    }

    public function testReadLoopReturnsAfterTimeout(): void
    {
        $connection = $this->connectAndOpen();
        $this->connection = $connection;

        $start = microtime(true);
        $connection->readLoop(timeout: 0.3);
        $elapsed = microtime(true) - $start;

        $this->assertGreaterThan(0.2, $elapsed, 'readLoop should block for approximately the timeout duration');
        $this->assertLessThan(1.0, $elapsed, 'readLoop should not block significantly longer than the timeout');
    }

    public function testReadLoopReturnsAfterTimeoutLongerThanOneSecond(): void
    {
        $connection = $this->connectAndOpen();
        $this->connection = $connection;

        // Regression for #382: socket_select() seconds were clamped to 1 but
        // microseconds came from the unclamped remainder, yielding
        // tv_usec >= 1_000_000 -> EINVAL + ConnectionException on Linux.
        $start = microtime(true);
        $connection->readLoop(timeout: 2.5);
        $elapsed = microtime(true) - $start;

        $this->assertGreaterThan(2.3, $elapsed, 'readLoop should block for approximately the timeout duration');
        $this->assertLessThan(3.5, $elapsed, 'readLoop should not block significantly longer than the timeout');
    }

    public function testReadLoopWithZeroTimeout(): void
    {
        $connection = $this->connectAndOpen();
        $this->connection = $connection;

        $start = microtime(true);
        $connection->readLoop(timeout: 0.0);
        $elapsed = microtime(true) - $start;

        $this->assertLessThan(0.5, $elapsed, 'readLoop with 0 timeout should return immediately');
    }

    public function testConnectionRemainsUsableAfterReadLoopTimeout(): void
    {
        $connection = $this->connectAndOpen();
        $this->connection = $connection;

        // First readLoop should timeout (no server-push frames pending)
        $connection->readLoop(timeout: 0.5);

        // Connection should still be usable
        $this->assertTrue($connection->isConnected(), 'Connection should remain usable after readLoop timeout');

        // Send a proper request and verify we get a response
        $connection->sendMessage(new CloseRequestV1());
        $response = $connection->readMessage();
        $this->assertInstanceOf(CloseResponseV1::class, $response);
    }

    public function testReadLoopWithMaxFrames(): void
    {
        $connection = $this->connectAndOpen();
        $this->connection = $connection;
        $this->streamName = 'test-readloop-maxframes-' . uniqid();

        // Create stream
        $connection->sendMessage(new CreateRequestV1($this->streamName));
        $createResponse = $connection->readMessage();
        $this->assertInstanceOf(CreateResponseV1::class, $createResponse);

        // Declare publisher
        $connection->sendMessage(new DeclarePublisherRequestV1(1, null, $this->streamName));
        $declareResponse = $connection->readMessage();
        $this->assertInstanceOf(DeclarePublisherResponseV1::class, $declareResponse);

        // Register publisher confirm callback
        $confirmCount = 0;
        $connection->registerPublisher(1, function ($publishingIds) use (&$confirmCount): void {
            $confirmCount += count($publishingIds);
        }, function ($errors): void {
        });

        // Publish 3 messages
        $connection->sendMessage(new PublishRequestV1(
            1,
            new PublishedMessage(0, 'message-0'),
            new PublishedMessage(1, 'message-1'),
            new PublishedMessage(2, 'message-2'),
        ));

        // Wait for publish confirms
        $connection->readLoop(maxFrames: 1, timeout: 5.0);
        $this->assertSame(3, $confirmCount, 'All 3 published messages should be confirmed');

        // Register subscriber callback and count deliver frames
        $deliverCount = 0;
        $connection->registerSubscriber(1, function ($deliver) use (&$deliverCount): void {
            $deliverCount++;
        });

        // Subscribe from the beginning with credit
        $connection->sendMessage(new SubscribeRequestV1(1, $this->streamName, OffsetSpec::first(), 10));
        $subscribeResponse = $connection->readMessage();
        $this->assertInstanceOf(SubscribeResponseV1::class, $subscribeResponse);

        // readLoop should receive delivery frames — use maxFrames to get exactly 1
        $connection->readLoop(maxFrames: 1, timeout: 5.0);

        $this->assertGreaterThanOrEqual(
            1,
            $deliverCount,
            'At least one deliver frame should be received via readLoop(maxFrames: 1)'
        );
    }

    public function testReadLoopMaxFramesOneFrameWithTimeout(): void
    {
        $connection = $this->connectAndOpen();
        $this->connection = $connection;
        $this->streamName = 'test-readloop-maxframes-one-' . uniqid();

        // Create stream
        $connection->sendMessage(new CreateRequestV1($this->streamName));
        $createResponse = $connection->readMessage();
        $this->assertInstanceOf(CreateResponseV1::class, $createResponse);

        // Declare publisher
        $connection->sendMessage(new DeclarePublisherRequestV1(1, null, $this->streamName));
        $declareResponse = $connection->readMessage();
        $this->assertInstanceOf(DeclarePublisherResponseV1::class, $declareResponse);

        // Register confirm callback
        $confirmCount = 0;
        $connection->registerPublisher(1, function ($publishingIds) use (&$confirmCount): void {
            $confirmCount += count($publishingIds);
        }, function ($errors): void {
        });

        // Publish messages
        $connection->sendMessage(new PublishRequestV1(
            1,
            new PublishedMessage(0, 'data-0'),
            new PublishedMessage(1, 'data-1'),
        ));

        // Wait for publish confirms
        $connection->readLoop(maxFrames: 1, timeout: 5.0);
        $this->assertSame(2, $confirmCount);

        // Register subscriber
        $deliverFrames = 0;
        $connection->registerSubscriber(1, function ($deliver) use (&$deliverFrames): void {
            $deliverFrames++;
        });

        // Subscribe from beginning
        $connection->sendMessage(new SubscribeRequestV1(1, $this->streamName, OffsetSpec::first(), 10));
        $subscribeResponse = $connection->readMessage();
        $this->assertInstanceOf(SubscribeResponseV1::class, $subscribeResponse);

        // readLoop with maxFrames should stop after 1 server-push frame
        $start = microtime(true);
        $connection->readLoop(maxFrames: 1, timeout: 5.0);
        $elapsed = microtime(true) - $start;

        $this->assertGreaterThanOrEqual(1, $deliverFrames, 'At least 1 deliver frame should arrive');
        $this->assertLessThan(4.0, $elapsed, 'readLoop should not wait for full timeout when frames arrive');
    }
}
