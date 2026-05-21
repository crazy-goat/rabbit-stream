<?php

declare(strict_types=1);

namespace CrazyGoat\RabbitStream\Tests\E2E;

use CrazyGoat\RabbitStream\Exception\TimeoutException;
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

class ReadMessageTimeoutTest extends TestCase
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

    public function testReadMessageTimesOutWhenNoDataAvailable(): void
    {
        $connection = $this->createConnection();
        $this->performHandshake($connection);

        // Don't send any request — readMessage should block and eventually timeout
        $start = microtime(true);

        try {
            $connection->readMessage(timeout: 1.0);
            $this->fail('Expected TimeoutException to be thrown');
        } catch (TimeoutException $e) {
            $this->assertStringContainsString('Read timeout', $e->getMessage());
        }

        $elapsed = microtime(true) - $start;
        $this->assertGreaterThan(0.9, $elapsed);
        $this->assertLessThan(2.0, $elapsed);

        $connection->close();
    }

    public function testConnectionRemainsUsableAfterTimeout(): void
    {
        $connection = $this->createConnection();
        $this->performHandshake($connection);

        // First read should timeout (no pending data)
        try {
            $connection->readMessage(timeout: 0.5);
            $this->fail('Expected TimeoutException to be thrown');
        } catch (TimeoutException $e) {
            $this->assertStringContainsString('Read timeout', $e->getMessage());
        }

        // Now send a proper request and verify we get a response
        $connection->sendMessage(new CloseRequestV1());
        $response = $connection->readMessage();

        $this->assertInstanceOf(CloseResponseV1::class, $response);

        $connection->close();
        $this->assertFalse($connection->isConnected());
    }

    public function testReadMessageWithZeroTimeoutReturnsImmediately(): void
    {
        $connection = $this->createConnection();
        $this->performHandshake($connection);

        $start = microtime(true);

        try {
            $connection->readMessage(timeout: 0.0);
            $this->fail('Expected TimeoutException to be thrown');
        } catch (TimeoutException $e) {
            $this->assertStringContainsString('Read timeout', $e->getMessage());
        }

        $elapsed = microtime(true) - $start;
        // With timeout 0.0, readFrame does a non-blocking poll and returns immediately
        $this->assertLessThan(0.5, $elapsed);

        $connection->close();
    }
}
