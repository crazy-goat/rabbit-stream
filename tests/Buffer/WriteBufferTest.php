<?php

namespace CrazyGoat\RabbitStream\Tests\Buffer;

use CrazyGoat\RabbitStream\Buffer\WriteBuffer;
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

    public function testAddInt32(): void
    {
        $buf = (new WriteBuffer())->addInt32(-1);
        $this->assertSame("\xFF\xFF\xFF\xFF", $buf->getContents());
    }

    public function testAddString(): void
    {
        $buf = (new WriteBuffer())->addString('hi');
        $this->assertSame("\x00\x02hi", $buf->getContents());
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
}
