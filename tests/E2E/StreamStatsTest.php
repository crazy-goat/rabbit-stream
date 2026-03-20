<?php

declare(strict_types=1);

namespace CrazyGoat\RabbitStream\Tests\E2E;

use CrazyGoat\RabbitStream\Request\CreateRequestV1;
use CrazyGoat\RabbitStream\Request\OpenRequest;
use CrazyGoat\RabbitStream\Request\PeerPropertiesToStreamBufferV1;
use CrazyGoat\RabbitStream\Request\SaslAuthenticateRequestV1;
use CrazyGoat\RabbitStream\Request\SaslHandshakeRequestV1;
use CrazyGoat\RabbitStream\Request\StreamStatsRequestV1;
use CrazyGoat\RabbitStream\Request\TuneRequestV1;
use CrazyGoat\RabbitStream\Response\CreateResponseV1;
use CrazyGoat\RabbitStream\Response\StreamStatsResponseV1;
use CrazyGoat\RabbitStream\StreamConnection;
use PHPUnit\Framework\TestCase;

class StreamStatsTest extends TestCase
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

    public function testStreamStatsReturnsStatistics(): void
    {
        $connection = $this->connectAndOpen();

        $streamName = 'test-stream-stats-' . uniqid();
        $connection->sendMessage(new CreateRequestV1($streamName));
        $this->assertInstanceOf(CreateResponseV1::class, $connection->readMessage());

        $connection->sendMessage(new StreamStatsRequestV1($streamName));
        $response = $connection->readMessage();

        $this->assertInstanceOf(StreamStatsResponseV1::class, $response);

        $stats = $response->getStats();
        $this->assertIsArray($stats);
        $this->assertGreaterThan(0, count($stats));

        $connection->close();
    }

    public function testStreamStatsForNonExistentStreamThrows(): void
    {
        $connection = $this->connectAndOpen();

        $streamName = 'test-nonexistent-stats-' . uniqid();

        $this->expectException(\Exception::class);
        $connection->sendMessage(new StreamStatsRequestV1($streamName));
        $connection->readMessage();

        $connection->close();
    }
}
