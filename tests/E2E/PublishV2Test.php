<?php

declare(strict_types=1);

namespace CrazyGoat\RabbitStream\Tests\E2E;

use CrazyGoat\RabbitStream\Client\OsirisChunkParser;
use CrazyGoat\RabbitStream\Request\CreateRequestV1;
use CrazyGoat\RabbitStream\Request\CreditRequestV1;
use CrazyGoat\RabbitStream\Request\DeclarePublisherRequestV1;
use CrazyGoat\RabbitStream\Request\DeletePublisherRequestV1;
use CrazyGoat\RabbitStream\Request\DeleteStreamRequestV1;
use CrazyGoat\RabbitStream\Request\PublishRequestV2;
use CrazyGoat\RabbitStream\Request\SubscribeRequestV1;
use CrazyGoat\RabbitStream\Response\CreateResponseV1;
use CrazyGoat\RabbitStream\Response\DeclarePublisherResponseV1;
use CrazyGoat\RabbitStream\Response\DeliverResponseV1;
use CrazyGoat\RabbitStream\Response\SubscribeResponseV1;
use CrazyGoat\RabbitStream\StreamConnection;
use CrazyGoat\RabbitStream\VO\OffsetSpec;
use CrazyGoat\RabbitStream\VO\PublishedMessageV2;

class PublishV2Test extends E2ETestCase
{
    private ?StreamConnection $connection = null;
    private string $streamName = '';

    protected function tearDown(): void
    {
        if (!$this->connection instanceof StreamConnection) {
            return;
        }
        try {
            if ($this->connection->isConnected() && $this->streamName !== '') {
                $this->connection->sendMessage(new DeleteStreamRequestV1($this->streamName));
                $this->connection->readMessage();
            }
        } catch (\Exception) {
            // Ignore cleanup errors — stream may already be deleted
        } finally {
            $this->connection->close();
        }
    }

    public function testPublishV2WithFilterValue(): void
    {
        $this->connection = $this->connectAndOpen();
        $this->streamName = 'test-publish-v2-' . uniqid();

        $this->connection->sendMessage(new CreateRequestV1($this->streamName));
        $createResponse = $this->connection->readMessage();
        $this->assertInstanceOf(CreateResponseV1::class, $createResponse);

        $this->connection->sendMessage(new DeclarePublisherRequestV1(1, null, $this->streamName));
        $declareResponse = $this->connection->readMessage();
        $this->assertInstanceOf(DeclarePublisherResponseV1::class, $declareResponse);

        $testMessage = 'Hello from V2 publish!';
        $filterValue = 'customer-123';
        $this->connection->sendMessage(
            new PublishRequestV2(1, new PublishedMessageV2(0, $filterValue, $testMessage))
        );

        $confirmedIds = [];
        $this->connection->registerPublisher(
            1,
            function (array $ids) use (&$confirmedIds): void {
                $confirmedIds = $ids;
            },
            function (): void {
            }
        );

        $this->connection->readLoop(maxFrames: 1, timeout: 5.0);
        $this->assertCount(1, $confirmedIds, 'Message should be confirmed');

        $receivedEntries = [];
        $this->connection->registerSubscriber(1, function (DeliverResponseV1 $deliver) use (&$receivedEntries): void {
            $chunkBytes = $deliver->getChunkBytes();
            $entries = OsirisChunkParser::parse($chunkBytes);
            foreach ($entries as $entry) {
                $receivedEntries[] = $entry;
            }
        });

        $this->connection->sendMessage(new SubscribeRequestV1(1, $this->streamName, OffsetSpec::first(), 10));
        $subscribeResponse = $this->connection->readMessage();
        $this->assertInstanceOf(SubscribeResponseV1::class, $subscribeResponse);

        $this->connection->sendMessage(new CreditRequestV1(1, 10));
        $this->connection->readLoop(maxFrames: 1, timeout: 5.0);

        $this->assertCount(1, $receivedEntries, 'Should receive 1 message');
        $this->assertSame(0, $receivedEntries[0]->getOffset());
        $this->assertSame($testMessage, $receivedEntries[0]->getData(), 'Message body should match published content');

        try {
            if ($this->connection->isConnected()) {
                $this->connection->sendMessage(new DeletePublisherRequestV1(1));
                $this->connection->readMessage();
            }
        } catch (\Throwable) {
            // Ignore cleanup errors
        }
    }

    public function testPublishV2MultipleMessages(): void
    {
        $this->connection = $this->connectAndOpen();
        $this->streamName = 'test-publish-v2-multi-' . uniqid();

        $this->connection->sendMessage(new CreateRequestV1($this->streamName));
        $createResponse = $this->connection->readMessage();
        $this->assertInstanceOf(CreateResponseV1::class, $createResponse);

        $this->connection->sendMessage(new DeclarePublisherRequestV1(1, null, $this->streamName));
        $declareResponse = $this->connection->readMessage();
        $this->assertInstanceOf(DeclarePublisherResponseV1::class, $declareResponse);

        $messages = [
            new PublishedMessageV2(0, 'filter-a', 'message-one'),
            new PublishedMessageV2(1, 'filter-b', 'message-two'),
            new PublishedMessageV2(2, 'filter-c', 'message-three'),
        ];

        $this->connection->sendMessage(new PublishRequestV2(1, ...$messages));

        $confirmedIds = [];
        $this->connection->registerPublisher(
            1,
            function (array $ids) use (&$confirmedIds): void {
                $confirmedIds = $ids;
            },
            function (): void {
            }
        );

        $this->connection->readLoop(maxFrames: 1, timeout: 5.0);
        $this->assertCount(3, $confirmedIds, 'All 3 messages should be confirmed');

        $receivedEntries = [];
        $this->connection->registerSubscriber(1, function (DeliverResponseV1 $deliver) use (&$receivedEntries): void {
            $chunkBytes = $deliver->getChunkBytes();
            $entries = OsirisChunkParser::parse($chunkBytes);
            foreach ($entries as $entry) {
                $receivedEntries[] = $entry;
            }
        });

        $this->connection->sendMessage(new SubscribeRequestV1(1, $this->streamName, OffsetSpec::first(), 10));
        $subscribeResponse = $this->connection->readMessage();
        $this->assertInstanceOf(SubscribeResponseV1::class, $subscribeResponse);
        $this->connection->sendMessage(new CreditRequestV1(1, 10));
        $this->connection->readLoop(maxFrames: 1, timeout: 5.0);

        $this->assertGreaterThanOrEqual(3, count($receivedEntries), 'Should receive at least 3 entries');
        $this->assertSame('message-one', $receivedEntries[0]->getData());
        $this->assertSame('message-two', $receivedEntries[1]->getData());
        $this->assertSame('message-three', $receivedEntries[2]->getData());

        try {
            if ($this->connection->isConnected()) {
                $this->connection->sendMessage(new DeletePublisherRequestV1(1));
                $this->connection->readMessage();
            }
        } catch (\Throwable) {
            // Ignore cleanup errors
        }
    }
}
