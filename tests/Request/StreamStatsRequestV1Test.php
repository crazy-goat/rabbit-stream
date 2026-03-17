<?php

namespace CrazyGoat\RabbitStream\Tests\Request;

use CrazyGoat\RabbitStream\Request\StreamStatsRequestV1;
use PHPUnit\Framework\TestCase;

class StreamStatsRequestV1Test extends TestCase
{
    public function testSerializesCorrectly(): void
    {
        $request = new StreamStatsRequestV1('my-stream');
        $request->withCorrelationId(42);

        $bytes = $request->toStreamBuffer()->getContents();

        $expected = pack('n', 0x001c)   // key (STREAM_STATS)
            . pack('n', 1)              // version
            . pack('N', 42)             // correlationId
            . pack('n', 9)              // stream name length
            . 'my-stream';              // stream name

        $this->assertSame($expected, $bytes);
    }
}
