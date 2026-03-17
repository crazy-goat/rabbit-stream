<?php

namespace CrazyGoat\RabbitStream\Tests\Response;

use CrazyGoat\RabbitStream\Buffer\ReadBuffer;
use CrazyGoat\RabbitStream\Response\CloseResponseV1;
use PHPUnit\Framework\TestCase;

class CloseResponseV1Test extends TestCase
{
    public function testDeserializesCorrectly(): void
    {
        $raw = pack('n', 0x8016)    // key
            . pack('n', 1)          // version
            . pack('N', 2)          // correlationId
            . pack('n', 0x0001);   // responseCode OK

        $response = CloseResponseV1::fromStreamBuffer(new ReadBuffer($raw));

        $this->assertInstanceOf(CloseResponseV1::class, $response);
        $this->assertSame(2, $response->getCorrelationId());
    }

    public function testThrowsOnErrorResponseCode(): void
    {
        $raw = pack('n', 0x8016)
            . pack('n', 1)
            . pack('N', 1)
            . pack('n', 0x0002); // generic error

        $this->expectException(\Exception::class);
        CloseResponseV1::fromStreamBuffer(new ReadBuffer($raw));
    }
}
