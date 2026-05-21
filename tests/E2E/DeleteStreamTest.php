<?php

declare(strict_types=1);

namespace CrazyGoat\RabbitStream\Tests\E2E;

use CrazyGoat\RabbitStream\Exception\ProtocolException;
use CrazyGoat\RabbitStream\Request\CreateRequestV1;
use CrazyGoat\RabbitStream\Request\DeleteStreamRequestV1;
use CrazyGoat\RabbitStream\Response\CreateResponseV1;
use CrazyGoat\RabbitStream\Response\DeleteStreamResponseV1;
use CrazyGoat\RabbitStream\Tests\E2E\E2ETestCase;

class DeleteStreamTest extends E2ETestCase
{
    public function testDeleteStream(): void
    {
        $connection = $this->connectAndOpen();

        // First create a stream
        $streamName = 'test-delete-stream-' . uniqid();
        $connection->sendMessage(new CreateRequestV1($streamName));
        $createResponse = $connection->readMessage();
        $this->assertInstanceOf(CreateResponseV1::class, $createResponse);

        // Now delete it
        $connection->sendMessage(new DeleteStreamRequestV1($streamName));
        $deleteResponse = $connection->readMessage();

        $this->assertInstanceOf(DeleteStreamResponseV1::class, $deleteResponse);

        $connection->close();
    }

    public function testDeleteNonExistentStreamThrows(): void
    {
        $connection = $this->connectAndOpen();

        $streamName = 'test-delete-nonexistent-' . uniqid();

        // Delete should fail with STREAM_NOT_EXIST (0x02) for non-existent stream
        $this->expectException(ProtocolException::class);
        $this->expectExceptionMessage('STREAM_NOT_EXIST');
        $connection->sendMessage(new DeleteStreamRequestV1($streamName));
        $connection->readMessage();

        $connection->close();
    }
}
