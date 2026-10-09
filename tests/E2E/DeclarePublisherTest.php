<?php

declare(strict_types=1);

namespace CrazyGoat\RabbitStream\Tests\E2E;

use CrazyGoat\RabbitStream\Exception\ProtocolException;
use CrazyGoat\RabbitStream\Request\CreateRequestV1;
use CrazyGoat\RabbitStream\Request\DeclarePublisherRequestV1;
use CrazyGoat\RabbitStream\Request\DeleteStreamRequestV1;
use CrazyGoat\RabbitStream\Response\CreateResponseV1;
use CrazyGoat\RabbitStream\Response\DeclarePublisherResponseV1;
use CrazyGoat\RabbitStream\StreamConnection;
use CrazyGoat\RabbitStream\Tests\E2E\E2ETestCase;

class DeclarePublisherTest extends E2ETestCase
{
    private ?StreamConnection $connection = null;
    private string $streamName = '';

    protected function setUp(): void
    {
        $this->connection = $this->connectAndOpen();
        $this->streamName = 'test-declare-publisher-' . uniqid();
        $this->connection->sendMessage(new CreateRequestV1($this->streamName));
        $response = $this->connection->readMessage();
        $this->assertInstanceOf(CreateResponseV1::class, $response);
    }

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

    public function testDeclarePublisherWithReference(): void
    {
        $connection = $this->connectAndOpen();

        $connection->sendMessage(new DeclarePublisherRequestV1(1, 'test-publisher', $this->streamName));
        $response = $connection->readMessage();

        $this->assertInstanceOf(DeclarePublisherResponseV1::class, $response);

        $connection->close();
    }

    public function testDeclarePublisherWithoutReference(): void
    {
        $connection = $this->connectAndOpen();

        $connection->sendMessage(new DeclarePublisherRequestV1(1, null, $this->streamName));
        $response = $connection->readMessage();

        $this->assertInstanceOf(DeclarePublisherResponseV1::class, $response);

        $connection->close();
    }

    public function testDeclarePublisherOnNonExistentStreamThrows(): void
    {
        $connection = $this->connectAndOpen();

        $this->expectException(ProtocolException::class);
        $this->expectExceptionMessage('STREAM_NOT_EXIST');
        $connection->sendMessage(new DeclarePublisherRequestV1(1, null, 'non-existent-stream'));
        $connection->readMessage();

        $connection->close();
    }
}
