<?php

declare(strict_types=1);

namespace CrazyGoat\RabbitStream\Tests;

use CrazyGoat\RabbitStream\Exception\ConnectionException;
use CrazyGoat\RabbitStream\Request\CreateRequestV1;
use CrazyGoat\RabbitStream\Request\CreditRequestV1;
use CrazyGoat\RabbitStream\Request\PublishRequestV1;
use CrazyGoat\RabbitStream\Request\SaslAuthenticateRequestV1;
use CrazyGoat\RabbitStream\Request\StoreOffsetRequestV1;
use CrazyGoat\RabbitStream\Request\TuneRequestV1;
use CrazyGoat\RabbitStream\Response\ConsumerUpdateResponseV1;
use CrazyGoat\RabbitStream\Response\DeliverResponseV1;
use CrazyGoat\RabbitStream\Response\MetadataUpdateResponseV1;
use CrazyGoat\RabbitStream\StreamConnection;
use CrazyGoat\RabbitStream\Tests\Util\RecordingLogger;
use CrazyGoat\RabbitStream\VO\PublishedMessage;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

class StreamConnectionTest extends TestCase
{
    public function testUsesNullLoggerByDefault(): void
    {
        $connection = new StreamConnection('127.0.0.1', 5552);

        // NullLogger should be used by default - no errors should occur
        $this->assertInstanceOf(StreamConnection::class, $connection);
    }

    public function testAcceptsCustomLogger(): void
    {
        $logger = $this->createMock(LoggerInterface::class);

        $connection = new StreamConnection('127.0.0.1', 5552, $logger);

        $this->assertInstanceOf(StreamConnection::class, $connection);
    }

    public function testAcceptsNullLoggerExplicitly(): void
    {
        $connection = new StreamConnection('127.0.0.1', 5552, new NullLogger());

        $this->assertInstanceOf(StreamConnection::class, $connection);
    }

    public function testReadFrameAcceptsFloatTimeout(): void
    {
        $connection = new StreamConnection('127.0.0.1', 5552);

        // Test that float timeout is accepted (method signature)
        $reflection = new \ReflectionMethod($connection, 'readFrame');
        $params = $reflection->getParameters();

        $this->assertCount(1, $params);
        $this->assertEquals('timeout', $params[0]->getName());
        $type = $params[0]->getType();
        $this->assertInstanceOf(\ReflectionNamedType::class, $type);
        $this->assertEquals('float', $type->getName());
        $this->assertEquals(30.0, $params[0]->getDefaultValue());
    }

    public function testReadMessageAcceptsFloatTimeout(): void
    {
        $connection = new StreamConnection('127.0.0.1', 5552);

        $reflection = new \ReflectionMethod($connection, 'readMessage');
        $params = $reflection->getParameters();

        $this->assertCount(1, $params);
        $this->assertEquals('timeout', $params[0]->getName());
        $type = $params[0]->getType();
        $this->assertInstanceOf(\ReflectionNamedType::class, $type);
        $this->assertEquals('float', $type->getName());
        $this->assertEquals(30.0, $params[0]->getDefaultValue());
    }

    public function testReadLoopAcceptsFloatTimeout(): void
    {
        $connection = new StreamConnection('127.0.0.1', 5552);

        $reflection = new \ReflectionMethod($connection, 'readLoop');
        $params = $reflection->getParameters();

        // maxFrames and timeout
        $this->assertCount(2, $params);
        $this->assertEquals('timeout', $params[1]->getName());
        $type = $params[1]->getType();
        $this->assertInstanceOf(\ReflectionNamedType::class, $type);
        $this->assertEquals('float', $type->getName());
        $this->assertNull($params[1]->getDefaultValue());
    }

    public function testSendFrameAcceptsOptionalWriteTimeout(): void
    {
        $connection = new StreamConnection('127.0.0.1', 5552);

        $reflection = new \ReflectionMethod($connection, 'sendFrame');
        $params = $reflection->getParameters();

        // frame and optional timeout
        $this->assertCount(2, $params);
        $this->assertEquals('timeout', $params[1]->getName());
        $this->assertTrue($params[1]->isOptional());
        $this->assertNull($params[1]->getDefaultValue());
    }

