<?php

declare(strict_types=1);

namespace CrazyGoat\RabbitStream\Tests\Client;

use CrazyGoat\RabbitStream\Client\Producer;
use CrazyGoat\RabbitStream\Request\PublishRequestV1;
use CrazyGoat\RabbitStream\Request\QueryPublisherSequenceRequestV1;
use CrazyGoat\RabbitStream\StreamConnection;
use PHPUnit\Framework\TestCase;

class ProducerTest extends TestCase
{
    public function testSendAcceptsOptionalWriteTimeout(): void
    {
        $connection = $this->createMock(StreamConnection::class);
        $connection->expects($this->any())->method('registerPublisher');
        $connection->expects($this->any())->method('readMessage')->willReturn(new \stdClass());

        $capturedTimeout = null;
        $connection->expects($this->any())
            ->method('sendMessage')
            ->willReturnCallback(function ($request, $timeout) use (&$capturedTimeout): null {
                $capturedTimeout = $timeout;
                return null;
            });

        $producer = new Producer($connection, 'test-stream', 1);

        // Test method signature accepts optional timeout
        $reflection = new \ReflectionMethod($producer, 'send');
        $params = $reflection->getParameters();

        $this->assertCount(2, $params);
        $this->assertEquals('message', $params[0]->getName());
        $this->assertEquals('timeout', $params[1]->getName());
        $this->assertTrue($params[1]->isOptional());
        $this->assertNull($params[1]->getDefaultValue());

        // Test calling with timeout passes it to connection
        $producer->send('test', 0.5);
        $this->assertEquals(0.5, $capturedTimeout);
    }

    public function testSendBatchAcceptsOptionalWriteTimeout(): void
    {
        $connection = $this->createMock(StreamConnection::class);
        $connection->expects($this->any())->method('registerPublisher');
        $connection->expects($this->any())->method('readMessage')->willReturn(new \stdClass());

        $capturedTimeout = null;
        $connection->expects($this->any())
            ->method('sendMessage')
            ->willReturnCallback(function ($request, $timeout) use (&$capturedTimeout): null {
                $capturedTimeout = $timeout;
                return null;
            });

        $producer = new Producer($connection, 'test-stream', 1);

        // Test method signature accepts optional timeout
        $reflection = new \ReflectionMethod($producer, 'sendBatch');
        $params = $reflection->getParameters();

        $this->assertCount(2, $params);
        $this->assertEquals('messages', $params[0]->getName());
        $this->assertEquals('timeout', $params[1]->getName());
        $this->assertTrue($params[1]->isOptional());
        $this->assertNull($params[1]->getDefaultValue());

        // Test calling with timeout passes it to connection
        $producer->sendBatch(['test1', 'test2'], 1.0);
        $this->assertEquals(1.0, $capturedTimeout);
    }

    public function testWaitForConfirmsAcceptsFloatTimeout(): void
    {
        $connection = $this->createMock(StreamConnection::class);
        $connection->expects($this->any())->method('registerPublisher');
        $connection->expects($this->any())->method('sendMessage');
        $connection->expects($this->any())->method('readMessage')->willReturn(new \stdClass());

        $capturedTimeout = null;
        $connection->expects($this->any())
            ->method('readLoop')
            ->willReturnCallback(function ($maxFrames, $timeout) use (&$capturedTimeout): null {
                $capturedTimeout = $timeout;
                return null;
            });

        $producer = new Producer($connection, 'test-stream', 1);

        // Test method signature accepts float timeout
        $reflection = new \ReflectionMethod($producer, 'waitForConfirms');
        $params = $reflection->getParameters();

        $this->assertCount(1, $params);
        $this->assertEquals('timeout', $params[0]->getName());
        $this->assertEquals('float', $params[0]->getType()->getName());
        $this->assertEquals(5.0, $params[0]->getDefaultValue());

        // Test calling with float timeout passes it to connection
        $producer->send('test');
        try {
            $producer->waitForConfirms(timeout: 0.1);
        } catch (\RuntimeException) {
            // Expected - timeout
        }
        $this->assertNotNull($capturedTimeout);
        $this->assertLessThanOrEqual(0.1, $capturedTimeout);
    }

    public function testWaitForConfirmsResolvesWhenConfirmsArrive(): void
    {
        $connection = $this->createMock(StreamConnection::class);

        $registeredCallbacks = null;
        $connection->expects($this->once())
            ->method('registerPublisher')
            ->with(1, $this->anything(), $this->anything())
            ->willReturnCallback(function ($id, $onConfirm, $onError) use (&$registeredCallbacks): void {
                $registeredCallbacks = ['onConfirm' => $onConfirm, 'onError' => $onError];
            });

        $connection->expects($this->any())
            ->method('sendMessage');

        $connection->expects($this->any())
            ->method('readMessage')
            ->willReturn(new \stdClass());

        $connection->expects($this->once())
            ->method('readLoop')
            ->willReturnCallback(function () use (&$registeredCallbacks): void {
                ($registeredCallbacks['onConfirm'])([0]);
            });

        $producer = new Producer($connection, 'test-stream', 1);
        $producer->send('test message');

        $producer->waitForConfirms(timeout: 1);

        $this->assertTrue(true);
    }

