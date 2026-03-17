<?php

namespace CrazyGoat\RabbitStream\Tests\Request;

use CrazyGoat\RabbitStream\Request\QueryOffsetRequestV1;
use PHPUnit\Framework\TestCase;

class QueryOffsetRequestV1Test extends TestCase
{
    public function testSerializesCorrectly(): void
    {
        $request = new QueryOffsetRequestV1(
            'my-reference',
            'my-stream'
        );
        $request->withCorrelationId(42);

        $bytes = $request->toStreamBuffer()->getContents();

        $expected = pack('n', 0x000b)                  // key
            . pack('n', 1)                             // version
            . pack('N', 42)                            // correlationId
            . pack('n', strlen('my-reference'))        // reference length
            . 'my-reference'                           // reference
            . pack('n', strlen('my-stream'))           // stream length
            . 'my-stream';                             // stream

        $this->assertSame($expected, $bytes);
    }
}