    public function testSendMessageAcceptsOptionalWriteTimeout(): void
    {
        $connection = new StreamConnection('127.0.0.1', 5552);

        $reflection = new \ReflectionMethod($connection, 'sendMessage');
        $params = $reflection->getParameters();

        // request and optional timeout
        $this->assertCount(2, $params);
        $this->assertEquals('timeout', $params[1]->getName());
        $this->assertTrue($params[1]->isOptional());
        $this->assertNull($params[1]->getDefaultValue());
    }

    public function testDefaultMaxFrameSizeIs8MB(): void
    {
        $connection = new StreamConnection('127.0.0.1', 5552);

        $this->assertEquals(8 * 1024 * 1024, $connection->getMaxFrameSize());
    }

    public function testMaxFrameSizeCanBeChanged(): void
    {
        $connection = new StreamConnection('127.0.0.1', 5552);

        $connection->setMaxFrameSize(1024 * 1024); // 1MB

        $this->assertEquals(1024 * 1024, $connection->getMaxFrameSize());
    }

    public function testMaxFrameSizeCanBeSetToZero(): void
    {
        $connection = new StreamConnection('127.0.0.1', 5552);

        $connection->setMaxFrameSize(0); // 0 = no limit

        $this->assertEquals(0, $connection->getMaxFrameSize());
    }

    public function testSetMaxFrameSizeRejectsNegativeValues(): void
    {
        $connection = new StreamConnection('127.0.0.1', 5552);

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Max frame size must be >= 0');

        $connection->setMaxFrameSize(-1);
    }

    public function testReadFrameThrowsWhenFrameSizeExceedsLimit(): void
    {
        [$serverSocket, $clientSocket] = $this->createSocketPair();

        $connection = new StreamConnection('127.0.0.1', 5552);
        $this->injectSocket($connection, $clientSocket);

        $connection->setMaxFrameSize(1024);

        $frameSize = 2048;
        socket_write($serverSocket, pack('N', $frameSize));

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessageMatches('/Frame size \d+ exceeds maximum allowed \d+/');

        $connection->readFrame();

        socket_close($serverSocket);
        socket_close($clientSocket);
    }

    public function testReadFrameClosesConnectionWhenFrameSizeExceedsLimit(): void
    {
        [$serverSocket, $clientSocket] = $this->createSocketPair();

        $connection = new StreamConnection('127.0.0.1', 5552);
        $this->injectSocket($connection, $clientSocket);

        $connection->setMaxFrameSize(1024);

        socket_write($serverSocket, pack('N', 2048));

        try {
            $connection->readFrame();
        } catch (\RuntimeException) {
        }

        $this->assertFalse($connection->isConnected());

        socket_close($serverSocket);
    }

    public function testReadFrameWithZeroMaxFrameSizeAllowsAnyFrame(): void
    {
        [$serverSocket, $clientSocket] = $this->createSocketPair();

        $connection = new StreamConnection('127.0.0.1', 5552);
        $this->injectSocket($connection, $clientSocket);

        $connection->setMaxFrameSize(0);

        $payload = str_repeat('x', 100);
        socket_write($serverSocket, pack('N', strlen($payload)) . $payload);

        $buffer = $connection->readFrame();

        $this->assertNotNull($buffer);

        socket_close($serverSocket);
        socket_close($clientSocket);
    }

    public function testSendMessageDoesNotIncrementCorrelationIdForNonCorrelatedRequests(): void
    {
        [$serverSocket, $clientSocket] = $this->createSocketPair();

        $connection = new StreamConnection('127.0.0.1', 5552);
        $this->injectSocket($connection, $clientSocket);

        // Get initial correlationId via reflection
        $reflection = new \ReflectionProperty($connection, 'correlationId');
        $initialId = $reflection->getValue($connection);
        assert(is_int($initialId));

        // Send non-correlated requests - correlationId should NOT change
        $nonCorrelatedRequests = [
            new PublishRequestV1(1, new PublishedMessage(0, 'test')),
            new CreditRequestV1(1, 10),
            new StoreOffsetRequestV1('ref', 'stream', 0),
            new TuneRequestV1(0, 0),
        ];

        foreach ($nonCorrelatedRequests as $request) {
            $connection->sendMessage($request);
            $this->assertEquals(
                $initialId,
                $reflection->getValue($connection),
                $request::class . ' should not increment correlationId'
            );
        }

        socket_close($serverSocket);
        socket_close($clientSocket);
    }

