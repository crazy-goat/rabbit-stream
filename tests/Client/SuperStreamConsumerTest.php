<?php

declare(strict_types=1);

namespace CrazyGoat\RabbitStream\Tests\Client;

use CrazyGoat\RabbitStream\Client\Message;
use CrazyGoat\RabbitStream\Client\SuperStreamConsumer;
use CrazyGoat\RabbitStream\Contract\ConsumerInterface;
use PHPUnit\Framework\TestCase;

class SuperStreamConsumerTest extends TestCase
{
    private function message(int $offset, string $stream): Message
    {
        return Message::fromRawEntry($offset, 1000, "\x00\x53\x75\xa0\x00", $stream);
    }

    public function testReadDrainsAlreadyBufferedMessagesWithoutReadLoop(): void
    {
        $p0 = $this->createMock(ConsumerInterface::class);
        $p0->method('hasUnread')->willReturn(true);
        $p0->expects($this->once())->method('drain')->willReturn([$this->message(1, 'p0')]);

        $p1 = $this->createMock(ConsumerInterface::class);
        $p1->method('hasUnread')->willReturn(false);
        $p1->expects($this->once())->method('drain')->willReturn([]);

        $readLoopCalled = false;
        $readLoop = function () use (&$readLoopCalled): void {
            $readLoopCalled = true;
        };

        $consumer = new SuperStreamConsumer(
            ['p0', 'p1'],
            ['p0' => $p0, 'p1' => $p1],
            \Closure::fromCallable($readLoop)
        );

        $messages = $consumer->read(5.0);

        $this->assertFalse($readLoopCalled, 'read() must not run readLoop when something is already buffered');
        $this->assertCount(1, $messages);
    }

    public function testReadRunsExactlyOneReadLoopWhenNothingBuffered(): void
    {
        $p0 = $this->createMock(ConsumerInterface::class);
        $p0->method('hasUnread')->willReturn(false);
        $p0->method('drain')->willReturn([$this->message(1, 'p0')]);

        $readLoopCallCount = 0;
        $capturedTimeout = null;
        $readLoop = function (float $timeout) use (&$readLoopCallCount, &$capturedTimeout): void {
            $readLoopCallCount++;
            $capturedTimeout = $timeout;
        };

        $consumer = new SuperStreamConsumer(['p0'], ['p0' => $p0], \Closure::fromCallable($readLoop));

        $messages = $consumer->read(3.5);

        $this->assertSame(1, $readLoopCallCount);
        $this->assertSame(3.5, $capturedTimeout);
        $this->assertCount(1, $messages);
    }

    public function testReadOneRoundRobinsFairlyAcrossPartitions(): void
    {
        $p0 = $this->createMock(ConsumerInterface::class);
        $p0->method('hasUnread')->willReturn(true);
        $p0->method('readOne')->willReturn($this->message(1, 'p0'));

        $p1 = $this->createMock(ConsumerInterface::class);
        $p1->method('hasUnread')->willReturn(true);
        $p1->method('readOne')->willReturn($this->message(2, 'p1'));

        $readLoop = function (): void {
        };

        $consumer = new SuperStreamConsumer(
            ['p0', 'p1'],
            ['p0' => $p0, 'p1' => $p1],
            \Closure::fromCallable($readLoop)
        );

        $first = $consumer->readOne();
        $second = $consumer->readOne();

        $this->assertSame('p0', $first?->getStream());
        $this->assertSame('p1', $second?->getStream());
    }

    public function testReadOneReturnsNullWhenNothingBufferedAfterReadLoop(): void
    {
        $p0 = $this->createMock(ConsumerInterface::class);
        $p0->method('hasUnread')->willReturn(false);

        $readLoop = function (): void {
        };

        $consumer = new SuperStreamConsumer(['p0'], ['p0' => $p0], \Closure::fromCallable($readLoop));

        $this->assertNull($consumer->readOne());
    }

    public function testStoreOffsetDelegatesToCorrectPartition(): void
    {
        $p0 = $this->createMock(ConsumerInterface::class);
        $p0->expects($this->never())->method('storeOffset');

        $p1 = $this->createMock(ConsumerInterface::class);
        $p1->expects($this->once())->method('storeOffset')->with(42);

        $readLoop = function (): void {
        };
        $consumer = new SuperStreamConsumer(
            ['p0', 'p1'],
            ['p0' => $p0, 'p1' => $p1],
            \Closure::fromCallable($readLoop)
        );

        $consumer->storeOffset('p1', 42);
    }

    public function testQueryOffsetDelegatesToCorrectPartition(): void
    {
        $p0 = $this->createMock(ConsumerInterface::class);
        $p0->expects($this->never())->method('queryOffset');

        $p1 = $this->createMock(ConsumerInterface::class);
        $p1->expects($this->once())->method('queryOffset')->willReturn(7);

        $readLoop = function (): void {
        };
        $consumer = new SuperStreamConsumer(
            ['p0', 'p1'],
            ['p0' => $p0, 'p1' => $p1],
            \Closure::fromCallable($readLoop)
        );

        $this->assertSame(7, $consumer->queryOffset('p1'));
    }

    public function testIsActiveDelegatesToCorrectPartition(): void
    {
        $p0 = $this->createMock(ConsumerInterface::class);
        $p0->method('isActive')->willReturn(true);

        $p1 = $this->createMock(ConsumerInterface::class);
        $p1->method('isActive')->willReturn(false);

        $readLoop = function (): void {
        };
        $consumer = new SuperStreamConsumer(
            ['p0', 'p1'],
            ['p0' => $p0, 'p1' => $p1],
            \Closure::fromCallable($readLoop)
        );

        $this->assertTrue($consumer->isActive('p0'));
        $this->assertFalse($consumer->isActive('p1'));
    }

    public function testCloseClosesAllPartitionConsumers(): void
    {
        $p0 = $this->createMock(ConsumerInterface::class);
        $p0->expects($this->once())->method('close');

        $p1 = $this->createMock(ConsumerInterface::class);
        $p1->expects($this->once())->method('close');

        $readLoop = function (): void {
        };
        $consumer = new SuperStreamConsumer(
            ['p0', 'p1'],
            ['p0' => $p0, 'p1' => $p1],
            \Closure::fromCallable($readLoop)
        );

        $consumer->close();
    }

    public function testGetPartitionsAndGetConsumers(): void
    {
        $p0 = $this->createMock(ConsumerInterface::class);
        $p1 = $this->createMock(ConsumerInterface::class);

        $readLoop = function (): void {
        };
        $consumer = new SuperStreamConsumer(
            ['p0', 'p1'],
            ['p0' => $p0, 'p1' => $p1],
            \Closure::fromCallable($readLoop)
        );

        $this->assertSame(['p0', 'p1'], $consumer->getPartitions());
        $this->assertSame(['p0' => $p0, 'p1' => $p1], $consumer->getConsumers());
    }
}
