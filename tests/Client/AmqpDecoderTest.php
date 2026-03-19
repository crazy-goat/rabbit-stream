<?php

namespace CrazyGoat\RabbitStream\Tests\Client;

use CrazyGoat\RabbitStream\Client\AmqpDecoder;
use PHPUnit\Framework\TestCase;

class AmqpDecoderTest extends TestCase
{
    // ========== Fixed-width types ==========

    public function testDecodeNull(): void
    {
        [$value, $pos] = AmqpDecoder::decodeValue("\x40", 0);
        $this->assertNull($value);
        $this->assertSame(1, $pos);
    }

    public function testDecodeBooleanTrue(): void
    {
        [$value, $pos] = AmqpDecoder::decodeValue("\x41", 0);
        $this->assertTrue($value);
        $this->assertSame(1, $pos);
    }

    public function testDecodeBooleanFalse(): void
    {
        [$value, $pos] = AmqpDecoder::decodeValue("\x42", 0);
        $this->assertFalse($value);
        $this->assertSame(1, $pos);
    }

    public function testDecodeUintZero(): void
    {
        [$value, $pos] = AmqpDecoder::decodeValue("\x43", 0);
        $this->assertSame(0, $value);
        $this->assertSame(1, $pos);
    }

    public function testDecodeUlongZero(): void
    {
        [$value, $pos] = AmqpDecoder::decodeValue("\x44", 0);
        $this->assertSame(0, $value);
        $this->assertSame(1, $pos);
    }

    public function testDecodeUbyte(): void
    {
        [$value, $pos] = AmqpDecoder::decodeValue("\x50\xff", 0);
        $this->assertSame(255, $value);
        $this->assertSame(2, $pos);
    }

    public function testDecodeByte(): void
    {
        [$value, $pos] = AmqpDecoder::decodeValue("\x51\xff", 0);
        $this->assertSame(-1, $value);
        $this->assertSame(2, $pos);
    }

    public function testDecodeSmalluint(): void
    {
        [$value, $pos] = AmqpDecoder::decodeValue("\x52\x7f", 0);
        $this->assertSame(127, $value);
        $this->assertSame(2, $pos);
    }

    public function testDecodeSmallulong(): void
    {
        [$value, $pos] = AmqpDecoder::decodeValue("\x53\x7f", 0);
        $this->assertSame(127, $value);
        $this->assertSame(2, $pos);
    }

    public function testDecodeSmallint(): void
    {
        [$value, $pos] = AmqpDecoder::decodeValue("\x54\xff", 0);
        $this->assertSame(-1, $value);
        $this->assertSame(2, $pos);
    }

    public function testDecodeSmalllong(): void
    {
        [$value, $pos] = AmqpDecoder::decodeValue("\x55\xff", 0);
        $this->assertSame(-1, $value);
        $this->assertSame(2, $pos);
    }

    public function testDecodeBoolean(): void
    {
        [$value, $pos] = AmqpDecoder::decodeValue("\x56\x01", 0);
        $this->assertTrue($value);
        $this->assertSame(2, $pos);

        [$value, $pos] = AmqpDecoder::decodeValue("\x56\x00", 0);
        $this->assertFalse($value);
        $this->assertSame(2, $pos);
    }

    public function testDecodeUshort(): void
    {
        [$value, $pos] = AmqpDecoder::decodeValue("\x60\x01\x02", 0);
        $this->assertSame(0x0102, $value);
        $this->assertSame(3, $pos);
    }

    public function testDecodeShort(): void
    {
        [$value, $pos] = AmqpDecoder::decodeValue("\x61\xff\xfe", 0);
        $this->assertSame(-2, $value);
        $this->assertSame(3, $pos);
    }

    public function testDecodeUint(): void
    {
        [$value, $pos] = AmqpDecoder::decodeValue("\x70\x01\x02\x03\x04", 0);
        $this->assertSame(0x01020304, $value);
        $this->assertSame(5, $pos);
    }

    public function testDecodeInt(): void
    {
        [$value, $pos] = AmqpDecoder::decodeValue("\x71\xff\xff\xff\xfe", 0);
        $this->assertSame(-2, $value);
        $this->assertSame(5, $pos);
    }

    public function testDecodeFloat(): void
    {
        [$value, $pos] = AmqpDecoder::decodeValue("\x72\x3f\x80\x00\x00", 0);
        $this->assertEqualsWithDelta(1.0, $value, 0.0001);
        $this->assertSame(5, $pos);
    }

    public function testDecodeUlong(): void
    {
        [$value, $pos] = AmqpDecoder::decodeValue("\x80\x01\x02\x03\x04\x05\x06\x07\x08", 0);
        $this->assertSame(0x0102030405060708, $value);
        $this->assertSame(9, $pos);
    }

