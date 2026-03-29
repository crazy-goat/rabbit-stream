<?php

declare(strict_types=1);

namespace CrazyGoat\RabbitStream\Tests\E2E;

use CrazyGoat\RabbitStream\Request\CreateRequestV1;
use CrazyGoat\RabbitStream\Request\OpenRequestV1;
use CrazyGoat\RabbitStream\Request\PeerPropertiesRequestV1;
use CrazyGoat\RabbitStream\Request\SaslAuthenticateRequestV1;
use CrazyGoat\RabbitStream\Request\SaslHandshakeRequestV1;
use CrazyGoat\RabbitStream\Request\TuneRequestV1;
use CrazyGoat\RabbitStream\Response\CreateResponseV1;
use CrazyGoat\RabbitStream\Response\TuneResponseV1;
use CrazyGoat\RabbitStream\StreamConnection;
use PHPUnit\Framework\TestCase;

class CreateStreamTest extends TestCase
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

        return $connection;
    }

    public function testCreateStream(): void
    {
        $connection = $this->connectAndOpen();

        $streamName = 'test-create-stream-' . uniqid();
        $connection->sendMessage(new CreateRequestV1($streamName));
        $response = $connection->readMessage();

        $this->assertInstanceOf(CreateResponseV1::class, $response);

        $connection->close();
    }

    public function testCreateStreamWithArguments(): void
    {
        $connection = $this->connectAndOpen();

        $streamName = 'test-create-stream-args-' . uniqid();
        $connection->sendMessage(new CreateRequestV1($streamName, [
            'max-length-bytes' => '1000000',
            'max-age' => '1h',
        ]));
        $response = $connection->readMessage();

        $this->assertInstanceOf(CreateResponseV1::class, $response);

        $connection->close();
    }

    public function testCreateDuplicateStreamThrows(): void
    {
        $connection = $this->connectAndOpen();

        $streamName = 'test-create-duplicate-' . uniqid();
        $connection->sendMessage(new CreateRequestV1($streamName));
        $connection->readMessage();

        // Second create should fail
        $this->expectException(\Exception::class);
        $connection->sendMessage(new CreateRequestV1($streamName));
        $connection->readMessage();

        $connection->close();
    }
}
