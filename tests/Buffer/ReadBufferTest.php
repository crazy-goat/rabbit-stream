<?php

declare(strict_types=1);

namespace CrazyGoat\RabbitStream\Tests\Buffer;

use CrazyGoat\RabbitStream\Buffer\ReadBuffer;
use CrazyGoat\RabbitStream\Exception\DeserializationException;
use CrazyGoat\RabbitStream\VO\KeyValue;
use PHPUnit\Framework\TestCase;

class ReadBufferTest extends TestCase
{
    public function testGetUint16(): void
    {
        $buf = new ReadBuffer("\x00\x11");
        $this->assertSame(0x0011, $buf->getUint16());
    }

    public function testGetUint32(): void
    {
        $buf = new ReadBuffer("\x00\x00\x00\x01");
        $this->assertSame(1, $buf->getUint32());
    }

    public function testGetString(): void
    {
        $buf = new ReadBuffer("\x00\x05hello");
        $this->assertSame('hello', $buf->getString());
    }

    public function testGetStringNull(): void
    {
        $buf = new ReadBuffer("\xFF\xFF");
        $this->assertNull($buf->getString());
    }

    public function testGetStringEmpty(): void
    {
        $buf = new ReadBuffer("\x00\x00");
        $this->assertSame('', $buf->getString());
        $this->assertSame(2, $buf->getPosition());
    }

    public function testGetBytes(): void
    {
        $buf = new ReadBuffer("\x00\x00\x00\x02AB");
        $this->assertSame('AB', $buf->getBytes());
    }

    public function testGetBytesNull(): void
    {
        $buf = new ReadBuffer("\xFF\xFF\xFF\xFF");
        $this->assertNull($buf->getBytes());
    }

    public function testGetBytesEmpty(): void
    {
        $buf = new ReadBuffer("\x00\x00\x00\x00");
        $this->assertSame('', $buf->getBytes());
        $this->assertSame(4, $buf->getPosition());
    }

    public function testGetStringArray(): void
    {
        $buf = new ReadBuffer("\x00\x00\x00\x02\x00\x03foo\x00\x03bar");
        $this->assertSame(['foo', 'bar'], $buf->getStringArray());
    }

    public function testGetStringArrayEmpty(): void
    {
        $buf = new ReadBuffer("\x00\x00\x00\x00");
        $this->assertSame([], $buf->getStringArray());
        $this->assertSame(4, $buf->getPosition());
    }

    public function testGetStringWithNegativeLengthThrows(): void
    {
        $buf = new ReadBuffer(pack('n', 0xFFFE) . 'ABCDEF');
        try {
            $buf->getString();
            $this->fail('Expected DeserializationException');
        } catch (DeserializationException $e) {
            $this->assertStringContainsString('Invalid string length -2', $e->getMessage());
            $this->assertStringContainsString('position 0', $e->getMessage());
        }
    }

    public function testGetStringArrayWithHugeCountAndNegativeLengthThrows(): void
    {
        $buf = new ReadBuffer(pack('N', 0xFFFFFFFF) . pack('n', 0xFFFE) . 'ABCDEF');
        try {
            $buf->getStringArray();
            $this->fail('Expected DeserializationException');
        } catch (DeserializationException $e) {
            $this->assertStringContainsString('Invalid string array count 4294967295', $e->getMessage());
        }
    }

    public function testGetStringArrayWithCountLargerThanRemainingThrows(): void
    {
        $buf = new ReadBuffer(pack('N', 1000) . "\x00\x03foo");
        try {
            $buf->getStringArray();
            $this->fail('Expected DeserializationException');
        } catch (DeserializationException $e) {
            $this->assertStringContainsString('Invalid string array count 1000', $e->getMessage());
        }
    }

    public function testRewind(): void
    {
        $buf = new ReadBuffer("\x00\x01\x00\x02");
        $buf->getUint16();
        $buf->rewind();
        $this->assertSame(1, $buf->getUint16());
    }

    public function testGetUint8ThrowsOnUnderflow(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Buffer underflow');
        $buf = new ReadBuffer('');
        $buf->getUint8();
    }

