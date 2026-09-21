<?php

declare(strict_types=1);

namespace CrazyGoat\RabbitStream\Tests\Response;

use CrazyGoat\RabbitStream\Buffer\ReadBuffer;
use CrazyGoat\RabbitStream\Enum\ResponseCodeEnum;
use CrazyGoat\RabbitStream\Exception\ProtocolException;
use CrazyGoat\RabbitStream\Response\QueryOffsetResponseV1;
use PHPUnit\Framework\TestCase;

class QueryOffsetResponseV1Test extends TestCase
{
    public function testDeserializesCorrectly(): void
    {
        $raw = pack('n', 0x800b)    // key
            . pack('n', 1)          // version
            . pack('N', 7)          // correlationId
            . pack('n', 0x0001)     // responseCode OK
            . pack('J', 123456);    // offset (uint64 big-endian)

        $response = QueryOffsetResponseV1::fromStreamBuffer(new ReadBuffer($raw));

        $this->assertInstanceOf(QueryOffsetResponseV1::class, $response);
        $this->assertSame(7, $response->getCorrelationId());
        $this->assertSame(123456, $response->getOffset());
    }

    public function testNoOffsetParsesAsNullOffset(): void
    {
        $raw = pack('n', 0x800b)    // key
            . pack('n', 1)          // version
            . pack('N', 9)          // correlationId
            . pack('n', 0x0013)     // responseCode NO_OFFSET
            . pack('J', 0);         // offset (uint64 big-endian, always present)

        $buffer = new ReadBuffer($raw);
        $response = QueryOffsetResponseV1::fromStreamBuffer($buffer);

        $this->assertInstanceOf(QueryOffsetResponseV1::class, $response);
        $this->assertSame(9, $response->getCorrelationId());
        $this->assertNull($response->getOffset());
        // The offset field is consumed even on NO_OFFSET, so the parser ends
        // exactly at the frame boundary (#467 review R1-F2).
        $this->assertSame(strlen($raw), $buffer->getPosition());
    }

    public function testZeroOffsetIsNotConfusedWithNoOffset(): void
    {
        $raw = pack('n', 0x800b)    // key
            . pack('n', 1)          // version
            . pack('N', 11)         // correlationId
            . pack('n', 0x0001)     // responseCode OK
            . pack('J', 0);         // offset = 0 (a real, stored offset)

        $buffer = new ReadBuffer($raw);
        $response = QueryOffsetResponseV1::fromStreamBuffer($buffer);

        $this->assertInstanceOf(QueryOffsetResponseV1::class, $response);
        $this->assertSame(11, $response->getCorrelationId());
        $this->assertSame(0, $response->getOffset());
        $this->assertSame(strlen($raw), $buffer->getPosition());
    }

    public function testThrowsOnErrorResponseCode(): void
    {
        $raw = pack('n', 0x800b)
            . pack('n', 1)
            . pack('N', 1)
            . pack('n', 0x0002); // Stream does not exist

        try {
            QueryOffsetResponseV1::fromStreamBuffer(new ReadBuffer($raw));
            $this->fail('Expected ProtocolException');
        } catch (ProtocolException $e) {
            $this->assertSame(ResponseCodeEnum::STREAM_NOT_EXIST, $e->getResponseCode());
            $this->assertStringContainsString('0x0002', $e->getMessage());
        }
    }
}
