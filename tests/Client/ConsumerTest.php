<?php

declare(strict_types=1);

namespace CrazyGoat\RabbitStream\Tests\Client;

use CrazyGoat\RabbitStream\Client\Consumer;
use CrazyGoat\RabbitStream\Client\Message;
use CrazyGoat\RabbitStream\Request\CreditRequestV1;
use CrazyGoat\RabbitStream\Request\StoreOffsetRequestV1;
use CrazyGoat\RabbitStream\Request\UnsubscribeRequestV1;
use CrazyGoat\RabbitStream\Response\QueryOffsetResponseV1;
use CrazyGoat\RabbitStream\StreamConnection;
use CrazyGoat\RabbitStream\VO\OffsetSpec;
use PHPUnit\Framework\TestCase;

class ConsumerTest extends TestCase
{
    private function makeConnection(): StreamConnection
    {
        $connection = $this->createMock(StreamConnection::class);
        $connection->expects($this->any())->method('registerSubscriber');
        $connection->expects($this->any())->method('sendMessage');
        $connection->expects($this->any())->method('readMessage')->willReturn(new \stdClass());
        return $connection;
    }

    public function testReadAcceptsFloatTimeout(): void
    {
        $connection = $this->createMock(StreamConnection::class);
        $connection->expects($this->any())->method('registerSubscriber');
        $connection->expects($this->any())->method('sendMessage');
        $connection->expects($this->any())->method('readMessage')->willReturn(new \stdClass());
        $connection->expects($this->any())->method('readLoop');

        $consumer = new Consumer($connection, 'test-stream', 1, OffsetSpec::first());

        // Test that float timeout is accepted (method signature)
        $reflection = new \ReflectionMethod($consumer, 'read');
        $params = $reflection->getParameters();

        $this->assertCount(1, $params);
        $this->assertSame('timeout', $params[0]->getName());
        $type = $params[0]->getType();
        $this->assertInstanceOf(\ReflectionNamedType::class, $type);
        $this->assertSame('float', $type->getName());
        $this->assertSame(5.0, $params[0]->getDefaultValue());

        // Test calling with float timeout works
        $result = $consumer->read(timeout: 0.5);
        $this->assertSame([], $result);
    }

    public function testReadOneAcceptsFloatTimeout(): void
    {
        $connection = $this->createMock(StreamConnection::class);
        $connection->expects($this->any())->method('registerSubscriber');
        $connection->expects($this->any())->method('sendMessage');
        $connection->expects($this->any())->method('readMessage')->willReturn(new \stdClass());
        $connection->expects($this->any())->method('readLoop');

        $consumer = new Consumer($connection, 'test-stream', 1, OffsetSpec::first());

        // Test that float timeout is accepted (method signature)
        $reflection = new \ReflectionMethod($consumer, 'readOne');
        $params = $reflection->getParameters();

        $this->assertCount(1, $params);
        $this->assertSame('timeout', $params[0]->getName());
        $type = $params[0]->getType();
        $this->assertInstanceOf(\ReflectionNamedType::class, $type);
        $this->assertSame('float', $type->getName());
        $this->assertSame(5.0, $params[0]->getDefaultValue());

        // Test calling with float timeout works
        $result = $consumer->readOne(timeout: 0.5);
        $this->assertNull($result);
    }

    public function testReadReturnsEmptyArrayOnTimeout(): void
    {
        $connection = $this->createMock(StreamConnection::class);
        $connection->expects($this->any())->method('registerSubscriber');
        $connection->expects($this->any())->method('sendMessage');
        $connection->expects($this->any())->method('readMessage')->willReturn(new \stdClass());
        $connection->expects($this->any())->method('readLoop');

        $consumer = new Consumer($connection, 'test-stream', 1, OffsetSpec::first());
        $result = $consumer->read(timeout: 1);

        $this->assertSame([], $result);
    }

    public function testReadOneReturnsNullOnTimeout(): void
    {
        $connection = $this->createMock(StreamConnection::class);
        $connection->expects($this->any())->method('registerSubscriber');
        $connection->expects($this->any())->method('sendMessage');
        $connection->expects($this->any())->method('readMessage')->willReturn(new \stdClass());
        $connection->expects($this->any())->method('readLoop');

        $consumer = new Consumer($connection, 'test-stream', 1, OffsetSpec::first());
        $result = $consumer->readOne(timeout: 1);

        $this->assertNull($result);
    }