    public function testSendMessageIncrementsCorrelationIdForCorrelatedRequests(): void
    {
        [$serverSocket, $clientSocket] = $this->createSocketPair();

        $connection = new StreamConnection('127.0.0.1', 5552);
        $this->injectSocket($connection, $clientSocket);

        // Get initial correlationId via reflection
        $reflection = new \ReflectionProperty($connection, 'correlationId');
        $initialId = $reflection->getValue($connection);
        assert(is_int($initialId));

        // Send correlated request - correlationId SHOULD increment
        $correlatedRequest = new CreateRequestV1('test-stream');
        $connection->sendMessage($correlatedRequest);

        $this->assertEquals(
            $initialId + 1,
            $reflection->getValue($connection),
            'Correlated request should increment correlationId'
        );

        socket_close($serverSocket);
        socket_close($clientSocket);
    }

    public function testDispatchHeartbeatEchoesBackAndInvokesCallback(): void
    {
        [$serverSocket, $clientSocket] = $this->createSocketPair();

        $connection = new StreamConnection('127.0.0.1', 5552);
        $this->injectSocket($connection, $clientSocket);

        $heartbeatReceived = false;
        $connection->onHeartbeat(function () use (&$heartbeatReceived): void {
            $heartbeatReceived = true;
        });

        $frame = $this->buildFrame(0x0017, 1);
        socket_write($serverSocket, $frame);

        $connection->readLoop(maxFrames: 1, timeout: 1.0);

        $this->assertTrue($heartbeatReceived);

        $response = $this->readResponse($serverSocket);
        $this->assertNotNull($response);
        $unpacked = unpack('n', substr($response, 0, 2));
        $this->assertIsArray($unpacked);
        $this->assertEquals(0x0017, $unpacked[1]);

        socket_close($serverSocket);
        socket_close($clientSocket);
    }

    public function testDispatchHeartbeatWithoutCallbackDoesNotCrash(): void
    {
        [$serverSocket, $clientSocket] = $this->createSocketPair();

        $connection = new StreamConnection('127.0.0.1', 5552);
        $this->injectSocket($connection, $clientSocket);

        $frame = $this->buildFrame(0x0017, 1);
        socket_write($serverSocket, $frame);

        $connection->readLoop(maxFrames: 1, timeout: 1.0);

        $response = $this->readResponse($serverSocket);
        $this->assertNotNull($response);

        socket_close($serverSocket);
        socket_close($clientSocket);
    }

    public function testDispatchPublishConfirmInvokesRegisteredCallback(): void
    {
        [$serverSocket, $clientSocket] = $this->createSocketPair();

        $connection = new StreamConnection('127.0.0.1', 5552);
        $this->injectSocket($connection, $clientSocket);

        $receivedIds = [];
        $connection->registerPublisher(
            5,
            function (array $ids) use (&$receivedIds): void {
                $receivedIds = $ids;
            },
            function (): void {
            }
        );

        $content = pack('C', 5)
            . pack('N', 2)
            . pack('J', 100)
            . pack('J', 200);
        $frame = $this->buildFrame(0x0003, 1, $content);
        socket_write($serverSocket, $frame);

        $connection->readLoop(maxFrames: 1, timeout: 1.0);

        $this->assertEquals([100, 200], $receivedIds);

        socket_close($serverSocket);
        socket_close($clientSocket);
    }

    public function testDispatchPublishConfirmIgnoresUnregisteredPublisher(): void
    {
        [$serverSocket, $clientSocket] = $this->createSocketPair();

        $connection = new StreamConnection('127.0.0.1', 5552);
        $this->injectSocket($connection, $clientSocket);

        $invokedCount = 0;
        $connection->registerPublisher(
            1,
            function () use (&$invokedCount): void {
                ++$invokedCount;
            },
            function (): void {
            }
        );

        $content = pack('C', 99)
            . pack('N', 1)
            . pack('J', 100);
        $frame = $this->buildFrame(0x0003, 1, $content);
        socket_write($serverSocket, $frame);

        $connection->readLoop(maxFrames: 1, timeout: 1.0);

        $this->assertEquals(0, $invokedCount);

        socket_close($serverSocket);
        socket_close($clientSocket);
    }

