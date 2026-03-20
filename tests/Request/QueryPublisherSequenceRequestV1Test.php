<?php

declare(strict_types=1);

namespace CrazyGoat\RabbitStream\Tests\Request;

use CrazyGoat\RabbitStream\Request\QueryPublisherSequenceRequestV1;
use PHPUnit\Framework\TestCase;

class QueryPublisherSequenceRequestV1Test extends TestCase
{
    public function testSerializesCorrectly(): void
    {
        $request = new QueryPublisherSequenceRequestV1(
            'my-reference',
            'my-stream'
        );
        $request->withCorrelationId(42);

        $bytes = $request->toStreamBuffer()->getContents();

        $expected = pack('n', 0x0005)                  // key
            . pack('n', 1)                             // version
            . pack('N', 42)                            // correlationId
            . pack('n', strlen('my-reference'))        // reference length
            . 'my-reference'                           // reference
            . pack('n', strlen('my-stream'))           // stream length
            . 'my-stream';                             // stream

        $this->assertSame($expected, $bytes);
    }
}
