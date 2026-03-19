<?php

namespace CrazyGoat\RabbitStream\Tests\Response;

use CrazyGoat\RabbitStream\Buffer\ReadBuffer;
use CrazyGoat\RabbitStream\Response\RouteResponseV1;
use PHPUnit\Framework\TestCase;

class RouteResponseV1Test extends TestCase
{
    public function testDeserializesWithStreams(): void
    {
        $raw = pack('n', 0x8018)           // key (ROUTE_RESPONSE)
            . pack('n', 1)                  // version
            . pack('N', 42)                 // correlationId
            . pack('n', 0x0001)             // response code (OK)
            . pack('N', 2)                  // 2 streams
            . pack('n', 10)                 // stream 1 name length
            . 'partition1'                   // stream 1 name
            . pack('n', 10)                 // stream 2 name length
            . 'partition2';                // stream 2 name

        $response = RouteResponseV1::fromStreamBuffer(new ReadBuffer($raw));

        $this->assertInstanceOf(RouteResponseV1::class, $response);
        $this->assertSame(42, $response->getCorrelationId());

        $streams = $response->getStreams();
        $this->assertCount(2, $streams);
        $this->assertSame('partition1', $streams[0]);
        $this->assertSame('partition2', $streams[1]);
    }

    public function testDeserializesWithEmptyStreams(): void
    {
        $raw = pack('n', 0x8018)           // key
            . pack('n', 1)                  // version
            . pack('N', 42)                 // correlationId
            . pack('n', 0x0001)             // response code (OK)
            . pack('N', 0);                 // 0 streams

        $response = RouteResponseV1::fromStreamBuffer(new ReadBuffer($raw));

        $this->assertInstanceOf(RouteResponseV1::class, $response);
        $this->assertSame(42, $response->getCorrelationId());
        $this->assertCount(0, $response->getStreams());
    }

    public function testThrowsOnNonOkResponseCode(): void
    {
        $raw = pack('n', 0x8018)           // key
            . pack('n', 1)                  // version
            . pack('N', 42)                 // correlationId
            . pack('n', 0x0002);            // response code (Stream does not exist)

        $this->expectException(\Exception::class);
        $this->expectExceptionMessage('Unexpected response code');

        RouteResponseV1::fromStreamBuffer(new ReadBuffer($raw));
    }
}