    public function testStoreOffsetThrowsForUnnamedConsumer(): void
    {
        $consumer = new Consumer($this->makeConnection(), 'test-stream', 1, OffsetSpec::first());

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Cannot store offset for unnamed consumer');
        $consumer->storeOffset(42);
    }

    public function testStoreOffsetSendsCorrectRequest(): void
    {
        $connection = $this->createMock(StreamConnection::class);
        $connection->expects($this->any())->method('registerSubscriber');
        $connection->expects($this->any())->method('readMessage')->willReturn(new \stdClass());

        $capturedRequest = null;
        $connection->expects($this->any())
            ->method('sendMessage')
            ->willReturnCallback(function ($request) use (&$capturedRequest): void {
                if ($request instanceof StoreOffsetRequestV1) {
                    $capturedRequest = $request;
                }
            });

        $consumer = new Consumer($connection, 'test-stream', 1, OffsetSpec::first(), 'my-consumer');
        $consumer->storeOffset(99);

        $this->assertInstanceOf(StoreOffsetRequestV1::class, $capturedRequest);
    }

    public function testQueryOffsetThrowsForUnnamedConsumer(): void
    {
        $consumer = new Consumer($this->makeConnection(), 'test-stream', 1, OffsetSpec::first());

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Cannot query offset for unnamed consumer');
        $consumer->queryOffset();
    }

    public function testQueryOffsetReturnsOffset(): void
    {
        $connection = $this->createMock(StreamConnection::class);
        $connection->expects($this->any())->method('registerSubscriber');
        $connection->expects($this->any())->method('sendMessage');

        $mockResponse = $this->createMock(QueryOffsetResponseV1::class);
        $mockResponse->method('getOffset')->willReturn(77);

        $connection->expects($this->any())
            ->method('readMessage')
            ->willReturnOnConsecutiveCalls(
                new \stdClass(),
                $mockResponse
            );

        $consumer = new Consumer($connection, 'test-stream', 1, OffsetSpec::first(), 'my-consumer');
        $offset = $consumer->queryOffset();

        $this->assertSame(77, $offset);
    }

    public function testCloseSendsUnsubscribeRequest(): void
    {
        $connection = $this->createMock(StreamConnection::class);
        $connection->expects($this->any())->method('registerSubscriber');
        $connection->expects($this->any())->method('readMessage')->willReturn(new \stdClass());

        $capturedRequest = null;
        $connection->expects($this->any())
            ->method('sendMessage')
            ->willReturnCallback(function ($request) use (&$capturedRequest): void {
                if ($request instanceof UnsubscribeRequestV1) {
                    $capturedRequest = $request;
                }
            });

        $consumer = new Consumer($connection, 'test-stream', 1, OffsetSpec::first());
        $consumer->close();

        $this->assertInstanceOf(UnsubscribeRequestV1::class, $capturedRequest);
    }

    public function testCloseDoesNotStoreOffsetWhenNoMessagesProcessed(): void
    {
        $connection = $this->createMock(StreamConnection::class);
        $connection->expects($this->any())->method('registerSubscriber');
        $connection->expects($this->any())->method('readMessage')->willReturn(new \stdClass());

        $storeOffsetCalled = false;
        $connection->expects($this->any())
            ->method('sendMessage')
            ->willReturnCallback(function ($request) use (&$storeOffsetCalled): void {
                if ($request instanceof StoreOffsetRequestV1) {
                    $storeOffsetCalled = true;
                }
            });

        $consumer = new Consumer(
            $connection,
            'test-stream',
            1,
            OffsetSpec::first(),
            name: 'my-consumer',
            autoCommit: 5,
        );
        $consumer->close();

        $this->assertFalse($storeOffsetCalled, 'storeOffset should not be called when no messages processed');
    }

    public function testReadBuffersMessagesViaCallback(): void
    {
        $registeredCallback = null;
        $connection = $this->createMock(StreamConnection::class);
        $connection->expects($this->any())
            ->method('registerSubscriber')
            ->willReturnCallback(function (int $id, callable $cb) use (&$registeredCallback): void {
                $registeredCallback = $cb;
            });
        $connection->expects($this->any())->method('sendMessage');
        $connection->expects($this->any())->method('readMessage')->willReturn(new \stdClass());

        new Consumer($connection, 'test-stream', 1, OffsetSpec::first());

        $this->assertNotNull($registeredCallback, 'registerSubscriber callback should be registered');
    }

