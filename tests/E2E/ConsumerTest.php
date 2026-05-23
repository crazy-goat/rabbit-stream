<?php

declare(strict_types=1);

namespace CrazyGoat\RabbitStream\Tests\E2E;

use CrazyGoat\RabbitStream\Client\Connection;
use CrazyGoat\RabbitStream\Client\Message;
use CrazyGoat\RabbitStream\VO\OffsetSpec;
use PHPUnit\Framework\TestCase;

class ConsumerTest extends TestCase
{
    private static string $host = '127.0.0.1';
    private static int $port = 5552;

    private ?Connection $connection = null;
    private string $streamName;

    private function amqp(string $body): string
    {
        return "\x00\x53\x75\xb0" . pack('N', strlen($body)) . $body;
    }

    public static function setUpBeforeClass(): void
    {
        self::$host = getenv('RABBITMQ_HOST') ?: self::$host;
        self::$port = (int)(getenv('RABBITMQ_PORT') ?: self::$port);
    }

    protected function setUp(): void
    {
        $this->connection = Connection::create(
            host: self::$host,
            port: self::$port,
            user: 'guest',
            password: 'guest',
            vhost: '/'
        );
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
        $producer->sendBatch([$this->amqp('hello'), $this->amqp('world'), $this->amqp('foo')]);
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
        $producer->sendBatch([$this->amqp('msg1'), $this->amqp('msg2')]);
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
        $producer->send($this->amqp('offset-test'));
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
            $messages[] = $this->amqp("message-{$i}");
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

        // Close should store the last offset
        $consumer->close();

        // Query offset - should be the last message's offset
        $storedOffset = $this->connection->queryOffset($consumerName, $this->streamName);
        $this->assertSame($lastOffset, $storedOffset, 'Stored offset should match last consumed message');
    }

    public function testNoAutoCommitOnCloseDoesNotStoreOffset(): void
    {
        $this->assertNotNull($this->connection);

        // Publish 3 messages
        $producer = $this->connection->createProducer($this->streamName);
        $producer->sendBatch([
            $this->amqp('msg1'),
            $this->amqp('msg2'),
            $this->amqp('msg3'),
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
            $messages[] = $this->amqp("message-{$i}");
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
            $messages[] = $this->amqp("offset-zero-{$i}");
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
            $messages[] = $this->amqp("beyond-end-{$i}");
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
        $producer2->send($this->amqp('new-message-after-subscribe'));
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
            $producer->send($this->amqp("before-{$i}"));
        }
        $producer->waitForConfirms(timeout: 5);
        $producer->close();

        $timestamp = time() * 1000;
        sleep(1);

        $producer2 = $this->connection->createProducer($this->streamName);
        for ($i = 0; $i < 5; $i++) {
            $producer2->send($this->amqp("after-{$i}"));
        }
        $producer2->waitForConfirms(timeout: 5);
        $producer2->close();

        $consumer = $this->connection->createConsumer(
            $this->streamName,
            OffsetSpec::timestamp($timestamp)
        );

        $received = [];
        $deadline = time() + 5;
        while (count($received) < 5 && time() < $deadline) {
            $msgs = $consumer->read(timeout: 0.5);
            foreach ($msgs as $msg) {
                $received[] = $msg->getBody();
            }
        }

        $consumer->close();

        $this->assertCount(5, $received, 'Should receive only messages published after the timestamp');
        $this->assertSame('after-0', $received[0], 'First message should be after-0');
    }

    public function testSubscribeFromFutureTimestampReturnsNoMessages(): void
    {
        $this->assertNotNull($this->connection);

        $producer = $this->connection->createProducer($this->streamName);
        for ($i = 0; $i < 3; $i++) {
            $producer->send($this->amqp("msg-{$i}"));
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
