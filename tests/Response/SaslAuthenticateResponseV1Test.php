<?php

namespace CrazyGoat\StreamyCarrot\Tests\Response;

use CrazyGoat\StreamyCarrot\Buffer\ReadBuffer;
use CrazyGoat\StreamyCarrot\Response\SaslAuthenticateResponseV1;
use PHPUnit\Framework\TestCase;

class SaslAuthenticateResponseV1Test extends TestCase
{
    public function testDeserializesCorrectly(): void
    {
        $raw = pack('n', 0x8013)    // key
            . pack('n', 1)          // version
            . pack('N', 2)          // correlationId
            . pack('n', 0x0001);   // responseCode OK

        $response = SaslAuthenticateResponseV1::fromStreamBuffer(new ReadBuffer($raw));

        $this->assertInstanceOf(SaslAuthenticateResponseV1::class, $response);
        $this->assertSame(2, $response->getCorrelationId());
    }

    public function testThrowsOnErrorResponseCode(): void
    {
        $raw = pack('n', 0x8013)
            . pack('n', 1)
            . pack('N', 1)
            . pack('n', 0x0008); // SASL error

        $this->expectException(\Exception::class);
        SaslAuthenticateResponseV1::fromStreamBuffer(new ReadBuffer($raw));
    }
}