    public function testSendBatchCreatesSingleRequestWithMultipleMessages(): void
    {
        $connection = $this->createMock(StreamConnection::class);
        $capturedRequest = null;

        // Allow constructor calls (declare() sends DeclarePublisherRequestV1 and reads response)
        $connection->expects($this->exactly(2))
            ->method('sendMessage')
            ->with($this->callback(function ($request) use (&$capturedRequest): true {
                if ($request instanceof PublishRequestV1) {
                    $capturedRequest = $request;
                }
                return true;
            }));

        // Only declare() reads response, sendBatch() is fire-and-forget like send()
        $connection->expects($this->once())
            ->method('readMessage');

        $producer = new Producer($connection, 'test-stream', 1);
        $producer->sendBatch(['msg1', 'msg2', 'msg3']);

        // Verify the request has 3 messages
        $this->assertNotNull($capturedRequest, 'PublishRequestV1 should have been captured');
        $this->assertInstanceOf(PublishRequestV1::class, $capturedRequest);
        /** @var PublishRequestV1 $capturedRequest */
        $this->assertSame(3, count($capturedRequest->toArray()['messages']), 'Should have 3 messages');
    }

    public function testWaitForConfirmsThrowsOnTimeout(): void
    {
        $connection = $this->createMock(StreamConnection::class);

        $connection->expects($this->any())
            ->method('registerPublisher');

        $connection->expects($this->any())
            ->method('sendMessage');

        $connection->expects($this->any())
            ->method('readMessage')
            ->willReturn(new \stdClass());

        $producer = new Producer($connection, 'test-stream', 1);
        $producer->send('test message');

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Timed out waiting for 1 publish confirms');

        $producer->waitForConfirms(timeout: 0);
    }

    public function testGetLastPublishingIdReturnsCorrectValue(): void
    {
        $connection = $this->createMock(StreamConnection::class);
        $connection->expects($this->any())->method('registerPublisher');
        $connection->expects($this->any())->method('sendMessage');
        $connection->expects($this->any())->method('readMessage');

        $producer = new Producer($connection, 'test-stream', 1);

        // Before any sends
        $this->assertEquals(-1, $producer->getLastPublishingId());

        $producer->send('msg1');
        $this->assertEquals(0, $producer->getLastPublishingId());

        $producer->send('msg2');
        $this->assertEquals(1, $producer->getLastPublishingId());
    }

    public function testSendIncrementsPendingConfirms(): void
    {
        $connection = $this->createMock(StreamConnection::class);

        $registeredCallbacks = null;
        $connection->expects($this->once())
            ->method('registerPublisher')
            ->willReturnCallback(function ($id, $onConfirm, $onError) use (&$registeredCallbacks): void {
                $registeredCallbacks = ['onConfirm' => $onConfirm, 'onError' => $onError];
            });

        $connection->expects($this->any())->method('sendMessage');
        $connection->expects($this->any())->method('readMessage')->willReturn(new \stdClass());

        $readLoopCalled = false;
        $connection->expects($this->once())
            ->method('readLoop')
            ->willReturnCallback(function () use (&$registeredCallbacks, &$readLoopCalled): void {
                $readLoopCalled = true;
                ($registeredCallbacks['onConfirm'])([0, 1, 2]);
            });

        $producer = new Producer($connection, 'test-stream', 1);
        $producer->send('msg1');
        $producer->send('msg2');
        $producer->send('msg3');

        $producer->waitForConfirms(timeout: 1);

        $this->assertTrue($readLoopCalled, 'readLoop should have been called to wait for confirms');
    }

    public function testSendBatchWithEmptyArrayDoesNotSend(): void
    {
        $connection = $this->createMock(StreamConnection::class);
        $connection->expects($this->any())->method('registerPublisher');
        $connection->expects($this->any())->method('readMessage')->willReturn(new \stdClass());

        $connection->expects($this->once())
            ->method('sendMessage');

        $producer = new Producer($connection, 'test-stream', 1);
        $producer->sendBatch([]);
    }

    public function testQuerySequenceThrowsForUnnamedProducer(): void
    {
        $connection = $this->createMock(StreamConnection::class);
        $connection->expects($this->any())->method('registerPublisher');
        $connection->expects($this->any())->method('sendMessage');
        $connection->expects($this->any())->method('readMessage');

        $producer = new Producer($connection, 'test-stream', 1); // No name provided

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Cannot query sequence for unnamed producer');

        $producer->querySequence();
    }

    public function testQuerySequenceReturnsSequenceForNamedProducer(): void
    {
        $connection = $this->createMock(StreamConnection::class);
        $connection->expects($this->any())->method('registerPublisher');

        $mockResponse = $this->createMock(\CrazyGoat\RabbitStream\Response\QueryPublisherSequenceResponseV1::class);
        $mockResponse->method('getSequence')->willReturn(42);

        // Constructor calls sendMessage with DeclarePublisherRequestV1
        // querySequence calls sendMessage with QueryPublisherSequenceRequestV1
        $capturedRequest = null;
        $connection->expects($this->exactly(2))
            ->method('sendMessage')
            ->with($this->callback(function ($request) use (&$capturedRequest): true {
                if ($request instanceof QueryPublisherSequenceRequestV1) {
                    $capturedRequest = $request;
                }
                return true;
            }));

        $connection->expects($this->exactly(2))
            ->method('readMessage')
            ->willReturnOnConsecutiveCalls(
                new \stdClass(), // For DeclarePublisher response
                $mockResponse     // For QueryPublisherSequence response
            );

        $producer = new Producer($connection, 'test-stream', 1, 'my-producer');

        $sequence = $producer->querySequence();
        $this->assertEquals(42, $sequence);
        $this->assertNotNull($capturedRequest, 'QueryPublisherSequenceRequestV1 should have been sent');
    }
}