    public function testGetUint16ThrowsOnUnderflow(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Buffer underflow');
        $buf = new ReadBuffer("\x00");
        $buf->getUint16();
    }

    public function testGetUint32ThrowsOnUnderflow(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Buffer underflow');
        $buf = new ReadBuffer("\x00\x00");
        $buf->getUint32();
    }

    public function testGetUint64ThrowsOnUnderflow(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Buffer underflow');
        $buf = new ReadBuffer("\x00\x00\x00\x00");
        $buf->getUint64();
    }

    public function testGetInt16ThrowsOnUnderflow(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Buffer underflow');
        $buf = new ReadBuffer("\x00");
        $buf->getInt16();
    }

    public function testGetInt32ThrowsOnUnderflow(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Buffer underflow');
        $buf = new ReadBuffer("\x00\x00");
        $buf->getInt32();
    }

    public function testGetInt64ThrowsOnUnderflow(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Buffer underflow');
        $buf = new ReadBuffer("\x00\x00\x00\x00");
        $buf->getInt64();
    }

    public function testGetStringThrowsOnUnderflow(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Buffer underflow');
        $buf = new ReadBuffer(pack('n', 100));
        $buf->getString();
    }

    public function testGetBytesThrowsOnUnderflow(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Buffer underflow');
        $buf = new ReadBuffer(pack('N', 100));
        $buf->getBytes();
    }

    public function testGetBytesWithNegativeLengthThrows(): void
    {
        $buf = new ReadBuffer(pack('N', 0xFFFFFFFE) . 'ABCDEF');
        try {
            $buf->getBytes();
            $this->fail('Expected DeserializationException');
        } catch (DeserializationException $e) {
            $this->assertStringContainsString('Invalid bytes length -2', $e->getMessage());
            $this->assertStringContainsString('position 0', $e->getMessage());
        }
    }

    public function testSkipThrowsOnUnderflow(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Buffer underflow');
        $buf = new ReadBuffer('abc');
        $buf->skip(10);
    }

    public function testReadBytesThrowsOnUnderflow(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Buffer underflow');
        $buf = new ReadBuffer('ab');
        $buf->readBytes(5);
    }

    public function testPeekUint16ThrowsOnUnderflow(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Buffer underflow');
        $buf = new ReadBuffer("\x00");
        $buf->peekUint16();
    }

    public function testSkipWithNegativeCountThrows(): void
    {
        // Regression guard for #447: a negative count previously slipped past
        // ensureAvailable()'s bounds check (never > the available byte count)
        // and silently moved position backwards instead of failing loudly.
        $buf = new ReadBuffer('abcdef');
        try {
            $buf->skip(-1);
            $this->fail('Expected DeserializationException');
        } catch (DeserializationException $e) {
            $this->assertStringContainsString('Invalid skip length -1', $e->getMessage());
            $this->assertStringContainsString('position 0', $e->getMessage());
        }
        $this->assertSame(0, $buf->getPosition(), 'position must not move on a rejected negative skip');
    }

    public function testReadBytesWithNegativeLengthThrows(): void
    {
        // Regression guard for #447 (see testSkipWithNegativeCountThrows).
        $buf = new ReadBuffer('abcdef');
        $buf->skip(2);
        try {
            $buf->readBytes(-3);
            $this->fail('Expected DeserializationException');
        } catch (DeserializationException $e) {
            $this->assertStringContainsString('Invalid read length -3', $e->getMessage());
            $this->assertStringContainsString('position 2', $e->getMessage());
        }
        $this->assertSame(2, $buf->getPosition(), 'position must not move on a rejected negative readBytes');
    }

    public function testSequentialReadsThrowOnUnderflow(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Buffer underflow');
        $buf = new ReadBuffer("\x00\x01\x00\x02");
        $buf->getUint16();
        $buf->getUint32();
    }

    public function testGetRemainingBytesOnEmptyBuffer(): void
    {
        $buf = new ReadBuffer('');
        $this->assertSame('', $buf->getRemainingBytes());
    }

