<?php

declare(strict_types=1);

namespace CrazyGoat\RabbitStream\Tests\Client;

use CrazyGoat\RabbitStream\Client\AmqpMessageEncoder;
use CrazyGoat\RabbitStream\Client\Producer;
use CrazyGoat\RabbitStream\Exception\InvalidArgumentException;
use CrazyGoat\RabbitStream\Exception\TimeoutException;
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
            ->willReturnCallback(function ($request, $timeout) use (&$capturedTimeout) {
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

    public function testSendEncodesMessageBodyAsAmqpDataSection(): void
    {
        $connection = $this->createMock(StreamConnection::class);
        $connection->expects($this->any())->method('registerPublisher');
        $connection->expects($this->any())->method('readMessage')->willReturn(new \stdClass());

        $capturedRequest = null;
        $connection->expects($this->any())
            ->method('sendMessage')
            ->willReturnCallback(function ($request, $timeout) use (&$capturedRequest) {
                if ($request instanceof PublishRequestV1) {
                    $capturedRequest = $request;
                }
                return null;
            });

        $producer = new Producer($connection, 'test-stream', 1);
        $producer->send('hello');

        $this->assertInstanceOf(PublishRequestV1::class, $capturedRequest);
        /** @var PublishRequestV1 $capturedRequest */
        $requestArray = $capturedRequest->toArray();
        $messages = is_array($requestArray['messages']) ? $requestArray['messages'] : [];
        $this->assertCount(1, $messages);

        // Guard the Producer -> AmqpMessageEncoder wiring on the single-message
        // path: send() must AMQP-encode the body, so a future refactor that
        // drops the encode call fails the unit suite (not only Docker-gated E2E).
        $this->assertIsArray($messages[0]);
        $this->assertArrayHasKey('data', $messages[0]);
        $this->assertSame(
            AmqpMessageEncoder::encodeDataSection('hello'),
            $messages[0]['data'],
            'send() must AMQP-encode the message body'
        );
    }

    public function testSendBatchAcceptsOptionalWriteTimeout(): void
    {
        $connection = $this->createMock(StreamConnection::class);
        $connection->expects($this->any())->method('registerPublisher');
        $connection->expects($this->any())->method('readMessage')->willReturn(new \stdClass());

        $capturedTimeout = null;
        $connection->expects($this->any())
            ->method('sendMessage')
            ->willReturnCallback(function ($request, $timeout) use (&$capturedTimeout) {
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
            ->willReturnCallback(function ($maxFrames, $timeout) use (&$capturedTimeout): int {
                $capturedTimeout = $timeout;
                return 1;
            });

        $producer = new Producer($connection, 'test-stream', 1);

        // Test method signature accepts float timeout
        $reflection = new \ReflectionMethod($producer, 'waitForConfirms');
        $params = $reflection->getParameters();

        $this->assertCount(1, $params);
        $this->assertEquals('timeout', $params[0]->getName());
        $type = $params[0]->getType();
        $this->assertInstanceOf(\ReflectionNamedType::class, $type);
        $this->assertEquals('float', $type->getName());
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

        /** @var array{onConfirm: callable, onError: callable}|null $registeredCallbacks */
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
            ->willReturnCallback(function () use (&$registeredCallbacks): int {
                $this->assertNotNull($registeredCallbacks, 'registerPublisher callback must have been called');
                ($registeredCallbacks['onConfirm'])([0]);
                return 1;
            });

        $producer = new Producer($connection, 'test-stream', 1);
        $producer->send('test message');

        $producer->waitForConfirms(timeout: 1);

        $this->addToAssertionCount(1);
    }

    public function testSendBatchCreatesSingleRequestWithMultipleMessages(): void
    {
        $connection = $this->createMock(StreamConnection::class);
        $capturedRequest = null;

        // Allow constructor calls (declare() sends DeclarePublisherRequestV1 and reads response)
        $connection->expects($this->exactly(2))
            ->method('sendMessage')
            ->with($this->callback(function ($request) use (&$capturedRequest): bool {
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
        $requestArray = $capturedRequest->toArray();
        $messages = is_array($requestArray['messages']) ? $requestArray['messages'] : [];
        $this->assertSame(3, count($messages), 'Should have 3 messages');

        // Guard the Producer -> AmqpMessageEncoder wiring: each published body
        // must be the AMQP 1.0 Data-section-encoded form of the plain input, so
        // a future refactor that drops the encode call fails the unit suite
        // (not only the Docker-gated E2E suite).
        $this->assertIsArray($messages[0]);
        $this->assertArrayHasKey('data', $messages[0]);
        $this->assertSame(
            AmqpMessageEncoder::encodeDataSection('msg1'),
            $messages[0]['data'],
            'sendBatch() must AMQP-encode each message body'
        );
        $this->assertIsArray($messages[2]);
        $this->assertArrayHasKey('data', $messages[2]);
        $this->assertSame(
            AmqpMessageEncoder::encodeDataSection('msg3'),
            $messages[2]['data']
        );
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
        $this->assertNull($producer->getLastPublishingId());

        $producer->send('msg1');
        $this->assertEquals(0, $producer->getLastPublishingId());

        $producer->send('msg2');
        $this->assertEquals(1, $producer->getLastPublishingId());
    }

    public function testSendIncrementsPendingConfirms(): void
    {
        $connection = $this->createMock(StreamConnection::class);

        /** @var array{onConfirm: callable, onError: callable}|null $registeredCallbacks */
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
            ->willReturnCallback(function () use (&$registeredCallbacks, &$readLoopCalled): int {
                $readLoopCalled = true;
                $this->assertNotNull($registeredCallbacks, 'registerPublisher callback must have been called');
                ($registeredCallbacks['onConfirm'])([0, 1, 2]);
                return 1;
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

    public function testWaitForConfirmsReturnsImmediatelyWhenNoPendingConfirms(): void
    {
        $connection = $this->createMock(StreamConnection::class);
        $connection->expects($this->any())->method('registerPublisher');
        $connection->expects($this->any())->method('sendMessage');
        $connection->expects($this->any())->method('readMessage')->willReturn(new \stdClass());

        $connection->expects($this->never())->method('readLoop');

        $producer = new Producer($connection, 'test-stream', 1);

        // Call waitForConfirms without any prior send() - should return immediately
        $producer->waitForConfirms();

        $this->addToAssertionCount(1);
    }

    public function testWaitForConfirmsCallsReadLoopWithMaxFramesOneAndPositiveTimeout(): void
    {
        // Regression guard for #385: waitForConfirms() must pass maxFrames: 1
        // so readLoop() hands control back after each dispatched frame instead
        // of blocking for the whole timeout.
        $connection = $this->createMock(StreamConnection::class);

        /** @var array{onConfirm: callable, onError: callable}|null $registeredCallbacks */
        $registeredCallbacks = null;
        $connection->expects($this->any())
            ->method('registerPublisher')
            ->willReturnCallback(function ($id, $onConfirm, $onError) use (&$registeredCallbacks): void {
                $registeredCallbacks = ['onConfirm' => $onConfirm, 'onError' => $onError];
            });

        $connection->expects($this->any())->method('sendMessage');
        $connection->expects($this->any())->method('readMessage')->willReturn(new \stdClass());

        $captured = ['maxFrames' => null, 'timeout' => null];
        $connection->expects($this->once())
            ->method('readLoop')
            ->willReturnCallback(function ($maxFrames, $timeout) use (&$registeredCallbacks, &$captured): int {
                $captured['maxFrames'] = $maxFrames;
                $captured['timeout'] = $timeout;
                $this->assertNotNull($registeredCallbacks, 'registerPublisher callback must have been called');
                ($registeredCallbacks['onConfirm'])([0]);
                return 1;
            });

        $producer = new Producer($connection, 'test-stream', 1);
        $producer->send('test message');

        $producer->waitForConfirms(timeout: 5);

        $this->assertSame(1, $captured['maxFrames'], 'readLoop() must be called with maxFrames === 1');
        $this->assertIsFloat($captured['timeout']);
        $this->assertGreaterThan(0.0, $captured['timeout'], 'readLoop() must be called with a positive timeout');
    }

    public function testWaitForConfirmsDrainsMultipleConfirmFramesOneAtATime(): void
    {
        // Guards the multi-confirm drain: with maxFrames: 1, readLoop() returns
        // after a single dispatched frame, so waitForConfirms() must loop and
        // call readLoop() again for each remaining pending confirm.
        $connection = $this->createMock(StreamConnection::class);

        /** @var array{onConfirm: callable, onError: callable}|null $registeredCallbacks */
        $registeredCallbacks = null;
        $connection->expects($this->any())
            ->method('registerPublisher')
            ->willReturnCallback(function ($id, $onConfirm, $onError) use (&$registeredCallbacks): void {
                $registeredCallbacks = ['onConfirm' => $onConfirm, 'onError' => $onError];
            });

        $connection->expects($this->any())->method('sendMessage');
        $connection->expects($this->any())->method('readMessage')->willReturn(new \stdClass());

        $readLoopCallCount = 0;
        $connection->expects($this->exactly(3))
            ->method('readLoop')
            ->willReturnCallback(function () use (&$registeredCallbacks, &$readLoopCallCount): int {
                $readLoopCallCount++;
                $this->assertNotNull($registeredCallbacks, 'registerPublisher callback must have been called');
                // One confirm frame per readLoop() call, as with maxFrames: 1.
                ($registeredCallbacks['onConfirm'])([$readLoopCallCount - 1]);
                return 1;
            });

        $producer = new Producer($connection, 'test-stream', 1);
        $producer->send('msg1');
        $producer->send('msg2');
        $producer->send('msg3');

        $producer->waitForConfirms(timeout: 5);

        $this->assertSame(3, $readLoopCallCount, 'readLoop() must be called once per confirm frame');
    }

    public function testWaitForConfirmsThrowsTimeoutExceptionWhenNoConfirmEverArrives(): void
    {
        $connection = $this->createMock(StreamConnection::class);
        $connection->expects($this->any())->method('registerPublisher');
        $connection->expects($this->any())->method('sendMessage');
        $connection->expects($this->any())->method('readMessage')->willReturn(new \stdClass());

        // readLoop() never invokes onConfirm, simulating a broker that never confirms.
        $connection->expects($this->atLeastOnce())->method('readLoop');

        $producer = new Producer($connection, 'test-stream', 1);
        $producer->send('test message');

        $this->expectException(TimeoutException::class);
        $this->expectExceptionMessage('Timed out waiting for 1 publish confirms');

        $producer->waitForConfirms(timeout: 0.01);
    }

    public function testQuerySequenceThrowsForUnnamedProducer(): void
    {
        $connection = $this->createMock(StreamConnection::class);
        $connection->expects($this->any())->method('registerPublisher');
        $connection->expects($this->any())->method('sendMessage');
        $connection->expects($this->any())->method('readMessage');

        $producer = new Producer($connection, 'test-stream', 1); // No name provided

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Cannot query sequence for unnamed producer');

        $producer->querySequence();
    }

    public function testGetPendingConfirmsReturnsCurrentCount(): void
    {
        $connection = $this->createMock(StreamConnection::class);
        $connection->expects($this->any())->method('registerPublisher');
        $connection->expects($this->any())->method('sendMessage');
        $connection->expects($this->any())->method('readMessage')->willReturn(new \stdClass());

        $producer = new Producer($connection, 'test-stream', 1);

        $this->assertSame(0, $producer->getPendingConfirms());
        $producer->send('msg1');
        $this->assertSame(1, $producer->getPendingConfirms());
        $producer->send('msg2');
        $this->assertSame(2, $producer->getPendingConfirms());
    }

    public function testMaxPendingConfirmsZeroPreservesUnlimitedBehaviour(): void
    {
        $connection = $this->createMock(StreamConnection::class);
        $connection->expects($this->any())->method('registerPublisher');
        $connection->expects($this->any())->method('sendMessage');
        $connection->expects($this->any())->method('readMessage')->willReturn(new \stdClass());

        // Backpressure disabled (0 = unlimited), so readLoop() must never be
        // invoked to drain confirms, no matter how many sends pile up.
        $connection->expects($this->never())->method('readLoop');

        $producer = new Producer($connection, 'test-stream', 1, maxPendingConfirms: 0);

        for ($i = 0; $i < 50; $i++) {
            $producer->send('msg');
        }

        $this->assertSame(50, $producer->getPendingConfirms());
    }

    public function testSendDrainsConfirmsWhenMaxPendingConfirmsReached(): void
    {
        $connection = $this->createMock(StreamConnection::class);

        /** @var array{onConfirm: callable, onError: callable}|null $registeredCallbacks */
        $registeredCallbacks = null;
        $connection->expects($this->any())
            ->method('registerPublisher')
            ->willReturnCallback(function ($id, $onConfirm, $onError) use (&$registeredCallbacks): void {
                $registeredCallbacks = ['onConfirm' => $onConfirm, 'onError' => $onError];
            });

        $connection->expects($this->any())->method('sendMessage');
        $connection->expects($this->any())->method('readMessage')->willReturn(new \stdClass());

        $readLoopCallCount = 0;
        $connection->expects($this->once())
            ->method('readLoop')
            ->willReturnCallback(function () use (&$registeredCallbacks, &$readLoopCallCount): int {
                $readLoopCallCount++;
                $this->assertNotNull($registeredCallbacks, 'registerPublisher callback must have been called');
                ($registeredCallbacks['onConfirm'])([0]);
                return 1;
            });

        $producer = new Producer($connection, 'test-stream', 1, maxPendingConfirms: 2);

        // First two sends fill the window without draining.
        $producer->send('msg1');
        $producer->send('msg2');
        $this->assertSame(2, $producer->getPendingConfirms());

        // Third send hits the cap and must drain one confirm before publishing.
        $producer->send('msg3');

        $this->assertSame(1, $readLoopCallCount, 'readLoop() must be called exactly once to drain');
        $this->assertSame(2, $producer->getPendingConfirms());
    }

    public function testSendBatchDrainsConfirmsWhenMaxPendingConfirmsReached(): void
    {
        $connection = $this->createMock(StreamConnection::class);

        /** @var array{onConfirm: callable, onError: callable}|null $registeredCallbacks */
        $registeredCallbacks = null;
        $connection->expects($this->any())
            ->method('registerPublisher')
            ->willReturnCallback(function ($id, $onConfirm, $onError) use (&$registeredCallbacks): void {
                $registeredCallbacks = ['onConfirm' => $onConfirm, 'onError' => $onError];
            });

        $connection->expects($this->any())->method('sendMessage');
        $connection->expects($this->any())->method('readMessage')->willReturn(new \stdClass());

        $connection->expects($this->once())
            ->method('readLoop')
            ->willReturnCallback(function () use (&$registeredCallbacks): int {
                $this->assertNotNull($registeredCallbacks, 'registerPublisher callback must have been called');
                ($registeredCallbacks['onConfirm'])([0, 1]);
                return 1;
            });

        $producer = new Producer($connection, 'test-stream', 1, maxPendingConfirms: 2);

        $producer->sendBatch(['msg1', 'msg2']);
        $this->assertSame(2, $producer->getPendingConfirms());

        // This batch hits the cap and must drain before publishing again.
        $producer->sendBatch(['msg3']);

        $this->assertSame(1, $producer->getPendingConfirms());
    }

    public function testSendThrowsTimeoutExceptionWhenBackpressureNeverDrains(): void
    {
        $connection = $this->createMock(StreamConnection::class);
        $connection->expects($this->any())->method('registerPublisher');
        $connection->expects($this->any())->method('sendMessage');
        $connection->expects($this->any())->method('readMessage')->willReturn(new \stdClass());

        // readLoop() never invokes onConfirm, simulating a broker that never confirms.
        $connection->expects($this->atLeastOnce())->method('readLoop');

        $producer = new Producer($connection, 'test-stream', 1, maxPendingConfirms: 1);

        $producer->send('msg1');

        $this->expectException(TimeoutException::class);
        $this->expectExceptionMessage('Timed out waiting for pending confirms to drop below 1');

        $producer->send('msg2', 0.01);
    }

    public function testQuerySequenceReturnsSequenceForNamedProducer(): void
    {
        $connection = $this->createMock(StreamConnection::class);
        $connection->expects($this->any())->method('registerPublisher');

        $mockResponse = $this->createMock(\CrazyGoat\RabbitStream\Response\QueryPublisherSequenceResponseV1::class);
        $mockResponse->method('getSequence')->willReturn(42);

        // Constructor calls sendMessage with DeclarePublisherRequestV1
        // initializePublishingId calls sendMessage with QueryPublisherSequenceRequestV1
        // querySequence calls sendMessage with QueryPublisherSequenceRequestV1
        $capturedRequest = null;
        $connection->expects($this->exactly(3))
            ->method('sendMessage')
            ->with($this->callback(function ($request) use (&$capturedRequest): bool {
                if ($request instanceof QueryPublisherSequenceRequestV1) {
                    $capturedRequest = $request;
                }
                return true;
            }));

        $connection->expects($this->exactly(3))
            ->method('readMessage')
            ->willReturnOnConsecutiveCalls(
                new \stdClass(), // For DeclarePublisher response
                $mockResponse,   // For initializePublishingId QueryPublisherSequence response
                $mockResponse    // For querySequence QueryPublisherSequence response
            );

        $producer = new Producer($connection, 'test-stream', 1, 'my-producer');

        $sequence = $producer->querySequence();
        $this->assertEquals(42, $sequence);
        $this->assertNotNull($capturedRequest, 'QueryPublisherSequenceRequestV1 should have been sent');
    }
}