    public function testDispatchPublishErrorInvokesRegisteredCallback(): void
    {
        [$serverSocket, $clientSocket] = $this->createSocketPair();

        $connection = new StreamConnection('127.0.0.1', 5552);
        $this->injectSocket($connection, $clientSocket);

        $receivedErrors = null;
        $connection->registerPublisher(
            3,
            function (): void {
            },
            function (array $errors) use (&$receivedErrors): void {
                $receivedErrors = $errors;
            }
        );

        $content = pack('C', 3)
            . pack('N', 1)
            . pack('J', 50)
            . pack('n', 0x0002);
        $frame = $this->buildFrame(0x0004, 1, $content);
        socket_write($serverSocket, $frame);

        $connection->readLoop(maxFrames: 1, timeout: 1.0);

        $this->assertNotNull($receivedErrors);
        $this->assertCount(1, $receivedErrors);
        $this->assertEquals(50, $receivedErrors[0]->getPublishingId());
        $this->assertEquals(0x0002, $receivedErrors[0]->getCode());

        socket_close($serverSocket);
        socket_close($clientSocket);
    }

    public function testDispatchPublishErrorIgnoresUnregisteredPublisher(): void
    {
        [$serverSocket, $clientSocket] = $this->createSocketPair();

        $connection = new StreamConnection('127.0.0.1', 5552);
        $this->injectSocket($connection, $clientSocket);

        $callbackInvoked = false;
        $connection->registerPublisher(
            1,
            function (): void {
            },
            function () use (&$callbackInvoked): void {
                $callbackInvoked = true;
            }
        );

        $content = pack('C', 99)
            . pack('N', 1)
            . pack('J', 50)
            . pack('n', 0x0002);
        $frame = $this->buildFrame(0x0004, 1, $content);
        socket_write($serverSocket, $frame);

        $connection->readLoop(maxFrames: 1, timeout: 1.0);

        $this->assertFalse($callbackInvoked);

        socket_close($serverSocket);
        socket_close($clientSocket);
    }

    public function testDispatchDeliverInvokesRegisteredSubscriberCallback(): void
    {
        [$serverSocket, $clientSocket] = $this->createSocketPair();

        $connection = new StreamConnection('127.0.0.1', 5552);
        $this->injectSocket($connection, $clientSocket);

        $receivedDeliver = null;
        $connection->registerSubscriber(7, function (DeliverResponseV1 $deliver) use (&$receivedDeliver): void {
            $receivedDeliver = $deliver;
        });

        $chunkData = 'test-chunk-data';
        $content = pack('C', 7) . $chunkData;
        $frame = $this->buildFrame(0x0008, 1, $content);
        socket_write($serverSocket, $frame);

        $connection->readLoop(maxFrames: 1, timeout: 1.0);

        $this->assertNotNull($receivedDeliver);
        $this->assertEquals(7, $receivedDeliver->getSubscriptionId());
        $this->assertEquals($chunkData, $receivedDeliver->getChunkBytes());

        socket_close($serverSocket);
        socket_close($clientSocket);
    }

    public function testDispatchDeliverIgnoresUnregisteredSubscription(): void
    {
        [$serverSocket, $clientSocket] = $this->createSocketPair();

        $connection = new StreamConnection('127.0.0.1', 5552);
        $this->injectSocket($connection, $clientSocket);

        $callbackInvoked = false;
        $connection->registerSubscriber(1, function () use (&$callbackInvoked): void {
            $callbackInvoked = true;
        });

        $chunkData = 'test-chunk-data';
        $content = pack('C', 99) . $chunkData;
        $frame = $this->buildFrame(0x0008, 1, $content);
        socket_write($serverSocket, $frame);

        $connection->readLoop(maxFrames: 1, timeout: 1.0);

        $this->assertFalse($callbackInvoked);

        socket_close($serverSocket);
        socket_close($clientSocket);
    }

    public function testDispatchServerCloseSendsResponseAndClosesConnection(): void
    {
        [$serverSocket, $clientSocket] = $this->createSocketPair();

        $connection = new StreamConnection('127.0.0.1', 5552);
        $this->injectSocket($connection, $clientSocket);

        $correlationId = 42;
        $code = 0x0002;
        $reason = 'Server shutting down';
        $content = pack('N', $correlationId)
            . pack('n', $code)
            . pack('n', strlen($reason))
            . $reason;
        $frame = $this->buildFrame(0x0016, 1, $content);
        socket_write($serverSocket, $frame);

        $connection->readLoop(maxFrames: 1, timeout: 1.0);

        $this->assertFalse($connection->isConnected());

        $response = $this->readResponse($serverSocket);
        $this->assertNotNull($response);
        $unpacked = unpack('n', substr($response, 0, 2));
        $this->assertIsArray($unpacked);
        $this->assertEquals(0x8016, $unpacked[1]);

        socket_close($serverSocket);
    }

