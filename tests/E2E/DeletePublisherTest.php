<?php

namespace CrazyGoat\StreamyCarrot\Tests\E2E;

use CrazyGoat\StreamyCarrot\Request\DeclarePublisherRequestV1;
use CrazyGoat\StreamyCarrot\Request\DeletePublisherRequestV1;
use CrazyGoat\StreamyCarrot\Request\OpenRequest;
use CrazyGoat\StreamyCarrot\Request\PeerPropertiesToStreamBufferV1;
use CrazyGoat\StreamyCarrot\Request\SaslAuthenticateRequestV1;
use CrazyGoat\StreamyCarrot\Request\SaslHandshakeRequestV1;
use CrazyGoat\StreamyCarrot\Request\TuneRequestV1;
use CrazyGoat\StreamyCarrot\Response\DeletePublisherResponseV1;
use CrazyGoat\StreamyCarrot\Response\TuneResponseV1;
use CrazyGoat\StreamyCarrot\StreamConnection;
use PHPUnit\Framework\TestCase;

class DeletePublisherTest extends TestCase
{
    private static string $host = '127.0.0.1';
    private static int $port = 5552;

    public static function setUpBeforeClass(): void
    {
        self::$host = getenv('RABBITMQ_HOST') ?: self::$host;
        self::$port = (int)(getenv('RABBITMQ_PORT') ?: self::$port);
    }

    private function connectAndOpen(): StreamConnection
    {
        $connection = new StreamConnection(self::$host, self::$port);
        $connection->connect();

        $connection->sendMessage(new PeerPropertiesToStreamBufferV1());
        $connection->readMessage();

        $connection->sendMessage(new SaslHandshakeRequestV1());
        $connection->readMessage();

        $connection->sendMessage(new SaslAuthenticateRequestV1('PLAIN', 'guest', 'guest'));
        $connection->readMessage();

        $tune = $connection->readMessage();
        $this->assertInstanceOf(TuneRequestV1::class, $tune);
        $connection->sendMessage(new TuneResponseV1($tune->getFrameMax(), $tune->getHeartbeat()));

        $connection->sendMessage(new OpenRequest('/'));
        $connection->readMessage();

        return $connection;
    }

    public function testDeletePublisherAfterDeclare(): void
    {
        $connection = $this->connectAndOpen();

        $connection->sendMessage(new DeclarePublisherRequestV1(1, 'test-publisher', 'test-stream'));
        $connection->readMessage();

        $connection->sendMessage(new DeletePublisherRequestV1(1));
        $response = $connection->readMessage();

        $this->assertInstanceOf(DeletePublisherResponseV1::class, $response);

        $connection->close();
    }

    public function testDeleteNonExistentPublisherThrows(): void
    {
        $connection = $this->connectAndOpen();

        $this->expectException(\Exception::class);
        $connection->sendMessage(new DeletePublisherRequestV1(99));
        $connection->readMessage();

        $connection->close();
    }
}
