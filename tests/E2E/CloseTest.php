<?php

declare(strict_types=1);

namespace CrazyGoat\RabbitStream\Tests\E2E;

use CrazyGoat\RabbitStream\Client\Connection;
use CrazyGoat\RabbitStream\Exception\ConnectionException;
use CrazyGoat\RabbitStream\Request\CloseRequestV1;
use CrazyGoat\RabbitStream\Request\OpenRequestV1;
use CrazyGoat\RabbitStream\Request\PeerPropertiesRequestV1;
use CrazyGoat\RabbitStream\Request\SaslAuthenticateRequestV1;
use CrazyGoat\RabbitStream\Request\SaslHandshakeRequestV1;
use CrazyGoat\RabbitStream\Request\TuneRequestV1;
use CrazyGoat\RabbitStream\Response\CloseResponseV1;
use CrazyGoat\RabbitStream\Response\TuneResponseV1;
use CrazyGoat\RabbitStream\StreamConnection;
use PHPUnit\Framework\TestCase;

class CloseTest extends TestCase
{
    private static string $host = '127.0.0.1';
    private static int $port = 5552;

    public static function setUpBeforeClass(): void
    {
        $host = getenv('RABBITMQ_HOST') ?: self::$host;
        $port = (int)(getenv('RABBITMQ_PORT') ?: self::$port);
        self::$host = $host;
        self::$port = $port;
    }

    private function createConnection(): StreamConnection
    {
        $connection = new StreamConnection(self::$host, self::$port);
        $connection->connect();
        return $connection;
    }

    private function performHandshake(StreamConnection $connection): void
    {
        $connection->sendMessage(new PeerPropertiesRequestV1());
        $connection->readMessage();

        $connection->sendMessage(new SaslHandshakeRequestV1());
        $connection->readMessage();

        $connection->sendMessage(new SaslAuthenticateRequestV1('PLAIN', 'guest', 'guest'));
        $connection->readMessage();

        $tune = $connection->readMessage();
        $this->assertInstanceOf(TuneRequestV1::class, $tune);
        $connection->sendMessage(new TuneResponseV1($tune->getFrameMax(), $tune->getHeartbeat()));

        $connection->sendMessage(new OpenRequestV1('/'));
        $connection->readMessage();
    }

    public function testCloseCommand(): void
    {
        $connection = $this->createConnection();
        $this->performHandshake($connection);

        $connection->sendMessage(new CloseRequestV1());
        $response = $connection->readMessage();

        $this->assertInstanceOf(CloseResponseV1::class, $response);

        $connection->close();
        $this->assertFalse($connection->isConnected());
    }

    public function testSendAfterClientCloseThrows(): void
    {
        $connection = Connection::create(self::$host, self::$port);
        $connection->close();

        $this->expectException(ConnectionException::class);
        $this->expectExceptionMessage('connection is closed');
        $connection->createStream('test-stream-' . uniqid());
    }

    public function testSendAfterServerCloseThrows(): void
    {
        $streamConnection = $this->createConnection();
        $this->performHandshake($streamConnection);

        // Trigger server-initiated close
        $streamConnection->sendMessage(new CloseRequestV1());
        $response = $streamConnection->readMessage();
        $this->assertInstanceOf(CloseResponseV1::class, $response);

        // Server has closed the connection - try to send another message
        // The socket might still be open from client side, so send might succeed
        // but read will definitely fail since server is gone
        try {
            $streamConnection->sendMessage(new OpenRequestV1('/'));
            // If send succeeded, try to read - this should fail
            $streamConnection->readMessage();
            $this->fail('Expected ConnectionException after server close');
        } catch (ConnectionException $e) {
            $this->assertStringContainsStringIgnoringCase('closed', $e->getMessage());
        }
    }

    public function testDoubleCloseIsIdempotent(): void
    {
        $connection = Connection::create(self::$host, self::$port);
        $connection->close();

        // Should not throw
        $connection->close();

        $this->assertFalse($connection->isConnected());
    }

    public function testStreamConnectionSendMessageAfterClose(): void
    {
        $connection = $this->createConnection();
        $connection->close();

        $this->expectException(ConnectionException::class);
        $this->expectExceptionMessage('connection is closed');
        $connection->sendMessage(new PeerPropertiesRequestV1());
    }

    public function testStreamConnectionReadMessageAfterClose(): void
    {
        $connection = $this->createConnection();
        $connection->close();

        $this->expectException(ConnectionException::class);
        $this->expectExceptionMessage('Connection closed');
        $connection->readMessage();
    }
}
