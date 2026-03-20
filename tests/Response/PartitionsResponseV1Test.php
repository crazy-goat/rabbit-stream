<?php

declare(strict_types=1);

namespace CrazyGoat\RabbitStream\Tests\Response;

use CrazyGoat\RabbitStream\Buffer\ReadBuffer;
use CrazyGoat\RabbitStream\Response\PartitionsResponseV1;
use PHPUnit\Framework\TestCase;

class PartitionsResponseV1Test extends TestCase
{
    public function testDeserializesWithStreams(): void
    {
        $raw = pack('n', 0x8019)           // key (PARTITIONS_RESPONSE)
            . pack('n', 1)                  // version
            . pack('N', 42)                 // correlationId
            . pack('n', 0x0001)             // response code (OK)
            . pack('N', 3)                  // 3 streams
            . pack('n', 10)                 // stream 1 name length
            . 'partition1'                   // stream 1 name
            . pack('n', 10)                 // stream 2 name length
            . 'partition2'                   // stream 2 name
            . pack('n', 10)                 // stream 3 name length
            . 'partition3';                // stream 3 name

        $response = PartitionsResponseV1::fromStreamBuffer(new ReadBuffer($raw));

        $this->assertInstanceOf(PartitionsResponseV1::class, $response);
        $this->assertSame(42, $response->getCorrelationId());

        $streams = $response->getStreams();
        $this->assertCount(3, $streams);
        $this->assertSame('partition1', $streams[0]);
        $this->assertSame('partition2', $streams[1]);
        $this->assertSame('partition3', $streams[2]);
    }

    public function testDeserializesWithEmptyStreams(): void
    {
        $raw = pack('n', 0x8019)           // key
            . pack('n', 1)                  // version
            . pack('N', 42)                 // correlationId
            . pack('n', 0x0001)             // response code (OK)
            . pack('N', 0);                 // 0 streams

        $response = PartitionsResponseV1::fromStreamBuffer(new ReadBuffer($raw));

        $this->assertInstanceOf(PartitionsResponseV1::class, $response);
        $this->assertSame(42, $response->getCorrelationId());
        $this->assertCount(0, $response->getStreams());
    }

    public function testThrowsOnNonOkResponseCode(): void
    {
        $raw = pack('n', 0x8019)           // key
            . pack('n', 1)                  // version
            . pack('N', 42)                 // correlationId
            . pack('n', 0x0002);            // response code (Stream does not exist)

        $this->expectException(\Exception::class);
        $this->expectExceptionMessage('Unexpected response code: 0x0002 (STREAM_NOT_EXIST: Stream does not exist)');

        PartitionsResponseV1::fromStreamBuffer(new ReadBuffer($raw));
    }
}
