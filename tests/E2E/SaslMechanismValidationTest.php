<?php

declare(strict_types=1);

namespace CrazyGoat\RabbitStream\Tests\E2E;

use CrazyGoat\RabbitStream\Exception\ProtocolException;
use CrazyGoat\RabbitStream\Exception\TimeoutException;
use CrazyGoat\RabbitStream\Request\PeerPropertiesRequestV1;
use CrazyGoat\RabbitStream\Request\SaslAuthenticateRequestV1;
use CrazyGoat\RabbitStream\Request\SaslHandshakeRequestV1;
use CrazyGoat\RabbitStream\Response\PeerPropertiesResponseV1;
use CrazyGoat\RabbitStream\Response\SaslHandshakeResponseV1;
use CrazyGoat\RabbitStream\StreamConnection;

class SaslMechanismValidationTest extends E2ETestCase
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

    private function performHandshakeUpToSaslAuthenticate(): StreamConnection
    {
        $connection = $this->createRawConnection();

        $connection->sendMessage(new PeerPropertiesRequestV1());
        $response = $connection->readMessage();
        $this->assertInstanceOf(PeerPropertiesResponseV1::class, $response);

        $connection->sendMessage(new SaslHandshakeRequestV1());
        $saslHandshake = $connection->readMessage();
        $this->assertInstanceOf(SaslHandshakeResponseV1::class, $saslHandshake);
        $this->assertContains('PLAIN', $saslHandshake->getMechanisms());

        return $connection;
    }

    public function testUnsupportedMechanismThrows(): void
    {
        $this->connection = $this->performHandshakeUpToSaslAuthenticate();

        $this->connection->sendMessage(new SaslAuthenticateRequestV1('SCRAM-SHA-256', 'guest', 'guest'));

        try {
            $this->connection->readMessage(timeout: 2.0);
            $this->fail('Expected an exception to be thrown');
        } catch (ProtocolException $e) {
            $this->assertStringContainsString('SASL_MECHANISM_NOT_SUPPORTED', $e->getMessage());
        } catch (TimeoutException $e) {
            $this->assertStringContainsString('Read timeout', $e->getMessage());
        }
    }

    public function testEmptyMechanismThrows(): void
    {
        $this->connection = $this->performHandshakeUpToSaslAuthenticate();

        $this->connection->sendMessage(new SaslAuthenticateRequestV1('', 'guest', 'guest'));

        try {
            $this->connection->readMessage(timeout: 2.0);
            $this->fail('Expected an exception to be thrown');
        } catch (ProtocolException $e) {
            $this->assertStringContainsString('SASL_MECHANISM_NOT_SUPPORTED', $e->getMessage());
        } catch (TimeoutException $e) {
            $this->assertStringContainsString('Read timeout', $e->getMessage());
        }
    }
}
