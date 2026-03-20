<?php

declare(strict_types=1);

namespace CrazyGoat\RabbitStream\Tests\Buffer;

use CrazyGoat\RabbitStream\Buffer\ReadBuffer;
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
}
