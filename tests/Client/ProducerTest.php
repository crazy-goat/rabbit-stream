<?php

namespace CrazyGoat\RabbitStream\Tests\Client;

use CrazyGoat\RabbitStream\Client\Producer;
use CrazyGoat\RabbitStream\Request\PublishRequestV1;
use CrazyGoat\RabbitStream\StreamConnection;
use PHPUnit\Framework\TestCase;

class ProducerTest extends TestCase
{
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
}
