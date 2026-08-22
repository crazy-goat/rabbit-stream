<?php

declare(strict_types=1);

namespace CrazyGoat\RabbitStream\Tests\Client;

use CrazyGoat\RabbitStream\Client\AmqpDecoder;
use CrazyGoat\RabbitStream\Exception\DeserializationException;
use CrazyGoat\RabbitStream\Exception\RabbitStreamExceptionInterface;
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

    public function testDecodeList0(): void
    {
        [$value, $pos] = AmqpDecoder::decodeValue("\x45", 0);
        $this->assertSame([], $value);
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

    // ========== Recursion depth limit (issue #397) ==========

    public function testDecodeDeeplyNestedPoCPayloadThrowsCatchableException(): void
    {
        // PoC payload from issue #397: 6 MB of nested list8 frames, well under the
        // 8 MB frame limit. Must throw a catchable DeserializationException, not a fatal.
        $payload = str_repeat("\xc0\xff\x01", 2_000_000);
        $baseline = memory_get_usage(true);

        try {
            AmqpDecoder::decodeValue($payload, 0);
            $this->fail('Expected DeserializationException for deeply nested payload');
        } catch (DeserializationException $e) {
            $this->assertStringContainsString('AMQP recursion depth limit exceeded (max 32)', $e->getMessage());
            $this->assertInstanceOf(RabbitStreamExceptionInterface::class, $e);
        }

        // The depth check must fire long before the payload can exhaust memory.
        $this->assertLessThan(32 * 1024 * 1024, memory_get_peak_usage(true) - $baseline);
    }

    public function testDecodeMessageWithDeeplyNestedBodyThrows(): void
    {
        // Exposure path from Consumer::read(): section value nested 33 lists deep.
        // Section value enters at depth 1, so the innermost element exceeds the limit.
        $message = "\x00\x53\x75" . $this->buildNestedList8(33);

        try {
            AmqpDecoder::decodeMessage($message);
            $this->fail('Expected DeserializationException for deeply nested body');
        } catch (DeserializationException $e) {
            $this->assertStringContainsString('AMQP recursion depth limit exceeded (max 32)', $e->getMessage());
            $this->assertInstanceOf(RabbitStreamExceptionInterface::class, $e);
        }
    }

    public function testDecodeNestedListsAtDepthLimit(): void
    {
        // depth == limit must still decode: 32 nested lists with a null at the center.
        [$value, $pos] = AmqpDecoder::decodeValue($this->buildNestedList8(32), 0);
        $this->assertSame($this->buildExpectedNestedValue(32), $value);
        $this->assertSame(strlen($this->buildNestedList8(32)), $pos);
    }

    public function testDecodeNestedListsBeyondDepthLimitThrows(): void
    {
        // depth == limit + 1 must throw.
        $this->expectException(DeserializationException::class);
        $this->expectExceptionMessage('AMQP recursion depth limit exceeded (max 32)');

        AmqpDecoder::decodeValue($this->buildNestedList8(33), 0);
    }

    public function testDecodeValueHonorsCustomMaxDepth(): void
    {
        // Custom maxDepth: 5 nested lists exceed a limit of 3...
        try {
            AmqpDecoder::decodeValue($this->buildNestedList8(5), 0, 0, 3);
            $this->fail('Expected DeserializationException when exceeding custom maxDepth');
        } catch (DeserializationException $e) {
            $this->assertStringContainsString('AMQP recursion depth limit exceeded (max 3)', $e->getMessage());
        }

        // ...but 3 nested lists decode fine with a limit of 3.
        [$value] = AmqpDecoder::decodeValue($this->buildNestedList8(3), 0, 0, 3);
        $this->assertSame($this->buildExpectedNestedValue(3), $value);
    }

    public function testDecodeMessageHonorsCustomMaxDepth(): void
    {
        // Direct test of decodeMessage()'s $maxDepth param: 5-deep body exceeds a limit of 3.
        // Section value enters at depth 1, so depth 4 is reached and the limit of 3 is exceeded.
        $message = "\x00\x53\x76" . $this->buildNestedList8(5);

        try {
            AmqpDecoder::decodeMessage($message, 3);
            $this->fail('Expected DeserializationException when exceeding custom maxDepth via decodeMessage');
        } catch (DeserializationException $e) {
            $this->assertStringContainsString('AMQP recursion depth limit exceeded (max 3)', $e->getMessage());
            $this->assertInstanceOf(RabbitStreamExceptionInterface::class, $e);
        }
    }

    public function testDecodeMessageWithShallowNestedBodyStillDecodes(): void
    {
        // Regression guard: legitimately nested message (20 levels) decodes normally.
        // AmqpValue section (0x76) is the correct carrier for a structured body.
        $message = "\x00\x53\x76" . $this->buildNestedList8(20);

        $sections = AmqpDecoder::decodeMessage($message);
        $this->assertSame($this->buildExpectedNestedValue(20), $sections['body']);
    }

    // ========== Element-count cap (issue #449) ==========

    public function testDecodeList32WithOversizedCountThrowsBeforeAllocating(): void
    {
        // PoC from issue #449 (lying variant): a list32 whose attacker-supplied
        // 32-bit count far exceeds the bytes available. A single null element
        // (0x40) is 1 byte, so count must be <= the available content bytes.
        // count = 8,388,608 with only 1 real content byte would otherwise build an
        // ~8-million-entry array from a 10-byte frame (uncatchable OOM fatal).
        // size = 5 (4 count bytes + 1 content byte), count = 0x00800000, content = 0x40.
        // Note: the guard's available-bytes window is size - 3 (= 2 here), because
        // $endPosition = position + size - 4 is treated as an inclusive bound (a
        // pre-existing off-by-one in the size math); the cap is still tight enough.
        $payload = "\xd0\x00\x00\x00\x05\x00\x80\x00\x00\x40";
        $baseline = memory_get_usage(true);

        try {
            AmqpDecoder::decodeValue($payload, 0);
            $this->fail('Expected DeserializationException for oversized list32 count');
        } catch (DeserializationException $e) {
            $this->assertStringContainsString('List32 count 8388608 exceeds available bytes 2', $e->getMessage());
            $this->assertInstanceOf(RabbitStreamExceptionInterface::class, $e);
        }

        // The guard must fire long before the array can be allocated: an
        // unguarded decode of count=8,388,608 would need hundreds of MB.
        $this->assertLessThan(32 * 1024 * 1024, memory_get_peak_usage(true) - $baseline);
    }

    public function testDecodeMap32WithOversizedCountThrowsBeforeAllocating(): void
    {
        // Same breadth vector for map32: count is total key+value elements (pairs*2).
        // count = 8,388,608 with only 1 real content byte available -> throws before
        // allocating the multi-hundred-MB map.
        $payload = "\xd1\x00\x00\x00\x05\x00\x80\x00\x00\x40";
        $baseline = memory_get_usage(true);

        try {
            AmqpDecoder::decodeValue($payload, 0);
            $this->fail('Expected DeserializationException for oversized map32 count');
        } catch (DeserializationException $e) {
            $this->assertStringContainsString('Map32 count 8388608 exceeds available bytes 2', $e->getMessage());
            $this->assertInstanceOf(RabbitStreamExceptionInterface::class, $e);
        }

        $this->assertLessThan(32 * 1024 * 1024, memory_get_peak_usage(true) - $baseline);
    }

    public function testDecodeList32CountWithinAvailableBytesStillDecodes(): void
    {
        // Boundary: count well within the available window. size = 7 (content = 3
        // null bytes), count = 3. available = size - 3 = 4, 3 <= 4 -> decodes.
        [$value, $pos] = AmqpDecoder::decodeValue("\xd0\x00\x00\x00\x07\x00\x00\x00\x03\x40\x40\x40", 0);
        $this->assertSame([null, null, null], $value);
        $this->assertSame(12, $pos);
    }

    public function testDecodeMap32CountWithinAvailableBytesStillDecodes(): void
    {
        // Boundary: 1 pair = 2 elements, both 1-byte nulls. size = 6 (content = 2),
        // count = 2. available = size - 3 = 3, 2 <= 3 -> decodes. Null key coerces
        // to '' by the map key logic.
        [$value, $pos] = AmqpDecoder::decodeValue("\xd1\x00\x00\x00\x06\x00\x00\x00\x02\x40\x40", 0);
        $this->assertSame(['' => null], $value);
        $this->assertSame(11, $pos);
    }

    public function testDecodeList32CountExceedingAvailableThrows(): void
    {
        // Boundary just past the cap: size = 7 -> available = 4, count = 5 > 4.
        $this->expectException(DeserializationException::class);
        $this->expectExceptionMessage('List32 count 5 exceeds available bytes 4');

        AmqpDecoder::decodeValue("\xd0\x00\x00\x00\x07\x00\x00\x00\x05\x40\x40\x40", 0);
    }

    public function testDecodeMap32CountExceedingAvailableThrows(): void
    {
        // Boundary just past the cap: size = 6 -> available = 3, count = 4 > 3.
        $this->expectException(DeserializationException::class);
        $this->expectExceptionMessage('Map32 count 4 exceeds available bytes 3');

        AmqpDecoder::decodeValue("\xd1\x00\x00\x00\x06\x00\x00\x00\x04\x40\x40", 0);
    }

    public function testDecodeValidList32AndMap32StillDecodeIdentically(): void
    {
        // Regression guard: the existing valid fixtures from testDecodeList32 /
        // testDecodeMap32 must decode byte-for-byte identically after the guard.
        [$list, $listPos] = AmqpDecoder::decodeValue(
            "\xd0\x00\x00\x00\x06\x00\x00\x00\x02\x52\x01\x52\x02",
            0
        );
        $this->assertSame([1, 2], $list);
        $this->assertSame(13, $listPos);

        [$map, $mapPos] = AmqpDecoder::decodeValue(
            "\xd1\x00\x00\x00\x09\x00\x00\x00\x02\xa1\x01k\x52\x01",
            0
        );
        $this->assertSame(['k' => 1], $map);
        $this->assertSame(14, $mapPos);
    }

    public function testDecodeMessageWithOversizedList32BodyThrows(): void
    {
        // Exposure path from Consumer::read() -> decodeMessage(): an AmqpValue
        // body (0x76) carrying a list32 with an oversized count must throw
        // catchably rather than fatal-OOM.
        $body = "\xd0\x00\x00\x00\x05\x00\x80\x00\x00\x40";
        $message = "\x00\x53\x76" . $body;

        try {
            AmqpDecoder::decodeMessage($message);
            $this->fail('Expected DeserializationException for oversized list32 body');
        } catch (DeserializationException $e) {
            $this->assertStringContainsString('List32 count 8388608 exceeds available bytes 2', $e->getMessage());
            $this->assertInstanceOf(RabbitStreamExceptionInterface::class, $e);
        }
    }

    public function testDecodeList32HonestLargeFrameThrowsBeforeAllocating(): void
    {
        // Headline PoC from issue #449: an *honest* list32 whose count truthfully
        // equals the available bytes. count = MAX_COMPOUND_ELEMENTS + 1 null
        // elements (0x40), each 1 byte, with a truthful size. The available-bytes
        // guard does NOT fire (count == available), but the element cap does —
        // before the loop allocates the multi-hundred-MB array.
        $count = 131073; // MAX_COMPOUND_ELEMENTS (131072) + 1
        $content = str_repeat("\x40", $count);
        $size = $count + 4; // 4 count bytes + count content bytes
        $payload = "\xd0" . pack('N', $size) . pack('N', $count) . $content;
        $baseline = memory_get_usage(true);

        try {
            AmqpDecoder::decodeValue($payload, 0);
            $this->fail('Expected DeserializationException for honest large list32');
        } catch (DeserializationException $e) {
            $this->assertStringContainsString(
                'List32 count 131073 exceeds maximum compound elements 131072',
                $e->getMessage()
            );
            $this->assertInstanceOf(RabbitStreamExceptionInterface::class, $e);
        }

        // The guard fires before the loop: no multi-hundred-MB allocation.
        $this->assertLessThan(32 * 1024 * 1024, memory_get_peak_usage(true) - $baseline);
    }

    public function testDecodeMap32HonestLargeFrameThrowsBeforeAllocating(): void
    {
        // Same honest-large-frame vector for map32. count is total key+value
        // elements; use MAX_COMPOUND_ELEMENTS + 1 one-byte nulls with truthful size.
        $count = 131073;
        $content = str_repeat("\x40", $count);
        $size = $count + 4;
        $payload = "\xd1" . pack('N', $size) . pack('N', $count) . $content;
        $baseline = memory_get_usage(true);

        try {
            AmqpDecoder::decodeValue($payload, 0);
            $this->fail('Expected DeserializationException for honest large map32');
        } catch (DeserializationException $e) {
            $this->assertStringContainsString(
                'Map32 count 131073 exceeds maximum compound elements 131072',
                $e->getMessage()
            );
            $this->assertInstanceOf(RabbitStreamExceptionInterface::class, $e);
        }

        $this->assertLessThan(32 * 1024 * 1024, memory_get_peak_usage(true) - $baseline);
    }

    public function testDecodeList32AtElementCapDecodes(): void
    {
        // Boundary: exactly MAX_COMPOUND_ELEMENTS elements decodes without error.
        // Use smalluint (0x52, 2 bytes: format code + value) to keep the payload
        // small while exercising a count near the cap.
        $count = 131072; // MAX_COMPOUND_ELEMENTS
        $element = "\x52\x00"; // smalluint 0 (2 bytes)
        $content = str_repeat($element, $count);
        $size = strlen($content) + 4;
        $payload = "\xd0" . pack('N', $size) . pack('N', $count) . $content;

        [$value, $pos] = AmqpDecoder::decodeValue($payload, 0);
        assert(is_array($value));
        $this->assertCount($count, $value);
        $this->assertSame(0, $value[0]);
        $this->assertSame(strlen($payload), $pos);
    }

    /**
     * Build a list8 chain $depth lists deep (each list holds a single child, innermost holds null).
     */
    private function buildNestedList8(int $depth): string
    {
        $payload = "\x40"; // innermost element: null
        for ($i = 0; $i < $depth; $i++) {
            $size = strlen($payload) + 1; // size includes the count byte
            $payload = "\xc0" . chr($size & 0xFF) . "\x01" . $payload;
        }
        return $payload;
    }

    /**
     * Build the expected decoded value for buildNestedList8().
     */
    private function buildExpectedNestedValue(int $depth): mixed
    {
        $value = null;
        for ($i = 0; $i < $depth; $i++) {
            $value = [$value];
        }
        return $value;
    }
}