    public function testDecodeLong(): void
    {
        [$value, $pos] = AmqpDecoder::decodeValue("\x81\xff\xff\xff\xff\xff\xff\xff\xfe", 0);
        $this->assertSame(-2, $value);
        $this->assertSame(9, $pos);
    }

    public function testDecodeDouble(): void
    {
        [$value, $pos] = AmqpDecoder::decodeValue("\x82\x3f\xf0\x00\x00\x00\x00\x00\x00", 0);
        $this->assertEqualsWithDelta(1.0, $value, 0.0001);
        $this->assertSame(9, $pos);
    }

    public function testDecodeTimestamp(): void
    {
        // Timestamp: milliseconds since Unix epoch
        // 1700000000000 ms = 2023-11-14 22:13:20 UTC
        // Pack as big-endian int64: 0x0000018BCFE56800
        [$value, $pos] = AmqpDecoder::decodeValue("\x83\x00\x00\x01\x8b\xcf\xe5\x68\x00", 0);
        $this->assertSame(1700000000000, $value);
        $this->assertSame(9, $pos);
    }

    public function testDecodeUuid(): void
    {
        // UUID: 16 bytes
        $uuidBytes = "\x01\x02\x03\x04\x05\x06\x07\x08\x09\x0a\x0b\x0c\x0d\x0e\x0f\x10";
        [$value, $pos] = AmqpDecoder::decodeValue("\x98" . $uuidBytes, 0);
        $this->assertSame('01020304-0506-0708-090a-0b0c0d0e0f10', $value);
        $this->assertSame(17, $pos);
    }

    // ========== Variable-width types (8-bit length) ==========

    public function testDecodeVbin8(): void
    {
        [$value, $pos] = AmqpDecoder::decodeValue("\xa0\x05hello", 0);
        $this->assertSame('hello', $value);
        $this->assertSame(7, $pos);
    }

    public function testDecodeStr8Utf8(): void
    {
        [$value, $pos] = AmqpDecoder::decodeValue("\xa1\x05hello", 0);
        $this->assertSame('hello', $value);
        $this->assertSame(7, $pos);
    }

    public function testDecodeSym8(): void
    {
        [$value, $pos] = AmqpDecoder::decodeValue("\xa3\x05hello", 0);
        $this->assertSame('hello', $value);
        $this->assertSame(7, $pos);
    }

    // ========== Variable-width types (32-bit length) ==========

    public function testDecodeVbin32(): void
    {
        [$value, $pos] = AmqpDecoder::decodeValue("\xb0\x00\x00\x00\x05hello", 0);
        $this->assertSame('hello', $value);
        $this->assertSame(10, $pos); // 1 (type) + 4 (length) + 5 (data)
    }

    public function testDecodeStr32Utf8(): void
    {
        [$value, $pos] = AmqpDecoder::decodeValue("\xb1\x00\x00\x00\x05hello", 0);
        $this->assertSame('hello', $value);
        $this->assertSame(10, $pos); // 1 (type) + 4 (length) + 5 (data)
    }

    public function testDecodeSym32(): void
    {
        [$value, $pos] = AmqpDecoder::decodeValue("\xb3\x00\x00\x00\x05hello", 0);
        $this->assertSame('hello', $value);
        $this->assertSame(10, $pos); // 1 (type) + 4 (length) + 5 (data)
    }

    // ========== Compound types ==========

    public function testDecodeList8(): void
    {
        // list8: size (1 byte) + count (1 byte) + items
        // size=5, count=2, items: 0x52 0x01 (smalluint 1), 0x52 0x02 (smalluint 2)
        [$value, $pos] = AmqpDecoder::decodeValue("\xc0\x05\x02\x52\x01\x52\x02", 0);
        $this->assertSame([1, 2], $value);
        $this->assertSame(7, $pos);
    }

    public function testDecodeList8WithNestedValues(): void
    {
        // list8 with string and int
        // size=9, count=2, items: str8 "hi" (0xa1 0x02 "hi" = 4 bytes), smalluint 42 (0x52 0x2a = 2 bytes)
        // Total: 1 (format) + 1 (size) + 1 (count) + 4 + 2 = 9 bytes
        [$value, $pos] = AmqpDecoder::decodeValue("\xc0\x09\x02\xa1\x02hi\x52\x2a", 0);
        $this->assertSame(['hi', 42], $value);
        $this->assertSame(9, $pos);
    }

    public function testDecodeList32(): void
    {
        // list32: size (4 bytes) + count (4 bytes) + items
        [$value, $pos] = AmqpDecoder::decodeValue("\xd0\x00\x00\x00\x06\x00\x00\x00\x02\x52\x01\x52\x02", 0);
        $this->assertSame([1, 2], $value);
        $this->assertSame(13, $pos);
    }