    public function testGetRemainingBytesAfterFullRead(): void
    {
        $buf = new ReadBuffer("\x00\x01");
        $buf->getUint16();
        $this->assertSame('', $buf->getRemainingBytes());
    }

    public function testUnderflowMessageContainsPosition(): void
    {
        $buf = new ReadBuffer("\x00\x01");
        $buf->getUint16();
        try {
            $buf->getUint16();
            $this->fail('Expected RuntimeException');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('position 2', $e->getMessage());
            $this->assertStringContainsString('need 2 bytes', $e->getMessage());
            $this->assertStringContainsString('0 available', $e->getMessage());
        }
    }

    public function testGetUint8(): void
    {
        $buf = new ReadBuffer("\xFF");
        $this->assertSame(255, $buf->getUint8());
    }

    public function testGetUint64(): void
    {
        $buf = new ReadBuffer(pack('J', 12345678901234));
        $this->assertSame(12345678901234, $buf->getUint64());
    }

    public function testGetUint64WithMaxValue(): void
    {
        $buf = new ReadBuffer("\xFF\xFF\xFF\xFF\xFF\xFF\xFF\xFF");
        // On 64-bit PHP, 0xFFFFFFFFFFFFFFFF unpacks as -1 due to signed integer overflow
        $this->assertSame(-1, $buf->getUint64());
    }

    public function testGetInt16Negative(): void
    {
        $buf = new ReadBuffer("\xFF\xFF");
        $this->assertSame(-1, $buf->getInt16());
    }

    public function testGetInt16WithMinValue(): void
    {
        $buf = new ReadBuffer("\x80\x00");
        $this->assertSame(-32768, $buf->getInt16());
    }

    public function testGetInt32Negative(): void
    {
        $buf = new ReadBuffer("\xFF\xFF\xFF\xFF");
        $this->assertSame(-1, $buf->getInt32());
    }

    public function testGetInt32WithMinValue(): void
    {
        $buf = new ReadBuffer("\x80\x00\x00\x00");
        $this->assertSame(-2147483648, $buf->getInt32());
    }

    public function testGetInt64Negative(): void
    {
        $buf = new ReadBuffer("\xFF\xFF\xFF\xFF\xFF\xFF\xFF\xFF");
        $this->assertSame(-1, $buf->getInt64());
    }

    public function testGetInt64WithLargeNegative(): void
    {
        $buf = new ReadBuffer("\x80\x00\x00\x00\x00\x00\x00\x00");
        $this->assertSame(PHP_INT_MIN, $buf->getInt64());
    }

    public function testGetPositionAdvancesCorrectly(): void
    {
        $buf = new ReadBuffer("\x00\x01\x00\x02\x00\x03\x00\x04");
        $this->assertSame(0, $buf->getPosition());
        $buf->getUint16();
        $this->assertSame(2, $buf->getPosition());
        $buf->getUint32();
        $this->assertSame(6, $buf->getPosition());
    }

    public function testGetPositionAfterVariousReads(): void
    {
        $buf = new ReadBuffer("\xFF\x00\x05hello\x00\x00\x00\x02AB");
        $this->assertSame(0, $buf->getPosition());
        $buf->getUint8();
        $this->assertSame(1, $buf->getPosition());
        $buf->getString();
        $this->assertSame(8, $buf->getPosition());
        $buf->getBytes();
        $this->assertSame(14, $buf->getPosition());
    }

    public function testGetRemainingBytesMidRead(): void
    {
        $buf = new ReadBuffer("\x00\x01\x00\x02\x00\x03");
        $buf->getUint16();
        $this->assertSame("\x00\x02\x00\x03", $buf->getRemainingBytes());
        $this->assertSame(6, $buf->getPosition());
    }

    public function testGetRemainingBytesPartialRead(): void
    {
        $buf = new ReadBuffer("\x00\x01\x00\x02\x00\x03\x00\x04");
        $buf->getUint16();
        $buf->getUint16();
        $this->assertSame("\x00\x03\x00\x04", $buf->getRemainingBytes());
    }

