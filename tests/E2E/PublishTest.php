<?php

namespace CrazyGoat\StreamyCarrot\Tests\E2E;

use CrazyGoat\StreamyCarrot\Request\DeclarePublisherRequestV1;
use CrazyGoat\StreamyCarrot\Request\OpenRequest;
use CrazyGoat\StreamyCarrot\Request\PeerPropertiesToStreamBufferV1;
use CrazyGoat\StreamyCarrot\Request\PublishRequestV1;
use CrazyGoat\StreamyCarrot\Request\SaslAuthenticateRequestV1;
use CrazyGoat\StreamyCarrot\Request\SaslHandshakeRequestV1;
use CrazyGoat\StreamyCarrot\Request\TuneRequestV1;
use CrazyGoat\StreamyCarrot\Response\PublishConfirmResponseV1;
use CrazyGoat\StreamyCarrot\Response\TuneResponseV1;
use CrazyGoat\StreamyCarrot\StreamConnection;
use CrazyGoat\StreamyCarrot\VO\PublishedMessage;
use PHPUnit\Framework\TestCase;

class PublishTest extends TestCase
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

    public function testPublishSingleMessage(): void
    {
        $connection = $this->connectAndOpen();

        $connection->sendMessage(new DeclarePublisherRequestV1(1, null, 'test-stream'));
        $connection->readMessage();

        $connection->sendMessage(new PublishRequestV1(1, new PublishedMessage(1, 'hello world')));
        $response = $connection->readMessage();

        $this->assertInstanceOf(PublishConfirmResponseV1::class, $response);
        $this->assertSame(1, $response->getPublisherId());
        $this->assertSame([1], $response->getPublishingIds());

        $connection->close();
    }

    public function testPublishMultipleMessages(): void
    {
        $connection = $this->connectAndOpen();

        $connection->sendMessage(new DeclarePublisherRequestV1(1, null, 'test-stream'));
        $connection->readMessage();

        $connection->sendMessage(new PublishRequestV1(
            1,
            new PublishedMessage(1, 'message-one'),
            new PublishedMessage(2, 'message-two'),
            new PublishedMessage(3, 'message-three'),
        ));
        $response = $connection->readMessage();

        $this->assertInstanceOf(PublishConfirmResponseV1::class, $response);
        $this->assertSame(1, $response->getPublisherId());
        $this->assertCount(3, $response->getPublishingIds());

        $connection->close();
    }
}
