<?php

namespace CrazyGoat\RabbitStream\Tests\Request;

use CrazyGoat\RabbitStream\Request\SubscribeRequestV1;
use CrazyGoat\RabbitStream\VO\OffsetSpec;
use PHPUnit\Framework\TestCase;

class SubscribeRequestV1Test extends TestCase
{
    public function testSerializesWithOffsetFirst(): void
    {
        $request = new SubscribeRequestV1(1, 'my-stream', OffsetSpec::first(), 10);
        $request->withCorrelationId(1);

        $bytes = $request->toStreamBuffer()->getContents();

        $expected = pack('n', 0x0007)           // key
            . pack('n', 1)                      // version
            . pack('N', 1)                      // correlationId
            . pack('C', 1)                      // subscriptionId
            . pack('n', 9) . 'my-stream'       // stream
            . pack('n', 0x0001)                // offsetSpec type (FIRST)
            . pack('n', 10);                   // credit

        $this->assertSame($expected, $bytes);
    }

    public function testSerializesWithOffsetLast(): void
    {
        $request = new SubscribeRequestV1(2, 'test-stream', OffsetSpec::last(), 5);
        $request->withCorrelationId(42);

        $bytes = $request->toStreamBuffer()->getContents();

        $expected = pack('n', 0x0007)
            . pack('n', 1)
            . pack('N', 42)
            . pack('C', 2)
            . pack('n', 11) . 'test-stream'
            . pack('n', 0x0002)                // offsetSpec type (LAST)
            . pack('n', 5);

        $this->assertSame($expected, $bytes);
    }

    public function testSerializesWithOffsetNext(): void
    {
        $request = new SubscribeRequestV1(3, 'events', OffsetSpec::next(), 100);
        $request->withCorrelationId(99);

        $bytes = $request->toStreamBuffer()->getContents();

        $expected = pack('n', 0x0007)
            . pack('n', 1)
            . pack('N', 99)
            . pack('C', 3)
            . pack('n', 6) . 'events'
            . pack('n', 0x0003)                // offsetSpec type (NEXT)
            . pack('n', 100);

        $this->assertSame($expected, $bytes);
    }

    public function testSerializesWithOffsetValue(): void
    {
        $request = new SubscribeRequestV1(4, 'logs', OffsetSpec::offset(12345), 50);
        $request->withCorrelationId(7);

        $bytes = $request->toStreamBuffer()->getContents();

        $expected = pack('n', 0x0007)
            . pack('n', 1)
            . pack('N', 7)
            . pack('C', 4)
            . pack('n', 4) . 'logs'
            . pack('n', 0x0004)                // offsetSpec type (OFFSET)
            . pack('J', 12345)                 // offset value (uint64)
            . pack('n', 50);

        $this->assertSame($expected, $bytes);
    }

    public function testSerializesWithTimestamp(): void
    {
        $request = new SubscribeRequestV1(5, 'metrics', OffsetSpec::timestamp(1700000000000), 20);
        $request->withCorrelationId(8);

        $bytes = $request->toStreamBuffer()->getContents();

        $expected = pack('n', 0x0007)
            . pack('n', 1)
            . pack('N', 8)
            . pack('C', 5)
            . pack('n', 7) . 'metrics'
            . pack('n', 0x0005)                // offsetSpec type (TIMESTAMP)
            . pack('J', 1700000000000)         // timestamp value (uint64)
            . pack('n', 20);

        $this->assertSame($expected, $bytes);
    }
}
