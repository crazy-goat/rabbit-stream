<?php

namespace CrazyGoat\RabbitStream\Tests\Client;

use CrazyGoat\RabbitStream\Client\Producer;
use CrazyGoat\RabbitStream\Request\PublishRequestV1;
use CrazyGoat\RabbitStream\Request\QueryPublisherSequenceRequestV1;
use CrazyGoat\RabbitStream\StreamConnection;
use PHPUnit\Framework\TestCase;

class ProducerTest extends TestCase
{
    public function testWaitForConfirmsResolvesWhenConfirmsArrive(): void
    {
        $connection = $this->createMock(StreamConnection::class);
        
        // Simulate confirm callback being triggered
        $registeredCallbacks = null;
        $connection->expects($this->once())
            ->method('registerPublisher')
            ->with(1, $this->anything(), $this->anything())
            ->willReturnCallback(function ($id, $onConfirm, $onError) use (&$registeredCallbacks) {
                $registeredCallbacks = ['onConfirm' => $onConfirm, 'onError' => $onError];
            });
        
        $connection->expects($this->any())
            ->method('sendMessage');
        
        $connection->expects($this->any())
            ->method('readMessage')
            ->willReturnCallback(function () use (&$registeredCallbacks) {
                // Simulate confirm arriving
                if ($registeredCallbacks !== null) {
                    ($registeredCallbacks['onConfirm'])([0]);
                }
                return new \stdClass();
            });
        
        $producer = new Producer($connection, 'test-stream', 1);
        $producer->send('test message');
        
        // Should not throw
        $producer->waitForConfirms(timeout: 1);
        
        $this->assertTrue(true); // If we get here, test passed
    }
    public function testSendBatchCreatesSingleRequestWithMultipleMessages(): void
    {
        $connection = $this->createMock(StreamConnection::class);
        $capturedRequest = null;
        
        // Allow constructor calls (declare() sends DeclarePublisherRequestV1 and reads response)
        $connection->expects($this->exactly(2))
            ->method('sendMessage')
            ->with($this->callback(function ($request) use (&$capturedRequest) {
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
            ->with($this->callback(function ($request) use (&$capturedRequest) {
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
