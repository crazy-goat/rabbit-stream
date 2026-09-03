<?php

declare(strict_types=1);

namespace CrazyGoat\RabbitStream\Tests\E2E;

use CrazyGoat\RabbitStream\Client\Connection;
use CrazyGoat\RabbitStream\Client\Message;
use CrazyGoat\RabbitStream\VO\OffsetSpec;

class LargeMessageE2ETest extends E2ETestCase
{
    private ?Connection $connection = null;
    private string $streamName;

    protected function setUp(): void
    {
        $this->connection = $this->createConnection();
        $this->streamName = 'test-large-msg-' . uniqid();
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

    /** @return Message[] */
    private function publishAndConsume(string $amqpBody): array
    {
        $this->assertNotNull($this->connection);

        $producer = $this->connection->createProducer($this->streamName);
        $producer->send($amqpBody);
        $producer->waitForConfirms(timeout: 5);
        $producer->close();

        $consumer = $this->connection->createConsumer($this->streamName, OffsetSpec::first());

        $received = [];
        $deadline = time() + 10;
        while ($received === [] && time() < $deadline) {
            $received = $consumer->read(timeout: 1.0);
        }

        $consumer->close();

        return $received;
    }

    public function testPublishAndConsume100kbMessage(): void
    {
        $body = str_repeat('A', 100_000);
        $messages = $this->publishAndConsume($body);

        $this->assertNotEmpty($messages, 'Should receive at least one message');
        $this->assertSame($body, $messages[0]->getBody(), '100KB body should round-trip correctly');
    }

    public function testPublishAndConsume1mbMessage(): void
    {
        $body = str_repeat('B', 1_000_000);
        $messages = $this->publishAndConsume($body);

        $this->assertNotEmpty($messages, 'Should receive at least one message');
        $this->assertSame($body, $messages[0]->getBody(), '1MB body should round-trip correctly');
    }

    /**
     * Regression test for the "poison chunk" bug: the broker does NOT enforce
     * the negotiated frame_max on Deliver frames (key 0x0008) — a stream chunk
     * is sent whole. A fast producer publishing a large batch in one shot makes
     * the broker coalesce it into a single chunk bigger than the negotiated
     * frame_max (default 1 MiB), and a consumer using the default incoming
     * frame cap on Deliver frames dies with "Frame size ... exceeds maximum
     * allowed ..." on every restart, forever, at the same offset — because the
     * oversized chunk lives on the broker's disk regardless of restarts.
     *
     * Reproduced deterministically by raising the *producer's* requestedFrameMax
     * (so publishing a >1 MiB batch in one PublishRequest frame is itself
     * allowed) while the *consumer* connection uses only defaults — exactly
     * the trap a real application falls into.
     */
    public function testConsumingChunkLargerThanDefaultFrameMaxDoesNotThrow(): void
    {
        $this->assertNotNull($this->connection);

        // Producer connection: defaults only. Each individual PublishRequest
        // batch (500 x 1KB =~ 512KB) stays comfortably under the default 1 MiB
        // negotiated frame_max, so this reproduces the bug purely from server
        // -side chunk coalescing, not from an oversized outgoing frame.
        $producerConnection = Connection::create(host: self::$host, port: self::$port);

        $messageCount = 3000;
        try {
            $producer = $producerConnection->createProducer($this->streamName);

            // Publish 6 batches of 500 x 1KB messages back-to-back, WITHOUT
            // waiting for confirms in between: the broker's Osiris chunk
            // writer sees a growing backlog of unflushed data and coalesces
            // it into progressively larger chunks (measured: chunk sizes grow
            // roughly 33KB, 66KB, 133KB, 265KB, 530KB, 1.06MB, ...), eventually
            // producing a single on-disk chunk bigger than the default 1 MiB
            // frame_max — even though every individual PublishRequest frame
            // sent to the broker stayed well under it.
            $batch = array_fill(0, 500, str_repeat('M', 1024));
            for ($i = 0; $i < $messageCount / 500; $i++) {
                $producer->sendBatch($batch);
            }
            $producer->waitForConfirms(timeout: 20.0);
            $producer->close();
        } finally {
            $producerConnection->close();
        }

        // Consumer connection: defaults only — this is what a real application
        // would use, and it must not choke on the broker's oversized chunk.
        $consumerConnection = Connection::create(host: self::$host, port: self::$port);

        try {
            $consumer = $consumerConnection->createConsumer($this->streamName, OffsetSpec::first());

            $received = [];
            $deadline = time() + 20;
            while (count($received) < $messageCount && time() < $deadline) {
                $received = array_merge($received, $consumer->read(timeout: 1.0));
            }

            $consumer->close();

            $this->assertCount(
                $messageCount,
                $received,
                'All messages should be received despite the oversized chunk'
            );
            $this->assertTrue($consumerConnection->isConnected(), 'Consumer connection should remain usable');
        } finally {
            $consumerConnection->close();
        }
    }

    public function testMessageExceedingFrameMaxThrowsGracefully(): void
    {
        $conn = Connection::create(
            host: self::$host,
            port: self::$port,
            requestedFrameMax: 4096,
        );

        $streamName = 'test-frame-max-' . uniqid();
        try {
            $conn->createStream($streamName);

            $body = str_repeat('C', 10_000);
            $producer = $conn->createProducer($streamName);

            // The outgoing frame is now rejected up front — before anything is
            // written to the socket — so send() itself throws, not
            // waitForConfirms() after the broker has already closed the
            // connection (see StreamConnection::sendFrame()).
            $threw = true;
            try {
                $producer->send($body);
                $threw = false;
            } catch (\CrazyGoat\RabbitStream\Exception\InvalidArgumentException $e) {
                $this->assertNotEmpty($e->getMessage());
            }
            $this->assertTrue($threw, 'Publishing a message exceeding frameMax should throw');

            // Unlike the old broker-closes-the-socket behavior, this is a pure
            // client-side validation error: the connection stays connected and
            // usable for subsequent, correctly-sized operations. Use a fresh
            // producer here: Producer::send() optimistically increments its
            // pendingConfirms counter before writing the frame, so the rejected
            // send above left the original producer's bookkeeping expecting a
            // confirm that will never arrive.
            $this->assertTrue($conn->isConnected(), 'Connection should remain usable after frameMax rejection');
            $producer->close();
            $freshProducer = $conn->createProducer($streamName);
            $freshProducer->send(str_repeat('D', 100));
            $freshProducer->waitForConfirms(timeout: 5.0);
            $freshProducer->close();
        } finally {
            try {
                $conn->deleteStream($streamName);
            } catch (\Exception) {
            }
            try {
                $conn->close();
            } catch (\Exception) {
            }
        }
    }
}
