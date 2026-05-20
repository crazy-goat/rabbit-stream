<?php

declare(strict_types=1);

namespace CrazyGoat\RabbitStream\Tests\E2E;

use CrazyGoat\RabbitStream\Client\Connection;
use CrazyGoat\RabbitStream\Client\Message;
use CrazyGoat\RabbitStream\VO\OffsetSpec;
use PHPUnit\Framework\TestCase;

class MessageContentTest extends TestCase
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
        $messages = $this->publishAndConsume($this->amqp(''));

        $this->assertNotEmpty($messages, 'Should receive at least one message');
        $body = $messages[0]->getBody();
        $this->assertSame('', $body, 'Empty body should round-trip as empty string');
    }

    public function testMessageWithNullBytes(): void
    {
        $originalBody = "hello\x00world\x00";
        $messages = $this->publishAndConsume($this->amqp($originalBody));

        $this->assertNotEmpty($messages);
        $body = $messages[0]->getBody();
        $this->assertSame($originalBody, $body, 'Null bytes should be preserved in round-trip');
    }

    public function testMessageWithUtf8Multibyte(): void
    {
        $originalBody = "Héllo Wörld 🐰 日本語";
        $messages = $this->publishAndConsume($this->amqp($originalBody));

        $this->assertNotEmpty($messages);
        $body = $messages[0]->getBody();
        $this->assertSame($originalBody, $body, 'UTF-8 multibyte characters should be preserved');
    }

    public function testMessageWithBinaryData(): void
    {
        $originalBody = random_bytes(256);
        $messages = $this->publishAndConsume($this->amqp($originalBody));

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
            $this->amqp($payloads['empty']),
            $this->amqp($payloads['nulls']),
            $this->amqp($payloads['utf8']),
            $this->amqp($payloads['binary']),
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
