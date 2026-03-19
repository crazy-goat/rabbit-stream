<?php

namespace CrazyGoat\RabbitStream\Tests\Request;

use CrazyGoat\RabbitStream\Request\DeleteSuperStreamRequestV1;
use PHPUnit\Framework\TestCase;

class DeleteSuperStreamRequestV1Test extends TestCase
{
    public function testSerializesCorrectly(): void
    {
        $request = new DeleteSuperStreamRequestV1('my-super-stream');
        $request->withCorrelationId(42);

        $bytes = $request->toStreamBuffer()->getContents();

        $expected = pack('n', 0x001e)   // key (DELETE_SUPER_STREAM)
            . pack('n', 1)              // version
            . pack('N', 42)             // correlationId
            . pack('n', 15)             // super stream name length
            . 'my-super-stream';        // super stream name

        $this->assertSame($expected, $bytes);
    }
}