    public function testPeekUint16DoesNotAdvancePosition(): void
    {
        $buf = new ReadBuffer("\x00\x01\x00\x02");
        $peeked = $buf->peekUint16();
        $this->assertSame(1, $peeked);
        $this->assertSame(0, $buf->getPosition());
        $read = $buf->getUint16();
        $this->assertSame($peeked, $read);
        $this->assertSame(2, $buf->getPosition());
    }

    public function testPeekUint16MultipleTimes(): void
    {
        $buf = new ReadBuffer("\x00\x42\x00\x01");
        $this->assertSame(0x42, $buf->peekUint16());
        $this->assertSame(0x42, $buf->peekUint16());
        $this->assertSame(0, $buf->getPosition());
    }

    public function testSkipAdvancesPosition(): void
    {
        $buf = new ReadBuffer("\x00\x01\x00\x02\x00\x03\x00\x04");
        $this->assertSame(0, $buf->getPosition());
        $buf->skip(2);
        $this->assertSame(2, $buf->getPosition());
        $this->assertSame(0x0002, $buf->getUint16());
        $buf->skip(2);
        $this->assertSame(6, $buf->getPosition());
    }

    public function testSkipZeroBytes(): void
    {
        $buf = new ReadBuffer("\x00\x01");
        $buf->skip(0);
        $this->assertSame(0, $buf->getPosition());
        $this->assertSame(1, $buf->getUint16());
    }

    public function testReadBytes(): void
    {
        $buf = new ReadBuffer("\x00\x01\x00\x02\x00\x03");
        $this->assertSame("\x00\x01", $buf->readBytes(2));
        $this->assertSame(2, $buf->getPosition());
        $this->assertSame("\x00\x02", $buf->readBytes(2));
        $this->assertSame(4, $buf->getPosition());
    }

    public function testReadBytesWithZeroLength(): void
    {
        $buf = new ReadBuffer("\x00\x01");
        $this->assertSame('', $buf->readBytes(0));
        $this->assertSame(0, $buf->getPosition());
    }

    public function testReadBytesAdvancesPositionCorrectly(): void
    {
        $buf = new ReadBuffer("ABCDEFGHIJ");
        $this->assertSame("ABC", $buf->readBytes(3));
        $this->assertSame(3, $buf->getPosition());
        $this->assertSame("DEF", $buf->readBytes(3));
        $this->assertSame(6, $buf->getPosition());
        $this->assertSame("GHIJ", $buf->readBytes(4));
        $this->assertSame(10, $buf->getPosition());
    }

    public function testGetObjectArray(): void
    {
        $binary = "\x00\x00\x00\x02"
            . "\x00\x03foo\x00\x03bar"
            . "\x00\x03baz\xFF\xFF";

        $buf = new ReadBuffer($binary);
        $result = $buf->getObjectArray(KeyValue::class);

        $this->assertCount(2, $result);
        $this->assertInstanceOf(KeyValue::class, $result[0]);
        $this->assertSame('foo', $result[0]->getKey());
        $this->assertSame('bar', $result[0]->getValue());
        $this->assertInstanceOf(KeyValue::class, $result[1]);
        $this->assertSame('baz', $result[1]->getKey());
        $this->assertNull($result[1]->getValue());
    }

    public function testGetObjectArrayEmpty(): void
    {
        $binary = "\x00\x00\x00\x00";
        $buf = new ReadBuffer($binary);
        $result = $buf->getObjectArray(KeyValue::class);

        $this->assertSame([], $result);
        $this->assertSame(4, $buf->getPosition());
    }

    public function testGetObjectArrayWithCountLargerThanRemainingThrows(): void
    {
        $buf = new ReadBuffer(pack('N', 1000) . "\x00\x03foo\x00\x03bar");
        try {
            $buf->getObjectArray(KeyValue::class);
            $this->fail('Expected DeserializationException');
        } catch (DeserializationException $e) {
            $this->assertStringContainsString('Invalid object array count 1000', $e->getMessage());
        }
    }

