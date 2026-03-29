<?php

declare(strict_types=1);

namespace CrazyGoat\RabbitStream\Tests\Request;

use CrazyGoat\RabbitStream\Request\PeerPropertiesRequestV1;
use CrazyGoat\RabbitStream\VO\KeyValue;
use PHPUnit\Framework\TestCase;

class PeerPropertiesRequestV1Test extends TestCase
{
    public function testSerializesWithNoProperties(): void
    {
        $request = new PeerPropertiesRequestV1();
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
        $request = new PeerPropertiesRequestV1(
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
