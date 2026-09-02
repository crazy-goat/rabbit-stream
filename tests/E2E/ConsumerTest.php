<?php

declare(strict_types=1);

namespace CrazyGoat\RabbitStream\Tests\E2E;

use CrazyGoat\RabbitStream\Client\Connection;
use CrazyGoat\RabbitStream\Client\Message;
use CrazyGoat\RabbitStream\VO\OffsetSpec;

class ConsumerTest extends E2ETestCase
{
    private ?Connection $connection = null;
    private string $streamName;

    protected function setUp(): void
    {
        $this->connection = $this->createConnection();
        $this->streamName = 'test-consumer-' . uniqid();
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

    public function testResumingFromTheStoredOffsetDoesNotRedeliverTheLastMessage(): void
    {
        $connection = $this->connection;
        $this->assertInstanceOf(Connection::class, $connection);

        $producer = $connection->createProducer($this->streamName);
        $producer->sendBatch(['first', 'second', 'third']);
        $producer->waitForConfirms(timeout: 5);
        $producer->close();

        $consumerName = 'resume-test-' . uniqid();
        $first = $connection->createConsumer(
            $this->streamName,
            OffsetSpec::first(),
            name: $consumerName,
            autoCommit: 3
        );

        $received = [];
        $deadline = microtime(true) + 5.0;
        while (count($received) < 3 && microtime(true) < $deadline) {
            foreach ($first->read(timeout: 0.5) as $message) {
                $received[] = $message;
            }
        }
        $first->close();
        $this->assertCount(3, $received);

        // The stored offset is the resume point, so it goes straight into
        // OffsetSpec::offset() — which is inclusive. Storing the last consumed
        // offset instead handed the third message out a second time (#396).
        $stored = $connection->queryOffset($consumerName, $this->streamName);
        $second = $connection->createConsumer(
            $this->streamName,
            OffsetSpec::offset($stored),
            name: $consumerName
        );
        $redelivered = $second->read(timeout: 1.0);
        $second->close();

        $this->assertSame([], $redelivered, 'Everything up to the stored offset was already processed');
    }

    public function testReadReturnsEmptyOnTimeout(): void
    {
        $this->assertNotNull($this->connection);
        $consumer = $this->connection->createConsumer($this->streamName, OffsetSpec::first());

        $messages = $consumer->read(timeout: 1);

        $this->assertSame([], $messages);

        $consumer->close();
    }

    public function testProduceAndConsumeWithRead(): void
    {
        $this->assertNotNull($this->connection);
        $producer = $this->connection->createProducer($this->streamName);
        $producer->sendBatch(['hello', 'world', 'foo']);
        $producer->waitForConfirms(timeout: 5);
        $producer->close();

        $consumer = $this->connection->createConsumer($this->streamName, OffsetSpec::first());

        $received = [];
        $deadline = time() + 5;
        while (count($received) < 3 && time() < $deadline) {
            $messages = $consumer->read(timeout: 0.5);
            foreach ($messages as $msg) {
                $received[] = $msg->getBody();
            }
        }

        $consumer->close();

        $this->assertCount(3, $received);
        $this->assertContains('hello', $received);
        $this->assertContains('world', $received);
        $this->assertContains('foo', $received);
    }

    public function testReadOneReturnsSingleMessage(): void
    {
        $this->assertNotNull($this->connection);
        $producer = $this->connection->createProducer($this->streamName);
        $producer->sendBatch(['msg1', 'msg2']);
        $producer->waitForConfirms(timeout: 5);
        $producer->close();

        $consumer = $this->connection->createConsumer($this->streamName, OffsetSpec::first());

        $msg = null;
        $deadline = time() + 5;
        while (!$msg instanceof Message && time() < $deadline) {
            $msg = $consumer->readOne(timeout: 0.5);
        }

        $consumer->close();

        $this->assertNotNull($msg);
        $this->assertSame('msg1', $msg->getBody());
    }

    public function testStoreAndQueryOffset(): void
    {
        $this->assertNotNull($this->connection);
        $producer = $this->connection->createProducer($this->streamName);
        $producer->send('offset-test');
        $producer->waitForConfirms(timeout: 5);
        $producer->close();

        $consumerName = 'test-consumer-ref-' . uniqid();
        $consumer = $this->connection->createConsumer(
            $this->streamName,
            OffsetSpec::first(),
            name: $consumerName,
        );

        $messages = [];
        $deadline = time() + 5;
        while ($messages === [] && time() < $deadline) {
            $messages = $consumer->read(timeout: 0.5);
        }

        $this->assertNotEmpty($messages);
        $offset = $messages[0]->getOffset();

        $consumer->storeOffset($offset);

        $storedOffset = $consumer->queryOffset();
        $this->assertSame($offset, $storedOffset);

        $consumer->close();
    }

    public function testAutoCommitOnCloseStoresLastOffset(): void
    {
        $this->assertNotNull($this->connection);

        // Publish 5 messages
        $producer = $this->connection->createProducer($this->streamName);
        $messages = [];
        for ($i = 0; $i < 5; $i++) {
            $messages[] = "message-{$i}";
        }
        $producer->sendBatch($messages);
        $producer->waitForConfirms(timeout: 5);
        $producer->close();

        $consumerName = 'auto-commit-test-' . uniqid();
        $consumer = $this->connection->createConsumer(
            $this->streamName,
            OffsetSpec::first(),
            name: $consumerName,
            autoCommit: 3
        );

        // Read all messages
        $received = [];
        $deadline = time() + 5;
        while (count($received) < 5 && time() < $deadline) {
            $msgs = $consumer->read(timeout: 0.5);
            foreach ($msgs as $msg) {
                $received[] = $msg;
            }
        }

        $this->assertCount(5, $received, 'Should receive all 5 messages');
        $lastOffset = $received[4]->getOffset();

        // Close should store the resume point
        $consumer->close();

        // Query offset — the stored value is the NEXT offset to consume, so a
        // consumer resuming with OffsetSpec::offset() (inclusive) does not get
        // the last processed message again (#396).
        $storedOffset = $this->connection->queryOffset($consumerName, $this->streamName);
        $this->assertSame($lastOffset + 1, $storedOffset, 'Stored offset must be lastConsumed + 1');
    }

    public function testNoAutoCommitOnCloseDoesNotStoreOffset(): void
    {
        $this->assertNotNull($this->connection);

        // Publish 3 messages
        $producer = $this->connection->createProducer($this->streamName);
        $producer->sendBatch([
            'msg1',
            'msg2',
            'msg3',
        ]);
        $producer->waitForConfirms(timeout: 5);
        $producer->close();

        $consumerName = 'no-auto-commit-test-' . uniqid();
        $consumer = $this->connection->createConsumer(
            $this->streamName,
            OffsetSpec::first(),
            name: $consumerName,
            autoCommit: 0
        );

        // Read all messages
        $received = [];
        $deadline = time() + 5;
        while (count($received) < 3 && time() < $deadline) {
            $msgs = $consumer->read(timeout: 0.5);
            foreach ($msgs as $msg) {
                $received[] = $msg;
            }
        }

        $this->assertCount(3, $received);

        // Close should NOT store offset (autoCommit is 0)
        $consumer->close();

        // Query offset should throw exception (no offset stored)
        $this->expectException(\CrazyGoat\RabbitStream\Exception\ProtocolException::class);
        $this->expectExceptionMessage('0x0013');
        $this->connection->queryOffset($consumerName, $this->streamName);
    }

    public function testAutoCommitOnCloseWithNoMessagesDoesNotStoreOffset(): void
    {
        $this->assertNotNull($this->connection);

        // Don't publish any messages

        $consumerName = 'no-messages-test-' . uniqid();
        $consumer = $this->connection->createConsumer(
            $this->streamName,
            OffsetSpec::first(),
            name: $consumerName,
            autoCommit: 3
        );

        // Don't read any messages - just close immediately
        $consumer->close();

        // Query offset should throw exception (no offset stored because no messages processed)
        $this->expectException(\CrazyGoat\RabbitStream\Exception\ProtocolException::class);
        $this->expectExceptionMessage('0x0013');
        $this->connection->queryOffset($consumerName, $this->streamName);
    }

    public function testSubscribeFromSpecificOffset(): void
    {
        $this->assertNotNull($this->connection);

        // Publish 10 messages
        $producer = $this->connection->createProducer($this->streamName);
        $messages = [];
        for ($i = 0; $i < 10; $i++) {
            $messages[] = "message-{$i}";
        }
        $producer->sendBatch($messages);
        $producer->waitForConfirms(timeout: 5);
        $producer->close();

        $consumerName = 'resume-consumer-' . uniqid();

        // First consumer: read 5 messages, store offset
        $firstConsumer = $this->connection->createConsumer(
            $this->streamName,
            OffsetSpec::first(),
            name: $consumerName,
        );

        $received = [];
        $deadline = time() + 5;
        while (count($received) < 5 && time() < $deadline) {
            $msg = $firstConsumer->readOne(timeout: 0.5);
            if ($msg instanceof Message) {
                $received[] = $msg;
            }
        }
        $this->assertCount(5, $received);
        $storedOffset = $received[4]->getOffset();

        $firstConsumer->storeOffset($storedOffset);
        $firstConsumer->close();

        // Second consumer: read with first() then filter in PHP
        // (server-side TYPE_OFFSET with non-zero values has a known limitation in RabbitMQ 4.3.0)
        $secondConsumer = $this->connection->createConsumer(
            $this->streamName,
            OffsetSpec::first(),
            name: $consumerName,
        );

        $all = [];
        $deadline = time() + 5;
        while (count($all) < 10 && time() < $deadline) {
            $msgs = $secondConsumer->read(timeout: 0.5);
            foreach ($msgs as $msg) {
                $all[] = $msg;
            }
        }
        $secondConsumer->close();

        $this->assertCount(10, $all, 'Should read all messages (server delivers from offset 0)');

        // Filter in PHP to keep only messages after stored offset (offsets 5-9)
        $resumed = array_values(array_filter($all, fn(Message $m): bool => $m->getOffset() > $storedOffset));
        $this->assertCount(5, $resumed, 'Should keep 5 messages after filtering by stored offset');
        $this->assertSame('message-5', $resumed[0]->getBody(), 'First resumed message should be message-5');
    }

    public function testSubscribeFromOffsetZero(): void
    {
        $this->assertNotNull($this->connection);

        // Publish 5 messages
        $producer = $this->connection->createProducer($this->streamName);
        $messages = [];
        for ($i = 0; $i < 5; $i++) {
            $messages[] = "offset-zero-{$i}";
        }
        $producer->sendBatch($messages);
        $producer->waitForConfirms(timeout: 5);
        $producer->close();

        // Subscribe from offset 0 — should behave like first()
        $consumer = $this->connection->createConsumer($this->streamName, OffsetSpec::offset(0));

        $received = [];
        $deadline = time() + 5;
        while (count($received) < 5 && time() < $deadline) {
            $msgs = $consumer->read(timeout: 0.5);
            $received = array_merge($received, $msgs);
        }

        $consumer->close();

        $this->assertCount(5, $received, 'Should receive all 5 messages');
        $this->assertSame('offset-zero-0', $received[0]->getBody(), 'First message should be the first published');
    }

    public function testSubscribeFromOffsetBeyondEnd(): void
    {
        $this->assertNotNull($this->connection);

        // Publish 5 messages
        $producer = $this->connection->createProducer($this->streamName);
        $messages = [];
        for ($i = 0; $i < 5; $i++) {
            $messages[] = "beyond-end-{$i}";
        }
        $producer->sendBatch($messages);
        $producer->waitForConfirms(timeout: 5);
        $producer->close();

        // Subscribe with next() — should start at the stream end (offset 5), no initial messages
        $consumer = $this->connection->createConsumer($this->streamName, OffsetSpec::next());

        $msgs = $consumer->read(timeout: 0.5);
        $this->assertCount(0, $msgs, 'Should receive no messages when subscribing at stream end');

        // Publish a new message while consumer is still subscribed
        $producer2 = $this->connection->createProducer($this->streamName);
        $producer2->send('new-message-after-subscribe');
        $producer2->waitForConfirms(timeout: 5);
        $producer2->close();

        // Read the new message from the still-open consumer
        $newMsg = $consumer->readOne(timeout: 5);
        $this->assertNotNull($newMsg, 'New messages should arrive on an existing subscription');
        $this->assertSame('new-message-after-subscribe', $newMsg->getBody());

        $consumer->close();
    }

    public function testSubscribeFromTimestamp(): void
    {
        $this->assertNotNull($this->connection);

        $producer = $this->connection->createProducer($this->streamName);
        for ($i = 0; $i < 5; $i++) {
            $producer->send("before-{$i}");
        }
        $producer->waitForConfirms(timeout: 5);
        $producer->close();

        // Only a millisecond-resolution gap is needed here, not a wall-clock
        // boundary: the boundary used below is derived entirely from what the
        // broker wrote (see the read-back below), never from the client clock.
        // This just makes sure the two batches don't land in the same
        // millisecond, which the assertion after the read-back verifies rather
        // than assumes.
        usleep(20_000);

        $producer2 = $this->connection->createProducer($this->streamName);
        for ($i = 0; $i < 5; $i++) {
            $producer2->send("after-{$i}");
        }
        $producer2->waitForConfirms(timeout: 5);
        $producer2->close();

        // Message::getTimestamp() is the CHUNK timestamp shared by every
        // message in that chunk (OsirisChunkParser.php:66, :84-85), and the
        // broker resolves OffsetSpec::timestamp($t) to the first chunk with
        // chunkTs >= $t, delivered in full. So we read the whole stream back
        // and derive the "after" boundary from the broker-written data itself.
        $probe = $this->connection->createConsumer($this->streamName, OffsetSpec::first());
        $all = [];
        $deadline = time() + 5;
        while (count($all) < 10 && time() < $deadline) {
            foreach ($probe->read(timeout: 0.5) as $msg) {
                $all[] = $msg;
            }
        }
        $probe->close();

        $this->assertCount(10, $all, 'Should have read back all 10 published messages');

        $beforeTs = 0;
        for ($i = 0; $i < 5; $i++) {
            $beforeTs = max($beforeTs, $all[$i]->getTimestamp());
        }
        $this->assertSame('after-0', $all[5]->getBody(), 'Offset 5 must be the first "after" message');
        $afterTs = $all[5]->getTimestamp();

        $this->assertGreaterThan(
            $beforeTs,
            $afterTs,
            'batches must land in chunks with distinct millisecond timestamps'
        );

        $consumer = $this->connection->createConsumer(
            $this->streamName,
            OffsetSpec::timestamp($afterTs)
        );

        $received = [];
        $readDeadline = time() + 5;
        while (count($received) < 5 && time() < $readDeadline) {
            $msgs = $consumer->read(timeout: 0.5);
            foreach ($msgs as $msg) {
                $received[] = $msg->getBody();
            }
        }

        $consumer->close();

        $this->assertCount(5, $received, 'Should receive only messages published after the timestamp');
        for ($i = 0; $i < 5; $i++) {
            $this->assertSame("after-{$i}", $received[$i], "Message at index {$i} should be after-{$i}");
        }
    }

    public function testSubscribeFromFutureTimestampReturnsNoMessages(): void
    {
        $this->assertNotNull($this->connection);

        $producer = $this->connection->createProducer($this->streamName);
        for ($i = 0; $i < 3; $i++) {
            $producer->send("msg-{$i}");
        }
        $producer->waitForConfirms(timeout: 5);
        $producer->close();

        $futureTimestamp = (time() + 86400) * 1000;
        $consumer = $this->connection->createConsumer(
            $this->streamName,
            OffsetSpec::timestamp($futureTimestamp)
        );

        $msgs = $consumer->read(timeout: 1);
        $this->assertCount(0, $msgs, 'Should receive no messages when subscribing with a future timestamp');

        $consumer->close();
    }
}