    public function testAutoCommitIsDisabledWhenZero(): void
    {
        $connection = $this->createMock(StreamConnection::class);
        $connection->expects($this->any())->method('registerSubscriber');
        $connection->expects($this->any())->method('readMessage')->willReturn(new \stdClass());

        $storeOffsetCalled = false;
        $connection->expects($this->any())
            ->method('sendMessage')
            ->willReturnCallback(function ($request) use (&$storeOffsetCalled): void {
                if ($request instanceof StoreOffsetRequestV1) {
                    $storeOffsetCalled = true;
                }
            });

        $consumer = new Consumer(
            $connection,
            'test-stream',
            1,
            OffsetSpec::first(),
            name: 'my-consumer',
            autoCommit: 0,
        );
        $consumer->close();

        $this->assertFalse($storeOffsetCalled, 'autoCommit=0 should not trigger storeOffset');
    }

    public function testReadOneBuffersRemainingMessages(): void
    {
        $registeredCallback = null;
        $connection = $this->createMock(StreamConnection::class);
        $connection->expects($this->any())
            ->method('registerSubscriber')
            ->willReturnCallback(function (int $id, callable $cb) use (&$registeredCallback): void {
                $registeredCallback = $cb;
            });
        $connection->expects($this->any())->method('sendMessage');
        $connection->expects($this->any())->method('readMessage')->willReturn(new \stdClass());

        $consumer = new Consumer($connection, 'test-stream', 1, OffsetSpec::first());

        $msg1 = $this->createMock(Message::class);
        $msg1->method('getOffset')->willReturn(0);
        $msg2 = $this->createMock(Message::class);
        $msg2->method('getOffset')->willReturn(1);

        $bufferProp = new \ReflectionProperty($consumer, 'buffer');
        $bufferProp->setValue($consumer, [$msg1, $msg2]);

        $result = $consumer->readOne();

        $this->assertSame($msg1, $result);

        $remaining = $bufferProp->getValue($consumer);
        $this->assertIsArray($remaining);
        $this->assertCount(1, $remaining);
        $this->assertSame($msg2, $remaining[0]);
    }

    public function testReadReturnsAllBufferedMessages(): void
    {
        $connection = $this->makeConnection();
        $consumer = new Consumer($connection, 'test-stream', 1, OffsetSpec::first());

        $msg1 = $this->createMock(Message::class);
        $msg1->method('getOffset')->willReturn(0);
        $msg2 = $this->createMock(Message::class);
        $msg2->method('getOffset')->willReturn(1);

        $bufferProp = new \ReflectionProperty($consumer, 'buffer');
        $bufferProp->setValue($consumer, [$msg1, $msg2]);

        $result = $consumer->read();

        $this->assertCount(2, $result);
        $this->assertSame($msg1, $result[0]);
        $this->assertSame($msg2, $result[1]);

        $this->assertSame([], $bufferProp->getValue($consumer));
    }

    public function testMaxBufferSizeMustBeGreaterThanZero(): void
    {
        $connection = $this->createMock(StreamConnection::class);
        $connection->expects($this->any())->method('registerSubscriber');
        $connection->expects($this->any())->method('sendMessage');
        $connection->expects($this->any())->method('readMessage')->willReturn(new \stdClass());

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('maxBufferSize must be greater than 0');

        new Consumer($connection, 'test-stream', 1, OffsetSpec::first(), maxBufferSize: 0);
    }

    public function testMaxBufferSizeRejectsNegativeValues(): void
    {
        $connection = $this->createMock(StreamConnection::class);
        $connection->expects($this->any())->method('registerSubscriber');
        $connection->expects($this->any())->method('sendMessage');
        $connection->expects($this->any())->method('readMessage')->willReturn(new \stdClass());

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('maxBufferSize must be greater than 0');

        new Consumer($connection, 'test-stream', 1, OffsetSpec::first(), maxBufferSize: -1);
    }

