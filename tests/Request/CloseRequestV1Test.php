<?php

namespace CrazyGoat\RabbitStream\Tests\Request;

use CrazyGoat\RabbitStream\Request\CloseRequestV1;
use PHPUnit\Framework\TestCase;

class CloseRequestV1Test extends TestCase
{
    public function testSerializesCorrectly(): void
    {
        $request = new CloseRequestV1();
        $request->withCorrelationId(2);

        $bytes = $request->toStreamBuffer()->getContents();

        $expected = pack('n', 0x0016)           // key
            . pack('n', 1)                      // version
            . pack('N', 2);                     // correlationId

        $this->assertSame($expected, $bytes);
    }
}
