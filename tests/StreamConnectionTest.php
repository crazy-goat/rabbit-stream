<?php

namespace CrazyGoat\RabbitStream\Tests;

use CrazyGoat\RabbitStream\StreamConnection;
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
        $this->assertEquals('float', $params[0]->getType()->getName());
        $this->assertEquals(30.0, $params[0]->getDefaultValue());
    }

    public function testReadMessageAcceptsFloatTimeout(): void
    {
        $connection = new StreamConnection('127.0.0.1', 5552);

        $reflection = new \ReflectionMethod($connection, 'readMessage');
        $params = $reflection->getParameters();

        $this->assertCount(1, $params);
        $this->assertEquals('timeout', $params[0]->getName());
        $this->assertEquals('float', $params[0]->getType()->getName());
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
        $this->assertEquals('float', $params[1]->getType()->getName());
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
}
