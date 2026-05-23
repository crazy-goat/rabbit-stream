<?php

declare(strict_types=1);

namespace CrazyGoat\RabbitStream\Tests\E2E;

use CrazyGoat\RabbitStream\Client\Connection;
use CrazyGoat\RabbitStream\Exception\ConnectionException;
use CrazyGoat\RabbitStream\StreamConnection;

/**
 * @group destructive
 * @group slow
 */
class ConnectionResilienceTest extends E2ETestCase
{
    public function testOperationAfterSocketDisconnect(): void
    {
        $connection = $this->createConnection();

        $streamName = 'test-resilience-' . uniqid();
        $connection->createStream($streamName);

        // Simulate socket error by closing socket via reflection
        $this->forceCloseSocket($connection);

        // Next operation should throw ConnectionException
        $this->expectException(ConnectionException::class);
        $connection->createStream('another-stream-' . uniqid());
    }

    public function testIsConnectedReturnsFalseAfterSocketError(): void
    {
        $connection = $this->createConnection();

        $this->assertTrue($connection->isConnected());

        // Simulate socket error
        $this->forceCloseSocket($connection);

        // isConnected() should return false
        $this->assertFalse($connection->isConnected());
    }

    public function testNoResourceLeaksAfterSocketError(): void
    {
        $connection = $this->createConnection();

        $streamName = 'test-resilience-leak-' . uniqid();
        $connection->createStream($streamName);

        // Force socket close
        $this->forceCloseSocket($connection);

        // Destructor should not throw or cause warnings
        unset($connection);

        // If we reach here without errors, test passes
        $this->addToAssertionCount(1);
    }

    private function forceCloseSocket(Connection $connection): void
    {
        // Access StreamConnection from Connection
        $streamConnReflection = new \ReflectionProperty(Connection::class, 'streamConnection');
        $streamConnection = $streamConnReflection->getValue($connection);

        $this->assertInstanceOf(StreamConnection::class, $streamConnection);

        // Access socket from StreamConnection
        $socketReflection = new \ReflectionProperty(StreamConnection::class, 'socket');
        $socket = $socketReflection->getValue($streamConnection);

        if ($socket instanceof \Socket) {
            // Force close the socket
            @socket_close($socket);
        }
    }
}
