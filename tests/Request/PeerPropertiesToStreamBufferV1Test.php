<?php

namespace CrazyGoat\StreamyCarrot\Tests\Request;

use CrazyGoat\StreamyCarrot\Request\PeerPropertiesToStreamBufferV1;
use CrazyGoat\StreamyCarrot\VO\KeyValue;
use PHPUnit\Framework\TestCase;

class PeerPropertiesToStreamBufferV1Test extends TestCase
{
    public function testSerializesWithNoProperties(): void
    {
        $request = new PeerPropertiesToStreamBufferV1();
        $request->withCorrelationId(1);

        $bytes = $request->toStreamBuffer()->getContents();

        $expected = pack('n', 0x0011)   // key
            . pack('n', 1)              // version
            . pack('N', 1)              // correlationId
            . pack('N', 0);            // empty array

        $this->assertSame($expected, $bytes);
    }

    public function testSerializesWithProperties(): void
    {
        $request = new PeerPropertiesToStreamBufferV1(
            new KeyValue('product', 'test-client'),
            new KeyValue('version', '1.0'),
        );
        $request->withCorrelationId(1);

        $bytes = $request->toStreamBuffer()->getContents();

        $expected = pack('n', 0x0011)
            . pack('n', 1)
            . pack('N', 1)
            . pack('N', 2)                          // 2 items
            . pack('n', 7) . 'product'
            . pack('n', 11) . 'test-client'
            . pack('n', 7) . 'version'
            . pack('n', 3) . '1.0';

        $this->assertSame($expected, $bytes);
    }
}
