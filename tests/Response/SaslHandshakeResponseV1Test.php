<?php

namespace CrazyGoat\StreamyCarrot\Tests\Response;

use CrazyGoat\StreamyCarrot\Buffer\ReadBuffer;
use CrazyGoat\StreamyCarrot\Response\SaslHandshakeResponseV1;
use PHPUnit\Framework\TestCase;

class SaslHandshakeResponseV1Test extends TestCase
{
    public function testDeserializesCorrectly(): void
    {
        $raw = pack('n', 0x8012)            // key
            . pack('n', 1)                  // version
            . pack('N', 1)                  // correlationId
            . pack('n', 0x0001)             // responseCode OK
            . pack('N', 2)                  // array length
            . pack('n', 5) . 'PLAIN'        // mechanism 1
            . pack('n', 9) . 'AMQPLAIN';   // mechanism 2

        $response = SaslHandshakeResponseV1::fromStreamBuffer(new ReadBuffer($raw));

        $this->assertInstanceOf(SaslHandshakeResponseV1::class, $response);
        $this->assertSame(1, $response->getCorrelationId());
    }
}
