<?php

namespace CrazyGoat\RabbitStream\Tests\Request;

use CrazyGoat\RabbitStream\Request\RouteRequestV1;
use PHPUnit\Framework\TestCase;

class RouteRequestV1Test extends TestCase
{
    public function testSerializesCorrectly(): void
    {
        $request = new RouteRequestV1('my-routing-key', 'my-super-stream');
        $request->withCorrelationId(42);

        $bytes = $request->toStreamBuffer()->getContents();

        $expected = pack('n', 0x0018)   // key (ROUTE)
            . pack('n', 1)              // version
            . pack('N', 42)             // correlationId
            . pack('n', 14)             // routingKey name length
            . 'my-routing-key'          // routingKey
            . pack('n', 15)             // superStream name length
            . 'my-super-stream';        // superStream name

        $this->assertSame($expected, $bytes);
    }
}
