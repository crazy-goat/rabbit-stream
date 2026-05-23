<?php

declare(strict_types=1);

namespace CrazyGoat\RabbitStream\Tests\E2E;

use CrazyGoat\RabbitStream\Request\CreateRequestV1;
use CrazyGoat\RabbitStream\Request\DeleteStreamRequestV1;
use CrazyGoat\RabbitStream\Request\MetadataRequestV1;
use CrazyGoat\RabbitStream\Request\OpenRequestV1;
use CrazyGoat\RabbitStream\Request\PeerPropertiesRequestV1;
use CrazyGoat\RabbitStream\Request\SaslAuthenticateRequestV1;
use CrazyGoat\RabbitStream\Request\SaslHandshakeRequestV1;
use CrazyGoat\RabbitStream\Request\TuneRequestV1;
use CrazyGoat\RabbitStream\Response\CreateResponseV1;
use CrazyGoat\RabbitStream\Response\DeleteStreamResponseV1;
use CrazyGoat\RabbitStream\Response\MetadataResponseV1;
use CrazyGoat\RabbitStream\Response\OpenResponseV1;
use CrazyGoat\RabbitStream\Response\PeerPropertiesResponseV1;
use CrazyGoat\RabbitStream\Response\SaslAuthenticateResponseV1;
use CrazyGoat\RabbitStream\Response\SaslHandshakeResponseV1;
use CrazyGoat\RabbitStream\Response\TuneResponseV1;
use CrazyGoat\RabbitStream\StreamConnection;

class HeartbeatTest extends E2ETestCase
{
    private const HEARTBEAT_INTERVAL = 1;
    private const MARGIN = 2;

    private ?StreamConnection $connection = null;

    protected function tearDown(): void
    {
        if ($this->connection instanceof StreamConnection && $this->connection->isConnected()) {
            $this->connection->close();
        }
        $this->connection = null;
    }

    private function createConnectionWithShortHeartbeat(int $heartbeatInterval): StreamConnection
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
        $this->assertInstanceOf(OpenResponseV1::class, $open);

        return $connection;
    }

    public function testHeartbeatIsEchoedBack(): void
    {
        $connection = $this->createConnectionWithShortHeartbeat(self::HEARTBEAT_INTERVAL);
        $this->connection = $connection;

        $heartbeatReceived = false;
        $connection->onHeartbeat(function () use (&$heartbeatReceived): void {
            $heartbeatReceived = true;
        });

        $totalWait = self::HEARTBEAT_INTERVAL + self::MARGIN;

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
    }

    public function testHeartbeatCallbackIsCalledMultipleTimes(): void
    {
        $connection = $this->createConnectionWithShortHeartbeat(self::HEARTBEAT_INTERVAL);
        $this->connection = $connection;

        $heartbeatCount = 0;
        $connection->onHeartbeat(function () use (&$heartbeatCount): void {
            $heartbeatCount++;
        });

        $maxFrames = 3;
        $totalWait = (self::HEARTBEAT_INTERVAL * $maxFrames) + self::MARGIN;

        $connection->readLoop(maxFrames: $maxFrames, timeout: (float) $totalWait);

        $this->assertGreaterThanOrEqual(
            2,
            $heartbeatCount,
            "Should receive at least 2 heartbeats within {$totalWait}s (testing multiple callbacks)"
        );

        $this->assertTrue(
            $connection->isConnected(),
            'Connection should remain alive after multiple heartbeat exchanges'
        );
    }

    public function testNoCorrelationIdDesyncAfterHeartbeat(): void
    {
        $connection = $this->createConnectionWithShortHeartbeat(self::HEARTBEAT_INTERVAL);
        $this->connection = $connection;

        $connection->onHeartbeat(function (): void {
        });

        $connection->readLoop(maxFrames: 1, timeout: (float) (self::HEARTBEAT_INTERVAL + self::MARGIN));

        $this->assertTrue(
            $connection->isConnected(),
            'Connection should still be connected after heartbeat'
        );

        $streamName = 'test-heartbeat-correlation-' . uniqid();
        $connection->sendMessage(new CreateRequestV1($streamName));
        $response = $connection->readMessage();
        $this->assertInstanceOf(
            CreateResponseV1::class,
            $response,
            'Request after heartbeat should receive correct response (correlationId not desynced)'
        );

        $connection->sendMessage(new MetadataRequestV1([$streamName]));
        $metadataResponse = $connection->readMessage();
        $this->assertInstanceOf(MetadataResponseV1::class, $metadataResponse);

        $connection->sendMessage(new DeleteStreamRequestV1($streamName));
        $deleteResponse = $connection->readMessage();
        $this->assertInstanceOf(DeleteStreamResponseV1::class, $deleteResponse);
    }

    public function testHeartbeatCallbackCanBeCleared(): void
    {
        $connection = $this->createConnectionWithShortHeartbeat(self::HEARTBEAT_INTERVAL);
        $this->connection = $connection;

        $heartbeatReceived = false;
        $connection->onHeartbeat(function () use (&$heartbeatReceived): void {
            $heartbeatReceived = true;
        });

        $connection->onHeartbeat();

        $connection->readLoop(maxFrames: 1, timeout: (float) (self::HEARTBEAT_INTERVAL + self::MARGIN));

        $this->assertFalse(
            $heartbeatReceived,
            'No heartbeat callback should fire after callback is cleared'
        );
    }

    public function testHeartbeatCallbackCanBeReplaced(): void
    {
        $connection = $this->createConnectionWithShortHeartbeat(self::HEARTBEAT_INTERVAL);
        $this->connection = $connection;

        $firstCallbackCalled = false;
        $connection->onHeartbeat(function () use (&$firstCallbackCalled): void {
            $firstCallbackCalled = true;
        });

        $secondCallbackCalled = false;
        $connection->onHeartbeat(function () use (&$secondCallbackCalled): void {
            $secondCallbackCalled = true;
        });

        $connection->readLoop(maxFrames: 1, timeout: (float) (self::HEARTBEAT_INTERVAL + self::MARGIN));

        $this->assertFalse(
            $firstCallbackCalled,
            'Old callback should not fire after being replaced'
        );
        $this->assertTrue(
            $secondCallbackCalled,
            'New callback should fire'
        );
    }
}
