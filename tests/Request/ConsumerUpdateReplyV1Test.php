<?php

declare(strict_types=1);

namespace CrazyGoat\RabbitStream\Tests\Request;

use CrazyGoat\RabbitStream\Contract\CorrelationInterface;
use CrazyGoat\RabbitStream\Enum\KeyEnum;
use CrazyGoat\RabbitStream\Request\ConsumerUpdateReplyV1;
use CrazyGoat\RabbitStream\VO\OffsetSpec;
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
            offsetType: 4,
            offset: 500,
        );
        $reply->withCorrelationId(7);

        $bytes = $reply->toStreamBuffer()->getContents();

        $expected = pack('n', KeyEnum::CONSUMER_UPDATE_RESPONSE->value)
            . pack('n', 1)              // version
            . pack('N', 7)              // correlationId
            . pack('n', 0x0001)         // responseCode
            . pack('n', 4)              // offsetType (offset)
            . pack('J', 500);           // offset (uint64)

        $this->assertSame($expected, $bytes);
    }

    /** @dataProvider provideValuelessOffsetTypes */
    public function testSerializesWithoutOffsetForValuelessOffsetTypes(int $offsetType): void
    {
        $reply = new ConsumerUpdateReplyV1(
            responseCode: 0x0001,
            offsetType: $offsetType,
            offset: 500,
        );
        $reply->withCorrelationId(7);

        $bytes = $reply->toStreamBuffer()->getContents();

        $expected = pack('n', KeyEnum::CONSUMER_UPDATE_RESPONSE->value)
            . pack('n', 1)              // version
            . pack('N', 7)              // correlationId
            . pack('n', 0x0001)         // responseCode
            . pack('n', $offsetType);   // offsetType (no offset value)

        $this->assertSame($expected, $bytes);
    }

    /** @return \Generator<string, array{int}> */
    public static function provideValuelessOffsetTypes(): \Generator
    {
        yield 'none (0)' => [0];
        yield 'first (1)' => [1];
        yield 'last (2)' => [2];
        yield 'next (3)' => [3];
    }

    public function testSerializesWithOffsetForTimestampType(): void
    {
        $reply = new ConsumerUpdateReplyV1(
            responseCode: 0x0001,
            offsetType: 5,
            offset: 1700000000000,
        );
        $reply->withCorrelationId(7);

        $bytes = $reply->toStreamBuffer()->getContents();

        $expected = pack('n', KeyEnum::CONSUMER_UPDATE_RESPONSE->value)
            . pack('n', 1)              // version
            . pack('N', 7)              // correlationId
            . pack('n', 0x0001)         // responseCode
            . pack('n', 5)              // offsetType (timestamp)
            . pack('J', 1700000000000); // offset (uint64)

        $this->assertSame($expected, $bytes);
    }

    public function testToArrayIncludesCorrelationId(): void
    {
        $reply = new ConsumerUpdateReplyV1(
            responseCode: 0x0001,
            offsetType: OffsetSpec::TYPE_OFFSET,
            offset: 200,
        );
        $reply->withCorrelationId(99);

        $array = $reply->toArray();

        $this->assertSame(99, $array['correlationId']);
        $this->assertSame(0x0001, $array['responseCode']);
        $this->assertSame(OffsetSpec::TYPE_OFFSET, $array['offsetType']);
        $this->assertSame(200, $array['offset']);
    }

    public function testToArrayOmitsOffsetForValuelessTypes(): void
    {
        $reply = new ConsumerUpdateReplyV1(
            responseCode: 0x0001,
            offsetType: OffsetSpec::TYPE_FIRST,
            offset: 200,
        );

        $array = $reply->toArray();

        $this->assertNull($array['offset'], 'value-less offset types carry no wire value');
    }

    public function testRejectsInvalidOffsetType(): void
    {
        // Review round 1 finding: the constructor silently accepted offset
        // types outside 0-5, serializing a protocol violation.
        $this->expectException(\CrazyGoat\RabbitStream\Exception\InvalidArgumentException::class);
        new ConsumerUpdateReplyV1(responseCode: 0x0001, offsetType: 99, offset: 0);
    }
}
