<?php

declare(strict_types=1);

namespace CrazyGoat\RabbitStream\Tests\Buffer;

use CrazyGoat\RabbitStream\Buffer\WriteBuffer;
use CrazyGoat\RabbitStream\VO\PublishedMessage;
use PHPUnit\Framework\TestCase;

class WriteBufferTest extends TestCase
{
    public function testAddUInt8(): void
    {
        $buf = (new WriteBuffer())->addUInt8(0xFF);
        $this->assertSame("\xFF", $buf->getContents());
    }

    public function testAddUInt16(): void
    {
        $buf = (new WriteBuffer())->addUInt16(0x0011);
        $this->assertSame("\x00\x11", $buf->getContents());
    }

    public function testAddUInt32(): void
    {
        $buf = (new WriteBuffer())->addUInt32(0x00000001);
        $this->assertSame("\x00\x00\x00\x01", $buf->getContents());
    }

    public function testAddUInt64(): void
    {
        $buf = (new WriteBuffer())->addUInt64(1);
        $this->assertSame("\x00\x00\x00\x00\x00\x00\x00\x01", $buf->getContents());
    }

    public function testAddInt8(): void
    {
        $buf = (new WriteBuffer())->addInt8(-1);
        $this->assertSame("\xFF", $buf->getContents());
    }

    public function testAddInt16(): void
    {
        $buf = (new WriteBuffer())->addInt16(-1);
        $this->assertSame("\xFF\xFF", $buf->getContents());
    }

    public function testAddInt16WithMinValue(): void
    {
        $buf = (new WriteBuffer())->addInt16(-32768);
        $this->assertSame("\x80\x00", $buf->getContents());
    }

    public function testAddInt16WithMaxValue(): void
    {
        $buf = (new WriteBuffer())->addInt16(32767);
        $this->assertSame("\x7F\xFF", $buf->getContents());
    }

    public function testAddInt16WithNegativeBoundary(): void
    {
        $buf = (new WriteBuffer())->addInt16(-256);
        $this->assertSame("\xFF\x00", $buf->getContents());
    }

    public function testAddInt32(): void
    {
        $buf = (new WriteBuffer())->addInt32(-1);
        $this->assertSame("\xFF\xFF\xFF\xFF", $buf->getContents());
    }

    public function testAddInt32WithMinValue(): void
    {
        $buf = (new WriteBuffer())->addInt32(-2147483648);
        $this->assertSame("\x80\x00\x00\x00", $buf->getContents());
    }

    public function testAddInt32WithMaxValue(): void
    {
        $buf = (new WriteBuffer())->addInt32(2147483647);
        $this->assertSame("\x7F\xFF\xFF\xFF", $buf->getContents());
    }

    public function testAddInt64(): void
    {
        $buf = (new WriteBuffer())->addInt64(-1);
        $this->assertSame("\xFF\xFF\xFF\xFF\xFF\xFF\xFF\xFF", $buf->getContents());
    }

    public function testAddInt64WithLargeNegative(): void
    {
        $buf = (new WriteBuffer())->addInt64(PHP_INT_MIN);
        $this->assertSame("\x80\x00\x00\x00\x00\x00\x00\x00", $buf->getContents());
    }

    public function testAddString(): void
    {
        $buf = (new WriteBuffer())->addString('hi');
        $this->assertSame("\x00\x02hi", $buf->getContents());
    }

    public function testAddStringWithUtf8(): void
    {
        $buf = (new WriteBuffer())->addString('héllo');
        $this->assertSame("\x00\x06héllo", $buf->getContents());
    }

    public function testAddStringWithMultiByteCharacters(): void
    {
        $buf = (new WriteBuffer())->addString('日本語');
        $this->assertSame("\x00\x09日本語", $buf->getContents());
    }

    public function testAddStringWithEmoji(): void
    {
        $buf = (new WriteBuffer())->addString('Hello 👋 World 🌍');
        $this->assertSame("\x00\x15Hello 👋 World 🌍", $buf->getContents());
    }

