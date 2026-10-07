<?php

declare(strict_types=1);

namespace CrazyGoat\RabbitStream\Tests\VO;

use CrazyGoat\RabbitStream\Buffer\ReadBuffer;
use CrazyGoat\RabbitStream\Exception\InvalidArgumentException;
use CrazyGoat\RabbitStream\VO\OffsetSpec;
use PHPUnit\Framework\TestCase;

class OffsetSpecTest extends TestCase
{
    public function testFirstHasCorrectTypeAndNullValue(): void
    {
        $spec = OffsetSpec::first();
        $this->assertSame(OffsetSpec::TYPE_FIRST, $spec->getType());
        $this->assertNull($spec->getValue());
    }

    public function testLastHasCorrectTypeAndNullValue(): void
    {
        $spec = OffsetSpec::last();
        $this->assertSame(OffsetSpec::TYPE_LAST, $spec->getType());
        $this->assertNull($spec->getValue());
    }

    public function testNextHasCorrectTypeAndNullValue(): void
    {
        $spec = OffsetSpec::next();
        $this->assertSame(OffsetSpec::TYPE_NEXT, $spec->getType());
        $this->assertNull($spec->getValue());
    }

    public function testOffsetHasCorrectTypeAndValue(): void
    {
        $spec = OffsetSpec::offset(42);
        $this->assertSame(OffsetSpec::TYPE_OFFSET, $spec->getType());
        $this->assertSame(42, $spec->getValue());
    }

    public function testTimestampHasCorrectTypeAndValue(): void
    {
        $ts = 1700000000000;
        $spec = OffsetSpec::timestamp($ts);
        $this->assertSame(OffsetSpec::TYPE_TIMESTAMP, $spec->getType());
        $this->assertSame($ts, $spec->getValue());
    }

    public function testRemovedIntervalTypeIsRejected(): void
    {
        // Offset type 0x0006 ("interval") was removed from the protocol surface
        // in #468: it is not defined by the RabbitMQ Stream protocol and a
        // Subscribe carrying it crashes the broker's connection process.
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Invalid offset spec type: 6');
        new OffsetSpec(0x0006, 3600);
    }

    public function testIntervalTypeIsNoLongerExposed(): void
    {
        $constants = (new \ReflectionClass(OffsetSpec::class))->getConstants();
        $this->assertArrayNotHasKey('TYPE_INTERVAL', $constants);
        $this->assertNotContains(0x0006, array_values($constants), 'Type 0x0006 must not be a public constant');
    }

    public function testInvalidTypeThrowsException(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Invalid offset spec type: 999');
        new OffsetSpec(999);
    }

    /**
     * @return array<string, array{int}>
     */
    public static function valueLessTypeProvider(): array
    {
        return [
            'none' => [OffsetSpec::TYPE_NONE],
            'first' => [OffsetSpec::TYPE_FIRST],
            'last' => [OffsetSpec::TYPE_LAST],
            'next' => [OffsetSpec::TYPE_NEXT],
        ];
    }

    /**
     * @dataProvider valueLessTypeProvider
     */
    public function testValueLessTypeRejectsNonNullValue(int $type): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage("Offset spec type $type does not accept a value");

        new OffsetSpec($type, 123);
    }

    /**
     * @dataProvider valueLessTypeProvider
     */
    public function testValueLessTypeRejectsZeroValue(int $type): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage("Offset spec type $type does not accept a value");

        new OffsetSpec($type, 0);
    }

    /**
     * @dataProvider valueLessTypeProvider
     */
    public function testValueLessTypeSerializesTypeFieldOnly(int $type): void
    {
        $binary = (new OffsetSpec($type))->toStreamBuffer()->getContents();

        $this->assertSame(2, strlen($binary));
        $this->assertSame(pack('n', $type), $binary);
    }

    public function testTimestampSerializesTypeAndInt64Value(): void
    {
        $binary = OffsetSpec::timestamp(3600)->toStreamBuffer()->getContents();

        $this->assertSame(10, strlen($binary));

        $expected = pack('n', OffsetSpec::TYPE_TIMESTAMP) . pack('J', 3600);
        $this->assertSame($expected, $binary);
    }

    public function testToStreamBufferWithValue(): void
    {
        $spec = OffsetSpec::offset(42);
        $binary = $spec->toStreamBuffer()->getContents();

        // Verify: uint16 type + uint64 value = 10 bytes
        $this->assertSame(10, strlen($binary));

        $expected = pack('n', 0x0004)      // type (OFFSET)
            . pack('J', 42);               // value (uint64)
        $this->assertSame($expected, $binary);
    }

    public function testToStreamBufferWithoutValue(): void
    {
        $spec = OffsetSpec::first();
        $binary = $spec->toStreamBuffer()->getContents();

        // Verify: uint16 type only = 2 bytes
        $this->assertSame(2, strlen($binary));

        $expected = pack('n', 0x0001);      // type (FIRST) only
        $this->assertSame($expected, $binary);
    }

    public function testToArray(): void
    {
        $spec = OffsetSpec::offset(42);
        $this->assertSame(['type' => 4, 'value' => 42], $spec->toArray());
    }

    public function testToArrayWithNullValue(): void
    {
        $spec = OffsetSpec::first();
        $this->assertSame(['type' => 1, 'value' => null], $spec->toArray());
    }

    public function testToArrayForNone(): void
    {
        $spec = OffsetSpec::none();
        $this->assertSame(['type' => OffsetSpec::TYPE_NONE, 'value' => null], $spec->toArray());
    }

    public function testNegativeTimestampUsesSigned64BitEncoding(): void
    {
        $binary = OffsetSpec::timestamp(-1000)->toStreamBuffer()->getContents();

        // Two's complement of -1000 as a big-endian 64-bit value.
        $expected = pack('n', OffsetSpec::TYPE_TIMESTAMP) . pack('J', -1000);
        $this->assertSame($expected, $binary);
    }

    public function testNegativeTimestampRoundTrips(): void
    {
        $binary = OffsetSpec::timestamp(-1000)->toStreamBuffer()->getContents();

        $buffer = new ReadBuffer($binary);
        $this->assertSame(OffsetSpec::TYPE_TIMESTAMP, $buffer->getUint16());
        $this->assertSame(-1000, $buffer->getInt64());
    }

    public function testOffsetWithoutValueThrows(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Offset spec type 4 requires a value');

        new OffsetSpec(OffsetSpec::TYPE_OFFSET);
    }

    public function testTimestampWithoutValueThrows(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Offset spec type 5 requires a value');

        new OffsetSpec(OffsetSpec::TYPE_TIMESTAMP);
    }
}
