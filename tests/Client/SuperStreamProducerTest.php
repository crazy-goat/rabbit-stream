<?php

declare(strict_types=1);

namespace CrazyGoat\RabbitStream\Tests\Client;

use CrazyGoat\RabbitStream\Client\Routing\RoutingStrategy;
use CrazyGoat\RabbitStream\Client\SuperStreamProducer;
use CrazyGoat\RabbitStream\Contract\ProducerInterface;
use PHPUnit\Framework\TestCase;

class SuperStreamProducerTest extends TestCase
{
    /**
     * A stub routing strategy that routes by exact match against a fixed map,
     * so tests can control which partition(s) a key lands on without going
     * through murmur3/broker routing.
     *
     * @param array<string, list<string>> $map
     */
    private function fixedStrategy(array $map): RoutingStrategy
    {
        return new class ($map) implements RoutingStrategy {
            /** @param array<string, list<string>> $map */
            public function __construct(private readonly array $map)
            {
            }

            public function route(string $routingKey, array $partitions): array
            {
                return $this->map[$routingKey] ?? [];
            }
        };
    }

    public function testSendOpensOnlyTheRoutedPartitionProducer(): void
    {
        $created = [];
        $factory = function (string $partition) use (&$created): ProducerInterface {
            $producer = $this->createMock(ProducerInterface::class);
            $created[$partition] = $producer;
            return $producer;
        };
        $strategy = $this->fixedStrategy(['key-a' => ['p1']]);

        $producer = new SuperStreamProducer(['p0', 'p1', 'p2'], $strategy, \Closure::fromCallable($factory));

        $producer->send('hello', 'key-a');

        $this->assertArrayHasKey('p1', $created);
        $this->assertArrayNotHasKey('p0', $created);
        $this->assertArrayNotHasKey('p2', $created);
    }

    public function testSendCallsSendOnRoutedPartitionProducer(): void
    {
        $captured = [];
        $factory = function (string $partition) use (&$captured): ProducerInterface {
            $producer = $this->createMock(ProducerInterface::class);
            $producer->method('send')->willReturnCallback(function (string $msg) use (&$captured, $partition): void {
                $captured[] = [$partition, $msg];
            });
            return $producer;
        };

        $strategy = $this->fixedStrategy(['key-a' => ['p1']]);
        $producer = new SuperStreamProducer(['p0', 'p1', 'p2'], $strategy, \Closure::fromCallable($factory));

        $producer->send('hello', 'key-a');

        $this->assertSame([['p1', 'hello']], $captured);
    }

    public function testSendRoutesToMultiplePartitionsForOneKey(): void
    {
        $captured = [];
        $factory = function (string $partition) use (&$captured): ProducerInterface {
            $producer = $this->createMock(ProducerInterface::class);
            $producer->method('send')->willReturnCallback(function (string $msg) use (&$captured, $partition): void {
                $captured[] = [$partition, $msg];
            });
            return $producer;
        };

        $strategy = $this->fixedStrategy(['fanout-key' => ['p0', 'p2']]);
        $producer = new SuperStreamProducer(['p0', 'p1', 'p2'], $strategy, \Closure::fromCallable($factory));

        $producer->send('msg', 'fanout-key');

        $this->assertSame([['p0', 'msg'], ['p2', 'msg']], $captured);
    }

    public function testSendBatchGroupsMessagesPerPartition(): void
    {
        $captured = [];
        $factory = function (string $partition) use (&$captured): ProducerInterface {
            $producer = $this->createMock(ProducerInterface::class);
            $producer->method('sendBatch')->willReturnCallback(
                function (array $messages) use (&$captured, $partition): void {
                    $captured[$partition] = $messages;
                }
            );
            return $producer;
        };

        $strategy = $this->fixedStrategy([
            'key-a' => ['p0'],
            'key-b' => ['p1'],
        ]);
        $producer = new SuperStreamProducer(['p0', 'p1'], $strategy, \Closure::fromCallable($factory));

        $producer->sendBatch([
            ['msg1', 'key-a'],
            ['msg2', 'key-b'],
            ['msg3', 'key-a'],
        ]);

        $this->assertSame(['msg1', 'msg3'], $captured['p0']);
        $this->assertSame(['msg2'], $captured['p1']);
    }

