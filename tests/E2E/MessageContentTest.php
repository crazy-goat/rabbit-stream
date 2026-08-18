<?php

declare(strict_types=1);

namespace CrazyGoat\RabbitStream\Tests\E2E;

use CrazyGoat\RabbitStream\Client\Connection;
use CrazyGoat\RabbitStream\Client\Message;
use CrazyGoat\RabbitStream\VO\OffsetSpec;

class MessageContentTest extends E2ETestCase
{
    private ?Connection $connection = null;
    private string $streamName;

    protected function setUp(): void
    {
        $this->connection = $this->createConnection();
        $this->streamName = 'test-msg-content-' . uniqid();
        $this->connection->createStream($this->streamName);
    }

    protected function tearDown(): void
    {
        if ($this->connection instanceof Connection) {
            try {
                $this->connection->deleteStream($this->streamName);
            } catch (\Exception) {
                // Ignore cleanup errors
            }
            $this->connection->close();
        }
    }

    /**
     * Publish a single message and consume it, returning the messages.
     *
     * @return Message[]
     */
    private function publishAndConsume(string $amqpBody): array
    {
        $this->assertNotNull($this->connection);

        $producer = $this->connection->createProducer($this->streamName);
        $producer->send($amqpBody);
        $producer->waitForConfirms(timeout: 5);
        $producer->close();

        $consumer = $this->connection->createConsumer($this->streamName, OffsetSpec::first());

        $received = [];
        $deadline = time() + 5;
        while ($received === [] && time() < $deadline) {
            $received = $consumer->read(timeout: 0.5);
        }

        $consumer->close();

        return $received;
    }

    public function testEmptyMessageBody(): void
    {
        $messages = $this->publishAndConsume('');

        $this->assertNotEmpty($messages, 'Should receive at least one message');
        $body = $messages[0]->getBody();
        $this->assertSame('', $body, 'Empty body should round-trip as empty string');
    }

    public function testMessageWithNullBytes(): void
    {
        $originalBody = "hello\x00world\x00";
        $messages = $this->publishAndConsume($originalBody);

        $this->assertNotEmpty($messages);
        $body = $messages[0]->getBody();
        $this->assertSame($originalBody, $body, 'Null bytes should be preserved in round-trip');
    }

    public function testMessageWithUtf8Multibyte(): void
    {
        $originalBody = "Héllo Wörld 🐰 日本語";
        $messages = $this->publishAndConsume($originalBody);

        $this->assertNotEmpty($messages);
        $body = $messages[0]->getBody();
        $this->assertSame($originalBody, $body, 'UTF-8 multibyte characters should be preserved');
    }

    public function testMessageWithBinaryData(): void
    {
        $originalBody = random_bytes(256);
        $messages = $this->publishAndConsume($originalBody);

        $this->assertNotEmpty($messages);
        $body = $messages[0]->getBody();
        $this->assertSame($originalBody, $body, 'Binary data should round-trip byte-for-byte');
    }

    public function testMultipleSpecialMessagesInBatch(): void
    {
        $this->assertNotNull($this->connection);

        $producer = $this->connection->createProducer($this->streamName);

        $payloads = [
            'empty' => '',
            'nulls' => "a\x00b\x00c",
            'utf8'  => '🐰 Rabbit 🥕 Stream',
            'binary' => random_bytes(64),
        ];

        $producer->sendBatch([
            $payloads['empty'],
            $payloads['nulls'],
            $payloads['utf8'],
            $payloads['binary'],
        ]);
        $producer->waitForConfirms(timeout: 5);
        $producer->close();

        $consumer = $this->connection->createConsumer($this->streamName, OffsetSpec::first());

        $received = [];
        $deadline = time() + 5;
        while (count($received) < 4 && time() < $deadline) {
            $msgs = $consumer->read(timeout: 0.5);
            foreach ($msgs as $msg) {
                $received[] = $msg;
            }
        }

        $consumer->close();

        $this->assertCount(4, $received, 'Should receive all 4 messages');

        $this->assertSame('', $received[0]->getBody(), 'Empty message body');
        $this->assertSame($payloads['nulls'], $received[1]->getBody(), 'Null bytes message');
        $this->assertSame($payloads['utf8'], $received[2]->getBody(), 'UTF-8 message');
        $this->assertSame($payloads['binary'], $received[3]->getBody(), 'Binary message');
    }
}
