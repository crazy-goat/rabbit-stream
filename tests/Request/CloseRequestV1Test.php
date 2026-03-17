<?php

namespace CrazyGoat\RabbitStream\Tests\Request;

use CrazyGoat\RabbitStream\Request\CloseRequestV1;
use PHPUnit\Framework\TestCase;

class CloseRequestV1Test extends TestCase
{
    public function testSerializesCorrectly(): void
    {
        $request = new CloseRequestV1(0, '');
        $request->withCorrelationId(2);

        $bytes = $request->toStreamBuffer()->getContents();

        $expected = pack('n', 0x0016)           // key
            . pack('n', 1)                      // version
            . pack('N', 2)                      // correlationId
            . pack('n', 0)                      // closingCode
            . pack('n', 0);                     // closingReason (empty string)

        $this->assertSame($expected, $bytes);
    }

    public function testSerializesWithReason(): void
    {
        $request = new CloseRequestV1(1, 'test reason');
        $request->withCorrelationId(3);

        $bytes = $request->toStreamBuffer()->getContents();

        $expected = pack('n', 0x0016)           // key
            . pack('n', 1)                      // version
            . pack('N', 3)                      // correlationId
            . pack('n', 1)                      // closingCode
            . pack('n', 11) . 'test reason';    // closingReason

        $this->assertSame($expected, $bytes);
    }
}
