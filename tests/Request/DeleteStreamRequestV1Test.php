<?php

declare(strict_types=1);

namespace CrazyGoat\RabbitStream\Tests\Request;

use CrazyGoat\RabbitStream\Request\DeleteStreamRequestV1;
use PHPUnit\Framework\TestCase;

class DeleteStreamRequestV1Test extends TestCase
{
    public function testSerializesCorrectly(): void
    {
        $request = new DeleteStreamRequestV1('my-stream');
        $request->withCorrelationId(42);

        $bytes = $request->toStreamBuffer()->getContents();

        $expected = pack('n', 0x000e)   // key
            . pack('n', 1)              // version
            . pack('N', 42)             // correlationId
            . pack('n', 9)              // stream name length
            . 'my-stream';              // stream name

        $this->assertSame($expected, $bytes);
    }
}
