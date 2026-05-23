<?php

declare(strict_types=1);

namespace CrazyGoat\RabbitStream\Tests\E2E;

use CrazyGoat\RabbitStream\Request\CloseRequestV1;
use CrazyGoat\RabbitStream\Request\OpenRequestV1;
use CrazyGoat\RabbitStream\Request\PeerPropertiesRequestV1;
use CrazyGoat\RabbitStream\Request\SaslAuthenticateRequestV1;
use CrazyGoat\RabbitStream\Request\SaslHandshakeRequestV1;
use CrazyGoat\RabbitStream\Request\TuneRequestV1;
use CrazyGoat\RabbitStream\Response\CloseResponseV1;
use CrazyGoat\RabbitStream\Response\TuneResponseV1;
use CrazyGoat\RabbitStream\StreamConnection;

class CloseTest extends E2ETestCase
{
    private function createRawConnection(): StreamConnection
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
        $connection = $this->createRawConnection();
        $this->performHandshake($connection);

        $connection->sendMessage(new CloseRequestV1());
        $response = $connection->readMessage();

        $this->assertInstanceOf(CloseResponseV1::class, $response);

        $connection->close();
        $this->assertFalse($connection->isConnected());
    }
}
