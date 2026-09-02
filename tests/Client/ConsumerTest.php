<?php

declare(strict_types=1);

namespace CrazyGoat\RabbitStream\Tests\Client;

use CrazyGoat\RabbitStream\Client\Consumer;
use CrazyGoat\RabbitStream\Client\Message;
use CrazyGoat\RabbitStream\Enum\ResponseCodeEnum;
use CrazyGoat\RabbitStream\Exception\InvalidArgumentException;
use CrazyGoat\RabbitStream\Exception\ProtocolException;
use CrazyGoat\RabbitStream\Request\CreditRequestV1;
use CrazyGoat\RabbitStream\Request\QueryOffsetRequestV1;
use CrazyGoat\RabbitStream\Request\StoreOffsetRequestV1;
use CrazyGoat\RabbitStream\Request\SubscribeRequestV1;
use CrazyGoat\RabbitStream\Request\UnsubscribeRequestV1;
use CrazyGoat\RabbitStream\Response\ConsumerUpdateResponseV1;
use CrazyGoat\RabbitStream\Response\MetadataUpdateResponseV1;
use CrazyGoat\RabbitStream\Response\QueryOffsetResponseV1;
use CrazyGoat\RabbitStream\StreamConnection;
use CrazyGoat\RabbitStream\Tests\Support\CapturedClosures;
use CrazyGoat\RabbitStream\Tests\Support\CapturedObjects;
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

    /**
     * Sets the internal buffer array AND the unreadCount it must be kept in sync
     * with (see #408 — back-pressure accounting uses unreadCount, not the raw
     * array size), simulating messages having arrived via the deliver callback.
     *
     * @param Message[] $messages
     */
    private function setBuffer(Consumer $consumer, array $messages): void
    {
        (new \ReflectionProperty($consumer, 'buffer'))->setValue($consumer, $messages);
        (new \ReflectionProperty($consumer, 'unreadCount'))->setValue($consumer, count($messages));
    }

    private function setPendingCredits(Consumer $consumer, int $value): void
    {
        (new \ReflectionProperty($consumer, 'pendingCredits'))->setValue($consumer, $value);
    }

    private function setCreditsInFlight(Consumer $consumer, int $value): void
    {
        (new \ReflectionProperty($consumer, 'creditsInFlight'))->setValue($consumer, $value);
    }

    private function getPendingCredits(Consumer $consumer): int
    {
        $value = (new \ReflectionProperty($consumer, 'pendingCredits'))->getValue($consumer);
        return is_int($value) ? $value : 0;
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

    public function testReadKeepsWaitingWhileNonDeliverFramesArrive(): void
    {
        // readLoop() reporting 1 dispatched frame but buffering no message models a
        // heartbeat / publish confirm / ConsumerUpdate arriving first: read() must
        // not treat that as "nothing within timeout" and keep waiting until the
        // deadline.
        $connection = $this->createMock(StreamConnection::class);
        $connection->expects($this->any())->method('registerSubscriber');
        $connection->expects($this->any())->method('request')->willReturn(new \stdClass());
        $calls = 0;
        $connection->expects($this->any())->method('readLoop')->willReturnCallback(function () use (&$calls): int {
            $calls++;
            usleep(2000);
            return 1;
        });

        $consumer = new Consumer($connection, 'test-stream', 1, OffsetSpec::first());
        $start = microtime(true);
        $result = $consumer->read(timeout: 0.05);

        $this->assertSame([], $result);
        $this->assertGreaterThanOrEqual(0.05, microtime(true) - $start);
        $this->assertGreaterThan(1, $calls);
    }

    public function testReadStopsWaitingWhenReadLoopDispatchesNothing(): void
    {
        // 0 dispatched frames = readLoop() hit its own timeout (or the connection
        // dropped): read() must return immediately instead of spinning.
        $connection = $this->createMock(StreamConnection::class);
        $connection->expects($this->any())->method('registerSubscriber');
        $connection->expects($this->any())->method('request')->willReturn(new \stdClass());
        $connection->expects($this->once())->method('readLoop')->willReturn(0);

        $consumer = new Consumer($connection, 'test-stream', 1, OffsetSpec::first());
        $this->assertSame([], $consumer->read(timeout: 5.0));
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

        // subscribe() and queryOffset() both go through the correlated request()
        $connection->expects($this->any())
            ->method('request')
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
            ->method('request')
            ->willReturnCallback(function ($request) use (&$capturedRequest): object {
                if ($request instanceof UnsubscribeRequestV1) {
                    $capturedRequest = $request;
                }
                return new \stdClass();
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

    public function testReadOneIsConstantTimeAndReleasesBufferWhenDrained(): void
    {
        $connection = $this->createMock(StreamConnection::class);
        $connection->expects($this->any())->method('registerSubscriber');
        $connection->expects($this->any())->method('sendMessage');
        $connection->expects($this->any())->method('readMessage')->willReturn(new \stdClass());

        $consumer = new Consumer($connection, 'test-stream', 1, OffsetSpec::first());

        $msg1 = $this->createMock(Message::class);
        $msg1->method('getOffset')->willReturn(0);
        $msg2 = $this->createMock(Message::class);
        $msg2->method('getOffset')->willReturn(1);

        $this->setBuffer($consumer, [$msg1, $msg2]);

        $bufferProp = new \ReflectionProperty($consumer, 'buffer');
        $bufferHeadProp = new \ReflectionProperty($consumer, 'bufferHead');
        $unreadCountProp = new \ReflectionProperty($consumer, 'unreadCount');

        $result = $consumer->readOne();
        $this->assertSame($msg1, $result);

        // Head cursor advances instead of the whole array being reindexed
        // (array_shift-style); unreadCount — not the raw array size — reflects
        // what is actually left to read.
        $this->assertSame(1, $bufferHeadProp->getValue($consumer));
        $this->assertSame(1, $unreadCountProp->getValue($consumer));

        $result2 = $consumer->readOne();
        $this->assertSame($msg2, $result2);

        // Buffer fully drained: released back to an empty array, cursor reset.
        $this->assertSame([], $bufferProp->getValue($consumer));
        $this->assertSame(0, $bufferHeadProp->getValue($consumer));
        $this->assertSame(0, $unreadCountProp->getValue($consumer));
    }

    public function testReadReturnsAllBufferedMessages(): void
    {
        $connection = $this->makeConnection();
        $consumer = new Consumer($connection, 'test-stream', 1, OffsetSpec::first());

        $msg1 = $this->createMock(Message::class);
        $msg1->method('getOffset')->willReturn(0);
        $msg2 = $this->createMock(Message::class);
        $msg2->method('getOffset')->willReturn(1);

        $this->setBuffer($consumer, [$msg1, $msg2]);

        $result = $consumer->read();

        $this->assertCount(2, $result);
        $this->assertSame($msg1, $result[0]);
        $this->assertSame($msg2, $result[1]);

        $bufferProp = new \ReflectionProperty($consumer, 'buffer');
        $unreadCountProp = new \ReflectionProperty($consumer, 'unreadCount');
        $this->assertSame([], $bufferProp->getValue($consumer));
        $this->assertSame(0, $unreadCountProp->getValue($consumer));
    }

    public function testReadAfterPartialReadOneReturnsOnlyUnreadMessages(): void
    {
        $connection = $this->makeConnection();
        $consumer = new Consumer($connection, 'test-stream', 1, OffsetSpec::first());

        $msg1 = $this->createMock(Message::class);
        $msg1->method('getOffset')->willReturn(0);
        $msg2 = $this->createMock(Message::class);
        $msg2->method('getOffset')->willReturn(1);
        $msg3 = $this->createMock(Message::class);
        $msg3->method('getOffset')->willReturn(2);

        $this->setBuffer($consumer, [$msg1, $msg2, $msg3]);

        $first = $consumer->readOne();
        $this->assertSame($msg1, $first);

        // read() must return only msg2 and msg3 — the already-consumed msg1
        // (whose slot was unset, not merely skipped) must not reappear.
        $rest = $consumer->read();
        $this->assertSame([$msg2, $msg3], $rest);
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

    public function testDeliverCallbackNeverDropsMessagesEvenPastMaxBufferSize(): void
    {
        // #485: a whole chunk is always accepted into the buffer, even when it
        // overshoots maxBufferSize — messages are never dropped.
        $registeredCallback = null;
        $connection = $this->createMock(StreamConnection::class);
        $connection->expects($this->any())
            ->method('registerSubscriber')
            ->willReturnCallback(function (int $id, callable $cb) use (&$registeredCallback): void {
                $registeredCallback = $cb;
            });
        $connection->expects($this->any())->method('sendMessage');
        $connection->expects($this->any())->method('readMessage')->willReturn(new \stdClass());

        $consumer = new Consumer($connection, 'test-stream', 1, OffsetSpec::first(), maxBufferSize: 2);
        $this->assertIsCallable($registeredCallback);

        // Build a minimal one-entry Osiris chunk carrying a single-byte AMQP body.
        $chunkBytes = $this->buildOneEntryChunk('X');

        // Deliver 3 chunks of 1 message each — well past maxBufferSize=2.
        $deliver = new class ($chunkBytes) {
            public function __construct(private readonly string $bytes)
            {
            }

            public function getChunkBytes(): string
            {
                return $this->bytes;
            }

            /** @return array{0: string, 1: int, 2: int} */
            public function getChunkView(): array
            {
                return [$this->bytes, 0, strlen($this->bytes)];
            }
        };

        $registeredCallback($deliver);
        $registeredCallback($deliver);
        $registeredCallback($deliver);

        $unreadCountProp = new \ReflectionProperty($consumer, 'unreadCount');
        $this->assertSame(3, $unreadCountProp->getValue($consumer), 'All 3 chunks worth of messages must be kept');
    }

    /**
     * @return array{0: Consumer, 1: callable, 2: \ArrayObject<int, int>} consumer, deliver callback, credits sent
     */
    private function consumerWithCapturedCredits(int $initialCredit, int $creditWindowBytes): array
    {
        $registeredCallback = null;
        /** @var \ArrayObject<int, int> $credits */
        $credits = new \ArrayObject();
        $connection = $this->createMock(StreamConnection::class);
        $connection->expects($this->any())
            ->method('registerSubscriber')
            ->willReturnCallback(function (int $id, callable $cb) use (&$registeredCallback): void {
                $registeredCallback = $cb;
            });
        $connection->expects($this->any())
            ->method('sendMessage')
            ->willReturnCallback(function ($request) use ($credits): void {
                if ($request instanceof CreditRequestV1) {
                    $credits[] = $request->toArray()['credit'];
                }
            });
        $connection->expects($this->any())->method('request')->willReturn(new \stdClass());

        $consumer = new Consumer(
            $connection,
            'test-stream',
            1,
            OffsetSpec::first(),
            initialCredit: $initialCredit,
            maxBufferSize: 1_000_000,
            creditWindowBytes: $creditWindowBytes,
        );
        $this->assertIsCallable($registeredCallback);

        return [$consumer, $registeredCallback, $credits];
    }

    private function deliverOf(string $chunkBytes): object
    {
        return new class ($chunkBytes) {
            public function __construct(private readonly string $bytes)
            {
            }

            /** @return array{0: string, 1: int, 2: int} */
            public function getChunkView(): array
            {
                return [$this->bytes, 0, strlen($this->bytes)];
            }
        };
    }

    public function testSmallChunksGrowCreditTargetToFillByteWindow(): void
    {
        // #500: a ~40-byte chunk with a 4000-byte window should raise the in-flight
        // target to ~100 chunks and grant the missing credit immediately.
        [$consumer, $deliver, $credits] = $this->consumerWithCapturedCredits(10, 4000);
        $chunk = $this->buildOneEntryChunk('X');

        $deliver($this->deliverOf($chunk));

        $expectedTarget = (int) ceil(4000 / strlen($chunk));
        $this->assertSame($expectedTarget, $consumer->getCreditTarget());
        // One replacement credit + the units needed to grow from 10 to the target.
        $this->assertSame([1 + ($expectedTarget - 10)], $credits->getArrayCopy());
    }

    public function testLargeChunksKeepInitialCreditAsTarget(): void
    {
        // Window smaller than one chunk: target stays at initialCredit and each
        // delivered chunk is replaced by exactly one credit, as before #500.
        [$consumer, $deliver, $credits] = $this->consumerWithCapturedCredits(10, 16);
        $chunk = $this->buildOneEntryChunk('X');

        $deliver($this->deliverOf($chunk));
        $deliver($this->deliverOf($chunk));

        $this->assertSame(10, $consumer->getCreditTarget());
        $this->assertSame([1, 1], $credits->getArrayCopy());
    }

    public function testZeroCreditWindowDisablesAdaptation(): void
    {
        [$consumer, $deliver, $credits] = $this->consumerWithCapturedCredits(10, 0);
        $deliver($this->deliverOf($this->buildOneEntryChunk('X')));

        $this->assertSame(10, $consumer->getCreditTarget());
        $this->assertSame([1], $credits->getArrayCopy());
    }

    public function testCreditTargetIsCappedAtMaxCredit(): void
    {
        // RabbitMQ decodes the Credit field as a signed int16: 32768+ turns
        // negative and silently kills the subscription (verified on 4.3.5).
        [$consumer, $deliver, $credits] = $this->consumerWithCapturedCredits(10, 1_000_000_000);
        $deliver($this->deliverOf($this->buildOneEntryChunk('X')));

        $this->assertSame(Consumer::MAX_CREDIT, $consumer->getCreditTarget());
        $this->assertSame([Consumer::MAX_CREDIT - 9], $credits->getArrayCopy());
        $this->assertLessThanOrEqual(32767, max($credits->getArrayCopy()));
    }

    public function testInitialCreditMustBeWithinMaxCredit(): void
    {
        $connection = $this->createMock(StreamConnection::class);
        $this->expectException(InvalidArgumentException::class);
        new Consumer($connection, 'test-stream', 1, OffsetSpec::first(), initialCredit: 0);
    }

    /**
     * Builds a minimal valid single-entry Osiris user-data chunk containing one
     * simple entry with the given raw entry bytes.
     */
    private function buildOneEntryChunk(string $entryData): string
    {
        $dataSection = pack('N', strlen($entryData)) . $entryData;
        $dataLength = strlen($dataSection);

        $header = pack('C', 0x50); // magic=5, version=0
        $header .= pack('C', 0x00); // chunkType: user data
        $header .= pack('n', 1); // numEntries
        $header .= pack('N', 1); // numRecords
        $header .= pack('J', 1000); // timestamp
        $header .= pack('J', 1); // epoch
        $header .= pack('J', 0); // chunkFirstOffset
        $header .= pack('N', 0); // chunkCrc
        $header .= pack('N', $dataLength); // dataLength
        $header .= pack('N', 0); // trailerLength
        $header .= pack('C', 0); // bloomSize
        $header .= "\x00\x00\x00"; // reserved

        return $header . $dataSection;
    }

    public function testNoCreditGrantedWhileUnreadCountAtOrOverMaxBufferSize(): void
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

        $sendPendingCredits = new \ReflectionMethod($consumer, 'sendPendingCredits');
        $unreadCountProp = new \ReflectionProperty($consumer, 'unreadCount');

        // Below the bound: gate is open, but pendingCredits is 0 so nothing to send.
        $unreadCountProp->setValue($consumer, 1);
        $creditRequests = [];
        $sendPendingCredits->invoke($consumer);
        $this->assertCount(0, $creditRequests);

        // At/over the bound: gate closed regardless of pendingCredits.
        $unreadCountProp->setValue($consumer, 2);
        $this->setPendingCredits($consumer, 1);
        $creditRequests = [];
        $sendPendingCredits->invoke($consumer);

        $this->assertCount(0, $creditRequests, 'Credits should be withheld when unreadCount >= maxBufferSize');
        $this->assertSame(1, $this->getPendingCredits($consumer), 'pendingCredits must remain owed, not lost');
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

        $this->setBuffer($consumer, [$msg1, $msg2]);
        // Simulate 2 chunks having been delivered without replenishing credit
        // (creditsInFlight dropped by 2 from its initial value), leaving 2
        // credit units owed.
        $this->setCreditsInFlight($consumer, 8);
        $this->setPendingCredits($consumer, 2);

        $consumer->read();

        $this->assertInstanceOf(
            CreditRequestV1::class,
            $capturedRequest,
            'Pending credits should be sent after read()'
        );
        $this->assertSame(2, $capturedRequest->toArray()['credit']);
        $this->assertSame(0, $this->getPendingCredits($consumer));
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

        $this->setBuffer($consumer, [$msg1, $msg2]);
        $this->setCreditsInFlight($consumer, 9);
        $this->setPendingCredits($consumer, 1);

        $consumer->readOne();

        $this->assertInstanceOf(
            CreditRequestV1::class,
            $capturedRequest,
            'Pending credits should be sent after readOne()'
        );
        $this->assertSame(1, $capturedRequest->toArray()['credit']);
    }

    public function testNoCreditsSentWhenPendingCreditsIsZero(): void
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

        $consumer = new Consumer($connection, 'test-stream', 1, OffsetSpec::first());

        $this->setPendingCredits($consumer, 0);

        $sendPendingCredits = new \ReflectionMethod($consumer, 'sendPendingCredits');
        $sendPendingCredits->invoke($consumer);

        $this->assertCount(0, $creditRequests, 'No credits should be sent when pendingCredits is 0');
    }

    public function testNoCreditsSentWhenPendingCreditsIsNegative(): void
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

        $consumer = new Consumer($connection, 'test-stream', 1, OffsetSpec::first());

        $this->setPendingCredits($consumer, -5);

        $sendPendingCredits = new \ReflectionMethod($consumer, 'sendPendingCredits');
        $sendPendingCredits->invoke($consumer);

        $this->assertCount(0, $creditRequests, 'No credits should be sent when pendingCredits is negative');
    }

    public function testCreditsCappedAtMaxCredit(): void
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

        $consumer = new Consumer(
            $connection,
            'test-stream',
            1,
            OffsetSpec::first(),
            initialCredit: 32767,
            maxBufferSize: 100000,
        );

        // No credit currently outstanding, so headroom (32,767) does not bind —
        // only the protocol's own uint16 credit field width does.
        $this->setCreditsInFlight($consumer, 0);
        $this->setPendingCredits($consumer, 70000);

        $sendPendingCredits = new \ReflectionMethod($consumer, 'sendPendingCredits');
        $sendPendingCredits->invoke($consumer);

        $this->assertInstanceOf(CreditRequestV1::class, $capturedRequest);
        $this->assertSame(32767, $capturedRequest->toArray()['credit'], 'Credits should be capped at MAX_CREDIT');
        $this->assertSame(70000 - 32767, $this->getPendingCredits($consumer));
    }

    public function testCreditsCappedAtInitialCreditHeadroom(): void
    {
        // #473: outstanding (in-flight) credit must never exceed initialCredit,
        // regardless of how many credit units are owed or how big the buffer is.
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

        $consumer = new Consumer(
            $connection,
            'test-stream',
            1,
            OffsetSpec::first(),
            initialCredit: 100,
            maxBufferSize: 100000,
        );

        // 1 credit unit already outstanding — headroom is 99.
        $this->setCreditsInFlight($consumer, 1);
        $this->setPendingCredits($consumer, 200);

        $sendPendingCredits = new \ReflectionMethod($consumer, 'sendPendingCredits');
        $sendPendingCredits->invoke($consumer);

        $this->assertInstanceOf(CreditRequestV1::class, $capturedRequest);
        $this->assertSame(
            99,
            $capturedRequest->toArray()['credit'],
            'Credits should be capped at initialCredit - creditsInFlight'
        );
        $this->assertSame(200 - 99, $this->getPendingCredits($consumer));
    }

    public function testPendingCreditsDecrementedCorrectlyAfterSend(): void
    {
        $capturedRequests = [];

        $connection = $this->createMock(StreamConnection::class);
        $connection->expects($this->any())->method('registerSubscriber');
        $connection->expects($this->any())
            ->method('sendMessage')
            ->willReturnCallback(function ($request) use (&$capturedRequests): void {
                if ($request instanceof CreditRequestV1) {
                    $capturedRequests[] = $request;
                }
            });
        $connection->expects($this->any())->method('readMessage')->willReturn(new \stdClass());

        $consumer = new Consumer(
            $connection,
            'test-stream',
            1,
            OffsetSpec::first(),
            initialCredit: 50,
            maxBufferSize: 100,
        );

        $this->setCreditsInFlight($consumer, 0);
        $this->setPendingCredits($consumer, 50);

        $sendPendingCredits = new \ReflectionMethod($consumer, 'sendPendingCredits');
        $sendPendingCredits->invoke($consumer);

        $this->assertCount(1, $capturedRequests);
        $this->assertSame(50, $capturedRequests[0]->toArray()['credit']);
        $this->assertSame(0, $this->getPendingCredits($consumer), 'pendingCredits should be decremented to 0');
    }

    public function testPendingCreditsRemainOwedWhileBufferFullThenSentOnceItDrains(): void
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

        $consumer = new Consumer(
            $connection,
            'test-stream',
            1,
            OffsetSpec::first(),
            initialCredit: 10,
            maxBufferSize: 5,
        );

        $unreadCountProp = new \ReflectionProperty($consumer, 'unreadCount');

        // Buffer full (unreadCount == maxBufferSize): 3 credit units owed, none sent.
        $unreadCountProp->setValue($consumer, 5);
        $this->setCreditsInFlight($consumer, 7);
        $this->setPendingCredits($consumer, 3);

        $sendPendingCredits = new \ReflectionMethod($consumer, 'sendPendingCredits');
        $sendPendingCredits->invoke($consumer);

        $this->assertCount(0, $creditRequests, 'No credits should be sent while the buffer is full');
        $this->assertSame(3, $this->getPendingCredits($consumer), 'pendingCredits should remain unchanged');

        // Buffer drains below the bound: owed credits are now granted, bounded
        // by the initialCredit headroom (10 - 7 = 3).
        $unreadCountProp->setValue($consumer, 2);
        $sendPendingCredits->invoke($consumer);

        $this->assertCount(1, $creditRequests);
        $this->assertSame(3, $creditRequests[0]->toArray()['credit']);
        $this->assertSame(0, $this->getPendingCredits($consumer));
    }


    // ---------------------------------------------------------------------
    // A stored offset is the NEXT offset to consume, not the last consumed
    // one — otherwise every resume redelivers one message (#396).
    // ---------------------------------------------------------------------

    public function testAutoCommitStoresTheNextOffsetToConsume(): void
    {
        [$connection, $stored] = $this->connectionCapturingStoredOffsets();

        $consumer = new Consumer(
            $connection,
            'test-stream',
            1,
            OffsetSpec::first(),
            name: 'my-consumer',
            autoCommit: 1,
        );
        $this->setBuffer($consumer, [new Message(41, 0, 'payload')]);
        $consumer->read(0.0);

        $this->assertSame(
            [42],
            $this->storedOffsetValues($stored),
            'Offset 41 was processed, so the resume point is 42'
        );
    }

    public function testAutoCommitOnReadOneStoresTheNextOffsetToConsume(): void
    {
        [$connection, $stored] = $this->connectionCapturingStoredOffsets();

        $consumer = new Consumer(
            $connection,
            'test-stream',
            1,
            OffsetSpec::first(),
            name: 'my-consumer',
            autoCommit: 1,
        );
        $this->setBuffer($consumer, [new Message(7, 0, 'payload')]);
        $consumer->readOne(0.0);

        $this->assertSame([8], $this->storedOffsetValues($stored));
    }

    public function testCloseStoresTheNextOffsetToConsume(): void
    {
        [$connection, $stored] = $this->connectionCapturingStoredOffsets();

        $consumer = new Consumer(
            $connection,
            'test-stream',
            1,
            OffsetSpec::first(),
            name: 'my-consumer',
            autoCommit: 5,
        );
        $this->setBuffer($consumer, [new Message(100, 0, 'payload')]);
        $consumer->read(0.0);
        $this->assertSame(
            [],
            $this->storedOffsetValues($stored),
            'Below the auto-commit threshold, nothing is stored yet'
        );

        $consumer->close();

        $this->assertSame([101], $this->storedOffsetValues($stored));
    }

    public function testSingleActiveConsumerResumesAtTheStoredOffsetWithoutSkipping(): void
    {
        $requests = new CapturedObjects();
        $connection = $this->createMock(StreamConnection::class);
        $connection->expects($this->any())->method('registerSubscriber');
        $connection->expects($this->any())->method('sendMessage');
        $connection->expects($this->any())->method('readMessage')->willReturn(new \stdClass());
        $connection->expects($this->any())
            ->method('request')
            ->willReturnCallback(function (object $request) use ($requests): object {
                $requests->add($request);
                if ($request instanceof QueryOffsetRequestV1) {
                    return QueryOffsetResponseV1::fromArray(['correlationId' => 1, 'offset' => 42]);
                }
                return new \stdClass();
            });

        $consumer = new Consumer(
            $connection,
            'test-stream',
            1,
            OffsetSpec::first(),
            name: 'my-consumer',
            singleActiveConsumer: true,
        );

        $resume = $this->invokeConsumerUpdate($consumer, true);

        // The stored 42 IS the next offset: adding 1 to it (the old behaviour,
        // which paired with storing the last consumed offset) would skip a
        // message now that auto-commit stores lastOffset + 1.
        $this->assertInstanceOf(OffsetSpec::class, $resume);
        $this->assertSame(42, $resume->getValue());
    }

    public function testCloseIsIdempotent(): void
    {
        $unsubscribes = 0;
        $connection = $this->createMock(StreamConnection::class);
        $connection->expects($this->any())->method('registerSubscriber');
        $connection->expects($this->any())->method('sendMessage');
        $connection->expects($this->any())->method('readMessage')->willReturn(new \stdClass());
        $connection->expects($this->any())
            ->method('request')
            ->willReturnCallback(function (object $request) use (&$unsubscribes): object {
                if ($request instanceof UnsubscribeRequestV1) {
                    $unsubscribes++;
                }
                return new \stdClass();
            });

        $consumer = new Consumer($connection, 'test-stream', 1, OffsetSpec::first());
        $consumer->close();
        $consumer->close();

        $this->assertSame(1, $unsubscribes, 'Unsubscribe must be sent once, however often close() is called');
        $this->assertTrue($consumer->isClosed());
    }

    public function testCloseInvokesTheOnCloseCallbackWithTheSubscriptionId(): void
    {
        $connection = $this->createMock(StreamConnection::class);
        $connection->expects($this->any())->method('registerSubscriber');
        $connection->expects($this->any())->method('sendMessage');
        $connection->expects($this->any())->method('readMessage')->willReturn(new \stdClass());
        $connection->expects($this->any())->method('request')->willReturn(new \stdClass());
        $released = [];

        $consumer = new Consumer(
            $connection,
            'test-stream',
            7,
            OffsetSpec::first(),
            onClose: function (int $id) use (&$released): void {
                $released[] = $id;
            },
        );
        $consumer->close();
        $consumer->close();

        $this->assertSame([7], $released, 'The id is released exactly once');
    }

    /**
     * @return array{StreamConnection, CapturedObjects<StoreOffsetRequestV1>}
     */
    private function connectionCapturingStoredOffsets(): array
    {
        /** @var CapturedObjects<StoreOffsetRequestV1> $stored */
        $stored = new CapturedObjects();
        $connection = $this->createMock(StreamConnection::class);
        $connection->expects($this->any())->method('registerSubscriber');
        $connection->expects($this->any())->method('readMessage')->willReturn(new \stdClass());
        $connection->expects($this->any())->method('request')->willReturn(new \stdClass());
        $connection->expects($this->any())
            ->method('sendMessage')
            ->willReturnCallback(function (object $request) use ($stored): void {
                if ($request instanceof StoreOffsetRequestV1) {
                    $stored->add($request);
                }
            });

        return [$connection, $stored];
    }

    /**
     * @param CapturedObjects<StoreOffsetRequestV1> $stored
     * @return list<int>
     */
    private function storedOffsetValues(CapturedObjects $stored): array
    {
        $offsets = [];
        foreach ($stored->all() as $request) {
            $offset = $request->toArray()['offset'];
            $this->assertIsInt($offset);
            $offsets[] = $offset;
        }

        return $offsets;
    }

    private function invokeConsumerUpdate(Consumer $consumer, bool $active): ?OffsetSpec
    {
        $method = new \ReflectionMethod($consumer, 'defaultConsumerUpdateHandler');
        $result = $method->invoke($consumer, new ConsumerUpdateResponseV1(1, $active));

        return $result instanceof OffsetSpec ? $result : null;
    }

    /**
     * @return array{
     *     0: StreamConnection&\PHPUnit\Framework\MockObject\MockObject,
     *     1: CapturedClosures,
     *     2: CapturedObjects<object>
     * }
     */
    private function connectionCapturingMetadataHandler(): array
    {
        $handlers = new CapturedClosures();
        /** @var CapturedObjects<object> $requests */
        $requests = new CapturedObjects();
        $connection = $this->createMock(StreamConnection::class);
        $connection->expects($this->any())
            ->method('registerMetadataUpdateHandler')
            ->willReturnCallback(function (string $stream, string $id, \Closure $h) use ($handlers): void {
                $this->assertSame('test-stream', $stream);
                $this->assertSame('subscription-1', $id);
                $handlers->add($h);
            });
        $connection->expects($this->any())
            ->method('request')
            ->willReturnCallback(function (object $request) use ($requests): object {
                $requests->add($request);
                return new \stdClass();
            });
        return [$connection, $handlers, $requests];
    }

    public function testMetadataUpdateMarksSubscriptionLostAndReadResubscribes(): void
    {
        [$connection, $handlers, $requests] = $this->connectionCapturingMetadataHandler();
        // readLoop() reports "nothing dispatched" so read() returns on its deadline.
        $connection->expects($this->any())->method('readLoop')->willReturn(0);

        $consumer = new Consumer($connection, 'test-stream', 1, OffsetSpec::next(), initialCredit: 7);
        $this->assertSame(1, $handlers->count(), 'Consumer must register a MetadataUpdate handler');
        $this->assertSame(1, $requests->count());

        $handlers->at()(
            new MetadataUpdateResponseV1(ResponseCodeEnum::STREAM_NOT_AVAILABLE->value, 'test-stream')
        );
        $this->assertTrue($consumer->isSubscriptionLost());

        $this->assertSame([], $consumer->read(0.05));

        $this->assertFalse($consumer->isSubscriptionLost());
        $this->assertSame(1, $consumer->getResubscribeCount());
        $this->assertSame(2, $requests->count());
        $resubscribe = $requests->at(1);
        $this->assertInstanceOf(SubscribeRequestV1::class, $resubscribe);
        $subscribe = $resubscribe->toArray();
        $this->assertIsArray($subscribe['offsetSpec']);
        $this->assertSame(
            OffsetSpec::next()->getType(),
            $subscribe['offsetSpec']['type'],
            'Nothing consumed yet: re-subscribe uses the original OffsetSpec'
        );
        $this->assertSame(7, $subscribe['credit']);
    }

    public function testResubscribeBacksOffWhileStreamIsMissing(): void
    {
        $handlers = new CapturedClosures();
        $subscribes = 0;
        $connection = $this->createMock(StreamConnection::class);
        $connection->expects($this->any())
            ->method('registerMetadataUpdateHandler')
            ->willReturnCallback(function (string $stream, string $id, \Closure $h) use ($handlers): void {
                $handlers->add($h);
            });
        $connection->expects($this->any())
            ->method('request')
            ->willReturnCallback(function (object $request) use (&$subscribes): object {
                $subscribes++;
                if ($subscribes > 1) {
                    throw new ProtocolException('gone', responseCode: ResponseCodeEnum::STREAM_NOT_EXIST);
                }
                return new \stdClass();
            });
        $connection->expects($this->any())
            ->method('readLoop')
            ->willReturnCallback(function (?int $maxFrames, ?float $timeout): int {
                usleep((int) (($timeout ?? 0.0) * 1_000_000));
                return 0;
            });

        $consumer = new Consumer($connection, 'test-stream', 1, OffsetSpec::first());
        $handlers->at()(
            new MetadataUpdateResponseV1(ResponseCodeEnum::STREAM_NOT_AVAILABLE->value, 'test-stream')
        );

        $this->assertSame([], $consumer->read(0.3));

        $this->assertTrue($consumer->isSubscriptionLost(), 'Stream still missing: subscription stays lost');
        // 0.3s with back-off 0.05, 0.1, 0.2: a handful of attempts, not a busy loop.
        $this->assertGreaterThanOrEqual(3, $subscribes);
        $this->assertLessThanOrEqual(6, $subscribes);
    }

    public function testResubscribeRethrowsNonRetryableErrors(): void
    {
        $handlers = new CapturedClosures();
        $calls = 0;
        $connection = $this->createMock(StreamConnection::class);
        $connection->expects($this->any())
            ->method('registerMetadataUpdateHandler')
            ->willReturnCallback(function (string $stream, string $id, \Closure $h) use ($handlers): void {
                $handlers->add($h);
            });
        $connection->expects($this->any())
            ->method('request')
            ->willReturnCallback(function () use (&$calls): object {
                $calls++;
                if ($calls > 1) {
                    throw new ProtocolException('nope', responseCode: ResponseCodeEnum::ACCESS_REFUSED);
                }
                return new \stdClass();
            });

        $consumer = new Consumer($connection, 'test-stream', 1, OffsetSpec::first());
        $handlers->at()(
            new MetadataUpdateResponseV1(ResponseCodeEnum::STREAM_NOT_AVAILABLE->value, 'test-stream')
        );

        $this->expectException(ProtocolException::class);
        $consumer->resubscribeIfLost();
    }

    public function testCloseOnLostSubscriptionSkipsUnsubscribe(): void
    {
        [$connection, $handlers, $requests] = $this->connectionCapturingMetadataHandler();
        $connection->expects($this->once())->method('unregisterSubscriber')->with(1);
        $connection->expects($this->once())
            ->method('unregisterMetadataUpdateHandler')
            ->with('test-stream', 'subscription-1');

        $consumer = new Consumer($connection, 'test-stream', 1, OffsetSpec::first());
        $handlers->at()(
            new MetadataUpdateResponseV1(ResponseCodeEnum::STREAM_NOT_AVAILABLE->value, 'test-stream')
        );

        $consumer->close();

        foreach ($requests->all() as $request) {
            $this->assertNotInstanceOf(
                UnsubscribeRequestV1::class,
                $request,
                'The broker already dropped the subscription'
            );
        }
    }
}
