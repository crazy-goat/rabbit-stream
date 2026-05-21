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
