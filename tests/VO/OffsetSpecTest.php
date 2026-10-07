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

    public function testInvalidTypeThrowsException(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Invalid offset spec type: 999');
        new OffsetSpec(999);
    }

    /**
     * #468: the protocol defines exactly six offset types — 1 first, 2 last,
     * 3 next, 4 offset, 5 timestamp, plus 0 none (allowed only in a
     * ConsumerUpdate reply). Type 6 was exposed as TYPE_INTERVAL and made the
     * broker drop the connection (`{case_clause,6}` in parse_request/1), so the
     * set is pinned here: a seventh constant must be justified against the
     * spec before it can be added.
     */
    public function testThePublicConstantSetIsExactlyTheProtocolTypes(): void
    {
        $constants = (new \ReflectionClass(OffsetSpec::class))
            ->getConstants(\ReflectionClassConstant::IS_PUBLIC);

        ksort($constants);

        $this->assertSame(
            [
                'TYPE_FIRST' => 0x0001,
                'TYPE_LAST' => 0x0002,
                'TYPE_NEXT' => 0x0003,
                'TYPE_NONE' => 0x0000,
                'TYPE_OFFSET' => 0x0004,
                'TYPE_TIMESTAMP' => 0x0005,
            ],
            $constants,
            'OffsetSpec must expose exactly the six protocol offset types; '
            . 'type 6 is not in the spec (#468).'
        );
    }

    public function testThereIsNoIntervalFactory(): void
    {
        $this->assertFalse(
            method_exists(OffsetSpec::class, 'interval'),
            'OffsetSpec::interval() serialized the out-of-spec type 6 and must stay removed (#468).'
        );
    }

    /**
     * #468: offset type 6 ("interval") is not in the protocol (OffsetType is
     * 1 first, 2 last, 3 next, 4 offset, 5 timestamp, plus 0 none only in a
     * ConsumerUpdate reply). A Subscribe carrying it makes the broker kill the
     * connection process with `{case_clause,6}` in parse_request/1, so the
     * value object must reject it instead of serializing an out-of-spec frame.
     */
    public function testTypeSixIsRejectedWithAValue(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Invalid offset spec type: 6');

        new OffsetSpec(0x0006, 1000);
    }

    public function testTypeSixIsRejectedWithoutAValue(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Invalid offset spec type: 6');

        new OffsetSpec(0x0006);
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
