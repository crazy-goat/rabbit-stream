<?php

declare(strict_types=1);

namespace CrazyGoat\RabbitStream\Tests\E2E;

use CrazyGoat\RabbitStream\Request\CreateSuperStreamRequestV1;
use CrazyGoat\RabbitStream\Request\OpenRequestV1;
use CrazyGoat\RabbitStream\Request\PartitionsRequestV1;
use CrazyGoat\RabbitStream\Request\PeerPropertiesToStreamBufferV1;
use CrazyGoat\RabbitStream\Request\SaslAuthenticateRequestV1;
use CrazyGoat\RabbitStream\Request\SaslHandshakeRequestV1;
use CrazyGoat\RabbitStream\Request\TuneRequestV1;
use CrazyGoat\RabbitStream\Response\PartitionsResponseV1;
use CrazyGoat\RabbitStream\Response\TuneResponseV1;
use CrazyGoat\RabbitStream\StreamConnection;
use PHPUnit\Framework\TestCase;

class PartitionsTest extends TestCase
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

        $connection->sendMessage(new OpenRequestV1('/'));
        $connection->readMessage();

        return $connection;
    }

    public function testPartitionsForNonExistentSuperStreamThrows(): void
    {
        $connection = $this->connectAndOpen();

        $superStreamName = 'test-nonexistent-partitions-' . uniqid();

        $this->expectException(\Exception::class);
        $connection->sendMessage(new PartitionsRequestV1($superStreamName));
        $connection->readMessage();

        $connection->close();
    }

    public function testPartitionsReturnsStreamsForSuperStream(): void
    {
        $connection = $this->connectAndOpen();

        $superStreamName = 'test-partitions-super-stream-' . uniqid();
        $partition1 = $superStreamName . '-0';
        $partition2 = $superStreamName . '-1';
        $partition3 = $superStreamName . '-2';

        $connection->sendMessage(new CreateSuperStreamRequestV1(
            $superStreamName,
            [$partition1, $partition2, $partition3],
            ['0', '1', '2']
        ));
        $connection->readMessage();

        $connection->sendMessage(new PartitionsRequestV1($superStreamName));
        $response = $connection->readMessage();

        $this->assertInstanceOf(PartitionsResponseV1::class, $response);
        $streams = $response->getStreams();
        $this->assertCount(3, $streams);
        $this->assertContains($partition1, $streams);
        $this->assertContains($partition2, $streams);
        $this->assertContains($partition3, $streams);

        $connection->close();
    }
}
