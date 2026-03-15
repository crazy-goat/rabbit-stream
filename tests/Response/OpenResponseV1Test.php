<?php

namespace CrazyGoat\StreamyCarrot\Tests\Response;

use CrazyGoat\StreamyCarrot\Buffer\ReadBuffer;
use CrazyGoat\StreamyCarrot\Response\OpenResponseV1;
use PHPUnit\Framework\TestCase;

class OpenResponseV1Test extends TestCase
{
    public function testDeserializesWithNoProperties(): void
    {
        $raw = pack('n', 0x8015)    // key
            . pack('n', 1)          // version
            . pack('N', 3)          // correlationId
            . pack('n', 0x0001)     // responseCode OK
            . pack('N', 0);        // empty properties array

        $response = OpenResponseV1::fromStreamBuffer(new ReadBuffer($raw));

        $this->assertInstanceOf(OpenResponseV1::class, $response);
        $this->assertSame(3, $response->getCorrelationId());
    }

    public function testDeserializesWithProperties(): void
    {
        $raw = pack('n', 0x8015)
            . pack('n', 1)
            . pack('N', 1)
            . pack('n', 0x0001)
            . pack('N', 1)                      // 1 property
            . pack('n', 7) . 'cluster'          // key
            . pack('n', 6) . 'rabbit';         // value

        $response = OpenResponseV1::fromStreamBuffer(new ReadBuffer($raw));

        $this->assertInstanceOf(OpenResponseV1::class, $response);
    }

    public function testThrowsOnErrorResponseCode(): void
    {
        $raw = pack('n', 0x8015)
            . pack('n', 1)
            . pack('N', 1)
            . pack('n', 0x000c); // Virtual host access failure

        $this->expectException(\Exception::class);
        OpenResponseV1::fromStreamBuffer(new ReadBuffer($raw));
    }
}
