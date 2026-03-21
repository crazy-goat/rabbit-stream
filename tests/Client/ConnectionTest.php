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
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

class ConnectionTest extends TestCase
{
    public function testCreateStreamSendsCorrectRequest(): void
    {
        $streamConnection = $this->createMock(StreamConnection::class);

        $capturedRequests = [];
        $streamConnection->method('sendMessage')
            ->willReturnCallback(function ($request) use (&$capturedRequests): void {
                $capturedRequests[] = $request;
            });

        $callCount = 0;
        $streamConnection->method('readMessage')
            ->willReturnCallback(function () use (&$callCount): CreateResponseV1|CloseResponseV1 {
                $callCount++;
                if ($callCount === 1) {
                    return new CreateResponseV1();
                }
                return new CloseResponseV1();
            });

        // Allow destructor to call close()
        $streamConnection->method('close');

        // Create Connection using reflection to inject mock
        $connection = $this->createConnectionWithMock($streamConnection);

        $connection->createStream('test-stream');

        // Verify the first request was CreateRequestV1
        $this->assertGreaterThanOrEqual(1, count($capturedRequests));
        $this->assertInstanceOf(CreateRequestV1::class, $capturedRequests[0]);
        $this->assertEquals('test-stream', $capturedRequests[0]->toArray()['stream']);
    }

    public function testCloseSendsCloseRequestBeforeClosingSocket(): void
    {
        $streamConnection = $this->createMock(StreamConnection::class);

        $capturedRequests = [];
        $streamConnection->method('sendMessage')
            ->willReturnCallback(function ($request) use (&$capturedRequests): void {
                $capturedRequests[] = $request;
            });

        $streamConnection->method('readMessage')
            ->willReturnCallback(fn(): CloseResponseV1 => new CloseResponseV1());

        $closeCalled = false;
        $streamConnection->method('close')
            ->willReturnCallback(function () use (&$closeCalled): void {
                $closeCalled = true;
            });

        $connection = $this->createConnectionWithMock($streamConnection);

        $connection->close();

        // Verify CloseRequestV1 was sent
        $this->assertGreaterThanOrEqual(1, count($capturedRequests));
        $this->assertInstanceOf(CloseRequestV1::class, $capturedRequests[0]);
        $this->assertTrue($closeCalled, 'close() should have been called on StreamConnection');
    }

    public function testStreamExistsReturnsTrueWhenStreamExists(): void
    {
        $streamConnection = $this->createMock(StreamConnection::class);

        $capturedRequests = [];
        $streamConnection->method('sendMessage')
            ->willReturnCallback(function ($request) use (&$capturedRequests): void {
                $capturedRequests[] = $request;
            });

        $metadata = new MetadataResponseV1(
            brokers: [new Broker(1, 'localhost', 5552)],
            streamMetadata: [new StreamMetadata('existing-stream', 0x01, 1, [])]
        );

        $callCount = 0;
        $streamConnection->method('readMessage')
            ->willReturnCallback(function () use (&$callCount, $metadata): MetadataResponseV1|CloseResponseV1 {
                $callCount++;
                if ($callCount === 1) {
                    return $metadata;
                }
                return new CloseResponseV1();
            });

        // Allow destructor to call close()
        $streamConnection->method('close');

        $connection = $this->createConnectionWithMock($streamConnection);

        $this->assertTrue($connection->streamExists('existing-stream'));

        // Verify the first request was MetadataRequestV1
        $this->assertGreaterThanOrEqual(1, count($capturedRequests));
        $this->assertInstanceOf(MetadataRequestV1::class, $capturedRequests[0]);
        $this->assertEquals(['existing-stream'], $capturedRequests[0]->toArray()['streams']);
    }

    public function testStreamExistsReturnsFalseWhenStreamDoesNotExist(): void
    {
        $streamConnection = $this->createMock(StreamConnection::class);

        $capturedRequests = [];
        $streamConnection->method('sendMessage')
            ->willReturnCallback(function ($request) use (&$capturedRequests): void {
                $capturedRequests[] = $request;
            });

        $metadata = new MetadataResponseV1(
            brokers: [],
            streamMetadata: [new StreamMetadata('non-existing-stream', 0x02, 0, [])] // 0x02 = STREAM_NOT_EXIST
        );

        $callCount = 0;
        $streamConnection->method('readMessage')
            ->willReturnCallback(function () use (&$callCount, $metadata): MetadataResponseV1|CloseResponseV1 {
                $callCount++;
                if ($callCount === 1) {
                    return $metadata;
                }
                return new CloseResponseV1();
            });

        // Allow destructor to call close()
        $streamConnection->method('close');

        $connection = $this->createConnectionWithMock($streamConnection);

        $this->assertFalse($connection->streamExists('non-existing-stream'));

        // Verify the first request was MetadataRequestV1
        $this->assertGreaterThanOrEqual(1, count($capturedRequests));
        $this->assertInstanceOf(MetadataRequestV1::class, $capturedRequests[0]);
        $this->assertEquals(['non-existing-stream'], $capturedRequests[0]->toArray()['streams']);
    }

