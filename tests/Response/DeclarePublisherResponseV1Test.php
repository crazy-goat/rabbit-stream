<?php

declare(strict_types=1);

namespace CrazyGoat\RabbitStream\Tests\Response;

use CrazyGoat\RabbitStream\Buffer\ReadBuffer;
use CrazyGoat\RabbitStream\Response\DeclarePublisherResponseV1;
use PHPUnit\Framework\TestCase;

class DeclarePublisherResponseV1Test extends TestCase
{
    public function testDeserializesCorrectly(): void
    {
        $raw = pack('n', 0x8001)    // key
            . pack('n', 1)          // version
            . pack('N', 7)          // correlationId
            . pack('n', 0x0001);   // responseCode OK

        $response = DeclarePublisherResponseV1::fromStreamBuffer(new ReadBuffer($raw));

        $this->assertInstanceOf(DeclarePublisherResponseV1::class, $response);
        $this->assertSame(7, $response->getCorrelationId());
    }

    public function testThrowsOnErrorResponseCode(): void
    {
        $raw = pack('n', 0x8001)
            . pack('n', 1)
            . pack('N', 1)
            . pack('n', 0x0012); // Publisher does not exist

        $this->expectException(\Exception::class);
        DeclarePublisherResponseV1::fromStreamBuffer(new ReadBuffer($raw));
    }
}
