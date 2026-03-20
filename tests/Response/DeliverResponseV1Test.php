<?php

declare(strict_types=1);

namespace CrazyGoat\RabbitStream\Tests\Response;

use CrazyGoat\RabbitStream\Buffer\ReadBuffer;
use CrazyGoat\RabbitStream\Response\DeliverResponseV1;
use PHPUnit\Framework\TestCase;

class DeliverResponseV1Test extends TestCase
{
    public function testDeserializesV1Frame(): void
    {
        $chunkBytes = 'test-chunk-data';
        $raw = pack('n', 0x0008)
            . pack('n', 1)
            . pack('C', 3)
            . $chunkBytes;

        $response = DeliverResponseV1::fromStreamBuffer(new ReadBuffer($raw));

        $this->assertInstanceOf(DeliverResponseV1::class, $response);
        $this->assertSame(3, $response->getSubscriptionId());
        $this->assertSame($chunkBytes, $response->getChunkBytes());
    }

    public function testDeserializesV2FrameSkipsCommittedChunkId(): void
    {
        $chunkBytes = 'test-chunk-data';
        $committedChunkId = pack('J', 123456789);
        $raw = pack('n', 0x0008)
            . pack('n', 2)
            . pack('C', 7)
            . $committedChunkId
            . $chunkBytes;

        $response = DeliverResponseV1::fromStreamBuffer(new ReadBuffer($raw));

        $this->assertInstanceOf(DeliverResponseV1::class, $response);
        $this->assertSame(7, $response->getSubscriptionId());
        $this->assertSame($chunkBytes, $response->getChunkBytes());
    }

    public function testThrowsOnWrongKey(): void
    {
        $raw = pack('n', 0x0003)
            . pack('n', 1)
            . pack('C', 1)
            . 'data';

        $this->expectException(\Exception::class);
        $this->expectExceptionMessage('Unexpected command code');

        DeliverResponseV1::fromStreamBuffer(new ReadBuffer($raw));
    }
}