    public function testAddEmptyString(): void
    {
        $buf = (new WriteBuffer())->addString('');
        $this->assertSame("\x00\x00", $buf->getContents());
    }

    public function testAddInvalidUtf8StringThrows(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('String must be valid UTF-8');

        $invalidUtf8 = "\x80\x81";
        (new WriteBuffer())->addString($invalidUtf8);
    }

    public function testAddStringAtMaxLength(): void
    {
        $maxLengthString = str_repeat('a', 32767);
        $buf = (new WriteBuffer())->addString($maxLengthString);
        $contents = $buf->getContents();

        $this->assertSame("\x7F\xFF", substr($contents, 0, 2));
        $this->assertSame($maxLengthString, substr($contents, 2));
    }

    public function testAddStringExceedingMaxLengthThrows(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('out of range for string length');

        $tooLongString = str_repeat('a', 32768);
        (new WriteBuffer())->addString($tooLongString);
    }

    public function testAddNullString(): void
    {
        $buf = (new WriteBuffer())->addString(null);
        $this->assertSame("\xFF\xFF", $buf->getContents());
    }

    public function testAddBytes(): void
    {
        $buf = (new WriteBuffer())->addBytes('AB');
        $this->assertSame("\x00\x00\x00\x02AB", $buf->getContents());
    }

    public function testAddNullBytes(): void
    {
        $buf = (new WriteBuffer())->addBytes(null);
        $this->assertSame("\xFF\xFF\xFF\xFF", $buf->getContents());
    }

    public function testAddRaw(): void
    {
        $buf = (new WriteBuffer())->addRaw("\xDE\xAD");
        $this->assertSame("\xDE\xAD", $buf->getContents());
    }

    public function testUInt16OutOfRangeThrows(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        (new WriteBuffer())->addUInt16(0x10000);
    }

    public function testUInt8OutOfRangeThrows(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        (new WriteBuffer())->addUInt8(256);
    }

    // addInt64 boundary tests
    public function testAddInt64WithZero(): void
    {
        $buf = (new WriteBuffer())->addInt64(0);
        $this->assertSame("\x00\x00\x00\x00\x00\x00\x00\x00", $buf->getContents());
    }

    public function testAddInt64WithPositiveValue(): void
    {
        $buf = (new WriteBuffer())->addInt64(1);
        $this->assertSame("\x00\x00\x00\x00\x00\x00\x00\x01", $buf->getContents());
    }

    public function testAddInt64WithMaxValue(): void
    {
        $buf = (new WriteBuffer())->addInt64(PHP_INT_MAX);
        $this->assertSame(pack('J', PHP_INT_MAX), $buf->getContents());
    }

    // addArray tests
    public function testAddArrayWithZeroItems(): void
    {
        $buf = (new WriteBuffer())->addArray();
        $this->assertSame("\x00\x00\x00\x00", $buf->getContents());
    }

    public function testAddArrayWithSingleItem(): void
    {
        $item = new PublishedMessage(1, 'test');
        $buf = (new WriteBuffer())->addArray($item);
        $expected = "\x00\x00\x00\x01"                      // count: 1
            . "\x00\x00\x00\x00\x00\x00\x00\x01"            // publishingId: 1 (uint64)
            . "\x00\x00\x00\x04test";                       // message length + content
        $this->assertSame($expected, $buf->getContents());
    }

    public function testAddArrayWithMultipleItems(): void
    {
        $item1 = new PublishedMessage(1, 'foo');
        $item2 = new PublishedMessage(2, 'bar');
        $buf = (new WriteBuffer())->addArray($item1, $item2);
        $expected = "\x00\x00\x00\x02"                      // count: 2
            . "\x00\x00\x00\x00\x00\x00\x00\x01"            // publishingId: 1
            . "\x00\x00\x00\x03foo"                         // message: foo
            . "\x00\x00\x00\x00\x00\x00\x00\x02"            // publishingId: 2
            . "\x00\x00\x00\x03bar";                        // message: bar
        $this->assertSame($expected, $buf->getContents());
    }

