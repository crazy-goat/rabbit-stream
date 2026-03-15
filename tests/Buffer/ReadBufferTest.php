<?php

namespace CrazyGoat\StreamyCarrot\Tests\Buffer;

use CrazyGoat\StreamyCarrot\Buffer\ReadBuffer;
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

    public function testGatString(): void
    {
        $buf = new ReadBuffer("\x00\x05hello");
        $this->assertSame('hello', $buf->gatString());
    }

    public function testGatStringNull(): void
    {
        $buf = new ReadBuffer("\xFF\xFF");
        $this->assertNull($buf->gatString());
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
}