    public function testSendBatchWithEmptyArrayCreatesNoPartitionProducers(): void
    {
        $factoryCalled = false;
        $factory = function (string $partition) use (&$factoryCalled): ProducerInterface {
            $factoryCalled = true;
            return $this->createMock(ProducerInterface::class);
        };

        $strategy = $this->fixedStrategy([]);
        $producer = new SuperStreamProducer(['p0'], $strategy, \Closure::fromCallable($factory));

        $producer->sendBatch([]);

        $this->assertFalse($factoryCalled);
    }

    public function testWaitForConfirmsCallsAllOpenedPartitionProducers(): void
    {
        $waited = [];
        $factory = function (string $partition) use (&$waited): ProducerInterface {
            $producer = $this->createMock(ProducerInterface::class);
            $producer->expects($this->once())->method('waitForConfirms')->with(2.5)
                ->willReturnCallback(function () use (&$waited, $partition): void {
                    $waited[] = $partition;
                });
            return $producer;
        };

        $strategy = $this->fixedStrategy(['key-a' => ['p0'], 'key-b' => ['p1']]);
        $producer = new SuperStreamProducer(['p0', 'p1'], $strategy, \Closure::fromCallable($factory));

        $producer->send('m1', 'key-a');
        $producer->send('m2', 'key-b');
        $producer->waitForConfirms(2.5);

        $this->assertSame(['p0', 'p1'], $waited);
    }

    public function testGetPendingConfirmsAggregatesAcrossPartitions(): void
    {
        $factory = function (string $partition): ProducerInterface {
            $producer = $this->createMock(ProducerInterface::class);
            $producer->method('getPendingConfirms')->willReturn($partition === 'p0' ? 3 : 7);
            return $producer;
        };

        $strategy = $this->fixedStrategy(['key-a' => ['p0'], 'key-b' => ['p1']]);
        $producer = new SuperStreamProducer(['p0', 'p1'], $strategy, \Closure::fromCallable($factory));

        $producer->send('m1', 'key-a');
        $producer->send('m2', 'key-b');

        $this->assertSame(10, $producer->getPendingConfirms());
    }

    public function testGetPendingConfirmsZeroWhenNoPartitionOpened(): void
    {
        $factory = fn(string $partition): ProducerInterface => $this->createMock(ProducerInterface::class);
        $strategy = $this->fixedStrategy([]);
        $producer = new SuperStreamProducer(['p0', 'p1'], $strategy, \Closure::fromCallable($factory));

        $this->assertSame(0, $producer->getPendingConfirms());
    }

    public function testCloseClosesAllOpenedPartitionProducers(): void
    {
        $closed = [];
        $factory = function (string $partition) use (&$closed): ProducerInterface {
            $producer = $this->createMock(ProducerInterface::class);
            $producer->expects($this->once())->method('close')
                ->willReturnCallback(function () use (&$closed, $partition): void {
                    $closed[] = $partition;
                });
            return $producer;
        };

        $strategy = $this->fixedStrategy(['key-a' => ['p0'], 'key-b' => ['p1']]);
        $producer = new SuperStreamProducer(['p0', 'p1', 'p2'], $strategy, \Closure::fromCallable($factory));

        // p2 is never routed to, so its producer is never opened/created.
        $producer->send('m1', 'key-a');
        $producer->send('m2', 'key-b');
        $producer->close();

        $this->assertSame(['p0', 'p1'], $closed);
    }

    public function testGetPartitionsReturnsFullPartitionList(): void
    {
        $factory = fn(string $partition): ProducerInterface => $this->createMock(ProducerInterface::class);
        $strategy = $this->fixedStrategy([]);
        $producer = new SuperStreamProducer(['p0', 'p1', 'p2'], $strategy, \Closure::fromCallable($factory));

        $this->assertSame(['p0', 'p1', 'p2'], $producer->getPartitions());
    }

    public function testPartitionProducerIsOpenedOnlyOnce(): void
    {
        $callCount = 0;
        $factory = function (string $partition) use (&$callCount): ProducerInterface {
            $callCount++;
            return $this->createMock(ProducerInterface::class);
        };

        $strategy = $this->fixedStrategy(['key-a' => ['p0']]);
        $producer = new SuperStreamProducer(['p0', 'p1'], $strategy, \Closure::fromCallable($factory));

        $producer->send('m1', 'key-a');
        $producer->send('m2', 'key-a');
        $producer->send('m3', 'key-a');

        $this->assertSame(1, $callCount, 'Partition producer must be opened lazily, only once');
    }
}
