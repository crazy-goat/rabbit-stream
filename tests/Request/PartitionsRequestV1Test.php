<?php

namespace CrazyGoat\RabbitStream\Tests\Request;

use CrazyGoat\RabbitStream\Request\PartitionsRequestV1;
use PHPUnit\Framework\TestCase;

class PartitionsRequestV1Test extends TestCase
{
    public function testSerializesCorrectly(): void
    {
        $request = new PartitionsRequestV1('my-super-stream');
        $request->withCorrelationId(42);

        $bytes = $request->toStreamBuffer()->getContents();

        $expected = pack('n', 0x0019)   // key (PARTITIONS)
            . pack('n', 1)              // version
            . pack('N', 42)             // correlationId
            . pack('n', 15)             // superStream name length
            . 'my-super-stream';        // superStream name

        $this->assertSame($expected, $bytes);
    }
}
