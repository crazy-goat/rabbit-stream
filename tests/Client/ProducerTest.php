<?php

declare(strict_types=1);

namespace CrazyGoat\RabbitStream\Tests\Client;

use CrazyGoat\RabbitStream\Client\AmqpMessageEncoder;
use CrazyGoat\RabbitStream\Client\ConfirmationStatus;
use CrazyGoat\RabbitStream\Client\Producer;
use CrazyGoat\RabbitStream\Enum\ResponseCodeEnum;
use CrazyGoat\RabbitStream\Exception\ConnectionException;
use CrazyGoat\RabbitStream\Exception\InvalidArgumentException;
use CrazyGoat\RabbitStream\Exception\ProtocolException;
use CrazyGoat\RabbitStream\Exception\TimeoutException;
use CrazyGoat\RabbitStream\Request\DeclarePublisherRequestV1;
use CrazyGoat\RabbitStream\Request\DeletePublisherRequestV1;
use CrazyGoat\RabbitStream\Request\PublishRequestV1;
use CrazyGoat\RabbitStream\Request\QueryPublisherSequenceRequestV1;
use CrazyGoat\RabbitStream\Response\MetadataUpdateResponseV1;
use CrazyGoat\RabbitStream\StreamConnection;
use CrazyGoat\RabbitStream\Tests\Support\CapturedClosures;
use CrazyGoat\RabbitStream\Tests\Support\CapturedObjects;
use CrazyGoat\RabbitStream\VO\PublishingError;
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


    // ---------------------------------------------------------------------
    // A failed write must not leave pendingConfirms raised forever (#395).
    // ---------------------------------------------------------------------

    public function testFailedSendDoesNotLeavePendingConfirmsBehind(): void
    {
        $connection = $this->createMock(StreamConnection::class);
        $connection->expects($this->any())->method('registerPublisher');
        $connection->expects($this->any())->method('readMessage')->willReturn(new \stdClass());
        $connection->expects($this->any())
            ->method('sendMessage')
            ->willReturnCallback(function (object $request): void {
                if ($request instanceof PublishRequestV1) {
                    throw new ConnectionException('short write');
                }
            });

        $producer = new Producer($connection, 'test-stream', 1);

        try {
            $producer->send('never-reaches-the-broker');
            $this->fail('The write failure must surface');
        } catch (ConnectionException) {
            // expected
        }

        $this->assertSame(0, $producer->getPendingConfirms(), 'A message the broker never saw is not pending');
        $this->assertNull($producer->getLastPublishingId(), 'No publishing id is consumed by a failed write');
        // Previously this blocked for the whole timeout and then threw, because
        // the counter had been raised before the write.
        $start = microtime(true);
        $producer->waitForConfirms(timeout: 1.0);
        $this->assertLessThan(0.5, microtime(true) - $start);
    }

    public function testFailedBatchSendDoesNotLeavePendingConfirmsBehind(): void
    {
        $connection = $this->createMock(StreamConnection::class);
        $connection->expects($this->any())->method('registerPublisher');
        $connection->expects($this->any())->method('readMessage')->willReturn(new \stdClass());
        $connection->expects($this->any())
            ->method('sendMessage')
            ->willReturnCallback(function (object $request): void {
                if ($request instanceof PublishRequestV1) {
                    throw new ConnectionException('short write');
                }
            });

        $producer = new Producer($connection, 'test-stream', 1);

        try {
            $producer->sendBatch(['a', 'b', 'c']);
            $this->fail('The write failure must surface');
        } catch (ConnectionException) {
            // expected
        }

        $this->assertSame(0, $producer->getPendingConfirms());
        $this->assertNull($producer->getLastPublishingId());
    }

    public function testSuccessfulSendStillCountsTowardsPendingConfirms(): void
    {
        $connection = $this->createMock(StreamConnection::class);
        $connection->expects($this->any())->method('registerPublisher');
        $connection->expects($this->any())->method('readMessage')->willReturn(new \stdClass());
        $connection->expects($this->any())->method('sendMessage');

        $producer = new Producer($connection, 'test-stream', 1);
        $producer->send('one');
        $producer->sendBatch(['two', 'three']);
        $producer->sendWithFilter('four', 'f');

        $this->assertSame(4, $producer->getPendingConfirms());
        $this->assertSame(3, $producer->getLastPublishingId(), 'Publishing ids stay contiguous: 0..3');
    }

    public function testCloseIsIdempotentAndReleasesThePublisherIdOnce(): void
    {
        $deletes = 0;
        $connection = $this->createMock(StreamConnection::class);
        $connection->expects($this->any())->method('registerPublisher');
        $connection->expects($this->any())->method('readMessage')->willReturn(new \stdClass());
        $connection->expects($this->any())
            ->method('sendMessage')
            ->willReturnCallback(function (object $request) use (&$deletes): void {
                if ($request instanceof DeletePublisherRequestV1) {
                    $deletes++;
                }
            });

        $released = [];
        $producer = new Producer(
            $connection,
            'test-stream',
            3,
            onClose: function (int $id) use (&$released): void {
                $released[] = $id;
            },
        );
        $producer->close();
        $producer->close();

        $this->assertSame(1, $deletes, 'DeletePublisher must be sent once, however often close() is called');
        $this->assertSame([3], $released, 'The id is released exactly once');
        $this->assertTrue($producer->isClosed());
    }

    public function testStalePublisherStillReleasesItsIdOnClose(): void
    {
        [$connection, $metadataHandlers] = $this->connectionCapturingHandlers();
        $connection->expects($this->any())->method('sendMessage');
        $released = [];

        $producer = new Producer(
            $connection,
            'test-stream',
            1,
            onClose: function (int $id) use (&$released): void {
                $released[] = $id;
            },
        );
        $metadataHandlers->at()(
            new MetadataUpdateResponseV1(ResponseCodeEnum::STREAM_NOT_AVAILABLE->value, 'test-stream')
        );
        $producer->close();

        $this->assertSame([1], $released, 'A stale publisher skips DeletePublisher but still frees its id');
    }

    /**
     * Producer wired to a mock connection that captures the per-stream
     * MetadataUpdate handler and the publisher error callback.
     *
     * @return array{
     *     0: StreamConnection&\PHPUnit\Framework\MockObject\MockObject,
     *     1: CapturedClosures,
     *     2: CapturedClosures
     * }
     */
    private function connectionCapturingHandlers(): array
    {
        $metadataHandlers = new CapturedClosures();
        $errorCallbacks = new CapturedClosures();
        $connection = $this->createMock(StreamConnection::class);
        $connection->expects($this->any())
            ->method('registerMetadataUpdateHandler')
            ->willReturnCallback(function (string $stream, string $id, \Closure $h) use ($metadataHandlers): void {
                $this->assertSame('test-stream', $stream);
                $this->assertSame('publisher-1', $id);
                $metadataHandlers->add($h);
            });
        $connection->expects($this->any())
            ->method('registerPublisher')
            ->willReturnCallback(
                function (int $id, \Closure $onConfirm, \Closure $onError) use ($errorCallbacks): void {
                    $errorCallbacks->add($onError);
                }
            );
        $connection->expects($this->any())->method('readMessage')->willReturn(new \stdClass());
        return [$connection, $metadataHandlers, $errorCallbacks];
    }

    public function testMetadataUpdateMarksProducerStaleAndNextSendRedeclares(): void
    {
        [$connection, $metadataHandlers] = $this->connectionCapturingHandlers();
        /** @var CapturedObjects<object> $requests */
        $requests = new CapturedObjects();
        $connection->expects($this->any())
            ->method('request')
            ->willReturnCallback(function (object $request) use ($requests): object {
                $requests->add($request);
                return new \stdClass();
            });
        $published = 0;
        $connection->expects($this->any())
            ->method('sendMessage')
            ->willReturnCallback(function (object $request) use (&$published): void {
                if ($request instanceof PublishRequestV1) {
                    $published++;
                }
            });

        $producer = new Producer($connection, 'test-stream', 1);
        $this->assertSame(1, $metadataHandlers->count(), 'Producer must register a MetadataUpdate handler');
        $this->assertFalse($producer->isStale());

        $metadataHandlers->at()(
            new MetadataUpdateResponseV1(ResponseCodeEnum::STREAM_NOT_AVAILABLE->value, 'test-stream')
        );
        $this->assertTrue($producer->isStale());
        $this->assertSame(0, $requests->count(), 'Re-declare must be lazy: nothing sent until the next publish');

        $producer->send('after');

        $this->assertFalse($producer->isStale());
        $this->assertSame(1, $producer->getRedeclareCount());
        $this->assertSame(1, $requests->count());
        $this->assertInstanceOf(DeclarePublisherRequestV1::class, $requests->at(0));
        $this->assertSame(1, $published);
    }

    public function testMetadataUpdateReportsUnconfirmedMessagesAsFailed(): void
    {
        [$connection, $metadataHandlers] = $this->connectionCapturingHandlers();
        $connection->expects($this->any())->method('request')->willReturn(new \stdClass());
        $connection->expects($this->any())->method('sendMessage');

        /** @var CapturedObjects<ConfirmationStatus> $statuses */
        $statuses = new CapturedObjects();
        $onConfirm = function (ConfirmationStatus $s) use ($statuses): void {
            $statuses->add($s);
        };
        $producer = new Producer($connection, 'test-stream', 1, onConfirm: $onConfirm);
        $producer->send('a'); // publishingId 0
        $producer->send('b'); // publishingId 1
        $this->assertSame(2, $producer->getPendingConfirms());

        $metadataHandlers->at()(
            new MetadataUpdateResponseV1(ResponseCodeEnum::STREAM_NOT_AVAILABLE->value, 'test-stream')
        );

        $this->assertSame(0, $producer->getPendingConfirms(), 'Lost messages must not count against back-pressure');
        $this->assertSame(2, $statuses->count());
        $this->assertFalse($statuses->at(0)->isConfirmed());
        $this->assertSame(0, $statuses->at(0)->getPublishingId());
        $this->assertSame(1, $statuses->at(1)->getPublishingId());
        $this->assertSame(ResponseCodeEnum::STREAM_NOT_AVAILABLE->value, $statuses->at(1)->getErrorCode());
        // waitForConfirms() has nothing left to wait for — no TimeoutException.
        $producer->waitForConfirms(0.01);
    }

    public function testPublishErrorPublisherNotExistMarksProducerStale(): void
    {
        [$connection, , $errorCallbacks] = $this->connectionCapturingHandlers();
        $connection->expects($this->any())->method('request')->willReturn(new \stdClass());
        $connection->expects($this->any())->method('sendMessage');

        $producer = new Producer($connection, 'test-stream', 1);
        $producer->send('a');

        $errorCallbacks->at()([new PublishingError(0, ResponseCodeEnum::PUBLISHER_NOT_EXIST->value)]);

        $this->assertTrue($producer->isStale());
    }

    public function testRedeclareRetriesWhileStreamMissingThenGivesUpWithProtocolException(): void
    {
        [$connection, $metadataHandlers] = $this->connectionCapturingHandlers();
        $attempts = 0;
        $connection->expects($this->any())
            ->method('request')
            ->willReturnCallback(function () use (&$attempts): object {
                $attempts++;
                throw new ProtocolException('nope', responseCode: ResponseCodeEnum::STREAM_NOT_EXIST);
            });
        $connection->expects($this->any())->method('sendMessage');

        $producer = new Producer($connection, 'test-stream', 1, redeclareTimeout: 0.2);
        $metadataHandlers->at()(
            new MetadataUpdateResponseV1(ResponseCodeEnum::STREAM_NOT_AVAILABLE->value, 'test-stream')
        );

        $start = microtime(true);
        try {
            $producer->send('x');
            $this->fail('Expected ProtocolException');
        } catch (ProtocolException $e) {
            $this->assertSame(ResponseCodeEnum::STREAM_NOT_EXIST, $e->getResponseCode());
            $this->assertStringContainsString('could not be re-declared', $e->getMessage());
        }
        $this->assertGreaterThan(1, $attempts, 'Should retry with back-off before giving up');
        $this->assertLessThan(1.0, microtime(true) - $start);
        $this->assertTrue($producer->isStale(), 'Still stale: the next send() tries again');
    }

    public function testRedeclareDoesNotRetryNonRetryableErrors(): void
    {
        [$connection, $metadataHandlers] = $this->connectionCapturingHandlers();
        $attempts = 0;
        $connection->expects($this->any())
            ->method('request')
            ->willReturnCallback(function () use (&$attempts): object {
                $attempts++;
                throw new ProtocolException('denied', responseCode: ResponseCodeEnum::ACCESS_REFUSED);
            });
        $connection->expects($this->any())->method('sendMessage');

        $producer = new Producer($connection, 'test-stream', 1, redeclareTimeout: 5.0);
        $metadataHandlers->at()(
            new MetadataUpdateResponseV1(ResponseCodeEnum::STREAM_NOT_AVAILABLE->value, 'test-stream')
        );

        $this->expectException(ProtocolException::class);
        try {
            $producer->send('x');
        } finally {
            $this->assertSame(1, $attempts);
        }
    }

    public function testCloseOnStaleProducerSkipsDeletePublisher(): void
    {
        [$connection, $metadataHandlers] = $this->connectionCapturingHandlers();
        $connection->expects($this->any())->method('request')->willReturn(new \stdClass());
        $deletes = 0;
        $connection->expects($this->any())
            ->method('sendMessage')
            ->willReturnCallback(function (object $request) use (&$deletes): void {
                if ($request instanceof DeletePublisherRequestV1) {
                    $deletes++;
                }
            });
        $connection->expects($this->once())->method('unregisterPublisher')->with(1);
        $connection->expects($this->once())
            ->method('unregisterMetadataUpdateHandler')
            ->with('test-stream', 'publisher-1');

        $producer = new Producer($connection, 'test-stream', 1);
        $metadataHandlers->at()(
            new MetadataUpdateResponseV1(ResponseCodeEnum::STREAM_NOT_AVAILABLE->value, 'test-stream')
        );
        $producer->close();

        $this->assertSame(0, $deletes, 'The broker already forgot the publisher');
    }
}
