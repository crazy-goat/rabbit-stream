<?php

declare(strict_types=1);

namespace CrazyGoat\RabbitStream\Tests\E2E;

use CrazyGoat\RabbitStream\Client\OsirisChunkParser;
use CrazyGoat\RabbitStream\Request\CreateRequestV1;
use CrazyGoat\RabbitStream\Request\CreditRequestV1;
use CrazyGoat\RabbitStream\Request\DeclarePublisherRequestV1;
use CrazyGoat\RabbitStream\Request\DeletePublisherRequestV1;
use CrazyGoat\RabbitStream\Request\OpenRequestV1;
use CrazyGoat\RabbitStream\Request\PeerPropertiesRequestV1;
use CrazyGoat\RabbitStream\Request\PublishRequestV1;
use CrazyGoat\RabbitStream\Request\SaslAuthenticateRequestV1;
use CrazyGoat\RabbitStream\Request\SaslHandshakeRequestV1;
use CrazyGoat\RabbitStream\Request\SubscribeRequestV1;
use CrazyGoat\RabbitStream\Request\TuneRequestV1;
use CrazyGoat\RabbitStream\Response\CreateResponseV1;
use CrazyGoat\RabbitStream\Response\DeclarePublisherResponseV1;
use CrazyGoat\RabbitStream\Response\DeliverResponseV1;
use CrazyGoat\RabbitStream\Response\SubscribeResponseV1;
use CrazyGoat\RabbitStream\Response\TuneResponseV1;
use CrazyGoat\RabbitStream\StreamConnection;
use CrazyGoat\RabbitStream\VO\OffsetSpec;
use CrazyGoat\RabbitStream\VO\PublishedMessage;
use PHPUnit\Framework\TestCase;