    public function testDispatchMetadataUpdateInvokesRegisteredCallback(): void
    {
        [$serverSocket, $clientSocket] = $this->createSocketPair();

        $connection = new StreamConnection('127.0.0.1', 5552);
        $this->injectSocket($connection, $clientSocket);

        $receivedUpdate = null;
        $connection->onMetadataUpdate(function (MetadataUpdateResponseV1 $update) use (&$receivedUpdate): void {
            $receivedUpdate = $update;
        });

        $content = pack('n', 0x0002)
            . pack('n', 11)
            . 'test-stream';
        $frame = $this->buildFrame(0x0010, 1, $content);
        socket_write($serverSocket, $frame);

        $connection->readLoop(maxFrames: 1, timeout: 1.0);

        $this->assertNotNull($receivedUpdate);
        $this->assertEquals(0x0002, $receivedUpdate->getCode());
        $this->assertEquals('test-stream', $receivedUpdate->getStream());

        socket_close($serverSocket);
        socket_close($clientSocket);
    }

    public function testDispatchMetadataUpdateWithoutCallbackDoesNotCrash(): void
    {
        [$serverSocket, $clientSocket] = $this->createSocketPair();

        $connection = new StreamConnection('127.0.0.1', 5552);
        $this->injectSocket($connection, $clientSocket);

        $content = pack('n', 0x0002)
            . pack('n', 11)
            . 'test-stream';
        $frame = $this->buildFrame(0x0010, 1, $content);
        socket_write($serverSocket, $frame);

        // Should not throw or crash
        $connection->readLoop(maxFrames: 1, timeout: 1.0);

        socket_close($serverSocket);
        socket_close($clientSocket);
    }

    public function testDispatchConsumerUpdateInvokesCallbackAndSendsReply(): void
    {
        [$serverSocket, $clientSocket] = $this->createSocketPair();

        $connection = new StreamConnection('127.0.0.1', 5552);
        $this->injectSocket($connection, $clientSocket);

        $receivedQuery = null;
        $connection->onConsumerUpdate(function (ConsumerUpdateResponseV1 $query) use (&$receivedQuery): array {
            $receivedQuery = $query;
            return [2, 1000];
        });

        $correlationId = 55;
        $subscriptionId = 3;
        $active = 1;
        $content = pack('N', $correlationId)
            . pack('C', $subscriptionId)
            . pack('C', $active);
        $frame = $this->buildFrame(0x001a, 1, $content);
        socket_write($serverSocket, $frame);

        $connection->readLoop(maxFrames: 1, timeout: 1.0);

        $this->assertNotNull($receivedQuery);
        $this->assertEquals($correlationId, $receivedQuery->getCorrelationId());
        $this->assertEquals($subscriptionId, $receivedQuery->getSubscriptionId());
        $this->assertTrue($receivedQuery->isActive());

        $response = $this->readResponse($serverSocket);
        $this->assertNotNull($response);
        $unpackedKey = unpack('n', substr($response, 0, 2));
        $this->assertIsArray($unpackedKey);
        $this->assertEquals(0x801a, $unpackedKey[1]);
        $unpackedCorr = unpack('N', substr($response, 4, 4));
        $this->assertIsArray($unpackedCorr);
        $this->assertEquals($correlationId, $unpackedCorr[1]);

        socket_close($serverSocket);
        socket_close($clientSocket);
    }

    public function testDispatchConsumerUpdateWithoutCallbackSendsDefaultReply(): void
    {
        [$serverSocket, $clientSocket] = $this->createSocketPair();

        $connection = new StreamConnection('127.0.0.1', 5552);
        $this->injectSocket($connection, $clientSocket);

        $correlationId = 1;
        $subscriptionId = 1;
        $active = 0;
        $content = pack('N', $correlationId)
            . pack('C', $subscriptionId)
            . pack('C', $active);
        $frame = $this->buildFrame(0x001a, 1, $content);
        socket_write($serverSocket, $frame);

        $connection->readLoop(maxFrames: 1, timeout: 1.0);

        $response = $this->readResponse($serverSocket);
        $this->assertNotNull($response);
        $unpacked = unpack('n', substr($response, 0, 2));
        $this->assertIsArray($unpacked);
        $this->assertEquals(0x801a, $unpacked[1]);

        socket_close($serverSocket);
        socket_close($clientSocket);
    }

