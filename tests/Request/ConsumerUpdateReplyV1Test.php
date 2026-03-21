<?php

declare(strict_types=1);

namespace CrazyGoat\RabbitStream\Tests\Request;

use CrazyGoat\RabbitStream\Contract\CorrelationInterface;
use CrazyGoat\RabbitStream\Enum\KeyEnum;
use CrazyGoat\RabbitStream\Request\ConsumerUpdateReplyV1;
use PHPUnit\Framework\TestCase;

class ConsumerUpdateReplyV1Test extends TestCase
{
    public function testImplementsCorrelationInterface(): void
    {
        $reply = new ConsumerUpdateReplyV1(
            responseCode: 0x0001,
            offsetType: 1,
            offset: 0,
        );

        $this->assertInstanceOf(CorrelationInterface::class, $reply);
    }

    public function testCorrelationIdCanBeSetViaWithCorrelationId(): void
    {
        $reply = new ConsumerUpdateReplyV1(
            responseCode: 0x0001,
            offsetType: 1,
            offset: 100,
        );
        $reply->withCorrelationId(42);

        $this->assertSame(42, $reply->getCorrelationId());
    }

    public function testSerializesCorrectly(): void
    {
        $reply = new ConsumerUpdateReplyV1(
            responseCode: 0x0001,
            offsetType: 1,
            offset: 500,
        );
        $reply->withCorrelationId(7);

        $bytes = $reply->toStreamBuffer()->getContents();

        $expected = pack('n', KeyEnum::CONSUMER_UPDATE_RESPONSE->value)
            . pack('n', 1)              // version
            . pack('N', 7)              // correlationId
            . pack('n', 0x0001)         // responseCode
            . pack('n', 1)              // offsetType
            . pack('J', 500);           // offset (uint64)

        $this->assertSame($expected, $bytes);
    }

    public function testToArrayIncludesCorrelationId(): void
    {
        $reply = new ConsumerUpdateReplyV1(
            responseCode: 0x0001,
            offsetType: 1,
            offset: 200,
        );
        $reply->withCorrelationId(99);

        $array = $reply->toArray();

        $this->assertSame(99, $array['correlationId']);
        $this->assertSame(0x0001, $array['responseCode']);
        $this->assertSame(1, $array['offsetType']);
        $this->assertSame(200, $array['offset']);
    }
}
