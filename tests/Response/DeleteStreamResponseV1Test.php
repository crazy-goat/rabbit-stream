<?php

namespace CrazyGoat\RabbitStream\Tests\Response;

use CrazyGoat\RabbitStream\Buffer\ReadBuffer;
use CrazyGoat\RabbitStream\Response\DeleteStreamResponseV1;
use PHPUnit\Framework\TestCase;

class DeleteStreamResponseV1Test extends TestCase
{
    public function testDeserializesCorrectly(): void
    {
        $raw = pack('n', 0x800e)    // key
            . pack('n', 1)          // version
            . pack('N', 42)         // correlationId
            . pack('n', 0x0001);   // responseCode OK

        $response = DeleteStreamResponseV1::fromStreamBuffer(new ReadBuffer($raw));

        $this->assertInstanceOf(DeleteStreamResponseV1::class, $response);
        $this->assertSame(42, $response->getCorrelationId());
    }

    public function testThrowsOnErrorResponseCode(): void
    {
        $raw = pack('n', 0x800e)
            . pack('n', 1)
            . pack('N', 1)
            . pack('n', 0x0002); // Stream does not exist

        $this->expectException(\Exception::class);
        DeleteStreamResponseV1::fromStreamBuffer(new ReadBuffer($raw));
    }
}