    public function testReadLoopHandlesTimeoutLongerThanOneSecond(): void
    {
        [$serverSocket, $clientSocket] = $this->createSocketPair();

        $connection = new StreamConnection('127.0.0.1', 5552);
        $this->injectSocket($connection, $clientSocket);

        $start = microtime(true);
        // No frame is sent: readLoop must poll with socket_select until the
        // deadline. Regression for #382: the seconds were clamped to 1 while
        // the microseconds were derived from the UNCLAMPED remainder
        // (2.5s -> sec = 1, usec = 1_500_000), which select(2) rejects with
        // EINVAL on Linux -> ConnectionException.
        $connection->readLoop(maxFrames: 1, timeout: 2.5);
        $elapsed = microtime(true) - $start;

        $this->assertGreaterThan(2.3, $elapsed, 'readLoop should block for approximately the timeout duration');
        $this->assertLessThan(3.5, $elapsed, 'readLoop should not block significantly longer than the timeout');
        $this->assertTrue($connection->isConnected(), 'connection should remain usable after the timeout');

        socket_close($serverSocket);
        socket_close($clientSocket);
    }

    /**
     * @return array{\Socket, \Socket}
     */
    private function createSocketPair(): array
    {
        $pair = [];
        socket_create_pair(AF_UNIX, SOCK_STREAM, 0, $pair);
        return [$pair[0], $pair[1]];
    }

    private function injectSocket(StreamConnection $connection, \Socket $socket): void
    {
        $reflection = new \ReflectionProperty($connection, 'socket');
        $reflection->setValue($connection, $socket);

        $connectedProp = new \ReflectionProperty($connection, 'connected');
        $connectedProp->setValue($connection, true);
    }

    private function buildFrame(int $key, int $version, string $content = ''): string
    {
        $payload = pack('nn', $key, $version) . $content;
        return pack('N', strlen($payload)) . $payload;
    }

    private function readResponse(\Socket $socket): ?string
    {
        $sizeData = $this->readBytesFromSocket($socket, 4);
        if ($sizeData === null) {
            return null;
        }
        $unpacked = unpack('N', $sizeData);
        if ($unpacked === false) {
            return null;
        }
        $size = $unpacked[1];
        return $this->readBytesFromSocket($socket, $size);
    }

    private function readBytesFromSocket(\Socket $socket, int $length): ?string
    {
        $data = '';
        $remaining = $length;

        while ($remaining > 0) {
            $chunk = socket_read($socket, $remaining);
            if ($chunk === false || $chunk === '') {
                return null;
            }
            $data .= $chunk;
            $remaining -= strlen($chunk);
        }

        return $data;
    }

    public function testSendFrameThrowsWhenSocketIsNull(): void
    {
        $connection = new StreamConnection('127.0.0.1', 5552);

        $this->expectException(ConnectionException::class);
        $this->expectExceptionMessage('socket is not connected');

        $connection->sendMessage(new TuneRequestV1(1024, 100));
    }

    public function testReadFrameThrowsWhenSocketIsNull(): void
    {
        $connection = new StreamConnection('127.0.0.1', 5552);

        $this->expectException(ConnectionException::class);
        $this->expectExceptionMessage('socket is not connected');

        $reflection = new \ReflectionMethod($connection, 'readFrame');
        $reflection->invoke($connection);
    }

    public function testReadLoopThrowsWhenSocketIsNull(): void
    {
        $connection = new StreamConnection('127.0.0.1', 5552);

        $this->expectException(ConnectionException::class);
        $this->expectExceptionMessage('socket is not connected');

        $reflection = new \ReflectionMethod($connection, 'readLoop');
        $reflection->invoke($connection, 1, 0.1);
    }

    public function testSendFrameThrowsAfterClose(): void
    {
        [$serverSocket, $clientSocket] = $this->createSocketPair();
        $connection = new StreamConnection('127.0.0.1', 5552);
        $this->injectSocket($connection, $clientSocket);

        $connection->close();

        $this->expectException(ConnectionException::class);
        $this->expectExceptionMessage('socket is not connected');
        $connection->sendMessage(new TuneRequestV1(1024, 100));

        socket_close($serverSocket);
    }

