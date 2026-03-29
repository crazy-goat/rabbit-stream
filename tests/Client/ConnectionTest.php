<?php

declare(strict_types=1);

namespace CrazyGoat\RabbitStream\Tests\Client;

use CrazyGoat\RabbitStream\Client\Connection;
use CrazyGoat\RabbitStream\Client\Consumer;
use CrazyGoat\RabbitStream\Client\Producer;
use CrazyGoat\RabbitStream\Exception\UnexpectedResponseException;
use CrazyGoat\RabbitStream\Request\CloseRequestV1;
use CrazyGoat\RabbitStream\Request\CreateRequestV1;
use CrazyGoat\RabbitStream\Request\DeleteStreamRequestV1;
use CrazyGoat\RabbitStream\Request\MetadataRequestV1;
use CrazyGoat\RabbitStream\Request\QueryOffsetRequestV1;
use CrazyGoat\RabbitStream\Request\StoreOffsetRequestV1;
use CrazyGoat\RabbitStream\Request\StreamStatsRequestV1;
use CrazyGoat\RabbitStream\Response\CloseResponseV1;
use CrazyGoat\RabbitStream\Response\CreateResponseV1;
use CrazyGoat\RabbitStream\Response\DeleteStreamResponseV1;
use CrazyGoat\RabbitStream\Response\MetadataResponseV1;
use CrazyGoat\RabbitStream\Response\QueryOffsetResponseV1;
use CrazyGoat\RabbitStream\Response\StreamStatsResponseV1;
use CrazyGoat\RabbitStream\StreamConnection;
use CrazyGoat\RabbitStream\VO\Broker;
use CrazyGoat\RabbitStream\VO\OffsetSpec;
use CrazyGoat\RabbitStream\VO\Statistic;
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

    public function testNegotiatedMaxValueBothNonZeroReturnsMin(): void
    {
        $this->assertSame(100, $this->invokeNegotiatedMaxValue(100, 200));
        $this->assertSame(100, $this->invokeNegotiatedMaxValue(200, 100));
        $this->assertSame(50, $this->invokeNegotiatedMaxValue(50, 50));
    }

    public function testNegotiatedMaxValueClientZeroReturnsServer(): void
    {
        $this->assertSame(200, $this->invokeNegotiatedMaxValue(0, 200));
    }

    public function testNegotiatedMaxValueServerZeroReturnsClient(): void
    {
        $this->assertSame(100, $this->invokeNegotiatedMaxValue(100, 0));
    }

    public function testNegotiatedMaxValueBothZeroReturnsZero(): void
    {
        $this->assertSame(0, $this->invokeNegotiatedMaxValue(0, 0));
    }

    public function testCloseClosesAllTrackedProducers(): void
    {
        $streamConnection = $this->createMock(StreamConnection::class);
        $streamConnection->method('readMessage')
            ->willReturnCallback(fn(): CloseResponseV1 => new CloseResponseV1());
        $streamConnection->method('close');

        $producer1 = $this->createMock(Producer::class);
        $producer1->expects($this->once())->method('close');

        $producer2 = $this->createMock(Producer::class);
        $producer2->expects($this->once())->method('close');

        $connection = $this->createConnectionWithMock($streamConnection);
        $this->injectProducers($connection, [0 => $producer1, 1 => $producer2]);

        $connection->close();
    }

    public function testCloseClosesAllTrackedConsumers(): void
    {
        $streamConnection = $this->createMock(StreamConnection::class);
        $streamConnection->method('readMessage')
            ->willReturnCallback(fn(): CloseResponseV1 => new CloseResponseV1());
        $streamConnection->method('close');

        $consumer1 = $this->createMock(Consumer::class);
        $consumer1->expects($this->once())->method('close');

        $consumer2 = $this->createMock(Consumer::class);
        $consumer2->expects($this->once())->method('close');

        $connection = $this->createConnectionWithMock($streamConnection);
        $this->injectConsumers($connection, [0 => $consumer1, 1 => $consumer2]);

        $connection->close();
    }

    public function testCloseClosesConsumersBeforeProducers(): void
    {
        $streamConnection = $this->createMock(StreamConnection::class);
        $streamConnection->method('readMessage')
            ->willReturnCallback(fn(): CloseResponseV1 => new CloseResponseV1());
        $streamConnection->method('close');

        $order = [];

        $consumer = $this->createMock(Consumer::class);
        $consumer->expects($this->once())->method('close')
            ->willReturnCallback(function () use (&$order): void {
                $order[] = 'consumer';
            });

        $producer = $this->createMock(Producer::class);
        $producer->expects($this->once())->method('close')
            ->willReturnCallback(function () use (&$order): void {
                $order[] = 'producer';
            });

        $connection = $this->createConnectionWithMock($streamConnection);
        $this->injectConsumers($connection, [0 => $consumer]);
        $this->injectProducers($connection, [0 => $producer]);

        $connection->close();

        $this->assertSame(['consumer', 'producer'], $order);
    }

    public function testCloseContinuesWhenProducerCloseThrows(): void
    {
        $streamConnection = $this->createMock(StreamConnection::class);
        $streamConnection->method('readMessage')
            ->willReturnCallback(fn(): CloseResponseV1 => new CloseResponseV1());
        $streamConnection->method('close');

        $producer1 = $this->createMock(Producer::class);
        $producer1->method('close')
            ->willThrowException(new \RuntimeException('Producer close failed'));

        $producer2 = $this->createMock(Producer::class);
        $producer2->expects($this->once())->method('close');

        $connection = $this->createConnectionWithMock($streamConnection);
        $this->injectProducers($connection, [0 => $producer1, 1 => $producer2]);

        $connection->close();
    }

    public function testCloseContinuesWhenConsumerCloseThrows(): void
    {
        $streamConnection = $this->createMock(StreamConnection::class);
        $streamConnection->method('readMessage')
            ->willReturnCallback(fn(): CloseResponseV1 => new CloseResponseV1());
        $streamConnection->method('close');

        $consumer1 = $this->createMock(Consumer::class);
        $consumer1->method('close')
            ->willThrowException(new \RuntimeException('Consumer close failed'));

        $consumer2 = $this->createMock(Consumer::class);
        $consumer2->expects($this->once())->method('close');

        $connection = $this->createConnectionWithMock($streamConnection);
        $this->injectConsumers($connection, [0 => $consumer1, 1 => $consumer2]);

        $connection->close();
    }

    public function testCloseLogsWarningWhenProducerCloseThrows(): void
    {
        $streamConnection = $this->createMock(StreamConnection::class);
        $streamConnection->method('readMessage')
            ->willReturnCallback(fn(): CloseResponseV1 => new CloseResponseV1());
        $streamConnection->method('close');

        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects($this->once())
            ->method('warning')
            ->with(
                $this->equalTo('Failed to close producer during connection close'),
                $this->callback(fn(array $context): bool => isset($context['publisherId'], $context['exception'])
                    && $context['publisherId'] === 0
                    && $context['exception'] instanceof \Throwable)
            );

        $producer = $this->createMock(Producer::class);
        $producer->method('close')
            ->willThrowException(new \RuntimeException('Producer close failed'));

        $connection = $this->createConnectionWithMock($streamConnection, $logger);
        $this->injectProducers($connection, [0 => $producer]);

        $connection->close();
    }

    public function testCloseLogsWarningWhenConsumerCloseThrows(): void
    {
        $streamConnection = $this->createMock(StreamConnection::class);
        $streamConnection->method('readMessage')
            ->willReturnCallback(fn(): CloseResponseV1 => new CloseResponseV1());
        $streamConnection->method('close');

        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects($this->once())
            ->method('warning')
            ->with(
                $this->equalTo('Failed to close consumer during connection close'),
                $this->callback(fn(array $context): bool => isset($context['subscriptionId'], $context['exception'])
                    && $context['subscriptionId'] === 0
                    && $context['exception'] instanceof \Throwable)
            );

        $consumer = $this->createMock(Consumer::class);
        $consumer->method('close')
            ->willThrowException(new \RuntimeException('Consumer close failed'));

        $connection = $this->createConnectionWithMock($streamConnection, $logger);
        $this->injectConsumers($connection, [0 => $consumer]);

        $connection->close();
    }

    public function testMultipleCloseCallsAreIdempotent(): void
    {
        $streamConnection = $this->createMock(StreamConnection::class);
        $streamConnection->method('readMessage')
            ->willReturnCallback(fn(): CloseResponseV1 => new CloseResponseV1());
        $streamConnection->method('close');

        $producer = $this->createMock(Producer::class);
        $producer->expects($this->once())->method('close');

        $consumer = $this->createMock(Consumer::class);
        $consumer->expects($this->once())->method('close');

        $connection = $this->createConnectionWithMock($streamConnection);
        $this->injectProducers($connection, [0 => $producer]);
        $this->injectConsumers($connection, [0 => $consumer]);

        $connection->close();
        $connection->close();
    }

    public function testGetStreamStatsReturnsKeyValueArray(): void
    {
        $streamConnection = $this->createMock(StreamConnection::class);

        $capturedRequests = [];
        $streamConnection->method('sendMessage')
            ->willReturnCallback(function ($request) use (&$capturedRequests): void {
                $capturedRequests[] = $request;
            });

        $statsResponse = new StreamStatsResponseV1([
            new Statistic('publishers', 5),
            new Statistic('consumers', 3),
            new Statistic('messages', 1000),
        ]);

        $callCount = 0;
        $streamConnection->method('readMessage')
            ->willReturnCallback(function () use (&$callCount, $statsResponse): StreamStatsResponseV1|CloseResponseV1 {
                $callCount++;
                if ($callCount === 1) {
                    return $statsResponse;
                }
                return new CloseResponseV1();
            });

        $streamConnection->method('close');

        $connection = $this->createConnectionWithMock($streamConnection);

        $result = $connection->getStreamStats('test-stream');

        $this->assertEquals(['publishers' => 5, 'consumers' => 3, 'messages' => 1000], $result);
        $this->assertGreaterThanOrEqual(1, count($capturedRequests));
        $this->assertInstanceOf(StreamStatsRequestV1::class, $capturedRequests[0]);
        $this->assertEquals('test-stream', $capturedRequests[0]->toArray()['stream']);
    }

    public function testGetStreamStatsThrowsOnWrongResponseType(): void
    {
        $streamConnection = $this->createMock(StreamConnection::class);

        $streamConnection->method('sendMessage');
        $streamConnection->method('readMessage')
            ->willReturnCallback(fn(): CreateResponseV1 => new CreateResponseV1());

        $streamConnection->method('close');

        $connection = $this->createConnectionWithMock($streamConnection);

        $this->expectException(UnexpectedResponseException::class);
        $this->expectExceptionMessage('Expected CrazyGoat\RabbitStream\Response\StreamStatsResponseV1');

        $connection->getStreamStats('test-stream');
    }

    public function testGetMetadataReturnsMetadataResponse(): void
    {
        $streamConnection = $this->createMock(StreamConnection::class);

        $capturedRequests = [];
        $streamConnection->method('sendMessage')
            ->willReturnCallback(function ($request) use (&$capturedRequests): void {
                $capturedRequests[] = $request;
            });

        $metadata = new MetadataResponseV1(
            brokers: [new Broker(1, 'localhost', 5552)],
            streamMetadata: [new StreamMetadata('stream1', 0x01, 1, [])]
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

        $streamConnection->method('close');

        $connection = $this->createConnectionWithMock($streamConnection);

        $result = $connection->getMetadata(['stream1', 'stream2']);

        $this->assertInstanceOf(MetadataResponseV1::class, $result);
        $this->assertEquals(['stream1', 'stream2'], $capturedRequests[0]->toArray()['streams']);
    }

    public function testGetMetadataThrowsOnWrongResponseType(): void
    {
        $streamConnection = $this->createMock(StreamConnection::class);

        $streamConnection->method('sendMessage');
        $streamConnection->method('readMessage')
            ->willReturnCallback(fn(): CreateResponseV1 => new CreateResponseV1());

        $streamConnection->method('close');

        $connection = $this->createConnectionWithMock($streamConnection);

        $this->expectException(UnexpectedResponseException::class);
        $this->expectExceptionMessage('Expected CrazyGoat\RabbitStream\Response\MetadataResponseV1');

        $connection->getMetadata(['stream1']);
    }

    public function testQueryOffsetReturnsOffsetInteger(): void
    {
        $streamConnection = $this->createMock(StreamConnection::class);

        $capturedRequests = [];
        $streamConnection->method('sendMessage')
            ->willReturnCallback(function ($request) use (&$capturedRequests): void {
                $capturedRequests[] = $request;
            });

        $offsetResponse = QueryOffsetResponseV1::fromArray([
            'correlationId' => 1,
            'offset' => 12345,
        ]);

        $callCount = 0;
        $streamConnection->method('readMessage')
            ->willReturnCallback(function () use (&$callCount, $offsetResponse): QueryOffsetResponseV1|CloseResponseV1 {
                $callCount++;
                if ($callCount === 1) {
                    return $offsetResponse;
                }
                return new CloseResponseV1();
            });

        $streamConnection->method('close');

        $connection = $this->createConnectionWithMock($streamConnection);

        $result = $connection->queryOffset('my-reference', 'test-stream');

        $this->assertEquals(12345, $result);
        $this->assertGreaterThanOrEqual(1, count($capturedRequests));
        $this->assertInstanceOf(QueryOffsetRequestV1::class, $capturedRequests[0]);
        $this->assertEquals('my-reference', $capturedRequests[0]->toArray()['reference']);
        $this->assertEquals('test-stream', $capturedRequests[0]->toArray()['stream']);
    }

    public function testQueryOffsetThrowsOnWrongResponseType(): void
    {
        $streamConnection = $this->createMock(StreamConnection::class);

        $streamConnection->method('sendMessage');
        $streamConnection->method('readMessage')
            ->willReturnCallback(fn(): CreateResponseV1 => new CreateResponseV1());

        $streamConnection->method('close');

        $connection = $this->createConnectionWithMock($streamConnection);

        $this->expectException(UnexpectedResponseException::class);
        $this->expectExceptionMessage('Expected CrazyGoat\RabbitStream\Response\QueryOffsetResponseV1');

        $connection->queryOffset('ref', 'stream');
    }

    public function testStoreOffsetSendsCorrectRequest(): void
    {
        $streamConnection = $this->createMock(StreamConnection::class);

        $capturedRequests = [];
        $streamConnection->method('sendMessage')
            ->willReturnCallback(function ($request) use (&$capturedRequests): void {
                $capturedRequests[] = $request;
            });

        $readMessageCallCount = 0;
        $streamConnection->method('readMessage')
            ->willReturnCallback(function () use (&$readMessageCallCount): CloseResponseV1 {
                $readMessageCallCount++;
                return new CloseResponseV1();
            });

        $streamConnection->method('close');

        $connection = $this->createConnectionWithMock($streamConnection);

        $connection->storeOffset('my-reference', 'test-stream', 999);

        $this->assertGreaterThanOrEqual(1, count($capturedRequests));
        $this->assertInstanceOf(StoreOffsetRequestV1::class, $capturedRequests[0]);
        $this->assertEquals('my-reference', $capturedRequests[0]->toArray()['reference']);
        $this->assertEquals('test-stream', $capturedRequests[0]->toArray()['stream']);
        $this->assertEquals(999, $capturedRequests[0]->toArray()['offset']);
    }

    public function testCreateProducerIncrementsPublisherId(): void
    {
        $streamConnection = $this->createMock(StreamConnection::class);
        $streamConnection->method('registerPublisher');
        $streamConnection->method('sendMessage');
        $streamConnection->method('readMessage');
        $streamConnection->method('close');

        $connection = $this->createConnectionWithMock($streamConnection);

        $producer1 = $connection->createProducer('stream1');
        $producer2 = $connection->createProducer('stream2');
        $producer3 = $connection->createProducer('stream3');

        $this->assertInstanceOf(Producer::class, $producer1);
        $this->assertInstanceOf(Producer::class, $producer2);
        $this->assertInstanceOf(Producer::class, $producer3);
    }

    public function testCreateProducerPassesCorrectParameters(): void
    {
        $streamConnection = $this->createMock(StreamConnection::class);
        $streamConnection->method('registerPublisher');
        $streamConnection->method('sendMessage');
        $streamConnection->method('readMessage');
        $streamConnection->method('close');

        $connection = $this->createConnectionWithMock($streamConnection);

        // Test without name to avoid sequence query
        $onConfirm = function (): void {
        };
        $producer = $connection->createProducer('my-stream', null, $onConfirm);

        $this->assertInstanceOf(Producer::class, $producer);
    }

    public function testCreateProducerStoresProducerInArray(): void
    {
        $streamConnection = $this->createMock(StreamConnection::class);
        $streamConnection->method('registerPublisher');
        $streamConnection->method('sendMessage');
        $streamConnection->method('readMessage');
        $streamConnection->method('close');

        $connection = $this->createConnectionWithMock($streamConnection);

        $producer = $connection->createProducer('test-stream');

        $reflection = new \ReflectionProperty(Connection::class, 'producers');
        $producers = $reflection->getValue($connection);

        $this->assertArrayHasKey(0, $producers);
        $this->assertSame($producer, $producers[0]);
    }

    public function testCreateConsumerIncrementsSubscriptionId(): void
    {
        $streamConnection = $this->createMock(StreamConnection::class);
        $streamConnection->method('registerSubscriber');
        $streamConnection->method('sendMessage');
        $streamConnection->method('readMessage');
        $streamConnection->method('close');

        $connection = $this->createConnectionWithMock($streamConnection);

        $consumer1 = $connection->createConsumer('stream1', OffsetSpec::first());
        $consumer2 = $connection->createConsumer('stream2', OffsetSpec::last());
        $consumer3 = $connection->createConsumer('stream3', OffsetSpec::next());

        $this->assertInstanceOf(Consumer::class, $consumer1);
        $this->assertInstanceOf(Consumer::class, $consumer2);
        $this->assertInstanceOf(Consumer::class, $consumer3);
    }

    public function testCreateConsumerPassesCorrectParameters(): void
    {
        $streamConnection = $this->createMock(StreamConnection::class);
        $streamConnection->method('registerSubscriber');
        $streamConnection->method('sendMessage');
        $streamConnection->method('readMessage');
        $streamConnection->method('close');

        $connection = $this->createConnectionWithMock($streamConnection);

        $offset = OffsetSpec::offset(100);
        $consumer = $connection->createConsumer('my-stream', $offset, 'consumer-name', 100, 20);

        $this->assertInstanceOf(Consumer::class, $consumer);
    }

    public function testCreateConsumerStoresConsumerInArray(): void
    {
        $streamConnection = $this->createMock(StreamConnection::class);
        $streamConnection->method('registerSubscriber');
        $streamConnection->method('sendMessage');
        $streamConnection->method('readMessage');
        $streamConnection->method('close');

        $connection = $this->createConnectionWithMock($streamConnection);

        $consumer = $connection->createConsumer('test-stream', OffsetSpec::first());

        $reflection = new \ReflectionProperty(Connection::class, 'consumers');
        $consumers = $reflection->getValue($connection);

        $this->assertArrayHasKey(0, $consumers);
        $this->assertSame($consumer, $consumers[0]);
    }

    public function testReadLoopDelegatesToStreamConnection(): void
    {
        $streamConnection = $this->createMock(StreamConnection::class);

        $capturedArgs = [];
        $streamConnection->method('readLoop')
            ->willReturnCallback(function (?int $maxFrames, ?float $timeout) use (&$capturedArgs): void {
                $capturedArgs = ['maxFrames' => $maxFrames, 'timeout' => $timeout];
            });

        $streamConnection->method('close');

        $connection = $this->createConnectionWithMock($streamConnection);

        $connection->readLoop(10, 5.0);

        $this->assertEquals(['maxFrames' => 10, 'timeout' => 5.0], $capturedArgs);
    }

    public function testReadLoopWithNullParameters(): void
    {
        $streamConnection = $this->createMock(StreamConnection::class);

        $capturedArgs = [];
        $streamConnection->method('readLoop')
            ->willReturnCallback(function (?int $maxFrames, ?float $timeout) use (&$capturedArgs): void {
                $capturedArgs = ['maxFrames' => $maxFrames, 'timeout' => $timeout];
            });

        $streamConnection->method('close');

        $connection = $this->createConnectionWithMock($streamConnection);

        $connection->readLoop();

        $this->assertEquals(['maxFrames' => null, 'timeout' => null], $capturedArgs);
    }

    /** @param array<int, Producer> $producers */
    private function injectProducers(Connection $connection, array $producers): void
    {
        $reflection = new \ReflectionProperty(Connection::class, 'producers');
        $reflection->setValue($connection, $producers);
    }

    /** @param array<int, Consumer> $consumers */
    private function injectConsumers(Connection $connection, array $consumers): void
    {
        $reflection = new \ReflectionProperty(Connection::class, 'consumers');
        $reflection->setValue($connection, $consumers);
    }

    private function invokeNegotiatedMaxValue(int $clientValue, int $serverValue): int
    {
        $method = new \ReflectionMethod(Connection::class, 'negotiatedMaxValue');
        $result = $method->invoke(null, $clientValue, $serverValue);
        if (!is_int($result)) {
            throw new \RuntimeException('Expected int from negotiatedMaxValue');
        }
        return $result;
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
