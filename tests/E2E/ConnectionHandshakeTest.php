<?php

declare(strict_types=1);

namespace CrazyGoat\RabbitStream\Tests\E2E;

use CrazyGoat\RabbitStream\Exception\ProtocolException;
use CrazyGoat\RabbitStream\Exception\TimeoutException;
use CrazyGoat\RabbitStream\Request\OpenRequestV1;
use CrazyGoat\RabbitStream\Request\PeerPropertiesRequestV1;
use CrazyGoat\RabbitStream\Request\SaslAuthenticateRequestV1;
use CrazyGoat\RabbitStream\Request\SaslHandshakeRequestV1;
use CrazyGoat\RabbitStream\Request\TuneRequestV1;
use CrazyGoat\RabbitStream\Response\OpenResponseV1;
use CrazyGoat\RabbitStream\Response\PeerPropertiesResponseV1;
use CrazyGoat\RabbitStream\Response\SaslAuthenticateResponseV1;
use CrazyGoat\RabbitStream\Response\SaslHandshakeResponseV1;
use CrazyGoat\RabbitStream\Response\TuneResponseV1;
use CrazyGoat\RabbitStream\StreamConnection;

class ConnectionHandshakeTest extends E2ETestCase
{
    private ?StreamConnection $connection = null;

    protected function tearDown(): void
    {
        if ($this->connection instanceof StreamConnection && $this->connection->isConnected()) {
            $this->connection->close();
        }
        $this->connection = null;
    }

    private function createRawConnection(): StreamConnection
    {
        $connection = new StreamConnection(self::$host, self::$port);
        $connection->connect();
        return $connection;
    }

    public function testFullHandshake(): void
    {
        $connection = $this->createRawConnection();

        $connection->sendMessage(new PeerPropertiesRequestV1());
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
        $this->connection = $this->createRawConnection();

        $this->connection->sendMessage(new PeerPropertiesRequestV1());
        $this->connection->readMessage();

        $this->connection->sendMessage(new SaslHandshakeRequestV1());
        $this->connection->readMessage();

        // Server may either reject with AUTHENTICATION_FAILURE or just close the connection (timeout)
        $this->connection->sendMessage(new SaslAuthenticateRequestV1('PLAIN', 'wrong', 'credentials'));
        try {
            $this->connection->readMessage(timeout: 2.0);
            $this->fail('Expected an exception to be thrown');
        } catch (ProtocolException $e) {
            $this->assertStringContainsString('AUTHENTICATION_FAILURE', $e->getMessage());
        } catch (TimeoutException $e) {
            $this->assertStringContainsString('Read timeout', $e->getMessage());
        }
    }

    public function testInvalidVhostThrows(): void
    {
        $this->connection = $this->createRawConnection();

        $this->connection->sendMessage(new PeerPropertiesRequestV1());
        $this->connection->readMessage();

        $this->connection->sendMessage(new SaslHandshakeRequestV1());
        $this->connection->readMessage();

        $this->connection->sendMessage(new SaslAuthenticateRequestV1('PLAIN', 'guest', 'guest'));
        $this->connection->readMessage();

        $tune = $this->connection->readMessage();
        $this->assertInstanceOf(TuneRequestV1::class, $tune);
        $this->connection->sendMessage(new TuneResponseV1($tune->getFrameMax(), $tune->getHeartbeat()));

        $this->connection->sendMessage(new OpenRequestV1('/nonexistent-vhost'));

        $this->expectException(ProtocolException::class);
        $this->expectExceptionMessage('VIRTUAL_HOST_ACCESS_FAILURE');
        $this->connection->readMessage();
    }
}
