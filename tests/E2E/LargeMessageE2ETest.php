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

    private function amqp(string $body): string
    {
        return "\x00\x53\x75\xb0" . pack('N', strlen($body)) . $body;
    }

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
        $messages = $this->publishAndConsume($this->amqp($body));

        $this->assertNotEmpty($messages, 'Should receive at least one message');
        $this->assertSame($body, $messages[0]->getBody(), '100KB body should round-trip correctly');
    }

    public function testPublishAndConsume1mbMessage(): void
    {
        $body = str_repeat('B', 1_000_000);
        $messages = $this->publishAndConsume($this->amqp($body));

        $this->assertNotEmpty($messages, 'Should receive at least one message');
        $this->assertSame($body, $messages[0]->getBody(), '1MB body should round-trip correctly');
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

            $producer->send($this->amqp($body));

            $threw = true;
            try {
                $producer->waitForConfirms(timeout: 5.0);
                $threw = false;
            } catch (\Exception $e) {
                $this->assertNotEmpty($e->getMessage());
            }
            $this->assertTrue($threw, 'Publishing a message exceeding frameMax should throw');
            $this->assertFalse($conn->isConnected(), 'Connection should be closed after frameMax violation');
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
