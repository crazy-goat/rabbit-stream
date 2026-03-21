<?php

declare(strict_types=1);

namespace CrazyGoat\RabbitStream\Tests\E2E;

use CrazyGoat\RabbitStream\Client\ChunkEntry;
use CrazyGoat\RabbitStream\Client\OsirisChunkParser;
use CrazyGoat\RabbitStream\Request\CreateRequestV1;
use CrazyGoat\RabbitStream\Request\CreditRequestV1;
use CrazyGoat\RabbitStream\Request\DeclarePublisherRequestV1;
use CrazyGoat\RabbitStream\Request\DeletePublisherRequestV1;
use CrazyGoat\RabbitStream\Request\OpenRequestV1;
use CrazyGoat\RabbitStream\Request\PeerPropertiesToStreamBufferV1;
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

class OsirisChunkParserE2ETest extends TestCase
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

    public function testParseRealChunkFromRabbitMQ(): void
    {
        $connection = $this->connectAndOpen();
        $streamName = 'test-chunk-parser-' . uniqid();

        $connection->sendMessage(new CreateRequestV1($streamName));
        $createResponse = $connection->readMessage();
        $this->assertInstanceOf(CreateResponseV1::class, $createResponse);

        $connection->sendMessage(new DeclarePublisherRequestV1(1, null, $streamName));
        $declareResponse = $connection->readMessage();
        $this->assertInstanceOf(DeclarePublisherResponseV1::class, $declareResponse);

        $testMessages = [
            'Hello from E2E test!',
            'Second message',
            'Third message',
        ];

        $messages = [];
        foreach ($testMessages as $index => $message) {
            $messages[] = new PublishedMessage($index, $message);
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
        $this->assertCount(3, $confirmedIds, 'Messages should be confirmed');

        $receivedEntries = [];
        $connection->registerSubscriber(1, function (DeliverResponseV1 $deliver) use (&$receivedEntries): void {
            $chunkBytes = $deliver->getChunkBytes();
            $entries = OsirisChunkParser::parse($chunkBytes);
            foreach ($entries as $entry) {
                $receivedEntries[] = $entry;
            }
        });

        $connection->sendMessage(new SubscribeRequestV1(1, $streamName, OffsetSpec::first(), 10));
        $subscribeResponse = $connection->readMessage();
        $this->assertInstanceOf(SubscribeResponseV1::class, $subscribeResponse);

        $connection->sendMessage(new CreditRequestV1(1, 10));

        $connection->readLoop(maxFrames: 1);

        $this->assertGreaterThanOrEqual(3, count($receivedEntries), 'Should receive at least 3 entries');

        foreach ($receivedEntries as $entry) {
            $this->assertInstanceOf(ChunkEntry::class, $entry);
            $this->assertGreaterThanOrEqual(0, $entry->getOffset());
            $this->assertNotEmpty($entry->getData());
            $this->assertGreaterThan(0, $entry->getTimestamp());
        }

        $offsets = array_map(fn(ChunkEntry $e): int => $e->getOffset(), $receivedEntries);
        $this->assertSame($offsets, array_unique($offsets), 'Offsets should be unique');

        try {
            if ($connection->isConnected()) {
                $connection->sendMessage(new DeletePublisherRequestV1(1));
                $connection->readMessage();
            }
        } catch (\Throwable) {
            // Server may have already closed the connection
        }

        $connection->close();
    }

    public function testChunkParserWithSingleMessage(): void
    {
        $connection = $this->connectAndOpen();
        $streamName = 'test-single-chunk-' . uniqid();

        $connection->sendMessage(new CreateRequestV1($streamName));
        $connection->readMessage();

        $connection->sendMessage(new DeclarePublisherRequestV1(1, null, $streamName));
        $connection->readMessage();

        $testMessage = 'Single test message';
        $connection->sendMessage(new PublishRequestV1(1, new PublishedMessage(0, $testMessage)));

        $confirmedIds = [];
        $connection->registerPublisher(
            1,
            function (array $ids) use (&$confirmedIds): void {
                $confirmedIds = $ids;
            },
            function (): void {
            }
        );

        $connection->readLoop(maxFrames: 1);
        $this->assertCount(1, $confirmedIds);

        $receivedEntries = [];
        $connection->registerSubscriber(1, function (DeliverResponseV1 $deliver) use (&$receivedEntries): void {
            $chunkBytes = $deliver->getChunkBytes();
            $entries = OsirisChunkParser::parse($chunkBytes);
            foreach ($entries as $entry) {
                $receivedEntries[] = $entry;
            }
        });

        $connection->sendMessage(new SubscribeRequestV1(1, $streamName, OffsetSpec::first(), 10));
        $connection->readMessage();
        $connection->sendMessage(new CreditRequestV1(1, 10));
        $connection->readLoop(maxFrames: 1);

        $this->assertCount(1, $receivedEntries);
        $this->assertSame(0, $receivedEntries[0]->getOffset());
        $this->assertNotEmpty($receivedEntries[0]->getData());

        try {
            if ($connection->isConnected()) {
                $connection->sendMessage(new DeletePublisherRequestV1(1));
                $connection->readMessage();
            }
        } catch (\Throwable) {
            // Server may have already closed the connection
        }

        $connection->close();
    }
}
