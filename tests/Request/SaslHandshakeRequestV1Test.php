<?php

declare(strict_types=1);

namespace CrazyGoat\RabbitStream\Tests\Request;

use CrazyGoat\RabbitStream\Request\SaslHandshakeRequestV1;
use PHPUnit\Framework\TestCase;

class SaslHandshakeRequestV1Test extends TestCase
{
    public function testSerializesCorrectly(): void
    {
        $request = new SaslHandshakeRequestV1();
        $request->withCorrelationId(1);

        $bytes = $request->toStreamBuffer()->getContents();

        $expected = pack('n', 0x0012)   // key
            . pack('n', 1)              // version
            . pack('N', 1);             // correlationId

        $this->assertSame($expected, $bytes);
    }
}
