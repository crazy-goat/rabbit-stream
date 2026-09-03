<?php

declare(strict_types=1);

namespace CrazyGoat\RabbitStream\Tests\E2E;

use CrazyGoat\RabbitStream\Client\Connection;
use CrazyGoat\RabbitStream\Client\Consumer;
use CrazyGoat\RabbitStream\Client\Message;
use CrazyGoat\RabbitStream\Client\Producer;
use CrazyGoat\RabbitStream\Client\SuperStreamProducer;
use CrazyGoat\RabbitStream\VO\OffsetSpec;

/**
 * Recovery from a MetadataUpdate: the broker drops every publisher and
 * subscription of a stream that becomes unavailable, and the client
 * re-declares/re-subscribes them transparently (#499).
 *
 * Each test deletes the stream from a second (admin) connection, which is what
 * makes the broker push MetadataUpdate to the connection under test.
 *
 * @group slow
 */
class MetadataUpdateRecoveryE2ETest extends E2ETestCase
{
    private ?Connection $connection = null;
    private ?Connection $admin = null;
    /** @var list<string> */
    private array $streamsToDelete = [];
    private string $superStreamName = '';

    protected function setUp(): void
    {
        $this->connection = $this->createConnection();
        $this->admin = $this->createConnection();
    }

    protected function tearDown(): void
    {
        $admin = $this->admin;
        if ($admin instanceof Connection) {
            foreach ($this->streamsToDelete as $stream) {
                try {
                    $admin->deleteStream($stream);
                } catch (\Exception) {
                    // Already gone — the test deleted it on purpose.
                }
            }
            if ($this->superStreamName !== '') {
                try {
                    $admin->deleteSuperStream($this->superStreamName);
                } catch (\Exception) {
                    // Already gone.
                }
            }
            $admin->close();
        }
        if ($this->connection instanceof Connection) {
            $this->connection->close();
        }
        $this->connection = null;
        $this->admin = null;
        $this->streamsToDelete = [];
        $this->superStreamName = '';
    }

    public function testProducerRedeclaresItselfAfterTheStreamIsDeletedAndRecreated(): void
    {
        $connection = $this->connection;
        $admin = $this->admin;
        $this->assertInstanceOf(Connection::class, $connection);
        $this->assertInstanceOf(Connection::class, $admin);

        $stream = 'test-mu-producer-' . uniqid();
        $this->streamsToDelete[] = $stream;
        $admin->createStream($stream);

        $producer = $connection->createProducer($stream);
        $this->assertInstanceOf(Producer::class, $producer);
        $producer->send('before');
        $producer->waitForConfirms(timeout: 5.0);
        $this->assertFalse($producer->isStale());

        // Deleting from the admin connection makes the broker push
        // MetadataUpdate to the publishing connection.
        $admin->deleteStream($stream);
        $this->awaitMetadataUpdate($connection, fn(): bool => $producer->isStale());
        $this->assertTrue($producer->isStale(), 'MetadataUpdate must mark the publisher stale');
        $this->assertSame(0, $producer->getRedeclareCount(), 'Re-declare happens on the next publish, not eagerly');

        $admin->createStream($stream);

        $producer->send('after');
        $producer->waitForConfirms(timeout: 5.0);

        $this->assertFalse($producer->isStale());
        $this->assertSame(1, $producer->getRedeclareCount());

        // The message published after the recreate really is in the stream.
        $consumer = $connection->createConsumer($stream, OffsetSpec::first());
        $bodies = $this->bodiesOf($consumer->read(timeout: 5.0));
        $consumer->close();
        $producer->close();

        $this->assertSame(['after'], $bodies);
    }

