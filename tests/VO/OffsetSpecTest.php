<?php

declare(strict_types=1);

namespace CrazyGoat\RabbitStream\Tests\VO;

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

    public function testIntervalHasCorrectTypeAndValue(): void
    {
        $spec = OffsetSpec::interval(3600);
        $this->assertSame(OffsetSpec::TYPE_INTERVAL, $spec->getType());
        $this->assertSame(3600, $spec->getValue());
    }

    public function testInvalidTypeThrowsException(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Invalid offset spec type: 999');
        new OffsetSpec(999);
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
}
