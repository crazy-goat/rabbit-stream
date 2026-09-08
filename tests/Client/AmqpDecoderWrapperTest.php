<?php

declare(strict_types=1);

namespace CrazyGoat\RabbitStream\Tests\Client;

use CrazyGoat\RabbitStream\Client\AmqpDecoder;
use CrazyGoat\RabbitStream\Exception\DeserializationException;
use PHPUnit\Framework\TestCase;

/**
 * Direct contract tests for the decodeValue() BC wrapper (public tuple API).
 */
class AmqpDecoderWrapperTest extends TestCase
{
    public function testWrapperReturnsTupleOfValueAndNewPosition(): void
    {
        // 0xa1 0x03 "abc" — symbol string, 5 bytes consumed
        $result = AmqpDecoder::decodeValue("\xa1\x03abc", 0);

        self::assertSame('abc', $result[0]);
        self::assertSame(5, $result[1]);
        self::assertCount(2, $result);
    }

    public function testWrapperStartsAtGivenPosition(): void
    {
        $data = "\x41\x41";

        [$value, $pos] = AmqpDecoder::decodeValue($data, 1);

        self::assertTrue($value);
        self::assertSame(2, $pos);
    }

    public function testWrapperLeavesPositionUnchangedOnException(): void
    {
        $position = 2;

        try {
            AmqpDecoder::decodeValue("\x40", $position);
            self::fail('Expected DeserializationException');
        } catch (DeserializationException) {
            // expected: truncated data past position 2
        }

        self::assertSame(2, $position);
    }
}
