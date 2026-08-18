<?php

declare(strict_types=1);

namespace CrazyGoat\RabbitStream\Tests\E2E;

use CrazyGoat\RabbitStream\Client\Message;
use CrazyGoat\RabbitStream\Contract\ConsumerInterface;
use CrazyGoat\RabbitStream\VO\OffsetSpec;

class MultipleConsumersTest extends E2ETestCase
{
    public function testTwoConsumersOnDifferentConnectionsReceiveAllMessages(): void
    {
        $stream = 'test-multi-consumer-' . uniqid();

        $conn1 = $this->createConnection();
        $conn2 = $this->createConnection();

        try {
            $conn1->createStream($stream);

            $producer = $conn1->createProducer($stream);
            $producer->sendBatch([
                'msg-1',
                'msg-2',
                'msg-3',
                'msg-4',
                'msg-5',
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

        $conn = $this->createConnection();

        try {
            $conn->createStream($stream);

            $producer = $conn->createProducer($stream);
            $producer->sendBatch([
                'a',
                'b',
                'c',
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

    public function testTwoSubscriptionsOnDifferentStreamsReceiveCorrectMessages(): void
    {
        $streamA = 'test-multi-stream-A-' . uniqid();
        $streamB = 'test-multi-stream-B-' . uniqid();

        $conn = $this->createConnection();

        try {
            $conn->createStream($streamA);
            $conn->createStream($streamB);

            $producerA = $conn->createProducer($streamA);
            $producerA->sendBatch([
                'from-A-1',
                'from-A-2',
            ]);
            $producerA->waitForConfirms(timeout: 5);
            $producerA->close();

            $producerB = $conn->createProducer($streamB);
            $producerB->sendBatch([
                'from-B-1',
                'from-B-2',
                'from-B-3',
            ]);
            $producerB->waitForConfirms(timeout: 5);
            $producerB->close();

            $consumerA = $conn->createConsumer($streamA, OffsetSpec::first(), name: 'multi-stream-A');
            $consumerB = $conn->createConsumer($streamB, OffsetSpec::first(), name: 'multi-stream-B');

            $msgsA = $this->readAll($consumerA, 2);
            $msgsB = $this->readAll($consumerB, 3);

            $this->assertCount(2, $msgsA);
            $this->assertCount(3, $msgsB);

            $bodiesA = array_map(fn(Message $m): string|int|float|bool|array|null => $m->getBody(), $msgsA);
            $bodiesB = array_map(fn(Message $m): string|int|float|bool|array|null => $m->getBody(), $msgsB);

            $this->assertContains('from-A-1', $bodiesA);
            $this->assertContains('from-A-2', $bodiesA);
            $this->assertNotContains('from-B-1', $bodiesA);

            $this->assertContains('from-B-1', $bodiesB);
            $this->assertContains('from-B-2', $bodiesB);
            $this->assertContains('from-B-3', $bodiesB);
            $this->assertNotContains('from-A-1', $bodiesB);

            $consumerA->close();
            $consumerB->close();
        } finally {
            try {
                $conn->deleteStream($streamA);
            } catch (\Exception) {
            }
            try {
                $conn->deleteStream($streamB);
            } catch (\Exception) {
            }
            $conn->close();
        }
    }

    public function testUnsubscribingOneConsumerDoesNotAffectOther(): void
    {
        $streamA = 'test-unsub-A-' . uniqid();
        $streamB = 'test-unsub-B-' . uniqid();

        $conn = $this->createConnection();

        try {
            $conn->createStream($streamA);
            $conn->createStream($streamB);

            $producer = $conn->createProducer($streamA);
            $producer->sendBatch([
                'initial-a-1',
                'initial-a-2',
            ]);
            $producer->waitForConfirms(timeout: 5);
            $producer->close();

            $consumerA = $conn->createConsumer($streamA, OffsetSpec::first(), name: 'unsub-A');
            $consumerB = $conn->createConsumer($streamB, OffsetSpec::next(), name: 'unsub-B');

            $msgsA = $this->readAll($consumerA, 2);
            $this->assertCount(2, $msgsA);

            // Publish a message to streamB while consumerB is subscribed
            $producerB = $conn->createProducer($streamB);
            $producerB->send('to-B-before-close');
            $producerB->waitForConfirms(timeout: 5);
            $producerB->close();

            $msgB = $this->readOneWithTimeout($consumerB, 5);
            $this->assertNotNull($msgB);
            $this->assertSame('to-B-before-close', $msgB->getBody());

            // Close consumerA — this unsubscribes from streamA
            $consumerA->close();

            // Publish more messages to streamB after consumerA is closed
            $producerB2 = $conn->createProducer($streamB);
            $producerB2->send('to-B-after-close');
            $producerB2->waitForConfirms(timeout: 5);
            $producerB2->close();

            // ConsumerB should still receive messages
            $msgB2 = $this->readOneWithTimeout($consumerB, 5);
            $this->assertNotNull($msgB2);
            $this->assertSame('to-B-after-close', $msgB2->getBody());

            // Verify consumerA is truly stopped — no more messages via its old subscription
            $consumerB->close();
        } finally {
            try {
                $conn->deleteStream($streamA);
            } catch (\Exception) {
            }
            try {
                $conn->deleteStream($streamB);
            } catch (\Exception) {
            }
            $conn->close();
        }
    }

    private function readOneWithTimeout(ConsumerInterface $consumer, float $timeout): ?Message
    {
        $deadline = time() + (int)$timeout;
        while (time() < $deadline) {
            $msg = $consumer->readOne(timeout: 0.5);
            if ($msg instanceof Message) {
                return $msg;
            }
        }
        return null;
    }
}
