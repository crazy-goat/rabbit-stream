<?php

declare(strict_types=1);

namespace CrazyGoat\RabbitStream\Tests\Response;

use CrazyGoat\RabbitStream\Buffer\ReadBuffer;
use CrazyGoat\RabbitStream\Response\StreamStatsResponseV1;
use CrazyGoat\RabbitStream\VO\Statistic;
use PHPUnit\Framework\TestCase;

class StreamStatsResponseV1Test extends TestCase
{
    public function testDeserializesWithStats(): void
    {
        $stat1 = new Statistic('message_count', 1000);
        $stat2 = new Statistic('byte_count', 976562);

        $bytes1 = $stat1->toStreamBuffer()->getContents();
        $bytes2 = $stat2->toStreamBuffer()->getContents();

        $raw = pack('n', 0x801c)           // key (STREAM_STATS_RESPONSE)
            . pack('n', 1)                  // version
            . pack('N', 42)                 // correlationId
            . pack('n', 0x0001)             // response code (OK)
            . pack('N', 2)                  // 2 stats
            . $bytes1                       // first stat
            . $bytes2;                      // second stat

        $response = StreamStatsResponseV1::fromStreamBuffer(new ReadBuffer($raw));

        $this->assertInstanceOf(StreamStatsResponseV1::class, $response);
        $this->assertSame(42, $response->getCorrelationId());

        $stats = $response->getStats();
        $this->assertCount(2, $stats);
        $this->assertSame('message_count', $stats[0]->getKey());
        $this->assertSame(1000, $stats[0]->getValue());
        $this->assertSame('byte_count', $stats[1]->getKey());
        $this->assertSame(976562, $stats[1]->getValue());
    }

    public function testDeserializesWithEmptyStats(): void
    {
        $raw = pack('n', 0x801c)           // key
            . pack('n', 1)                  // version
            . pack('N', 42)                 // correlationId
            . pack('n', 0x0001)             // response code (OK)
            . pack('N', 0);                 // 0 stats

        $response = StreamStatsResponseV1::fromStreamBuffer(new ReadBuffer($raw));

        $this->assertInstanceOf(StreamStatsResponseV1::class, $response);
        $this->assertSame(42, $response->getCorrelationId());
        $this->assertCount(0, $response->getStats());
    }

    public function testThrowsOnNonOkResponseCode(): void
    {
        $raw = pack('n', 0x801c)           // key
            . pack('n', 1)                  // version
            . pack('N', 42)                 // correlationId
            . pack('n', 0x0002);            // response code (Stream does not exist)

        $this->expectException(\Exception::class);
        $this->expectExceptionMessage('StreamStats request failed with response code: 2');

        StreamStatsResponseV1::fromStreamBuffer(new ReadBuffer($raw));
    }
}
