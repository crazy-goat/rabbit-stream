<?php

namespace CrazyGoat\RabbitStream\Tests\Request;

use CrazyGoat\RabbitStream\Request\CreateRequestV1;
use PHPUnit\Framework\TestCase;

class CreateRequestV1Test extends TestCase
{
    public function testSerializesCorrectlyWithoutArguments(): void
    {
        $request = new CreateRequestV1('my-stream');
        $request->withCorrelationId(42);

        $bytes = $request->toStreamBuffer()->getContents();

        $expected = pack('n', 0x000d)   // key
            . pack('n', 1)              // version
            . pack('N', 42)             // correlationId
            . pack('n', 9)              // stream name length
            . 'my-stream'               // stream name
            . pack('N', 0);             // arguments array length (0)

        $this->assertSame($expected, $bytes);
    }

    public function testSerializesCorrectlyWithArguments(): void
    {
        $request = new CreateRequestV1('my-stream', ['max-length-bytes' => '1000000', 'max-age' => '1h']);
        $request->withCorrelationId(42);

        $bytes = $request->toStreamBuffer()->getContents();

        $expected = pack('n', 0x000d)   // key
            . pack('n', 1)              // version
            . pack('N', 42)             // correlationId
            . pack('n', 9)              // stream name length
            . 'my-stream'               // stream name
            . pack('N', 2)              // arguments array length (2)
            . pack('n', 16)             // arg1 key length
            . 'max-length-bytes'        // arg1 key
            . pack('n', 7)              // arg1 value length
            . '1000000'                 // arg1 value
            . pack('n', 7)              // arg2 key length
            . 'max-age'                 // arg2 key
            . pack('n', 2)              // arg2 value length
            . '1h';                     // arg2 value

        $this->assertSame($expected, $bytes);
    }
}
