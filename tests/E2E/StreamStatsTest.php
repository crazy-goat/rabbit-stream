<?php

declare(strict_types=1);

namespace CrazyGoat\RabbitStream\Tests\E2E;

use CrazyGoat\RabbitStream\Request\CreateRequestV1;
use CrazyGoat\RabbitStream\Request\StreamStatsRequestV1;
use CrazyGoat\RabbitStream\Response\CreateResponseV1;
use CrazyGoat\RabbitStream\Response\StreamStatsResponseV1;
use CrazyGoat\RabbitStream\Tests\E2E\E2ETestCase;

class StreamStatsTest extends E2ETestCase
{
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
        $this->assertNotEmpty($stats);

        $connection->close();
    }

    public function testStreamStatsReturnsTheBrokerKeysForNewStream(): void
    {
        $connection = $this->createConnection();
        $streamName = 'test-stream-stats-new-' . uniqid();
        $connection->createStream($streamName);

        try {
            $stats = $connection->getStreamStats($streamName);

            foreach (['first_chunk_id', 'last_chunk_id', 'committed_chunk_id', 'committed_offset'] as $key) {
                $this->assertArrayHasKey($key, $stats);
                $this->assertSame(-1, $stats[$key], "$key of a new stream");
            }
        } finally {
            $connection->deleteStream($streamName);
            $connection->close();
        }
    }

    public function testStreamStatsAfterOneConfirmedMessage(): void
    {
        $connection = $this->createConnection();
        $streamName = 'test-stream-stats-publish-' . uniqid();
        $connection->createStream($streamName);

        try {
            $producer = $connection->createProducer($streamName);
            $producer->send('stats message');
            $producer->waitForConfirms(timeout: 5);
            $producer->close();

            $stats = $connection->getStreamStats($streamName);

            $this->assertSame(0, $stats['first_chunk_id']);
            $this->assertSame(0, $stats['last_chunk_id']);
            $this->assertSame(0, $stats['committed_chunk_id']);
            $this->assertGreaterThanOrEqual(0, $stats['committed_offset']);
        } finally {
            $connection->deleteStream($streamName);
            $connection->close();
        }
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
