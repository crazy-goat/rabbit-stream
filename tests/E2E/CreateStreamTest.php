<?php

declare(strict_types=1);

namespace CrazyGoat\RabbitStream\Tests\E2E;

use CrazyGoat\RabbitStream\Exception\ProtocolException;
use CrazyGoat\RabbitStream\Request\CreateRequestV1;
use CrazyGoat\RabbitStream\Request\DeleteStreamRequestV1;
use CrazyGoat\RabbitStream\Response\CreateResponseV1;
use CrazyGoat\RabbitStream\StreamConnection;
use CrazyGoat\RabbitStream\Tests\E2E\E2ETestCase;

class CreateStreamTest extends E2ETestCase
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

    public function testCreateStream(): void
    {
        $this->connection = $this->connectAndOpen();
        $this->streamName = 'test-create-stream-' . uniqid();
        $this->connection->sendMessage(new CreateRequestV1($this->streamName));
        $response = $this->connection->readMessage();

        $this->assertInstanceOf(CreateResponseV1::class, $response);
    }

    public function testCreateStreamWithArguments(): void
    {
        $this->connection = $this->connectAndOpen();
        $this->streamName = 'test-create-stream-args-' . uniqid();
        $this->connection->sendMessage(new CreateRequestV1($this->streamName, [
            'max-length-bytes' => '1000000',
            'max-age' => '1h',
        ]));
        $response = $this->connection->readMessage();

        $this->assertInstanceOf(CreateResponseV1::class, $response);
    }

    public function testCreateDuplicateStreamThrows(): void
    {
        $this->connection = $this->connectAndOpen();
        $this->streamName = 'test-create-duplicate-' . uniqid();
        $this->connection->sendMessage(new CreateRequestV1($this->streamName));
        $this->connection->readMessage();

        // Second create should fail with STREAM_ALREADY_EXISTS (0x05)
        $this->expectException(ProtocolException::class);
        $this->expectExceptionMessage('STREAM_ALREADY_EXISTS');
        $this->connection->sendMessage(new CreateRequestV1($this->streamName));
        $this->connection->readMessage();
    }
}