    // --- Window (offset/length) tests -------------------------------------------------

    public function testWindowReadsAreRelativeToOffset(): void
    {
        $backing = 'PREFIX *SUFFIX';
        $buf = new ReadBuffer($backing, offset: 6, length: 2);

        $this->assertSame(0x2A, $buf->getUint16());
        $this->assertSame(2, $buf->getPosition());
    }

    public function testWindowDefaultLengthIsToEndOfBuffer(): void
    {
        $backing = 'PREFIXhello';
        $buf = new ReadBuffer($backing, offset: 6);

        $this->assertSame('hello', $buf->getRemainingBytes());
    }

    public function testWindowGetStringRespectsBounds(): void
    {
        $backing = 'XX fooYY';
        $buf = new ReadBuffer($backing, offset: 2, length: 5);

        $this->assertSame('foo', $buf->getString());
        $this->assertSame(5, $buf->getPosition());
    }

    public function testWindowGetBytesRespectsBounds(): void
    {
        $backing = 'XX' . pack('N', 3) . 'abc' . 'YY';
        $buf = new ReadBuffer($backing, offset: 2, length: 7);

        $this->assertSame('abc', $buf->getBytes());
    }

    public function testWindowReadPastEndThrows(): void
    {
        $backing = "\x00\x01\x02\x03\x04\x05";
        $buf = new ReadBuffer($backing, offset: 1, length: 2);

        $buf->readBytes(2);
        $this->expectException(DeserializationException::class);
        $this->expectExceptionMessage('Buffer underflow: need 1 bytes at position 2, but only 0 available');
        $buf->getUint8();
    }

    public function testWindowCannotReadBeyondItsLengthEvenIfBackingStringHasMoreData(): void
    {
        $backing = "\x00\x01\x02\x03\x04\x05\x06\x07\x08\x09";
        $buf = new ReadBuffer($backing, offset: 0, length: 3);

        $buf->readBytes(3);
        $this->expectException(DeserializationException::class);
        $buf->getUint8();
    }

    public function testWindowCannotReadBeforeItsOffset(): void
    {
        $backing = "\x00\x01\x02\x03\x04\x05";
        $buf = new ReadBuffer($backing, offset: 3, length: 3);

        // Position 0 of the window is absolute offset 3; getUint8() must return
        // byte 3 (0x03), never byte 0 of the backing string.
        $this->assertSame(0x03, $buf->getUint8());
    }

    public function testWindowRewindReturnsToWindowStartNotBackingStart(): void
    {
        $backing = "\xFF\xFF" . "\x00\x2A";
        $buf = new ReadBuffer($backing, offset: 2, length: 2);

        $buf->getUint16();
        $buf->rewind();

        $this->assertSame(0, $buf->getPosition());
        $this->assertSame(0x2A, $buf->getUint16());
    }

    public function testWindowSkipRespectsBounds(): void
    {
        $backing = "\x00\x01\x02\x03\x04\x05";
        $buf = new ReadBuffer($backing, offset: 1, length: 3);

        $buf->skip(3);
        $this->expectException(DeserializationException::class);
        $buf->skip(1);
    }

    public function testWindowPeekUint16DoesNotAdvancePosition(): void
    {
        $backing = 'X4Y';
        $buf = new ReadBuffer($backing, offset: 1, length: 2);

        $this->assertSame(0x1234, $buf->peekUint16());
        $this->assertSame(0, $buf->getPosition());
        $this->assertSame(0x1234, $buf->getUint16());
    }

    public function testWindowGetRemainingBytesCopiesOnlyTheWindow(): void
    {
        $backing = 'PREFIXMIDDLESUFFIX';
        $buf = new ReadBuffer($backing, offset: 6, length: 6);

        $this->assertSame('MIDDLE', $buf->getRemainingBytes());
    }

