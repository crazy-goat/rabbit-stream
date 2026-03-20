<?php

declare(strict_types=1);

namespace CrazyGoat\RabbitStream\Tests\E2E;

use CrazyGoat\RabbitStream\Client\ConfirmationStatus;
use CrazyGoat\RabbitStream\Client\Connection;
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

    private function connect(): Connection
    {
        return Connection::create(
            host: self::$host,
            port: self::$port,
            user: 'guest',
            password: 'guest',
            vhost: '/'
        );
    }

    public function testPublishSingleMessage(): void
    {
        $connection = $this->connect();

        $confirmedIds = [];
        $producer = $connection->createProducer(
            stream: 'test-stream',
            onConfirm: function (ConfirmationStatus $status) use (&$confirmedIds): void {
                if ($status->isConfirmed()) {
                    $confirmedIds[] = $status->getPublishingId();
                }
            }
        );

        $producer->send('hello world');
        $producer->waitForConfirms(timeout: 5.0);

        $this->assertSame([0], $confirmedIds);

        $producer->close();
        $connection->close();
    }

    public function testPublishMultipleMessages(): void
    {
        $connection = $this->connect();

        $confirmedIds = [];
        $producer = $connection->createProducer(
            stream: 'test-stream',
            onConfirm: function (ConfirmationStatus $status) use (&$confirmedIds): void {
                if ($status->isConfirmed()) {
                    $confirmedIds[] = $status->getPublishingId();
                }
            }
        );

        $producer->send('message-one');
        $producer->send('message-two');
        $producer->send('message-three');

        $producer->waitForConfirms(timeout: 5.0);

        $this->assertSame([0, 1, 2], $confirmedIds);

        $producer->close();
        $connection->close();
    }
}
