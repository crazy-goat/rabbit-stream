<?php

declare(strict_types=1);

namespace CrazyGoat\RabbitStream\Tests\E2E;

use CrazyGoat\RabbitStream\Client\Connection;
use CrazyGoat\RabbitStream\Client\Message;
use CrazyGoat\RabbitStream\Contract\ConsumerInterface;
use CrazyGoat\RabbitStream\VO\OffsetSpec;
use PHPUnit\Framework\TestCase;

class MultipleConsumersTest extends TestCase
{
    private static string $host = '127.0.0.1';
    private static int $port = 5552;

    public static function setUpBeforeClass(): void
    {
        self::$host = getenv('RABBITMQ_HOST') ?: self::$host;
        self::$port = (int)(getenv('RABBITMQ_PORT') ?: self::$port);
    }

    private function amqp(string $body): string
    {
        return "\x00\x53\x75\xb0" . pack('N', strlen($body)) . $body;
    }

    public function testTwoConsumersOnDifferentConnectionsReceiveAllMessages(): void
    {
        $stream = 'test-multi-consumer-' . uniqid();

        $conn1 = Connection::create(self::$host, self::$port);
        $conn2 = Connection::create(self::$host, self::$port);

        try {
            $conn1->createStream($stream);

            $producer = $conn1->createProducer($stream);
            $producer->sendBatch([
                $this->amqp('msg-1'),
                $this->amqp('msg-2'),
                $this->amqp('msg-3'),
                $this->amqp('msg-4'),
                $this->amqp('msg-5'),
            ]);
            $producer->waitForConfirms(timeout: 5);
            $producer->close();

            $consumer1 = $conn1->createConsumer($stream, OffsetSpec::first(), name: 'consumer-1');
            $consumer2 = $conn2->createConsumer($stream, OffsetSpec::first(), name: 'consumer-2');

            $msgs1 = $this->readAll($consumer1, 5);
            $msgs2 = $this->readAll($consumer2, 5);

            $this->assertCount(5, $msgs1);
            $this->assertCount(5, $msgs2);

            $consumer1->storeOffset($msgs1[2]->getOffset());
            $consumer2->storeOffset($msgs2[4]->getOffset());

            $offset1 = $conn1->queryOffset('consumer-1', $stream);
            $offset2 = $conn2->queryOffset('consumer-2', $stream);
            $this->assertNotSame($offset1, $offset2, 'Each consumer should have an independent offset');

            $consumer1->close();
            $consumer2->close();
        } finally {
            try {
                $conn1->deleteStream($stream);
            } catch (\Exception) {
            }
            $conn1->close();
            $conn2->close();
        }
    }

    public function testTwoConsumersOnSameConnectionReceiveAllMessages(): void
    {
        $stream = 'test-multi-consumer-same-conn-' . uniqid();

        $conn = Connection::create(self::$host, self::$port);

        try {
            $conn->createStream($stream);

            $producer = $conn->createProducer($stream);
            $producer->sendBatch([
                $this->amqp('a'),
                $this->amqp('b'),
                $this->amqp('c'),
            ]);
            $producer->waitForConfirms(timeout: 5);
            $producer->close();

            $consumer1 = $conn->createConsumer($stream, OffsetSpec::first(), name: 'shared-conn-c1');
            $consumer2 = $conn->createConsumer($stream, OffsetSpec::first(), name: 'shared-conn-c2');

            $msgs1 = $this->readAll($consumer1, 3);
            $msgs2 = $this->readAll($consumer2, 3);

            $this->assertCount(3, $msgs1);
            $this->assertCount(3, $msgs2);

            $consumer1->close();
            $consumer2->close();
        } finally {
            try {
                $conn->deleteStream($stream);
            } catch (\Exception) {
            }
            $conn->close();
        }
    }

    /** @return Message[] */
    private function readAll(ConsumerInterface $consumer, int $expectedCount): array
    {
        $received = [];
        $deadline = time() + 10;
        while (count($received) < $expectedCount && time() < $deadline) {
            $messages = $consumer->read(timeout: 0.5);
            foreach ($messages as $msg) {
                $received[] = $msg;
            }
        }
        return $received;
    }
}
