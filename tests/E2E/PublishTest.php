<?php

declare(strict_types=1);

namespace CrazyGoat\RabbitStream\Tests\E2E;

use CrazyGoat\RabbitStream\Client\ConfirmationStatus;
use CrazyGoat\RabbitStream\Client\Connection;

class PublishTest extends E2ETestCase
{
    private function connect(): Connection
    {
        return $this->createConnection();
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