    public function testDecodeMap8(): void
    {
        // map8: size (1 byte) + count (1 byte) + key-value pairs
        // size=6, count=2 (1 pair), key: str8 "k" (0xa1 0x01 "k"), value: smalluint 1 (0x52 0x01)
        [$value, $pos] = AmqpDecoder::decodeValue("\xc1\x06\x02\xa1\x01k\x52\x01", 0);
        $this->assertSame(['k' => 1], $value);
        $this->assertSame(8, $pos);
    }

    public function testDecodeMap8WithMultiplePairs(): void
    {
        // map8 with 2 pairs
        // size=10, count=4 (2 pairs)
        // pair1: str8 "a" (0xa1 0x01 "a" = 3 bytes), smalluint 1 (0x52 0x01 = 2 bytes)
        // pair2: str8 "b" (0xa1 0x01 "b" = 3 bytes), smalluint 2 (0x52 0x02 = 2 bytes)
        // Total: 1 (format) + 1 (size) + 1 (count) + 3 + 2 + 3 + 2 = 13 bytes
        [$value, $pos] = AmqpDecoder::decodeValue("\xc1\x0a\x04\xa1\x01a\x52\x01\xa1\x01b\x52\x02", 0);
        $this->assertSame(['a' => 1, 'b' => 2], $value);
        $this->assertSame(13, $pos);
    }

    public function testDecodeMap32(): void
    {
        // map32: size (4 bytes) + count (4 bytes) + items
        // size=9 (4 bytes count + 5 bytes items: str8 "k" (3 bytes) + smalluint 1 (2 bytes))
        // count=2 (1 pair = 2 elements)
        // Total: 1 (format) + 4 (size) + 4 (count) + 3 + 2 = 14 bytes
        [$value, $pos] = AmqpDecoder::decodeValue("\xd1\x00\x00\x00\x09\x00\x00\x00\x02\xa1\x01k\x52\x01", 0);
        $this->assertSame(['k' => 1], $value);
        $this->assertSame(14, $pos);
    }

    // ========== Described types ==========

    public function testDecodeDescribedType(): void
    {
        // Described type: 0x00 + descriptor + value
        // Descriptor: smallulong 0x73 (0x53 0x73) = Properties section
        // Value: list8 with 1 item (smalluint 42)
        [$value, $pos] = AmqpDecoder::decodeValue("\x00\x53\x73\xc0\x03\x01\x52\x2a", 0);
        $this->assertIsArray($value);
        $this->assertSame(0x73, $value['descriptor']);
        $this->assertSame([42], $value['value']);
        $this->assertSame(8, $pos);
    }

    public function testDecodeDescribedTypeWithStringDescriptor(): void
    {
        // Described type with string descriptor
        [$value, $pos] = AmqpDecoder::decodeValue("\x00\xa1\x04test\x52\x2a", 0);
        $this->assertIsArray($value);
        $this->assertSame('test', $value['descriptor']);
        $this->assertSame(42, $value['value']);
        $this->assertSame(9, $pos); // 1 (marker) + 1 (str8 type) + 1 (len) + 4 (str) + 1 (smalluint type) + 1 (value)
    }

    // ========== Error cases ==========

    public function testDecodeUnsupportedTypeThrowsException(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Unsupported AMQP type: 0x99');

        AmqpDecoder::decodeValue("\x99", 0);
    }

    public function testDecodeUnexpectedEndOfData(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Unexpected end of data');

        AmqpDecoder::decodeValue("", 0);
    }

    public function testDecodeUnexpectedEndReadingUint8(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Unexpected end of data reading uint8');

        AmqpDecoder::decodeValue("\x50", 0);
    }

    public function testDecodeUnexpectedEndReadingUint16(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Unexpected end of data reading uint16');

        AmqpDecoder::decodeValue("\x60\x01", 0);
    }

    public function testDecodeUnexpectedEndReadingUint32(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Unexpected end of data reading uint32');

        AmqpDecoder::decodeValue("\x70\x01\x02\x03", 0);
    }

    public function testDecodeUnexpectedEndReadingUint64(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Unexpected end of data reading uint64');

        AmqpDecoder::decodeValue("\x80\x01\x02\x03\x04\x05\x06\x07", 0);
    }

    public function testDecodeUnexpectedEndReadingBinary8(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Unexpected end of data reading binary8 content');

        AmqpDecoder::decodeValue("\xa0\x05hi", 0);
    }

    public function testDecodeUnexpectedEndReadingBinary32(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Unexpected end of data reading binary32 content');

        AmqpDecoder::decodeValue("\xb0\x00\x00\x00\x05hi", 0);
    }

    public function testDecodeUnexpectedEndReadingList8(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Unexpected end of data');

        // list8: size=3, count=5 (but only 1 byte of data)
        AmqpDecoder::decodeValue("\xc0\x03\x05\x52", 0);
    }

    public function testDecodeUnexpectedEndReadingMap8(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Unexpected end of data');

        // map8: size=3, count=4 (but only 1 byte of data)
        AmqpDecoder::decodeValue("\xc1\x03\x04\xa1", 0);
    }
}
