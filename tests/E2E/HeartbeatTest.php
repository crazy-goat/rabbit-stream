<?php

declare(strict_types=1);

namespace CrazyGoat\RabbitStream\Tests\E2E;

use CrazyGoat\RabbitStream\Request\CreateRequestV1;
use CrazyGoat\RabbitStream\Request\DeleteStreamRequestV1;
use CrazyGoat\RabbitStream\Request\OpenRequestV1;
use CrazyGoat\RabbitStream\Request\PeerPropertiesRequestV1;
use CrazyGoat\RabbitStream\Request\SaslAuthenticateRequestV1;
use CrazyGoat\RabbitStream\Request\SaslHandshakeRequestV1;
use CrazyGoat\RabbitStream\Request\TuneRequestV1;
use CrazyGoat\RabbitStream\Response\CreateResponseV1;
use CrazyGoat\RabbitStream\Response\OpenResponseV1;
use CrazyGoat\RabbitStream\Response\TuneResponseV1;
use CrazyGoat\RabbitStream\StreamConnection;
use PHPUnit\Framework\TestCase;

class HeartbeatTest extends TestCase
{
    private static string $host = '127.0.0.1';
    private static int $port = 5552;

    public static function setUpBeforeClass(): void
    {
        self::$host = getenv('RABBITMQ_HOST') ?: self::$host;
        self::$port = (int)(getenv('RABBITMQ_PORT') ?: self::$port);
    }

    public function testHeartbeatIsEchoedBackAndCallbackInvoked(): void
    {
        $connection = new StreamConnection(self::$host, self::$port);
        $connection->connect();

        // Complete handshake with 1-second heartbeat
        $connection->sendMessage(new PeerPropertiesRequestV1());
        $connection->readMessage();

        $connection->sendMessage(new SaslHandshakeRequestV1());
        $connection->readMessage();

        $connection->sendMessage(new SaslAuthenticateRequestV1('PLAIN', 'guest', 'guest'));
        $connection->readMessage();

        $tune = $connection->readMessage();
        $this->assertInstanceOf(TuneRequestV1::class, $tune);

        // Request 1-second heartbeat
        $connection->sendMessage(new TuneResponseV1($tune->getFrameMax(), 1));

        $connection->sendMessage(new OpenRequestV1('/'));
        $connection->readMessage();

        // Register heartbeat callback
        $heartbeatReceived = false;
        $connection->onHeartbeat(function () use (&$heartbeatReceived): void {
            $heartbeatReceived = true;
        });

        // Wait for server heartbeat (1s interval + 2s margin = 3s total)
        $connection->readLoop(maxFrames: 1, timeout: 3.0);

        // Verify callback was invoked
        $this->assertTrue($heartbeatReceived, 'Heartbeat callback should have been invoked');

        // Verify connection is still alive
        $this->assertTrue($connection->isConnected(), 'Connection should remain alive after heartbeat');

        // Regression test for #101: verify correlationId is still in sync
        // by sending a command that requires correlation
        $streamName = 'test-heartbeat-stream-' . uniqid();
        $connection->sendMessage(new CreateRequestV1($streamName));
        $response = $connection->readMessage();
        $this->assertInstanceOf(CreateResponseV1::class, $response);

        // Cleanup
        $connection->sendMessage(new DeleteStreamRequestV1($streamName));
        $connection->readMessage();
        $connection->close();
    }

    public function testHeartbeatWithoutCallbackDoesNotCrash(): void
    {
        $connection = new StreamConnection(self::$host, self::$port);
        $connection->connect();

        // Complete handshake with 1-second heartbeat
        $connection->sendMessage(new PeerPropertiesRequestV1());
        $connection->readMessage();

        $connection->sendMessage(new SaslHandshakeRequestV1());
        $connection->readMessage();

        $connection->sendMessage(new SaslAuthenticateRequestV1('PLAIN', 'guest', 'guest'));
        $connection->readMessage();

        $tune = $connection->readMessage();
        $this->assertInstanceOf(TuneRequestV1::class, $tune);

        // Request 1-second heartbeat
        $connection->sendMessage(new TuneResponseV1($tune->getFrameMax(), 1));

        $connection->sendMessage(new OpenRequestV1('/'));
        $connection->readMessage();

        // Do NOT register a heartbeat callback - should not crash

        // Wait for server heartbeat
        $connection->readLoop(maxFrames: 1, timeout: 3.0);

        // Verify connection is still alive
        $this->assertTrue(
            $connection->isConnected(),
            'Connection should remain alive after heartbeat without callback'
        );

        $connection->close();
    }

    public function testMultipleHeartbeatsKeepConnectionAlive(): void
    {
        $connection = new StreamConnection(self::$host, self::$port);
        $connection->connect();

        // Complete handshake with 1-second heartbeat
        $connection->sendMessage(new PeerPropertiesRequestV1());
        $connection->readMessage();

        $connection->sendMessage(new SaslHandshakeRequestV1());
        $connection->readMessage();

        $connection->sendMessage(new SaslAuthenticateRequestV1('PLAIN', 'guest', 'guest'));
        $connection->readMessage();

        $tune = $connection->readMessage();
        $this->assertInstanceOf(TuneRequestV1::class, $tune);

        // Request 1-second heartbeat
        $connection->sendMessage(new TuneResponseV1($tune->getFrameMax(), 1));

        $connection->sendMessage(new OpenRequestV1('/'));
        $openResponse = $connection->readMessage();
        $this->assertInstanceOf(OpenResponseV1::class, $openResponse);

        // Register heartbeat callback to count heartbeats
        $heartbeatCount = 0;
        $connection->onHeartbeat(function () use (&$heartbeatCount): void {
            $heartbeatCount++;
        });

        // Wait for 2 heartbeats (2s interval + 2s margin = 4s total)
        $connection->readLoop(maxFrames: 2, timeout: 4.0);

        // Verify at least 2 heartbeats were received
        $this->assertGreaterThanOrEqual(2, $heartbeatCount, 'Should have received at least 2 heartbeats');

        // Verify connection is still alive
        $this->assertTrue($connection->isConnected(), 'Connection should remain alive after multiple heartbeats');

        $connection->close();
    }
}
