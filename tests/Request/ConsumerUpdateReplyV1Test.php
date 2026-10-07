<?php

declare(strict_types=1);

namespace CrazyGoat\RabbitStream\Tests\Request;

use CrazyGoat\RabbitStream\Buffer\ReadBuffer;
use CrazyGoat\RabbitStream\Contract\CorrelationInterface;
use CrazyGoat\RabbitStream\Enum\KeyEnum;
use CrazyGoat\RabbitStream\Exception\InvalidArgumentException;
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
            offsetType: OffsetSpec::TYPE_OFFSET,
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
            offset: 0,
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

    public function testSerializesNegativeTimestampAsSignedInt64(): void
    {
        // #527: the protocol defines the timestamp (type 5) value as int64, so a
        // pre-1970 timestamp (accepted by OffsetSpec::timestamp() since #392)
        // must be written as two's complement, not rejected by addUInt64().
        $reply = new ConsumerUpdateReplyV1(
            responseCode: 0x0001,
            offsetType: OffsetSpec::TYPE_TIMESTAMP,
            offset: -1000,
        );
        $reply->withCorrelationId(7);

        $bytes = $reply->toStreamBuffer()->getContents();

        $expected = pack('n', KeyEnum::CONSUMER_UPDATE_RESPONSE->value)
            . pack('n', 1)              // version
            . pack('N', 7)              // correlationId
            . pack('n', 0x0001)         // responseCode
            . pack('n', 5)              // offsetType (timestamp)
            . pack('J', -1000);         // timestamp (int64, two's complement)

        $this->assertSame($expected, $bytes);
    }

    public function testNegativeTimestampRoundTripsThroughReadBuffer(): void
    {
        $reply = new ConsumerUpdateReplyV1(
            responseCode: 0x0001,
            offsetType: OffsetSpec::TYPE_TIMESTAMP,
            offset: -1000,
        );
        $reply->withCorrelationId(7);

        $buffer = new ReadBuffer($reply->toStreamBuffer()->getContents());
        $buffer->getUint16(); // key
        $buffer->getUint16(); // version
        $buffer->getUint32(); // correlationId
        $buffer->getUint16(); // responseCode
        $this->assertSame(OffsetSpec::TYPE_TIMESTAMP, $buffer->getUint16());
        $this->assertSame(-1000, $buffer->getInt64());
    }

    public function testReplyBuiltFromOffsetSpecTimestampMatchesOffsetSpecEncoding(): void
    {
        // The path StreamConnection::handleConsumerUpdate() takes when a
        // registered handler returns OffsetSpec::timestamp(-1000): the reply's
        // value field must equal OffsetSpec's own (signed) encoding.
        $spec = OffsetSpec::timestamp(-1000);
        $reply = new ConsumerUpdateReplyV1(
            responseCode: 0x0001,
            offsetType: $spec->getType(),
            offset: $spec->getValue() ?? 0,
        );
        $reply->withCorrelationId(7);

        $contents = $reply->toStreamBuffer()->getContents();

        // Everything after key/version/correlationId/responseCode.
        $this->assertSame(
            bin2hex($spec->toStreamBuffer()->getContents()),
            bin2hex(substr($contents, 10))
        );
    }

    public function testSerializesMaxOffsetAsUint64ForOffsetType(): void
    {
        // TYPE_OFFSET keeps the uint64 encoding: the full positive range up to
        // PHP_INT_MAX must still be accepted and written unchanged.
        $reply = new ConsumerUpdateReplyV1(
            responseCode: 0x0001,
            offsetType: OffsetSpec::TYPE_OFFSET,
            offset: PHP_INT_MAX,
        );
        $reply->withCorrelationId(7);

        $bytes = $reply->toStreamBuffer()->getContents();

        $expected = pack('n', KeyEnum::CONSUMER_UPDATE_RESPONSE->value)
            . pack('n', 1)              // version
            . pack('N', 7)              // correlationId
            . pack('n', 0x0001)         // responseCode
            . pack('n', 4)              // offsetType (offset)
            . pack('J', PHP_INT_MAX);   // offset (uint64)

        $this->assertSame($expected, $bytes);
    }

    public function testRejectsNegativeOffsetForOffsetType(): void
    {
        // The offset (type 4) value is uint64, so a negative value is not a
        // valid offset and must be rejected — only the timestamp is signed.
        $reply = new ConsumerUpdateReplyV1(
            responseCode: 0x0001,
            offsetType: OffsetSpec::TYPE_OFFSET,
            offset: -1,
        );

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('out of range for uint64');

        $reply->toStreamBuffer();
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
            offset: 0,
        );

        $array = $reply->toArray();

        $this->assertNull($array['offset'], 'value-less offset types carry no wire value');
    }

    /** @dataProvider provideValuelessOffsetTypes */
    public function testRejectsPositiveOffsetForValuelessTypes(int $offsetType): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage("Offset type {$offsetType} does not accept a non-zero offset");

        new ConsumerUpdateReplyV1(responseCode: 0x0001, offsetType: $offsetType, offset: 123);
    }

    /** @dataProvider provideValuelessOffsetTypes */
    public function testRejectsNegativeOffsetForValuelessTypes(int $offsetType): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage("Offset type {$offsetType} does not accept a non-zero offset");

        new ConsumerUpdateReplyV1(responseCode: 0x0001, offsetType: $offsetType, offset: -1);
    }

    public function testRejectsInvalidOffsetType(): void
    {
        // Review round 1 finding: the constructor silently accepted offset
        // types outside 0-5, serializing a protocol violation.
        $this->expectException(\CrazyGoat\RabbitStream\Exception\InvalidArgumentException::class);
        new ConsumerUpdateReplyV1(responseCode: 0x0001, offsetType: 99, offset: 0);
    }
}