    public function testReadFrameThrowsAfterClose(): void
    {
        [$serverSocket, $clientSocket] = $this->createSocketPair();
        $connection = new StreamConnection('127.0.0.1', 5552);
        $this->injectSocket($connection, $clientSocket);

        $connection->close();

        $this->expectException(ConnectionException::class);
        $this->expectExceptionMessage('socket is not connected');

        $reflection = new \ReflectionMethod($connection, 'readFrame');
        $reflection->invoke($connection);

        socket_close($serverSocket);
    }

    public function testReadLoopThrowsAfterClose(): void
    {
        [$serverSocket, $clientSocket] = $this->createSocketPair();
        $connection = new StreamConnection('127.0.0.1', 5552);
        $this->injectSocket($connection, $clientSocket);

        $connection->close();

        $this->expectException(ConnectionException::class);
        $this->expectExceptionMessage('socket is not connected');

        $reflection = new \ReflectionMethod($connection, 'readLoop');
        $reflection->invoke($connection, 1, 0.1);

        socket_close($serverSocket);
    }

    // ---- Frame logging redaction tests (#401) ----

    public function testNullLoggerProducesNoDebugOutputOnSendFrame(): void
    {
        [$serverSocket, $clientSocket] = $this->createSocketPair();

        // NullLogger — debug logging is disabled, bin2hex must not run
        $connection = new StreamConnection('127.0.0.1', 5552, new NullLogger());
        $this->injectSocket($connection, $clientSocket);

        // Should not throw and should not produce any debug output.
        // The gate ($debugLogging === false) ensures bin2hex is never called.
        $written = $connection->sendFrame($this->buildFrame(0x0014, 1));
        $this->assertGreaterThan(0, $written);

        socket_close($serverSocket);
        socket_close($clientSocket);
    }

    public function testSaslAuthenticateFrameIsRedactedWhenDebugLoggingEnabled(): void
    {
        [$serverSocket, $clientSocket] = $this->createSocketPair();

        $logger = new RecordingLogger();
        $connection = new StreamConnection('127.0.0.1', 5552, $logger);
        $this->injectSocket($connection, $clientSocket);

        $username = 'secret-user';
        $password = 's3cr3t-p4ss';
        $connection->sendMessage(
            new SaslAuthenticateRequestV1('PLAIN', $username, $password)
        );

        $debugMessages = $logger->debugMessages();
        $this->assertCount(1, $debugMessages, 'exactly one debug message expected for sendFrame');

        $msg = $debugMessages[0];
        $this->assertStringContainsString('redacted', $msg);
        $this->assertStringContainsString('SASL_AUTHENTICATE', $msg);

        // The credential bytes must NOT appear in the log — neither as raw text
        // nor as hex.
        $this->assertStringNotContainsString($username, $msg);
        $this->assertStringNotContainsString($password, $msg);
        $this->assertStringNotContainsString(bin2hex($username), $msg);
        $this->assertStringNotContainsString(bin2hex($password), $msg);

        socket_close($serverSocket);
        socket_close($clientSocket);
    }

    public function testNonSaslFrameProducesNormalHexDebugLineWhenDebugLoggingEnabled(): void
    {
        [$serverSocket, $clientSocket] = $this->createSocketPair();

        $logger = new RecordingLogger();
        $connection = new StreamConnection('127.0.0.1', 5552, $logger);
        $this->injectSocket($connection, $clientSocket);

        // TuneRequestV1 is not SASL_AUTHENTICATE, so we expect normal hex logging.
        $connection->sendMessage(new TuneRequestV1(1024, 100));

        $debugMessages = $logger->debugMessages();
        $this->assertCount(1, $debugMessages);
        $this->assertStringStartsWith('Socket -> ', $debugMessages[0]);
        // Must contain hex digits (bin2hex output), not a redaction marker.
        $this->assertStringNotContainsString('redacted', $debugMessages[0]);
        $this->assertMatchesRegularExpression('/^Socket -> [0-9a-f]+$/', $debugMessages[0]);

        socket_close($serverSocket);
        socket_close($clientSocket);
    }

