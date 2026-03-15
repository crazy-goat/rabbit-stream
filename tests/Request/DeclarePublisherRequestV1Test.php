<?php

namespace CrazyGoat\StreamyCarrot\Tests\Request;

use CrazyGoat\StreamyCarrot\Request\DeclarePublisherRequestV1;
use PHPUnit\Framework\TestCase;

class DeclarePublisherRequestV1Test extends TestCase
{
    public function testSerializesWithReference(): void
    {
        $request = new DeclarePublisherRequestV1(1, 'my-publisher', 'my-stream');
        $request->withCorrelationId(1);

        $bytes = $request->toStreamBuffer()->getContents();

        $expected = pack('n', 0x0001)           // key
            . pack('n', 1)                      // version
            . pack('N', 1)                      // correlationId
            . pack('C', 1)                      // publisherId
            . pack('n', 12) . 'my-publisher'    // publisherReference
            . pack('n', 9) . 'my-stream';      // stream

        $this->assertSame($expected, $bytes);
    }

    public function testSerializesWithNullReference(): void
    {
        $request = new DeclarePublisherRequestV1(2, null, 'my-stream');
        $request->withCorrelationId(5);

        $bytes = $request->toStreamBuffer()->getContents();

        $expected = pack('n', 0x0001)
            . pack('n', 1)
            . pack('N', 5)
            . pack('C', 2)
            . pack('n', 0) . ''             // empty string
            . pack('n', 9) . 'my-stream';

        $this->assertSame($expected, $bytes);
    }
}
