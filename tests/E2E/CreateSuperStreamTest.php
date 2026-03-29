<?php

declare(strict_types=1);

namespace CrazyGoat\RabbitStream\Tests\E2E;

use CrazyGoat\RabbitStream\Request\CreateSuperStreamRequestV1;
use CrazyGoat\RabbitStream\Request\OpenRequestV1;
use CrazyGoat\RabbitStream\Request\PartitionsRequestV1;
use CrazyGoat\RabbitStream\Request\PeerPropertiesRequestV1;
use CrazyGoat\RabbitStream\Request\SaslAuthenticateRequestV1;
use CrazyGoat\RabbitStream\Request\SaslHandshakeRequestV1;
use CrazyGoat\RabbitStream\Request\TuneRequestV1;
use CrazyGoat\RabbitStream\Response\CreateSuperStreamResponseV1;
use CrazyGoat\RabbitStream\Response\PartitionsResponseV1;
use CrazyGoat\RabbitStream\StreamConnection;
use PHPUnit\Framework\TestCase;

class CreateSuperStreamTest extends TestCase
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
        $connection->sendMessage(new TuneRequestV1($tune->getFrameMax(), $tune->getHeartbeat()));

        $connection->sendMessage(new OpenRequestV1('/'));
        $connection->readMessage();

        return $connection;
    }

    public function testCreateSuperStream(): void
    {
        $connection = $this->connectAndOpen();

        $superStreamName = 'test-super-stream-' . uniqid();
        $partition1 = $superStreamName . '-partition1';
        $partition2 = $superStreamName . '-partition2';
        $partition3 = $superStreamName . '-partition3';

        $connection->sendMessage(new CreateSuperStreamRequestV1(
            $superStreamName,
            [$partition1, $partition2, $partition3],
            ['key1', 'key2', 'key3'],
            ['max-length-bytes' => '1000000']
        ));
        $response = $connection->readMessage();

        $this->assertInstanceOf(CreateSuperStreamResponseV1::class, $response);

        // Verify we can query partitions
        $connection->sendMessage(new PartitionsRequestV1($superStreamName));
        $partitionsResponse = $connection->readMessage();

        $this->assertInstanceOf(PartitionsResponseV1::class, $partitionsResponse);
        $streams = $partitionsResponse->getStreams();
        $this->assertCount(3, $streams);
        $this->assertContains($partition1, $streams);
        $this->assertContains($partition2, $streams);
        $this->assertContains($partition3, $streams);

        $connection->close();
    }

    public function testCreateDuplicateSuperStreamThrows(): void
    {
        $connection = $this->connectAndOpen();

        $superStreamName = 'test-duplicate-super-stream-' . uniqid();

        // First create should succeed
        $connection->sendMessage(new CreateSuperStreamRequestV1(
            $superStreamName,
            [$superStreamName . '-p1', $superStreamName . '-p2'],
            ['k1', 'k2']
        ));
        $connection->readMessage();

        // Second create should fail
        $this->expectException(\Exception::class);
        $connection->sendMessage(new CreateSuperStreamRequestV1(
            $superStreamName,
            [$superStreamName . '-p1', $superStreamName . '-p2'],
            ['k1', 'k2']
        ));
        $connection->readMessage();

        $connection->close();
    }
}
