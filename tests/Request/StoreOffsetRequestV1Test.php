<?php

declare(strict_types=1);

namespace CrazyGoat\RabbitStream\Tests\Request;

use CrazyGoat\RabbitStream\Request\StoreOffsetRequestV1;
use PHPUnit\Framework\TestCase;

class StoreOffsetRequestV1Test extends TestCase
{
    public function testSerializesCorrectly(): void
    {
        $request = new StoreOffsetRequestV1(
            'my-reference',
            'my-stream',
            1234567890
        );

        $bytes = $request->toStreamBuffer()->getContents();

        $expected = pack('n', 0x000a)                  // key
            . pack('n', 1)                             // version
            . pack('n', strlen('my-reference'))        // reference length
            . 'my-reference'                           // reference
            . pack('n', strlen('my-stream'))           // stream length
            . 'my-stream'                              // stream
            . pack('J', 1234567890);                   // offset (uint64 big-endian)

        $this->assertSame($expected, $bytes);
    }
}
