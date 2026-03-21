<?php

declare(strict_types=1);

namespace CrazyGoat\RabbitStream\Tests\Client;

use CrazyGoat\RabbitStream\Client\ChunkEntry;
use PHPUnit\Framework\TestCase;

class ChunkEntryTest extends TestCase
{
    public function testGetters(): void
    {
        $entry = new ChunkEntry(offset: 5, data: 'hello', timestamp: 1700000000);
        $this->assertSame(5, $entry->getOffset());
        $this->assertSame('hello', $entry->getData());
        $this->assertSame(1700000000, $entry->getTimestamp());
    }

    public function testZeroOffset(): void
    {
        $entry = new ChunkEntry(offset: 0, data: 'test', timestamp: 1700000000);
        $this->assertSame(0, $entry->getOffset());
        $this->assertSame('test', $entry->getData());
        $this->assertSame(1700000000, $entry->getTimestamp());
    }

    public function testEmptyData(): void
    {
        $entry = new ChunkEntry(offset: 1, data: '', timestamp: 1700000000);
        $this->assertSame(1, $entry->getOffset());
        $this->assertSame('', $entry->getData());
        $this->assertSame(1700000000, $entry->getTimestamp());
    }

    public function testZeroTimestamp(): void
    {
        $entry = new ChunkEntry(offset: 10, data: 'data', timestamp: 0);
        $this->assertSame(10, $entry->getOffset());
        $this->assertSame('data', $entry->getData());
        $this->assertSame(0, $entry->getTimestamp());
    }

    public function testLargeOffset(): void
    {
        $entry = new ChunkEntry(offset: PHP_INT_MAX, data: 'large', timestamp: 1700000000);
        $this->assertSame(PHP_INT_MAX, $entry->getOffset());
        $this->assertSame('large', $entry->getData());
        $this->assertSame(1700000000, $entry->getTimestamp());
    }

    public function testBinaryData(): void
    {
        $binaryData = "\x00\x01\x02\x03\xff";
        $entry = new ChunkEntry(offset: 100, data: $binaryData, timestamp: 1700000000);
        $this->assertSame(100, $entry->getOffset());
        $this->assertSame($binaryData, $entry->getData());
        $this->assertSame(1700000000, $entry->getTimestamp());
    }
}
