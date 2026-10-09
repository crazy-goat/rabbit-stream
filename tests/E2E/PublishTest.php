<?php

declare(strict_types=1);

namespace CrazyGoat\RabbitStream\Tests\E2E;

use CrazyGoat\RabbitStream\Client\ConfirmationStatus;
use CrazyGoat\RabbitStream\Client\Connection;
use CrazyGoat\RabbitStream\Request\CreateRequestV1;
use CrazyGoat\RabbitStream\Request\DeleteStreamRequestV1;
use CrazyGoat\RabbitStream\Response\CreateResponseV1;
use CrazyGoat\RabbitStream\StreamConnection;

class PublishTest extends E2ETestCase
{
    private ?StreamConnection $streamConnection = null;
    private string $streamName = '';

    protected function setUp(): void
    {
        $this->streamConnection = $this->connectAndOpen();
        $this->streamName = 'test-publish-' . uniqid();
        $this->streamConnection->sendMessage(new CreateRequestV1($this->streamName));
        $response = $this->streamConnection->readMessage();
        $this->assertInstanceOf(CreateResponseV1::class, $response);
    }

    protected function tearDown(): void
    {
        if (!$this->streamConnection instanceof StreamConnection) {
            return;
        }
        try {
            if ($this->streamConnection->isConnected() && $this->streamName !== '') {
                $this->streamConnection->sendMessage(new DeleteStreamRequestV1($this->streamName));
                $this->streamConnection->readMessage();
            }
        } catch (\Exception) {
            // Ignore cleanup errors — stream may already be deleted
        } finally {
            $this->streamConnection->close();
        }
    }

    private function connect(): Connection
    {
        return $this->createConnection();
    }

    public function testPublishSingleMessage(): void
    {
        $connection = $this->connect();

        $confirmedIds = [];
        $producer = $connection->createProducer(
            stream: $this->streamName,
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
            stream: $this->streamName,
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