    public function testConsumerResubscribesAfterTheStreamIsDeletedAndRecreated(): void
    {
        $connection = $this->connection;
        $admin = $this->admin;
        $this->assertInstanceOf(Connection::class, $connection);
        $this->assertInstanceOf(Connection::class, $admin);

        $stream = 'test-mu-consumer-' . uniqid();
        $this->streamsToDelete[] = $stream;
        $admin->createStream($stream);

        $seed = $admin->createProducer($stream);
        $seed->sendBatch(['a', 'b']);
        $seed->waitForConfirms(timeout: 5.0);
        $seed->close();

        $consumer = $connection->createConsumer($stream, OffsetSpec::first());
        $this->assertInstanceOf(Consumer::class, $consumer);
        $this->assertCount(2, $consumer->read(timeout: 5.0));
        $this->assertFalse($consumer->isSubscriptionLost());

        $admin->deleteStream($stream);
        $this->awaitMetadataUpdate($connection, fn(): bool => $consumer->isSubscriptionLost());
        $this->assertTrue($consumer->isSubscriptionLost(), 'MetadataUpdate must mark the subscription lost');

        $admin->createStream($stream);
        $reseed = $admin->createProducer($stream);
        $reseed->sendBatch(['c', 'd']);
        $reseed->waitForConfirms(timeout: 5.0);
        $reseed->close();

        // read() re-subscribes on its own; the recreated stream restarts its
        // offsets, so the consumer falls back to its initial OffsetSpec (FIRST)
        // instead of waiting past an offset that no longer exists.
        $bodies = $this->bodiesOf($consumer->read(timeout: 5.0));
        $consumer->close();

        $this->assertSame(['c', 'd'], $bodies);
        $this->assertFalse($consumer->isSubscriptionLost());
        $this->assertSame(1, $consumer->getResubscribeCount());
    }

    public function testSuperStreamProducerRefreshesPartitionsAfterOneIsDeleted(): void
    {
        $connection = $this->connection;
        $admin = $this->admin;
        $this->assertInstanceOf(Connection::class, $connection);
        $this->assertInstanceOf(Connection::class, $admin);

        $this->superStreamName = 'test-mu-ss-' . uniqid();
        $partitions = [
            $this->superStreamName . '-0',
            $this->superStreamName . '-1',
            $this->superStreamName . '-2',
        ];
        $admin->createSuperStream($this->superStreamName, $partitions, ['0', '1', '2']);

        $producer = $connection->createSuperStreamProducer($this->superStreamName);
        $this->assertInstanceOf(SuperStreamProducer::class, $producer);
        for ($i = 0; $i < 30; $i++) {
            $producer->send("message-{$i}", "key-{$i}");
        }
        $producer->waitForConfirms(timeout: 5.0);
        $this->assertCount(3, $producer->getPartitions());

        // Deleting a partition stream unbinds it from the super stream, so the
        // refreshed partition list is one shorter.
        $admin->deleteStream($partitions[1]);
        $this->awaitMetadataUpdate($connection, fn(): bool => $producer->isPartitionsStale());
        $this->assertTrue($producer->isPartitionsStale(), 'MetadataUpdate on a partition must mark the topology stale');
        $this->assertSame(3, count($producer->getPartitions()), 'Refresh happens on the next publish, not eagerly');

        for ($i = 30; $i < 60; $i++) {
            $producer->send("message-{$i}", "key-{$i}");
        }
        $producer->waitForConfirms(timeout: 5.0);

        $this->assertFalse($producer->isPartitionsStale());
        $this->assertSame(1, $producer->getRefreshCount());
        $this->assertCount(2, $producer->getPartitions(), 'The deleted partition is dropped from the routing set');
        $this->assertNotContains($partitions[1], $producer->getPartitions());

        $producer->close();
    }

    /**
     * @param array<Message> $messages
     * @return list<mixed> each message's body, asserted to be a string
     */
    private function bodiesOf(array $messages): array
    {
        $bodies = [];
        foreach ($messages as $message) {
            $body = $message->getBody();
            $this->assertIsString($body);
            $bodies[] = $body;
        }
        return $bodies;
    }

    /**
     * Service the socket until the pushed MetadataUpdate has been dispatched
     * (or the deadline passes, so a failing assertion reports the real state).
     */
    private function awaitMetadataUpdate(Connection $connection, callable $isHandled, float $timeout = 5.0): void
    {
        $deadline = microtime(true) + $timeout;
        while (!$isHandled() && microtime(true) < $deadline) {
            $connection->readLoop(maxFrames: 1, timeout: 0.2);
        }
    }
}
