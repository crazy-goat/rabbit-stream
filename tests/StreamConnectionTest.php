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
}
