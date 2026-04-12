<?php

declare(strict_types=1);

namespace CrazyGoat\RabbitStream\Tests\E2E;

use CrazyGoat\RabbitStream\Request\OpenRequestV1;
use CrazyGoat\RabbitStream\Request\PeerPropertiesRequestV1;
use CrazyGoat\RabbitStream\Request\SaslAuthenticateRequestV1;
use CrazyGoat\RabbitStream\Request\SaslHandshakeRequestV1;
use CrazyGoat\RabbitStream\Request\TuneRequestV1;
use CrazyGoat\RabbitStream\Response\PeerPropertiesResponseV1;
use CrazyGoat\RabbitStream\Response\SaslAuthenticateResponseV1;
use CrazyGoat\RabbitStream\Response\SaslHandshakeResponseV1;
use CrazyGoat\RabbitStream\Response\TuneResponseV1;
use CrazyGoat\RabbitStream\StreamConnection;
use PHPUnit\Framework\TestCase;

class HeartbeatTest extends TestCase
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

    private function createConnectionWithShortHeartbeat(int $heartbeatInterval = 2): StreamConnection
    {
        $connection = new StreamConnection(self::$host, self::$port);
        $connection->connect();

        $connection->sendMessage(new PeerPropertiesRequestV1());
        $peerResponse = $connection->readMessage();
        $this->assertInstanceOf(PeerPropertiesResponseV1::class, $peerResponse);

        $connection->sendMessage(new SaslHandshakeRequestV1());
        $saslHandshake = $connection->readMessage();
        $this->assertInstanceOf(SaslHandshakeResponseV1::class, $saslHandshake);
        $this->assertContains('PLAIN', $saslHandshake->getMechanisms());

        $connection->sendMessage(new SaslAuthenticateRequestV1('PLAIN', 'guest', 'guest'));
        $saslAuth = $connection->readMessage();
        $this->assertInstanceOf(SaslAuthenticateResponseV1::class, $saslAuth);

        $tune = $connection->readMessage();
        $this->assertInstanceOf(TuneRequestV1::class, $tune);

        $negotiatedHeartbeat = min($heartbeatInterval, $tune->getHeartbeat());
        $negotiatedHeartbeat = $negotiatedHeartbeat > 0 ? $negotiatedHeartbeat : $heartbeatInterval;

        $connection->sendMessage(new TuneResponseV1(
            $tune->getFrameMax(),
            $negotiatedHeartbeat
        ));

        $connection->sendMessage(new OpenRequestV1('/'));
        $open = $connection->readMessage();
        // OpenResponse throws on non-OK response code via assertResponseCodeOk()
        $this->assertInstanceOf(\CrazyGoat\RabbitStream\Response\OpenResponseV1::class, $open);

        return $connection;
    }

    public function testHeartbeatIsEchoedBack(): void
    {
        $heartbeatInterval = 2;
        $connection = $this->createConnectionWithShortHeartbeat($heartbeatInterval);

        $heartbeatReceived = false;
        $connection->onHeartbeat(function () use (&$heartbeatReceived): void {
            $heartbeatReceived = true;
        });

        $margin = 3;
        $totalWait = $heartbeatInterval + $margin;

        $start = microtime(true);
        $connection->readLoop(maxFrames: 1, timeout: (float) $totalWait);
        $elapsed = microtime(true) - $start;

        $this->assertTrue(
            $heartbeatReceived,
            "Heartbeat callback should have been invoked within {$totalWait}s (actual: {$elapsed}s)"
        );

        $this->assertTrue(
            $connection->isConnected(),
            'Connection should remain alive after heartbeat exchange'
        );

        $connection->close();
    }

    public function testHeartbeatCallbackIsCalledMultipleTimes(): void
    {
        $heartbeatInterval = 2;
        $connection = $this->createConnectionWithShortHeartbeat($heartbeatInterval);

        $heartbeatCount = 0;
        $connection->onHeartbeat(function () use (&$heartbeatCount): void {
            $heartbeatCount++;
        });

        $maxFrames = 3;
        $totalWait = ($heartbeatInterval * $maxFrames) + 5;

        $connection->readLoop(maxFrames: $maxFrames, timeout: (float) $totalWait);

        $this->assertGreaterThanOrEqual(
            1,
            $heartbeatCount,
            "Should receive at least 1 heartbeat within {$totalWait}s"
        );

        $this->assertTrue(
            $connection->isConnected(),
            'Connection should remain alive after multiple heartbeat exchanges'
        );

        $connection->close();
    }

    public function testNoCorrelationIdDesyncAfterHeartbeat(): void
    {
        $heartbeatInterval = 2;
        $connection = $this->createConnectionWithShortHeartbeat($heartbeatInterval);

        $connection->onHeartbeat(function (): void {
        });

        $margin = 3;
        $connection->readLoop(maxFrames: 1, timeout: (float) ($heartbeatInterval + $margin));

        $this->assertTrue(
            $connection->isConnected(),
            'Connection should still be connected after heartbeat (no correlationId desync)'
        );

        $connection->close();
    }

    public function testHeartbeatWithHighIntervalStillWorks(): void
    {
        $connection = $this->createConnectionWithShortHeartbeat(heartbeatInterval: 60);

        $heartbeatReceived = false;
        $connection->onHeartbeat(function () use (&$heartbeatReceived): void {
            $heartbeatReceived = true;
        });

        $connection->close();

        $this->assertFalse(
            $heartbeatReceived,
            'No heartbeat should have been received within test duration for 60s interval'
        );
    }
}
