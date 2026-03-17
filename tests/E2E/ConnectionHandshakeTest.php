<?php

namespace CrazyGoat\RabbitStream\Tests\E2E;

use CrazyGoat\RabbitStream\Request\OpenRequest;
use CrazyGoat\RabbitStream\Request\PeerPropertiesToStreamBufferV1;
use CrazyGoat\RabbitStream\Request\SaslAuthenticateRequestV1;
use CrazyGoat\RabbitStream\Request\SaslHandshakeRequestV1;
use CrazyGoat\RabbitStream\Request\TuneRequestV1;
use CrazyGoat\RabbitStream\Response\OpenResponseV1;
use CrazyGoat\RabbitStream\Response\PeerPropertiesResponseV1;
use CrazyGoat\RabbitStream\Response\SaslAuthenticateResponseV1;
use CrazyGoat\RabbitStream\Response\SaslHandshakeResponseV1;
use CrazyGoat\RabbitStream\Response\TuneResponseV1;
use CrazyGoat\RabbitStream\StreamConnection;
use PHPUnit\Framework\TestCase;

class ConnectionHandshakeTest extends TestCase
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

    public function testPeerPropertiesExchange(): void
    {
        $connection = $this->createConnection();

        $connection->sendMessage(new PeerPropertiesToStreamBufferV1());
        $response = $connection->readMessage();

        $this->assertInstanceOf(PeerPropertiesResponseV1::class, $response);

        $connection->close();
    }

    public function testSaslHandshake(): void
    {
        $connection = $this->createConnection();

        $connection->sendMessage(new PeerPropertiesToStreamBufferV1());
        $connection->readMessage();

        $connection->sendMessage(new SaslHandshakeRequestV1());
        $response = $connection->readMessage();

        $this->assertInstanceOf(SaslHandshakeResponseV1::class, $response);

        $connection->close();
    }

    public function testSaslAuthenticate(): void
    {
        $connection = $this->createConnection();

        $connection->sendMessage(new PeerPropertiesToStreamBufferV1());
        $connection->readMessage();

        $connection->sendMessage(new SaslHandshakeRequestV1());
        $connection->readMessage();

        $connection->sendMessage(new SaslAuthenticateRequestV1('PLAIN', 'guest', 'guest'));
        $response = $connection->readMessage();

        $this->assertInstanceOf(SaslAuthenticateResponseV1::class, $response);

        $connection->close();
    }

    public function testFullHandshake(): void
    {
        $connection = $this->createConnection();

        $connection->sendMessage(new PeerPropertiesToStreamBufferV1());
        $peerResponse = $connection->readMessage();
        $this->assertInstanceOf(PeerPropertiesResponseV1::class, $peerResponse);

        $connection->sendMessage(new SaslHandshakeRequestV1());
        $saslHandshake = $connection->readMessage();
        $this->assertInstanceOf(SaslHandshakeResponseV1::class, $saslHandshake);

        $connection->sendMessage(new SaslAuthenticateRequestV1('PLAIN', 'guest', 'guest'));
        $saslAuth = $connection->readMessage();
        $this->assertInstanceOf(SaslAuthenticateResponseV1::class, $saslAuth);

        $tune = $connection->readMessage();
        $this->assertInstanceOf(TuneRequestV1::class, $tune);
        $this->assertGreaterThan(0, $tune->getFrameMax());

        $connection->sendMessage(new TuneResponseV1($tune->getFrameMax(), $tune->getHeartbeat()));

        $connection->sendMessage(new OpenRequest('/'));
        $open = $connection->readMessage();
        $this->assertInstanceOf(OpenResponseV1::class, $open);

        $connection->close();
        $this->assertFalse($connection->isConnected());
    }

    public function testInvalidCredentialsThrows(): void
    {
        $connection = $this->createConnection();

        $connection->sendMessage(new PeerPropertiesToStreamBufferV1());
        $connection->readMessage();

        $connection->sendMessage(new SaslHandshakeRequestV1());
        $connection->readMessage();

        $this->expectException(\Exception::class);
        $connection->sendMessage(new SaslAuthenticateRequestV1('PLAIN', 'wrong', 'credentials'));
        $connection->readMessage();

        $connection->close();
    }
}
