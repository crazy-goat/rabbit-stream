<?php

declare(strict_types=1);

namespace CrazyGoat\RabbitStream\Tests;

use CrazyGoat\RabbitStream\Request\CreateRequestV1;
use CrazyGoat\RabbitStream\Request\CreditRequestV1;
use CrazyGoat\RabbitStream\Request\PublishRequestV1;
use CrazyGoat\RabbitStream\Request\StoreOffsetRequestV1;
use CrazyGoat\RabbitStream\Request\TuneRequestV1;
use CrazyGoat\RabbitStream\StreamConnection;
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
}
