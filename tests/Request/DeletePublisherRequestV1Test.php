<?php

namespace CrazyGoat\StreamyCarrot\Tests\Request;

use CrazyGoat\StreamyCarrot\Request\DeletePublisherRequestV1;
use PHPUnit\Framework\TestCase;

class DeletePublisherRequestV1Test extends TestCase
{
    public function testSerializesCorrectly(): void
    {
        $request = new DeletePublisherRequestV1(3);
        $request->withCorrelationId(42);

        $bytes = $request->toStreamBuffer()->getContents();

        $expected = pack('n', 0x0006)   // key
            . pack('n', 1)              // version
            . pack('N', 42)             // correlationId
            . pack('C', 3);             // publisherId

        $this->assertSame($expected, $bytes);
    }
}
