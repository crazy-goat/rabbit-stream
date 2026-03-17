<?php

namespace CrazyGoat\RabbitStream\Tests\Response;

use CrazyGoat\RabbitStream\Buffer\ReadBuffer;
use CrazyGoat\RabbitStream\Response\DeletePublisherResponseV1;
use PHPUnit\Framework\TestCase;

class DeletePublisherResponseV1Test extends TestCase
{
    public function testDeserializesCorrectly(): void
    {
        $raw = pack('n', 0x8006)    // key
            . pack('n', 1)          // version
            . pack('N', 42)         // correlationId
            . pack('n', 0x0001);   // responseCode OK

        $response = DeletePublisherResponseV1::fromStreamBuffer(new ReadBuffer($raw));

        $this->assertInstanceOf(DeletePublisherResponseV1::class, $response);
        $this->assertSame(42, $response->getCorrelationId());
    }

    public function testThrowsOnErrorResponseCode(): void
    {
        $raw = pack('n', 0x8006)
            . pack('n', 1)
            . pack('N', 1)
            . pack('n', 0x0012); // Publisher does not exist

        $this->expectException(\Exception::class);
        DeletePublisherResponseV1::fromStreamBuffer(new ReadBuffer($raw));
    }
}