    public function testWindowGetRemainingWindowIsZeroCopyAndScopedToTheWindow(): void
    {
        $backing = 'PREFIXMIDDLESUFFIX';
        $buf = new ReadBuffer($backing, offset: 6, length: 6);

        $buf->getUint8(); // advance position by 1 within the window
        [$rawBuffer, $absOffset, $length] = $buf->getRemainingWindow();

        $this->assertSame($backing, $rawBuffer);
        $this->assertSame(7, $absOffset);
        $this->assertSame(5, $length);
        $this->assertSame(substr($backing, $absOffset, $length), 'IDDLE');
    }

    public function testGetRemainingWindowPastEndThrows(): void
    {
        $buf = new ReadBuffer("\x00\x01");
        $buf->readBytes(2);

        // position === windowLength is valid (empty remainder); force position
        // past the end via skip() to exercise the underflow branch.
        $reflection = new \ReflectionProperty($buf, 'position');
        $reflection->setValue($buf, 5);

        $this->expectException(DeserializationException::class);
        $buf->getRemainingWindow();
    }

    public function testSliceCreatesIndependentWindowSharingTheBackingString(): void
    {
        $backing = "\x00\x01\x02\x03\x04\x05\x06\x07";
        $buf = new ReadBuffer($backing);
        $buf->skip(2); // position now at absolute offset 2

        $slice = $buf->slice(1, 3); // absolute offset 3, length 3

        // The parent buffer's position is untouched by slicing.
        $this->assertSame(2, $buf->getPosition());

        $this->assertSame(0x03, $slice->getUint8());
        $this->assertSame(0x04, $slice->getUint8());
        $this->assertSame(0x05, $slice->getUint8());
        $this->expectException(DeserializationException::class);
        $slice->getUint8();
    }

    public function testSliceDefaultLengthGoesToEndOfParentWindow(): void
    {
        $backing = "\x00\x01\x02\x03\x04\x05";
        $buf = new ReadBuffer($backing, offset: 1, length: 4); // window: bytes 1..4

        $buf->skip(1); // window position 1 (absolute offset 2)
        $slice = $buf->slice(1); // absolute offset 3, default length to end of parent window (2 bytes left: 3,4)

        $this->assertSame(0x03, $slice->getUint8());
        $this->assertSame(0x04, $slice->getUint8());
        $this->expectException(DeserializationException::class);
        $slice->getUint8();
    }

    public function testNestedSlicesEachRespectTheirOwnBounds(): void
    {
        $backing = "\x00\x01\x02\x03\x04\x05\x06\x07\x08\x09";
        $outer = new ReadBuffer($backing, offset: 1, length: 8); // window: bytes 1..8

        $middle = $outer->slice(1, 6); // absolute offset 2, length 6: bytes 2..7
        $inner = $middle->slice(1, 4); // absolute offset 3, length 4: bytes 3..6

        $this->assertSame(0x03, $inner->getUint8());
        $this->assertSame(0x04, $inner->getUint8());
        $this->assertSame(0x05, $inner->getUint8());
        $this->assertSame(0x06, $inner->getUint8());
        $this->expectException(DeserializationException::class);
        $inner->getUint8();
    }

    public function testSliceWithNegativeOffsetThrows(): void
    {
        $buf = new ReadBuffer("\x00\x01\x02");
        $this->expectException(DeserializationException::class);
        $buf->slice(-1);
    }

    public function testSliceOffsetPastWindowEndThrows(): void
    {
        $buf = new ReadBuffer("\x00\x01\x02", offset: 0, length: 2);
        $this->expectException(DeserializationException::class);
        $buf->slice(3);
    }

    public function testSliceLengthExceedingAvailableThrows(): void
    {
        $buf = new ReadBuffer("\x00\x01\x02\x03", offset: 0, length: 3);
        $this->expectException(DeserializationException::class);
        $buf->slice(0, 10);
    }

    public function testSliceAtExactWindowEndProducesEmptySlice(): void
    {
        $buf = new ReadBuffer("\x00\x01\x02", offset: 0, length: 3);
        $slice = $buf->slice(3);

        $this->assertSame(0, $slice->getPosition());
        $this->expectException(DeserializationException::class);
        $slice->getUint8();
    }
}
