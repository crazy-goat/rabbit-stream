<?php

declare(strict_types=1);

namespace CrazyGoat\RabbitStream\Tests\E2E;

use CrazyGoat\RabbitStream\Client\Connection;
use CrazyGoat\RabbitStream\VO\OffsetSpec;

/**
 * Broker-side stream filtering (#380) filters per-CHUNK using a bloom filter
 * over the filter values of the messages in that chunk, not per-message: a
 * chunk is delivered as soon as its bloom filter MAY contain a subscribed
 * filter value, and once delivered every message in it arrives, matching or
 * not. So:
 *  - a message with a matching filter value is always delivered;
 *  - a message with a non-matching filter value MAY still be delivered if it
 *    shares a chunk with a matching one (false sharing, not a false positive
 *    of the bloom filter itself);
 *  - a chunk containing ONLY non-matching filter values is never delivered.
 *
 * Exact (message-granular) filtering therefore requires the consumer to
 * post-filter on the message's own filter value once the wire exposes it
 * (out of scope here — this test only exercises the broker-side chunk
 * filter). To make the "never delivered" half of the contract observable
 * without relying on filter-value post-filtering, the matching and
 * non-matching batches are published far enough apart (separate producers,
 * separate stream positions with a small settle delay) that the broker
 * writes them into distinct chunks.
 */
class SubscribeFilterE2ETest extends E2ETestCase
{
    private ?Connection $connection = null;
    private string $streamName;

    protected function setUp(): void
    {
        $this->connection = $this->createConnection();
        $this->streamName = 'test-filter-' . uniqid();
        $this->connection->createStream($this->streamName);
    }

    protected function tearDown(): void
    {
        if ($this->connection instanceof Connection) {
            try {
                $this->connection->deleteStream($this->streamName);
            } catch (\Exception) {
            }
            $this->connection->close();
        }
    }

    public function testSubscribeWithFilterValuesOnlyReceivesMatchingChunks(): void
    {
        $this->assertNotNull($this->connection);

        $producer = $this->connection->createProducer($this->streamName);

        // Non-matching batch first, in its own chunk (flushed via waitForConfirms
        // + a settle delay before the next batch is published).
        for ($i = 0; $i < 5; $i++) {
            $producer->sendWithFilter("other-{$i}", 'region-us');
        }
        $producer->waitForConfirms(timeout: 5);
        usleep(200_000);

        // Matching batch, in a separate chunk.
        for ($i = 0; $i < 5; $i++) {
            $producer->sendWithFilter("eu-{$i}", 'region-eu');
        }
        $producer->waitForConfirms(timeout: 5);
        usleep(200_000);

        // Another non-matching batch after, again in its own chunk.
        for ($i = 0; $i < 5; $i++) {
            $producer->sendWithFilter("other2-{$i}", 'region-us');
        }
        $producer->waitForConfirms(timeout: 5);
        $producer->close();

        $consumer = $this->connection->createConsumer(
            $this->streamName,
            OffsetSpec::first(),
            filterValues: ['region-eu'],
        );

        /** @var list<string> $received */
        $received = [];
        $deadline = time() + 3;
        while (time() < $deadline) {
            foreach ($consumer->read(timeout: 0.5) as $msg) {
                $received[] = $msg->getBody();
            }
        }

        $consumer->close();

        // Every delivered message matches the filter (chunks were kept pure).
        foreach ($received as $body) {
            $this->assertIsString($body);
            $this->assertStringStartsWith('eu-', $body, "Unexpected non-matching message delivered: {$body}");
        }
        // Chunks containing only non-matching values were never delivered.
        foreach ($received as $body) {
            $this->assertIsString($body);
            $this->assertStringNotContainsString('other', $body);
        }
        $this->assertNotEmpty($received, 'At least the matching chunk should have been delivered');
    }

    public function testSubscribeWithMatchUnfilteredReceivesUnfilteredMessages(): void
    {
        $this->assertNotNull($this->connection);

        $producer = $this->connection->createProducer($this->streamName);

        // Published without any filter value at all.
        for ($i = 0; $i < 3; $i++) {
            $producer->sendWithFilter("unfiltered-{$i}", null);
        }
        $producer->waitForConfirms(timeout: 5);
        $producer->close();

        $consumer = $this->connection->createConsumer(
            $this->streamName,
            OffsetSpec::first(),
            filterValues: ['region-eu'],
            matchUnfiltered: true,
        );

        $received = [];
        $deadline = time() + 3;
        while (count($received) < 3 && time() < $deadline) {
            foreach ($consumer->read(timeout: 0.5) as $msg) {
                $received[] = $msg->getBody();
            }
        }

        $consumer->close();

        $this->assertCount(3, $received, 'matchUnfiltered=true should deliver messages with no filter value');
    }
}
