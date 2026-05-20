<?php

declare(strict_types=1);

namespace CrazyGoat\RabbitStream\Tests\VO;

use CrazyGoat\RabbitStream\Buffer\ReadBuffer;
use CrazyGoat\RabbitStream\VO\StreamMetadata;
use PHPUnit\Framework\TestCase;

class StreamMetadataTest extends TestCase
{
    public function testConstructorAndGetters(): void
    {
        $meta = new StreamMetadata('my-stream', 0x0001, 5, [3, 7]);
        $this->assertSame('my-stream', $meta->getStreamName());
        $this->assertSame(0x0001, $meta->getResponseCode());
        $this->assertSame(5, $meta->getLeaderReference());
        $this->assertSame([3, 7], $meta->getReplicasReferences());
    }

    public function testEmptyReplicas(): void
    {
        $meta = new StreamMetadata('single-node', 0x0001, 0, []);
        $this->assertSame('single-node', $meta->getStreamName());
        $this->assertSame([], $meta->getReplicasReferences());
    }

    public function testZeroResponseCode(): void
    {
        $meta = new StreamMetadata('not-found', 0x0000, 0, []);
        $this->assertSame(0x0000, $meta->getResponseCode());
    }

    public function testSingleReplica(): void
    {
        $meta = new StreamMetadata('with-replica', 0x0001, 1, [2]);
        $this->assertCount(1, $meta->getReplicasReferences());
        $this->assertSame([2], $meta->getReplicasReferences());
    }

    public function testMultipleReplicas(): void
    {
        $replicas = [10, 20, 30, 40, 50];
        $meta = new StreamMetadata('multi-replica', 0x0001, 5, $replicas);
        $this->assertCount(5, $meta->getReplicasReferences());
        $this->assertSame($replicas, $meta->getReplicasReferences());
    }

    public function testFromStreamBufferWithoutReplicas(): void
    {
        $streamName = 'stream-no-replicas';
        $buffer = new ReadBuffer(
            pack('n', strlen($streamName))  // stream name length
            . $streamName
            . pack('n', 0x0001)             // response code (uint16)
            . pack('n', 0)                  // leader reference (uint16)
            . pack('N', 0)                  // replicas count (uint32)
        );
        $meta = StreamMetadata::fromStreamBuffer($buffer);
        $this->assertNotNull($meta);
        $this->assertSame($streamName, $meta->getStreamName());
        $this->assertSame(0x0001, $meta->getResponseCode());
        $this->assertSame(0, $meta->getLeaderReference());
        $this->assertSame([], $meta->getReplicasReferences());
    }

    public function testFromStreamBufferWithReplicas(): void
    {
        $streamName = 'stream-replicas';
        $buffer = new ReadBuffer(
            pack('n', strlen($streamName))  // stream name length
            . $streamName
            . pack('n', 0x0001)             // response code (uint16)
            . pack('n', 3)                  // leader reference (uint16)
            . pack('N', 2)                  // replicas count (uint32)
            . pack('n', 7)                  // replica 1 (uint16)
            . pack('n', 9)                  // replica 2 (uint16)
        );
        $meta = StreamMetadata::fromStreamBuffer($buffer);
        $this->assertNotNull($meta);
        $this->assertSame($streamName, $meta->getStreamName());
        $this->assertSame(0x0001, $meta->getResponseCode());
        $this->assertSame(3, $meta->getLeaderReference());
        $this->assertSame([7, 9], $meta->getReplicasReferences());
    }

    public function testToArray(): void
    {
        $meta = new StreamMetadata('s1', 0x0001, 2, [5, 8]);
        $this->assertSame([
            'stream' => 's1',
            'responseCode' => 0x0001,
            'leaderReference' => 2,
            'replicasReferences' => [5, 8],
        ], $meta->toArray());
    }

    public function testFromArray(): void
    {
        $meta = StreamMetadata::fromArray([
            'stream' => 'arr-stream',
            'responseCode' => 0x0001,
            'leaderReference' => 99,
            'replicasReferences' => [11, 22],
        ]);
        $this->assertInstanceOf(StreamMetadata::class, $meta);
        $this->assertSame('arr-stream', $meta->getStreamName());
        $this->assertSame(99, $meta->getLeaderReference());
        $this->assertSame([11, 22], $meta->getReplicasReferences());
    }

    public function testFromArrayWithDefaultReplicas(): void
    {
        $meta = StreamMetadata::fromArray([
            'stream' => 'default-replicas',
            'responseCode' => 0x0001,
            'leaderReference' => 0,
        ]);
        $this->assertSame([], $meta->getReplicasReferences());
    }

    public function testToArrayFromArrayRoundTrip(): void
    {
        $meta = new StreamMetadata('round-trip', 0x0001, 42, [1, 2, 3]);
        $array = $meta->toArray();
        $restored = StreamMetadata::fromArray($array);
        $this->assertSame('round-trip', $restored->getStreamName());
        $this->assertSame(42, $restored->getLeaderReference());
        $this->assertSame([1, 2, 3], $restored->getReplicasReferences());
    }

    public function testFromStreamBufferNullStreamName(): void
    {
        $buffer = new ReadBuffer(
            pack('n', 0xFFFF)               // stream name: null
            . pack('n', 0x0001)              // response code (uint16)
            . pack('n', 0)                   // leader reference (uint16)
            . pack('N', 0)                   // replicas count (uint32)
        );
        $meta = StreamMetadata::fromStreamBuffer($buffer);
        $this->assertNotNull($meta);
        $this->assertSame('', $meta->getStreamName());
    }
}
