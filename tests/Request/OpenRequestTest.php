<?php

namespace CrazyGoat\RabbitStream\Tests\Request;

use CrazyGoat\RabbitStream\Request\OpenRequest;
use PHPUnit\Framework\TestCase;

class OpenRequestTest extends TestCase
{
    public function testSerializesWithDefaultVhost(): void
    {
        $request = new OpenRequest();
        $request->withCorrelationId(3);

        $bytes = $request->toStreamBuffer()->getContents();

        $expected = pack('n', 0x0015)   // key
            . pack('n', 1)              // version
            . pack('N', 3)              // correlationId
            . pack('n', 1) . '/';      // vhost string

        $this->assertSame($expected, $bytes);
    }

    public function testSerializesWithCustomVhost(): void
    {
        $request = new OpenRequest('/myapp');
        $request->withCorrelationId(1);

        $bytes = $request->toStreamBuffer()->getContents();

        $expected = pack('n', 0x0015)
            . pack('n', 1)
            . pack('N', 1)
            . pack('n', 6) . '/myapp';

        $this->assertSame($expected, $bytes);
    }
}
