<?php

declare(strict_types=1);

namespace CrazyGoat\RabbitStream\Tests\Response;

use CrazyGoat\RabbitStream\Buffer\ReadBuffer;
use CrazyGoat\RabbitStream\Response\QueryPublisherSequenceResponseV1;
use PHPUnit\Framework\TestCase;

class QueryPublisherSequenceResponseV1Test extends TestCase
{
    public function testDeserializesCorrectly(): void
    {
        $raw = pack('n', 0x8005)    // key
            . pack('n', 1)          // version
            . pack('N', 7)          // correlationId
            . pack('n', 0x0001)     // responseCode OK
            . pack('J', 123456);    // sequence (uint64 big-endian)

        $response = QueryPublisherSequenceResponseV1::fromStreamBuffer(new ReadBuffer($raw));

        $this->assertInstanceOf(QueryPublisherSequenceResponseV1::class, $response);
        $this->assertSame(7, $response->getCorrelationId());
        $this->assertSame(123456, $response->getSequence());
    }

    public function testThrowsOnErrorResponseCode(): void
    {
        $raw = pack('n', 0x8005)
            . pack('n', 1)
            . pack('N', 1)
            . pack('n', 0x0002); // Stream does not exist

        $this->expectException(\Exception::class);
        QueryPublisherSequenceResponseV1::fromStreamBuffer(new ReadBuffer($raw));
    }
}
