<?php

namespace CrazyGoat\RabbitStream\Tests\E2E;

use CrazyGoat\RabbitStream\Client\ConfirmationStatus;
use CrazyGoat\RabbitStream\Client\ProducerConfig;
use CrazyGoat\RabbitStream\Client\StreamClient;
use CrazyGoat\RabbitStream\Client\StreamClientConfig;
use PHPUnit\Framework\TestCase;

class StreamClientTest extends TestCase
{
    private static string $host = '127.0.0.1';
    private static int $port = 5552;

    public static function setUpBeforeClass(): void
    {
        self::$host = getenv('RABBITMQ_HOST') ?: self::$host;
        self::$port = (int)(getenv('RABBITMQ_PORT') ?: self::$port);
    }

    public function testConnectAndPublish(): void
    {
        $client = StreamClient::connect(new StreamClientConfig(
            host: self::$host,
            port: self::$port,
        ));

        $confirmedIds = [];
        $producer = $client->createProducer('test-stream', new ProducerConfig(
            onConfirmation: function (ConfirmationStatus $status) use (&$confirmedIds): void {
                if ($status->isConfirmed()) {
                    $confirmedIds[] = $status->getPublishingId();
                }
            }
        ));

        $producer->send('hello from StreamClient');
        
        $client->readLoop(maxFrames: 1);

        $this->assertSame([0], $confirmedIds);

        $producer->close();
        $client->close();
    }
}