    /**
     * Synthetic test: drive debugFrame()'s key-match branch through the
     * readFrame() entry point using a frame whose command key is the
     * SASL_AUTHENTICATE REQUEST key (0x0013).
     *
     * This is NOT a real wire scenario — a server never sends 0x0013 on the
     * read path; the SASL_AUTHENTICATE *response* uses 0x8013
     * (KeyEnum::SASL_AUTHENTICATE_RESPONSE), which debugFrame() does NOT
     * redact (and does not need to: the response carries no client
     * credentials — the leak is solely in the request, see
     * testSaslAuthenticateFrameIsRedactedWhenDebugLoggingEnabled). This test
     * only exercises the redaction branch of debugFrame() via readFrame() to
     * confirm the helper behaves identically on both entry points.
     */
    public function testDebugFrameRedactsFrameWithSaslAuthenticateRequestKeyOnReadPath(): void
    {
        [$serverSocket, $clientSocket] = $this->createSocketPair();

        $logger = new RecordingLogger();
        $connection = new StreamConnection('127.0.0.1', 5552, $logger);
        $this->injectSocket($connection, $clientSocket);

        // Fabricated frame with the SASL_AUTHENTICATE REQUEST key (0x0013) and
        // credential-like bytes in the body. Written from the server side purely
        // to drive debugFrame() through readFrame(); never occurs on the wire.
        $username = 'secret-user';
        $password = 's3cr3t-p4ss';
        $payload = pack('nn', 0x0013, 1)
            . pack('N', 1)        // correlationId
            . pack('n', 0x0001)   // responseCode OK
            . "\0" . $username . "\0" . $password;
        socket_write($serverSocket, pack('N', strlen($payload)) . $payload);

        $connection->readFrame(timeout: 1.0);

        $debugMessages = $logger->debugMessages();
        $this->assertCount(1, $debugMessages, 'exactly one debug message expected for readFrame');

        $msg = $debugMessages[0];
        $this->assertStringContainsString('redacted', $msg);
        $this->assertStringContainsString('SASL_AUTHENTICATE', $msg);
        $this->assertStringNotContainsString($username, $msg);
        $this->assertStringNotContainsString($password, $msg);
        $this->assertStringNotContainsString(bin2hex($username), $msg);
        $this->assertStringNotContainsString(bin2hex($password), $msg);

        socket_close($serverSocket);
        socket_close($clientSocket);
    }

    /**
     * A real SASL_AUTHENTICATE *response* (key 0x8013) carries no client
     * credentials, so debugFrame() must log it as normal hex. This documents
     * the intended behaviour and guards against someone over-redacting on the
     * read path.
     */
    public function testSaslAuthenticateResponseReadFrameIsLoggedAsNormalHex(): void
    {
        [$serverSocket, $clientSocket] = $this->createSocketPair();

        $logger = new RecordingLogger();
        $connection = new StreamConnection('127.0.0.1', 5552, $logger);
        $this->injectSocket($connection, $clientSocket);

        // Real SASL_AUTHENTICATE_RESPONSE frame (key 0x8013, version 1):
        // correlationId + responseCode OK. No credential body.
        $payload = pack('nn', 0x8013, 1) . pack('N', 1) . pack('n', 0x0001);
        socket_write($serverSocket, pack('N', strlen($payload)) . $payload);

        $connection->readFrame(timeout: 1.0);

        $debugMessages = $logger->debugMessages();
        $this->assertCount(1, $debugMessages);
        $this->assertStringStartsWith('Socket <-', $debugMessages[0]);
        $this->assertStringNotContainsString('redacted', $debugMessages[0]);
        $this->assertMatchesRegularExpression('/^Socket <-[0-9a-f]+$/', $debugMessages[0]);

        socket_close($serverSocket);
        socket_close($clientSocket);
    }

    public function testNonSaslReadFrameProducesNormalHexDebugLineWhenDebugLoggingEnabled(): void
    {
        [$serverSocket, $clientSocket] = $this->createSocketPair();

        $logger = new RecordingLogger();
        $connection = new StreamConnection('127.0.0.1', 5552, $logger);
        $this->injectSocket($connection, $clientSocket);

        $payload = pack('nn', 0x0014, 1) . pack('N', 1) . pack('n', 0x0001);
        socket_write($serverSocket, pack('N', strlen($payload)) . $payload);

        $connection->readFrame(timeout: 1.0);

        $debugMessages = $logger->debugMessages();
        $this->assertCount(1, $debugMessages);
        $this->assertStringStartsWith('Socket <-', $debugMessages[0]);
        $this->assertStringNotContainsString('redacted', $debugMessages[0]);
        $this->assertMatchesRegularExpression('/^Socket <-[0-9a-f]+$/', $debugMessages[0]);

        socket_close($serverSocket);
        socket_close($clientSocket);
    }
}
