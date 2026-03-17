<?php

namespace CrazyGoat\RabbitStream\Tests\Request;

use CrazyGoat\RabbitStream\Request\MetadataRequestV1;
use PHPUnit\Framework\TestCase;

class MetadataRequestV1Test extends TestCase
{
    public function testSerializesCorrectlyWithSingleStream(): void
    {
        $request = new MetadataRequestV1(['my-stream']);
        $request->withCorrelationId(42);

        $bytes = $request->toStreamBuffer()->getContents();

        $expected = pack('n', 0x000f)   // key
            . pack('n', 1)              // version
            . pack('N', 42)             // correlationId
            . pack('N', 1)              // array length (1 stream)
            . pack('n', 9)              // stream name length
            . 'my-stream';              // stream name

        $this->assertSame($expected, $bytes);
    }

    public function testSerializesCorrectlyWithMultipleStreams(): void
    {
        $request = new MetadataRequestV1(['stream-1', 'stream-2']);
        $request->withCorrelationId(42);

        $bytes = $request->toStreamBuffer()->getContents();

        $expected = pack('n', 0x000f)   // key
            . pack('n', 1)              // version
            . pack('N', 42)             // correlationId
            . pack('N', 2)              // array length (2 streams)
            . pack('n', 8)              // stream-1 name length
            . 'stream-1'                // stream-1 name
            . pack('n', 8)              // stream-2 name length
            . 'stream-2';               // stream-2 name

        $this->assertSame($expected, $bytes);
    }

    public function testSerializesCorrectlyWithEmptyStreams(): void
    {
        $request = new MetadataRequestV1([]);
        $request->withCorrelationId(42);

        $bytes = $request->toStreamBuffer()->getContents();

        $expected = pack('n', 0x000f)   // key
            . pack('n', 1)              // version
            . pack('N', 42)             // correlationId
            . pack('N', 0);             // array length (0 streams)

        $this->assertSame($expected, $bytes);
    }
}
