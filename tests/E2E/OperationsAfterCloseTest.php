<?php

declare(strict_types=1);

namespace CrazyGoat\RabbitStream\Tests\E2E;

use CrazyGoat\RabbitStream\Client\Connection;
use CrazyGoat\RabbitStream\Exception\ConnectionException;
use CrazyGoat\RabbitStream\Request\CloseRequestV1;

class OperationsAfterCloseTest extends E2ETestCase
{
    public function testSendMessageAfterCloseThrows(): void
    {
        $connection = $this->connectAndOpen();

        $connection->close();

        $this->expectException(ConnectionException::class);
        $this->expectExceptionMessage('socket is not connected');
        $connection->sendMessage(new CloseRequestV1());
    }

    public function testReadMessageAfterCloseThrows(): void
    {
        $connection = $this->connectAndOpen();

        $connection->close();

        $this->expectException(ConnectionException::class);
        $this->expectExceptionMessage('Connection closed');
        $connection->readMessage();
    }

    public function testHighLevelMethodsAfterCloseThrow(): void
    {
        $connection = Connection::create(self::$host, self::$port);
        $connection->close();

        $this->expectException(ConnectionException::class);
        $connection->createStream('should-fail-' . uniqid());
    }

    public function testDoubleCloseIsIdempotent(): void
    {
        $connection = Connection::create(self::$host, self::$port);

        $connection->close();
        $this->assertFalse($connection->isConnected());

        $connection->close();
        $this->assertFalse($connection->isConnected());
    }

    public function testIsConnectedFalseAfterClose(): void
    {
        $connection = $this->connectAndOpen();

        $connection->close();

        $this->assertFalse($connection->isConnected());
    }
}