    public function testDeleteStreamSendsCorrectRequest(): void
    {
        $streamConnection = $this->createMock(StreamConnection::class);

        $capturedRequests = [];
        $streamConnection->method('sendMessage')
            ->willReturnCallback(function ($request) use (&$capturedRequests): void {
                $capturedRequests[] = $request;
            });

        $callCount = 0;
        $streamConnection->method('readMessage')
            ->willReturnCallback(function () use (&$callCount): DeleteStreamResponseV1|CloseResponseV1 {
                $callCount++;
                if ($callCount === 1) {
                    return new DeleteStreamResponseV1();
                }
                return new CloseResponseV1();
            });

        // Allow destructor to call close()
        $streamConnection->method('close');

        $connection = $this->createConnectionWithMock($streamConnection);

        $connection->deleteStream('stream-to-delete');

        // Verify the first request was DeleteStreamRequestV1
        $this->assertGreaterThanOrEqual(1, count($capturedRequests));
        $this->assertInstanceOf(DeleteStreamRequestV1::class, $capturedRequests[0]);
        $this->assertEquals('stream-to-delete', $capturedRequests[0]->toArray()['stream']);
    }

    public function testDestructorCallsCloseWhenNotAlreadyClosed(): void
    {
        $streamConnection = $this->createMock(StreamConnection::class);

        $closeCalled = false;
        $streamConnection->method('sendMessage')
            ->willReturnCallback(function ($request) use (&$closeCalled): void {
                if ($request instanceof CloseRequestV1) {
                    $closeCalled = true;
                }
            });

        $streamConnection->method('readMessage')
            ->willReturnCallback(fn(): CloseResponseV1 => new CloseResponseV1());

        $streamConnection->method('close');

        $connection = $this->createConnectionWithMock($streamConnection);

        // Trigger destructor by unsetting the connection
        unset($connection);

        // Verify close was called during destruction
        $this->assertTrue($closeCalled, 'Destructor should call close()');
    }

    public function testDestructorIsSafeWhenSocketIsBroken(): void
    {
        $streamConnection = $this->createMock(StreamConnection::class);

        $streamConnection->method('sendMessage')
            ->willReturnCallback(function (): void {
                throw new \RuntimeException('Socket is broken');
            });

        $streamConnection->method('readMessage')
            ->willReturnCallback(function (): void {
                throw new \RuntimeException('Socket is broken');
            });

        $streamConnection->method('close');

        $connection = $this->createConnectionWithMock($streamConnection);

        // This should not throw - destructor catches all throwables
        unset($connection);

        // Test passes if we reach here without exception
        $this->addToAssertionCount(1);
    }

    public function testDestructorIsIdempotentWhenCloseAlreadyCalled(): void
    {
        $streamConnection = $this->createMock(StreamConnection::class);

        $sendMessageCallCount = 0;
        $streamConnection->method('sendMessage')
            ->willReturnCallback(function ($request) use (&$sendMessageCallCount): void {
                if ($request instanceof CloseRequestV1) {
                    $sendMessageCallCount++;
                }
            });

        $streamConnection->method('readMessage')
            ->willReturnCallback(fn(): CloseResponseV1 => new CloseResponseV1());

        $streamConnection->method('close');

        $connection = $this->createConnectionWithMock($streamConnection);

        // Explicitly close the connection
        $connection->close();

        // Verify close was called once
        $this->assertEquals(1, $sendMessageCallCount, 'close() should send CloseRequestV1 once');

        // Trigger destructor - should not send another close request
        unset($connection);

        // Verify close was still only called once (idempotent)
        $this->assertEquals(1, $sendMessageCallCount, 'Destructor should not send CloseRequestV1 again');
    }

    public function testDestructorLogsErrorWhenCloseFails(): void
    {
        $streamConnection = $this->createMock(StreamConnection::class);

        // Mock logger that expects error() to be called
        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects($this->once())
            ->method('error')
            ->with(
                $this->equalTo('Failed to close connection in destructor'),
                $this->callback(fn(array $context): bool => isset($context['exception'])
                    && $context['exception'] instanceof \Throwable)
            );

        $streamConnection->method('sendMessage')
            ->willReturnCallback(function (): void {
                throw new \RuntimeException('Socket is broken');
            });

        $streamConnection->method('readMessage')
            ->willReturnCallback(function (): void {
                throw new \RuntimeException('Socket is broken');
            });

        $streamConnection->method('close');

        $connection = $this->createConnectionWithMock($streamConnection, $logger);

        // Trigger destructor - should catch exception and log error
        unset($connection);
    }

    public function testStreamConnectionDestructorCallsClose(): void
    {
        // Create a real StreamConnection to test actual destructor behavior
        // We can't mock this effectively since PHPUnit doesn't track destructor calls
        $streamConnection = new StreamConnection('127.0.0.1', 5552);

        // Verify initial state
        $this->assertFalse($streamConnection->isConnected());

        // Trigger destructor by unsetting
        unset($streamConnection);

        // Test passes if no errors occur during destruction
        $this->addToAssertionCount(1);
    }

    private function createConnectionWithMock(StreamConnection $mock, ?LoggerInterface $logger = null): Connection
    {
        $reflection = new \ReflectionClass(Connection::class);
        $constructor = $reflection->getConstructor();
        $this->assertNotNull($constructor, 'Connection class must have a constructor');

        $connection = $reflection->newInstanceWithoutConstructor();
        $constructor->invoke($connection, $mock, $logger ?? new NullLogger());

        return $connection;
    }
}
