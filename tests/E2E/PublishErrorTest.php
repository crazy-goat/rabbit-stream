<?php

declare(strict_types=1);

namespace CrazyGoat\RabbitStream\Tests\E2E;

use CrazyGoat\RabbitStream\Client\ConfirmationStatus;
use CrazyGoat\RabbitStream\Client\Connection;
use PHPUnit\Framework\TestCase;

class PublishErrorTest extends TestCase
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
        $this->streamName = 'test-publish-error-' . uniqid();
        $this->connection->createStream($this->streamName);
    }

    protected function tearDown(): void
    {
        if ($this->connection instanceof Connection) {
            try {
                $this->connection->deleteStream($this->streamName);
            } catch (\Exception) {
                // Ignore cleanup errors - stream may already be deleted
            }
            $this->connection->close();
        }
    }

    public function testPublishErrorCallbackIsInvokedWhenStreamDeleted(): void
    {
        $this->assertNotNull($this->connection);

        $confirmations = [];
        $producer = $this->connection->createProducer(
            $this->streamName,
            onConfirm: function (ConfirmationStatus $status) use (&$confirmations): void {
                $confirmations[] = $status;
            }
        );

        // Delete the stream while publisher is active
        $this->connection->deleteStream($this->streamName);

        // Attempt to publish - should trigger PublishError
        $producer->send('this-should-fail');

        // Wait for the error response
        $producer->waitForConfirms(timeout: 5.0);

        // Assertions
        $this->assertCount(1, $confirmations);
        $this->assertFalse($confirmations[0]->isConfirmed());
        $this->assertNotNull($confirmations[0]->getErrorCode());
        $this->assertSame(0, $confirmations[0]->getPublishingId());

        // Verify error code is a valid error (not OK)
        $errorCode = $confirmations[0]->getErrorCode();
        $this->assertNotEquals(0x01, $errorCode, 'Error code should not be OK');

        // Publisher may already be gone due to stream deletion, so ignore close errors
        try {
            $producer->close();
        } catch (\Exception) {
            // Ignore - publisher already deleted by server
        }
    }

    public function testPublishErrorCallbackIsInvokedForBatchWhenStreamDeleted(): void
    {
        $this->assertNotNull($this->connection);

        $confirmations = [];
        $producer = $this->connection->createProducer(
            $this->streamName,
            onConfirm: function (ConfirmationStatus $status) use (&$confirmations): void {
                $confirmations[] = $status;
            }
        );

        // Delete the stream while publisher is active
        $this->connection->deleteStream($this->streamName);

        // Attempt to publish batch - should trigger PublishError for all messages
        $producer->sendBatch(['msg1', 'msg2', 'msg3']);

        // Wait for the error responses
        $producer->waitForConfirms(timeout: 5.0);

        // Assertions
        $this->assertCount(3, $confirmations);

        foreach ($confirmations as $index => $status) {
            $this->assertFalse($status->isConfirmed());
            $this->assertNotNull($status->getErrorCode());
            $this->assertSame($index, $status->getPublishingId());
            $this->assertNotEquals(0x01, $status->getErrorCode(), 'Error code should not be OK');
        }

        // Publisher may already be gone due to stream deletion, so ignore close errors
        try {
            $producer->close();
        } catch (\Exception) {
            // Ignore - publisher already deleted by server
        }
    }
}
