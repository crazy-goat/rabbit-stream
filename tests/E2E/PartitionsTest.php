<?php

namespace CrazyGoat\RabbitStream\Tests\E2E;

use CrazyGoat\RabbitStream\Request\OpenRequest;
use CrazyGoat\RabbitStream\Request\PartitionsRequestV1;
use CrazyGoat\RabbitStream\Request\PeerPropertiesToStreamBufferV1;
use CrazyGoat\RabbitStream\Request\SaslAuthenticateRequestV1;
use CrazyGoat\RabbitStream\Request\SaslHandshakeRequestV1;
use CrazyGoat\RabbitStream\Request\TuneRequestV1;
use CrazyGoat\RabbitStream\Response\PartitionsResponseV1;
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
        $connection->sendMessage(new TuneRequestV1($tune->getFrameMax(), $tune->getHeartbeat()));

        $connection->sendMessage(new OpenRequest('/'));
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

    /**
     * @todo Enable when CreateSuperStream is implemented (issue #7)
     */
    public function testPartitionsReturnsStreamsForSuperStream(): void
    {
        $this->markTestSkipped('CreateSuperStream not yet implemented (issue #7)');

        $connection = $this->connectAndOpen();

        // This test requires CreateSuperStream to be implemented first
        // Once implemented, it should:
        // 1. Create a super stream with partitions
        // 2. Query partitions
        // 3. Verify the response contains the partition streams

        $connection->close();
    }
}
