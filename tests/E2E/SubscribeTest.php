<?php

declare(strict_types=1);

namespace CrazyGoat\RabbitStream\Tests\E2E;

use CrazyGoat\RabbitStream\Exception\ProtocolException;
use CrazyGoat\RabbitStream\Request\CreateRequestV1;
use CrazyGoat\RabbitStream\Request\DeleteStreamRequestV1;
use CrazyGoat\RabbitStream\Request\SubscribeRequestV1;
use CrazyGoat\RabbitStream\Response\CreateResponseV1;
use CrazyGoat\RabbitStream\Response\SubscribeResponseV1;
use CrazyGoat\RabbitStream\StreamConnection;
use CrazyGoat\RabbitStream\Tests\E2E\E2ETestCase;
use CrazyGoat\RabbitStream\VO\OffsetSpec;

class SubscribeTest extends E2ETestCase
{
    private ?StreamConnection $connection = null;
    private string $streamName = '';

    protected function tearDown(): void
    {
        if (!$this->connection instanceof StreamConnection) {
            return;
        }
        try {
            if ($this->connection->isConnected() && $this->streamName !== '') {
                $this->connection->sendMessage(new DeleteStreamRequestV1($this->streamName));
                $this->connection->readMessage();
            }
        } catch (\Exception) {
            // Ignore cleanup errors — stream may already be deleted
        } finally {
            $this->connection->close();
        }
    }

    public function testSubscribeToStream(): void
    {
        $this->connection = $this->connectAndOpen();
        $this->streamName = 'test-subscribe-stream-' . uniqid();

        // Create a test stream
        $this->connection->sendMessage(new CreateRequestV1($this->streamName));
        $createResponse = $this->connection->readMessage();
        $this->assertInstanceOf(CreateResponseV1::class, $createResponse);

        // Subscribe to the stream
        $this->connection->sendMessage(new SubscribeRequestV1(1, $this->streamName, OffsetSpec::first(), 10));
        $response = $this->connection->readMessage();

        $this->assertInstanceOf(SubscribeResponseV1::class, $response);
    }

    public function testSubscribeWithOffsetLast(): void
    {
        $this->connection = $this->connectAndOpen();
        $this->streamName = 'test-subscribe-last-' . uniqid();
        $this->connection->sendMessage(new CreateRequestV1($this->streamName));
        $this->connection->readMessage();

        $this->connection->sendMessage(new SubscribeRequestV1(1, $this->streamName, OffsetSpec::last(), 5));
        $response = $this->connection->readMessage();

        $this->assertInstanceOf(SubscribeResponseV1::class, $response);
    }

    public function testSubscribeWithOffsetNext(): void
    {
        $this->connection = $this->connectAndOpen();
        $this->streamName = 'test-subscribe-next-' . uniqid();
        $this->connection->sendMessage(new CreateRequestV1($this->streamName));
        $this->connection->readMessage();

        $this->connection->sendMessage(new SubscribeRequestV1(1, $this->streamName, OffsetSpec::next(), 20));
        $response = $this->connection->readMessage();

        $this->assertInstanceOf(SubscribeResponseV1::class, $response);
    }

    public function testSubscribeToNonExistentStreamThrows(): void
    {
        $this->connection = $this->connectAndOpen();

        $this->expectException(ProtocolException::class);
        $this->expectExceptionMessage('STREAM_NOT_EXIST');
        $this->connection->sendMessage(new SubscribeRequestV1(
            1,
            'non-existent-stream-' . uniqid(),
            OffsetSpec::first(),
            10
        ));
        $this->connection->readMessage();
    }

    public function testDuplicateSubscriptionIdThrows(): void
    {
        $this->connection = $this->connectAndOpen();
        $this->streamName = 'test-subscribe-duplicate-' . uniqid();
        $this->connection->sendMessage(new CreateRequestV1($this->streamName));
        $this->connection->readMessage();

        // First subscription should succeed
        $this->connection->sendMessage(new SubscribeRequestV1(1, $this->streamName, OffsetSpec::first(), 10));
        $response = $this->connection->readMessage();
        $this->assertInstanceOf(SubscribeResponseV1::class, $response);

        // Second subscription with same ID should fail with SUBSCRIPTION_ID_ALREADY_EXISTS (0x03)
        $this->expectException(ProtocolException::class);
        $this->expectExceptionMessage('SUBSCRIPTION_ID_ALREADY_EXISTS');
        $this->connection->sendMessage(new SubscribeRequestV1(1, $this->streamName, OffsetSpec::first(), 10));
        $this->connection->readMessage();
    }
}
