<?php

declare(strict_types=1);

namespace CrazyGoat\RabbitStream\Tests\Client;

use CrazyGoat\RabbitStream\Client\Connection;
use CrazyGoat\RabbitStream\Request\CloseRequestV1;
use CrazyGoat\RabbitStream\Request\CreateRequestV1;
use CrazyGoat\RabbitStream\Request\DeleteStreamRequestV1;
use CrazyGoat\RabbitStream\Request\MetadataRequestV1;
use CrazyGoat\RabbitStream\Response\CloseResponseV1;
use CrazyGoat\RabbitStream\Response\CreateResponseV1;
use CrazyGoat\RabbitStream\Response\DeleteStreamResponseV1;
use CrazyGoat\RabbitStream\Response\MetadataResponseV1;
use CrazyGoat\RabbitStream\StreamConnection;
use CrazyGoat\RabbitStream\VO\Broker;
use CrazyGoat\RabbitStream\VO\StreamMetadata;
use PHPUnit\Framework\TestCase;

class ConnectionTest extends TestCase
{
    public function testCreateStreamSendsCorrectRequest(): void
    {
        $streamConnection = $this->createMock(StreamConnection::class);

        $streamConnection->expects($this->once())
            ->method('sendMessage')
            ->with($this->callback(fn($request): bool => $request instanceof CreateRequestV1
                && $request->toArray()['stream'] === 'test-stream'));

        $streamConnection->expects($this->once())
            ->method('readMessage')
            ->willReturn(new CreateResponseV1());

        // Create Connection using reflection to inject mock
        $connection = $this->createConnectionWithMock($streamConnection);

        $connection->createStream('test-stream');
    }

    public function testCloseSendsCloseRequestBeforeClosingSocket(): void
    {
        $streamConnection = $this->createMock(StreamConnection::class);

        $streamConnection->expects($this->once())
            ->method('sendMessage')
            ->with($this->callback(fn($request): bool => $request instanceof CloseRequestV1));

        $streamConnection->expects($this->once())
            ->method('readMessage')
            ->willReturn(new CloseResponseV1());

        $streamConnection->expects($this->once())
            ->method('close');

        $connection = $this->createConnectionWithMock($streamConnection);

        $connection->close();
    }

    public function testStreamExistsReturnsTrueWhenStreamExists(): void
    {
        $streamConnection = $this->createMock(StreamConnection::class);

        $streamConnection->expects($this->once())
            ->method('sendMessage')
            ->with($this->callback(fn($request): bool => $request instanceof MetadataRequestV1
                && $request->toArray()['streams'] === ['existing-stream']));

        $metadata = new MetadataResponseV1(
            brokers: [new Broker(1, 'localhost', 5552)],
            streamMetadata: [new StreamMetadata('existing-stream', 0x01, 1, [])]
        );

        $streamConnection->expects($this->once())
            ->method('readMessage')
            ->willReturn($metadata);

        $connection = $this->createConnectionWithMock($streamConnection);

        $this->assertTrue($connection->streamExists('existing-stream'));
    }

    public function testStreamExistsReturnsFalseWhenStreamDoesNotExist(): void
    {
        $streamConnection = $this->createMock(StreamConnection::class);

        $streamConnection->expects($this->once())
            ->method('sendMessage')
            ->with($this->callback(fn($request): bool => $request instanceof MetadataRequestV1
                && $request->toArray()['streams'] === ['non-existing-stream']));

        $metadata = new MetadataResponseV1(
            brokers: [],
            streamMetadata: [new StreamMetadata('non-existing-stream', 0x02, 0, [])] // 0x02 = STREAM_NOT_EXIST
        );

        $streamConnection->expects($this->once())
            ->method('readMessage')
            ->willReturn($metadata);

        $connection = $this->createConnectionWithMock($streamConnection);

        $this->assertFalse($connection->streamExists('non-existing-stream'));
    }

    public function testDeleteStreamSendsCorrectRequest(): void
    {
        $streamConnection = $this->createMock(StreamConnection::class);

        $streamConnection->expects($this->once())
            ->method('sendMessage')
            ->with($this->callback(fn($request): bool => $request instanceof DeleteStreamRequestV1
                && $request->toArray()['stream'] === 'stream-to-delete'));

        $streamConnection->expects($this->once())
            ->method('readMessage')
            ->willReturn(new DeleteStreamResponseV1());

        $connection = $this->createConnectionWithMock($streamConnection);

        $connection->deleteStream('stream-to-delete');
    }

    private function createConnectionWithMock(StreamConnection $mock): Connection
    {
        $reflection = new \ReflectionClass(Connection::class);
        $constructor = $reflection->getConstructor();

        $connection = $reflection->newInstanceWithoutConstructor();
        $constructor->invoke($connection, $mock);

        return $connection;
    }
}