    public function testCreditWithheldWhenBufferExceedsMaxBufferSize(): void
    {
        $creditRequests = [];

        $connection = $this->createMock(StreamConnection::class);
        $connection->expects($this->any())->method('registerSubscriber');
        $connection->expects($this->any())
            ->method('sendMessage')
            ->willReturnCallback(function ($request) use (&$creditRequests): void {
                if ($request instanceof CreditRequestV1) {
                    $creditRequests[] = $request;
                }
            });
        $connection->expects($this->any())->method('readMessage')->willReturn(new \stdClass());

        $consumer = new Consumer($connection, 'test-stream', 1, OffsetSpec::first(), maxBufferSize: 2);

        $msg1 = $this->createMock(Message::class);
        $msg1->method('getOffset')->willReturn(0);
        $msg2 = $this->createMock(Message::class);
        $msg2->method('getOffset')->willReturn(1);

        $bufferProp = new \ReflectionProperty($consumer, 'buffer');
        $pendingCreditsProp = new \ReflectionProperty($consumer, 'pendingCredits');

        $bufferProp->setValue($consumer, [$msg1]);
        $creditRequests = [];

        $sendPendingCredits = new \ReflectionMethod($consumer, 'sendPendingCredits');
        $sendPendingCredits->invoke($consumer);

        $this->assertCount(0, $creditRequests);

        $bufferProp->setValue($consumer, [$msg1, $msg2]);
        $pendingCreditsProp->setValue($consumer, 1);
        $creditRequests = [];

        $sendPendingCredits->invoke($consumer);

        $this->assertCount(0, $creditRequests, 'Credits should be withheld when buffer at maxBufferSize');
        $this->assertSame(1, $pendingCreditsProp->getValue($consumer));
    }

    public function testPendingCreditsSentAfterReadDrainsBuffer(): void
    {
        $capturedRequest = null;

        $connection = $this->createMock(StreamConnection::class);
        $connection->expects($this->any())->method('registerSubscriber');
        $connection->expects($this->any())
            ->method('sendMessage')
            ->willReturnCallback(function ($request) use (&$capturedRequest): void {
                if ($request instanceof CreditRequestV1) {
                    $capturedRequest = $request;
                }
            });
        $connection->expects($this->any())->method('readMessage')->willReturn(new \stdClass());
        $connection->expects($this->any())->method('readLoop');

        $consumer = new Consumer($connection, 'test-stream', 1, OffsetSpec::first(), maxBufferSize: 2);

        $msg1 = $this->createMock(Message::class);
        $msg1->method('getOffset')->willReturn(0);
        $msg2 = $this->createMock(Message::class);
        $msg2->method('getOffset')->willReturn(1);

        $bufferProp = new \ReflectionProperty($consumer, 'buffer');
        $pendingCreditsProp = new \ReflectionProperty($consumer, 'pendingCredits');

        $bufferProp->setValue($consumer, [$msg1, $msg2]);
        $pendingCreditsProp->setValue($consumer, 2);

        $consumer->read();

        $this->assertInstanceOf(
            CreditRequestV1::class,
            $capturedRequest,
            'Pending credits should be sent after read()'
        );
        $this->assertSame(2, $capturedRequest->toArray()['credit']);
        $this->assertSame(0, $pendingCreditsProp->getValue($consumer));
    }

    public function testPendingCreditsSentAfterReadOneDrainsBuffer(): void
    {
        $capturedRequest = null;

        $connection = $this->createMock(StreamConnection::class);
        $connection->expects($this->any())->method('registerSubscriber');
        $connection->expects($this->any())
            ->method('sendMessage')
            ->willReturnCallback(function ($request) use (&$capturedRequest): void {
                if ($request instanceof CreditRequestV1) {
                    $capturedRequest = $request;
                }
            });
        $connection->expects($this->any())->method('readMessage')->willReturn(new \stdClass());
        $connection->expects($this->any())->method('readLoop');

        $consumer = new Consumer($connection, 'test-stream', 1, OffsetSpec::first(), maxBufferSize: 2);

        $msg1 = $this->createMock(Message::class);
        $msg1->method('getOffset')->willReturn(0);
        $msg2 = $this->createMock(Message::class);
        $msg2->method('getOffset')->willReturn(1);

        $bufferProp = new \ReflectionProperty($consumer, 'buffer');
        $pendingCreditsProp = new \ReflectionProperty($consumer, 'pendingCredits');

        $bufferProp->setValue($consumer, [$msg1, $msg2]);
        $pendingCreditsProp->setValue($consumer, 1);

        $consumer->readOne();

        $this->assertInstanceOf(
            CreditRequestV1::class,
            $capturedRequest,
            'Pending credits should be sent after readOne()'
        );
        $this->assertSame(1, $capturedRequest->toArray()['credit']);
    }
}
