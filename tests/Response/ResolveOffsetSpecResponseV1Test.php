<?php

namespace CrazyGoat\RabbitStream\Tests\Response;

use CrazyGoat\RabbitStream\Buffer\ReadBuffer;
use CrazyGoat\RabbitStream\Response\ResolveOffsetSpecResponseV1;
use PHPUnit\Framework\TestCase;

class ResolveOffsetSpecResponseV1Test extends TestCase
{
    public function testDeserializesCorrectly(): void
    {
        $raw = pack('n', 0x801f)    // key
            . pack('n', 1)          // version
            . pack('N', 7)          // correlationId
            . pack('n', 0x0001)     // responseCode OK
            . pack('J', 123456);    // offset (uint64 big-endian)

        $response = ResolveOffsetSpecResponseV1::fromStreamBuffer(new ReadBuffer($raw));

        $this->assertInstanceOf(ResolveOffsetSpecResponseV1::class, $response);
        $this->assertSame(7, $response->getCorrelationId());
        $this->assertSame(123456, $response->getOffset());
    }

    public function testThrowsOnErrorResponseCode(): void
    {
        $raw = pack('n', 0x801f)
            . pack('n', 1)
            . pack('N', 1)
            . pack('n', 0x0002); // Stream does not exist

        $this->expectException(\Exception::class);
        ResolveOffsetSpecResponseV1::fromStreamBuffer(new ReadBuffer($raw));
    }
}
