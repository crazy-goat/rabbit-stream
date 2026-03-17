<?php

namespace CrazyGoat\RabbitStream\Tests\E2E;

use CrazyGoat\RabbitStream\Request\DeclarePublisherRequestV1;
use CrazyGoat\RabbitStream\Request\OpenRequest;
use CrazyGoat\RabbitStream\Request\PeerPropertiesToStreamBufferV1;
use CrazyGoat\RabbitStream\Request\PublishRequestV1;
use CrazyGoat\RabbitStream\Request\SaslAuthenticateRequestV1;
use CrazyGoat\RabbitStream\Request\SaslHandshakeRequestV1;
use CrazyGoat\RabbitStream\Request\TuneRequestV1;
use CrazyGoat\RabbitStream\Response\TuneResponseV1;
use CrazyGoat\RabbitStream\StreamConnection;
use CrazyGoat\RabbitStream\VO\PublishedMessage;
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

        $confirmedIds = [];
        $connection->registerPublisher(
            publisherId: 1,
            onConfirm: function (array $ids) use (&$confirmedIds): void {
                $confirmedIds = $ids;
            },
            onError: function (array $errors): void {
                $this->fail('Unexpected publish error: ' . json_encode($errors));
            },
        );

        $connection->sendMessage(new PublishRequestV1(1, new PublishedMessage(1, 'hello world')));
        $connection->readLoop(maxFrames: 1);

        $this->assertSame([1], $confirmedIds);

        $connection->close();
    }

    public function testPublishMultipleMessages(): void
    {
        $connection = $this->connectAndOpen();

        $connection->sendMessage(new DeclarePublisherRequestV1(1, null, 'test-stream'));
        $connection->readMessage();

        $confirmedIds = [];
        $connection->registerPublisher(
            publisherId: 1,
            onConfirm: function (array $ids) use (&$confirmedIds): void {
                $confirmedIds = $ids;
            },
            onError: function (array $errors): void {
                $this->fail('Unexpected publish error: ' . json_encode($errors));
            },
        );

        $connection->sendMessage(new PublishRequestV1(
            1,
            new PublishedMessage(1, 'message-one'),
            new PublishedMessage(2, 'message-two'),
            new PublishedMessage(3, 'message-three'),
        ));
        $connection->readLoop(maxFrames: 1);

        $this->assertCount(3, $confirmedIds);

        $connection->close();
    }
}
