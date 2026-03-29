<?php

declare(strict_types=1);

namespace CrazyGoat\RabbitStream\Tests\Buffer;

use CrazyGoat\RabbitStream\Buffer\ReadBuffer;
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

    public function testGetStringArray(): void
    {
        $buf = new ReadBuffer("\x00\x00\x00\x02\x00\x03foo\x00\x03bar");
        $this->assertSame(['foo', 'bar'], $buf->getStringArray());
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
}