    // addStringArray tests
    public function testAddStringArrayWithZeroStrings(): void
    {
        $buf = (new WriteBuffer())->addStringArray();
        $this->assertSame("\x00\x00\x00\x00", $buf->getContents());
    }

    public function testAddStringArrayWithSingleString(): void
    {
        $buf = (new WriteBuffer())->addStringArray('foo');
        $expected = "\x00\x00\x00\x01"                      // count: 1
            . "\x00\x03foo";                                 // string length + content
        $this->assertSame($expected, $buf->getContents());
    }

    public function testAddStringArrayWithMultipleStrings(): void
    {
        $buf = (new WriteBuffer())->addStringArray('foo', 'bar');
        $expected = "\x00\x00\x00\x02"                      // count: 2
            . "\x00\x03foo"                                  // string 1
            . "\x00\x03bar";                                 // string 2
        $this->assertSame($expected, $buf->getContents());
    }

    // Fluent chaining test
    public function testFluentChainingProducesCorrectOutput(): void
    {
        $buf = (new WriteBuffer())
            ->addUInt16(0x0001)
            ->addUInt32(1)
            ->addString('test')
            ->addInt8(-1);

        $expected = "\x00\x01"                                // uint16: 1
            . "\x00\x00\x00\x01"                              // uint32: 1
            . "\x00\x04test"                                  // string: test
            . "\xFF";                                         // int8: -1
        $this->assertSame($expected, $buf->getContents());
    }

    // Boundary value tests
    public function testAddUInt32WithZero(): void
    {
        $buf = (new WriteBuffer())->addUInt32(0);
        $this->assertSame("\x00\x00\x00\x00", $buf->getContents());
    }

    public function testAddUInt32WithMaxValue(): void
    {
        $buf = (new WriteBuffer())->addUInt32(0xFFFFFFFF);
        $this->assertSame("\xFF\xFF\xFF\xFF", $buf->getContents());
    }

    public function testAddUInt64WithZero(): void
    {
        $buf = (new WriteBuffer())->addUInt64(0);
        $this->assertSame("\x00\x00\x00\x00\x00\x00\x00\x00", $buf->getContents());
    }

    public function testAddUInt64WithMaxValue(): void
    {
        $buf = (new WriteBuffer())->addUInt64(PHP_INT_MAX);
        $this->assertSame(pack('J', PHP_INT_MAX), $buf->getContents());
    }

    public function testAddInt8WithMinValue(): void
    {
        $buf = (new WriteBuffer())->addInt8(-128);
        $this->assertSame("\x80", $buf->getContents());
    }

    public function testAddInt8WithMaxValue(): void
    {
        $buf = (new WriteBuffer())->addInt8(127);
        $this->assertSame("\x7F", $buf->getContents());
    }

    // Out-of-range validation tests
    public function testAddInt8OutOfRangeNegativeThrows(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('out of range for int8');
        (new WriteBuffer())->addInt8(-129);
    }

    public function testAddInt8OutOfRangePositiveThrows(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('out of range for int8');
        (new WriteBuffer())->addInt8(128);
    }

    public function testAddInt16OutOfRangeNegativeThrows(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('out of range for int16');
        (new WriteBuffer())->addInt16(-32769);
    }

    public function testAddInt16OutOfRangePositiveThrows(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('out of range for int16');
        (new WriteBuffer())->addInt16(32768);
    }

    public function testAddInt32OutOfRangeNegativeThrows(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('out of range for int32');
        (new WriteBuffer())->addInt32(-2147483649);
    }

    public function testAddInt32OutOfRangePositiveThrows(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('out of range for int32');
        (new WriteBuffer())->addInt32(2147483648);
    }

    public function testAddUInt32OutOfRangeThrows(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('out of range for uint32');
        (new WriteBuffer())->addUInt32(4294967296);
    }
}
