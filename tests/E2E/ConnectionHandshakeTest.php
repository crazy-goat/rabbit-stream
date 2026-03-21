<?php

declare(strict_types=1);

namespace CrazyGoat\RabbitStream\Tests\E2E;

use CrazyGoat\RabbitStream\Request\OpenRequestV1;
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
use PHPUnit\Framework\Attributes\Depends;
use PHPUnit\Framework\TestCase;

class ConnectionHandshakeTest extends TestCase
{
    private static string $host = '127.0.0.1';
    private static int $port = 5552;
    private ?StreamConnection $connection = null;

    public static function setUpBeforeClass(): void
    {
        $host = getenv('RABBITMQ_HOST') ?: self::$host;
        $port = (int)(getenv('RABBITMQ_PORT') ?: self::$port);
        self::$host = $host;
        self::$port = $port;
    }

    protected function tearDown(): void
    {
        // Only close connection in tearDown for tests that don't provide it to dependents
        // Provider tests (testPeerPropertiesExchange, testSaslHandshake) pass connection via return
        $providerTests = ['testPeerPropertiesExchange', 'testSaslHandshake'];
        $currentTest = $this->name();

        $isProviderTest = in_array($currentTest, $providerTests, true);
        $hasOpenConnection = $this->connection instanceof StreamConnection && $this->connection->isConnected();

        if (!$isProviderTest && $hasOpenConnection) {
            $this->connection->close();
        }
        $this->connection = null;
    }

    private function createConnection(): StreamConnection
    {
        $connection = new StreamConnection(self::$host, self::$port);
        $connection->connect();
        return $connection;
    }

    public function testPeerPropertiesExchange(): StreamConnection
    {
        $this->connection = $this->createConnection();

        $this->connection->sendMessage(new PeerPropertiesToStreamBufferV1());
        $response = $this->connection->readMessage();

        $this->assertInstanceOf(PeerPropertiesResponseV1::class, $response);

        return $this->connection;
    }

    #[Depends('testPeerPropertiesExchange')]
    public function testSaslHandshake(StreamConnection $connection): StreamConnection
    {
        $this->connection = $connection;

        $this->connection->sendMessage(new SaslHandshakeRequestV1());
        $response = $this->connection->readMessage();

        $this->assertInstanceOf(SaslHandshakeResponseV1::class, $response);

        return $this->connection;
    }

    #[Depends('testSaslHandshake')]
    public function testSaslAuthenticate(StreamConnection $connection): void
    {
        $this->connection = $connection;

        $this->connection->sendMessage(new SaslAuthenticateRequestV1('PLAIN', 'guest', 'guest'));
        $response = $this->connection->readMessage();

        $this->assertInstanceOf(SaslAuthenticateResponseV1::class, $response);

        $this->connection->close();
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

        $connection->sendMessage(new OpenRequestV1('/'));
        $open = $connection->readMessage();
        $this->assertInstanceOf(OpenResponseV1::class, $open);

        $connection->close();
        $this->assertFalse($connection->isConnected());
    }

    public function testInvalidCredentialsThrows(): void
    {
        $this->connection = $this->createConnection();

        $this->connection->sendMessage(new PeerPropertiesToStreamBufferV1());
        $this->connection->readMessage();

        $this->connection->sendMessage(new SaslHandshakeRequestV1());
        $this->connection->readMessage();

        $this->expectException(\Exception::class);
        $this->connection->sendMessage(new SaslAuthenticateRequestV1('PLAIN', 'wrong', 'credentials'));
        $this->connection->readMessage(timeout: 2.0);
    }
}