class SubscribeTest extends TestCase
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

    public function testSubscribeToStream(): void
    {
        $connection = $this->connectAndOpen();

        // Create a test stream
        $streamName = 'test-subscribe-stream-' . uniqid();
        $connection->sendMessage(new CreateRequestV1($streamName));
        $createResponse = $connection->readMessage();
        $this->assertInstanceOf(CreateResponseV1::class, $createResponse);

        // Subscribe to the stream
        $connection->sendMessage(new SubscribeRequestV1(1, $streamName, OffsetSpec::first(), 10));
        $response = $connection->readMessage();

        $this->assertInstanceOf(SubscribeResponseV1::class, $response);

        $connection->close();
    }

    public function testSubscribeWithOffsetLast(): void
    {
        $connection = $this->connectAndOpen();

        $streamName = 'test-subscribe-last-' . uniqid();
        $connection->sendMessage(new CreateRequestV1($streamName));
        $connection->readMessage();

        $connection->sendMessage(new SubscribeRequestV1(1, $streamName, OffsetSpec::last(), 5));
        $response = $connection->readMessage();

        $this->assertInstanceOf(SubscribeResponseV1::class, $response);

        $connection->close();
    }

    public function testSubscribeWithOffsetNext(): void
    {
        $connection = $this->connectAndOpen();

        $streamName = 'test-subscribe-next-' . uniqid();
        $connection->sendMessage(new CreateRequestV1($streamName));
        $connection->readMessage();

        $connection->sendMessage(new SubscribeRequestV1(1, $streamName, OffsetSpec::next(), 20));
        $response = $connection->readMessage();

        $this->assertInstanceOf(SubscribeResponseV1::class, $response);

        $connection->close();
    }

    public function testSubscribeToNonExistentStreamThrows(): void
    {
        $connection = $this->connectAndOpen();

        $this->expectException(\Exception::class);
        $connection->sendMessage(new SubscribeRequestV1(1, 'non-existent-stream-' . uniqid(), OffsetSpec::first(), 10));
        $connection->readMessage();

        $connection->close();
    }

    public function testDuplicateSubscriptionIdThrows(): void
    {
        $connection = $this->connectAndOpen();

        $streamName = 'test-subscribe-duplicate-' . uniqid();
        $connection->sendMessage(new CreateRequestV1($streamName));
        $connection->readMessage();

        // First subscription should succeed
        $connection->sendMessage(new SubscribeRequestV1(1, $streamName, OffsetSpec::first(), 10));
        $response = $connection->readMessage();
        $this->assertInstanceOf(SubscribeResponseV1::class, $response);

        // Second subscription with same ID should fail
        $this->expectException(\Exception::class);
        $connection->sendMessage(new SubscribeRequestV1(1, $streamName, OffsetSpec::first(), 10));
        $connection->readMessage();

        $connection->close();
    }

    public function testSubscribeFromSpecificOffset(): void
    {
        $connection = $this->connectAndOpen();
        $streamName = 'test-subscribe-offset-' . uniqid();

        $connection->sendMessage(new CreateRequestV1($streamName));
        $createResponse = $connection->readMessage();
        $this->assertInstanceOf(CreateResponseV1::class, $createResponse);

        $connection->sendMessage(new DeclarePublisherRequestV1(1, null, $streamName));
        $declareResponse = $connection->readMessage();
        $this->assertInstanceOf(DeclarePublisherResponseV1::class, $declareResponse);

        $messages = [];
        for ($i = 0; $i < 10; $i++) {
            $messages[] = new PublishedMessage($i, "Message $i");
        }

        $connection->sendMessage(new PublishRequestV1(1, ...$messages));

        $confirmedIds = [];
        $connection->registerPublisher(
            1,
            function (array $ids) use (&$confirmedIds): void {
                $confirmedIds = array_merge($confirmedIds, $ids);
            },
            function (): void {
            }
        );

        $connection->readLoop(maxFrames: 1);
        $this->assertCount(10, $confirmedIds, 'All 10 messages should be confirmed');

        $receivedEntries = [];
        $connection->registerSubscriber(1, function (DeliverResponseV1 $deliver) use (&$receivedEntries): void {
            $chunkBytes = $deliver->getChunkBytes();
            $entries = OsirisChunkParser::parse($chunkBytes);
            foreach ($entries as $entry) {
                $receivedEntries[] = $entry;
            }
        });

        $connection->sendMessage(new SubscribeRequestV1(1, $streamName, OffsetSpec::offset(5), 10));
        $subscribeResponse = $connection->readMessage();
        $this->assertInstanceOf(SubscribeResponseV1::class, $subscribeResponse);

        $connection->sendMessage(new CreditRequestV1(1, 10));
        $connection->readLoop(maxFrames: 1);

        $this->assertCount(5, $receivedEntries, 'Should receive exactly 5 messages (offsets 5-9)');

        $getOffset = fn(\CrazyGoat\RabbitStream\Client\ChunkEntry $e): int => $e->getOffset();
        $offsets = array_map($getOffset, $receivedEntries);
        $this->assertSame([5, 6, 7, 8, 9], $offsets, 'Should receive messages with offsets 5, 6, 7, 8, 9');

        try {
            if ($connection->isConnected()) {
                $connection->sendMessage(new DeletePublisherRequestV1(1));
                $connection->readMessage();
            }
        } catch (\Throwable) {
        }

        $connection->close();
    }

    public function testSubscribeFromOffsetZero(): void
    {
        $connection = $this->connectAndOpen();
        $streamName = 'test-subscribe-offset-zero-' . uniqid();

        $connection->sendMessage(new CreateRequestV1($streamName));
        $createResponse = $connection->readMessage();
        $this->assertInstanceOf(CreateResponseV1::class, $createResponse);

        $connection->sendMessage(new DeclarePublisherRequestV1(1, null, $streamName));
        $declareResponse = $connection->readMessage();
        $this->assertInstanceOf(DeclarePublisherResponseV1::class, $declareResponse);

        $messages = [];
        for ($i = 0; $i < 10; $i++) {
            $messages[] = new PublishedMessage($i, "Message $i");
        }

        $connection->sendMessage(new PublishRequestV1(1, ...$messages));

        $confirmedIds = [];
        $connection->registerPublisher(
            1,
            function (array $ids) use (&$confirmedIds): void {
                $confirmedIds = array_merge($confirmedIds, $ids);
            },
            function (): void {
            }
        );

        $connection->readLoop(maxFrames: 1);
        $this->assertCount(10, $confirmedIds, 'All 10 messages should be confirmed');

        $receivedEntries = [];
        $connection->registerSubscriber(1, function (DeliverResponseV1 $deliver) use (&$receivedEntries): void {
            $chunkBytes = $deliver->getChunkBytes();
            $entries = OsirisChunkParser::parse($chunkBytes);
            foreach ($entries as $entry) {
                $receivedEntries[] = $entry;
            }
        });

        $connection->sendMessage(new SubscribeRequestV1(1, $streamName, OffsetSpec::offset(0), 10));
        $subscribeResponse = $connection->readMessage();
        $this->assertInstanceOf(SubscribeResponseV1::class, $subscribeResponse);

        $connection->sendMessage(new CreditRequestV1(1, 10));
        $connection->readLoop(maxFrames: 1);

        $this->assertCount(10, $receivedEntries, 'Should receive all 10 messages when subscribing from offset 0');

        $getOffset = fn(\CrazyGoat\RabbitStream\Client\ChunkEntry $e): int => $e->getOffset();
        $offsets = array_map($getOffset, $receivedEntries);
        $this->assertSame([0, 1, 2, 3, 4, 5, 6, 7, 8, 9], $offsets, 'Should receive messages with offsets 0-9');

        try {
            if ($connection->isConnected()) {
                $connection->sendMessage(new DeletePublisherRequestV1(1));
                $connection->readMessage();
            }
        } catch (\Throwable) {
        }

        $connection->close();
    }

    public function testSubscribeFromOffsetBeyondEnd(): void
    {
        $connection = $this->connectAndOpen();
        $streamName = 'test-subscribe-offset-beyond-' . uniqid();

        $connection->sendMessage(new CreateRequestV1($streamName));
        $createResponse = $connection->readMessage();
        $this->assertInstanceOf(CreateResponseV1::class, $createResponse);

        $connection->sendMessage(new DeclarePublisherRequestV1(1, null, $streamName));
        $declareResponse = $connection->readMessage();
        $this->assertInstanceOf(DeclarePublisherResponseV1::class, $declareResponse);

        $messages = [];
        for ($i = 0; $i < 5; $i++) {
            $messages[] = new PublishedMessage($i, "Message $i");
        }

        $connection->sendMessage(new PublishRequestV1(1, ...$messages));

        $confirmedIds = [];
        $connection->registerPublisher(
            1,
            function (array $ids) use (&$confirmedIds): void {
                $confirmedIds = array_merge($confirmedIds, $ids);
            },
            function (): void {
            }
        );

        $connection->readLoop(maxFrames: 1);
        $this->assertCount(5, $confirmedIds, 'All 5 messages should be confirmed');

        $receivedEntries = [];
        $connection->registerSubscriber(1, function (DeliverResponseV1 $deliver) use (&$receivedEntries): void {
            $chunkBytes = $deliver->getChunkBytes();
            $entries = OsirisChunkParser::parse($chunkBytes);
            foreach ($entries as $entry) {
                $receivedEntries[] = $entry;
            }
        });

        $connection->sendMessage(new SubscribeRequestV1(1, $streamName, OffsetSpec::offset(100), 10));
        $subscribeResponse = $connection->readMessage();
        $this->assertInstanceOf(SubscribeResponseV1::class, $subscribeResponse);

        $connection->sendMessage(new CreditRequestV1(1, 10));

        $connection->readLoop(maxFrames: 1, timeout: 1.0);

        $this->assertCount(
            0,
            $receivedEntries,
            'Should receive no messages when subscribing from offset beyond stream end'
        );

        $connection->sendMessage(new PublishRequestV1(1, new PublishedMessage(5, 'Message 5')));
        $connection->readLoop(maxFrames: 1);

        $connection->readLoop(maxFrames: 1);

        $this->assertCount(1, $receivedEntries, 'Should receive 1 message after publishing to offset 5');
        $this->assertSame(5, $receivedEntries[0]->getOffset(), 'Received message should have offset 5');

        try {
            if ($connection->isConnected()) {
                $connection->sendMessage(new DeletePublisherRequestV1(1));
                $connection->readMessage();
            }
        } catch (\Throwable) {
        }

        $connection->close();
    }
}
