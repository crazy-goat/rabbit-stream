<?php

declare(strict_types=1);

namespace CrazyGoat\RabbitStream\Tests\E2E;

use CrazyGoat\RabbitStream\Client\Connection;
use CrazyGoat\RabbitStream\Exception\ConnectionException;
use CrazyGoat\RabbitStream\StreamConnection;
use PHPUnit\Framework\TestCase;

/**
 * @group destructive
 * @group slow
 */
class ConnectionResilienceTest extends TestCase
{
    private static string $host = '127.0.0.1';
    private static int $port = 5552;

    public static function setUpBeforeClass(): void
    {
        self::$host = getenv('RABBITMQ_HOST') ?: self::$host;
        self::$port = (int)(getenv('RABBITMQ_PORT') ?: self::$port);
    }

    public function testOperationAfterSocketDisconnect(): void
    {
        $connection = Connection::create(
            host: self::$host,
            port: self::$port,
            user: 'guest',
            password: 'guest',
            vhost: '/'
        );

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
        $connection = Connection::create(
            host: self::$host,
            port: self::$port,
            user: 'guest',
            password: 'guest',
            vhost: '/'
        );

        $this->assertTrue($connection->isConnected());

        // Simulate socket error
        $this->forceCloseSocket($connection);

        // isConnected() should return false
        $this->assertFalse($connection->isConnected());
    }

    public function testNoResourceLeaksAfterSocketError(): void
    {
        $connection = Connection::create(
            host: self::$host,
            port: self::$port,
            user: 'guest',
            password: 'guest',
            vhost: '/'
        );

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
