<?php

declare(strict_types=1);

namespace CrazyGoat\RabbitStream\Tests\Client;

use CrazyGoat\RabbitStream\Client\ChunkEntry;
use CrazyGoat\RabbitStream\Client\OsirisChunkParser;
use PHPUnit\Framework\TestCase;

class OsirisChunkParserTest extends TestCase
{
    public function testParseSimpleChunkWithOneEntry(): void
    {
        $chunk = $this->createChunk(
            numEntries: 1,
            numRecords: 1,
            timestamp: 1234567890,
            chunkFirstOffset: 100,
            entries: [
                ['type' => 'simple', 'data' => 'Hello World'],
            ]
        );

        $entries = OsirisChunkParser::parse($chunk);

        $this->assertCount(1, $entries);
        $this->assertInstanceOf(ChunkEntry::class, $entries[0]);
        $this->assertSame(100, $entries[0]->getOffset());
        $this->assertSame('Hello World', $entries[0]->getData());
        $this->assertSame(1234567890, $entries[0]->getTimestamp());
    }

    public function testParseMultiEntryChunk(): void
    {
        $chunk = $this->createChunk(
            numEntries: 3,
            numRecords: 3,
            timestamp: 1234567890,
            chunkFirstOffset: 50,
            entries: [
                ['type' => 'simple', 'data' => 'Message 1'],
                ['type' => 'simple', 'data' => 'Message 2'],
                ['type' => 'simple', 'data' => 'Message 3'],
            ]
        );

        $entries = OsirisChunkParser::parse($chunk);

        $this->assertCount(3, $entries);
        $this->assertSame(50, $entries[0]->getOffset());
        $this->assertSame('Message 1', $entries[0]->getData());
        $this->assertSame(51, $entries[1]->getOffset());
        $this->assertSame('Message 2', $entries[1]->getData());
        $this->assertSame(52, $entries[2]->getOffset());
        $this->assertSame('Message 3', $entries[2]->getData());
    }

    public function testParseUncompressedSubBatch(): void
    {
        $innerEntries = [
            ['data' => 'SubMessage 1'],
            ['data' => 'SubMessage 2'],
            ['data' => 'SubMessage 3'],
        ];

        $chunk = $this->createChunk(
            numEntries: 1,
            numRecords: 3,
            timestamp: 999999999,
            chunkFirstOffset: 1000,
            entries: [
                ['type' => 'subbatch', 'codec' => 0, 'entries' => $innerEntries],
            ]
        );

        $entries = OsirisChunkParser::parse($chunk);

        $this->assertCount(3, $entries);
        $this->assertSame(1000, $entries[0]->getOffset());
        $this->assertSame('SubMessage 1', $entries[0]->getData());
        $this->assertSame(1001, $entries[1]->getOffset());
        $this->assertSame('SubMessage 2', $entries[1]->getData());
        $this->assertSame(1002, $entries[2]->getOffset());
        $this->assertSame('SubMessage 3', $entries[2]->getData());
        $this->assertSame(999999999, $entries[0]->getTimestamp());
    }

    public function testInvalidMagicThrowsException(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Invalid chunk magic: expected 5, got 0');

        // 0x00 has magic=0, version=0 → invalid magic
        $chunk = "\x00" . str_repeat("\x00", 100);
        OsirisChunkParser::parse($chunk);
    }

    public function testUnsupportedChunkVersionThrowsException(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Unsupported chunk version: expected 0, got 1');

        // 0x51 has magic=5, version=1 → unsupported version
        $chunk = "\x51" . str_repeat("\x00", 100);
        OsirisChunkParser::parse($chunk);
    }

    public function testUnsupportedChunkTypeThrowsException(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Unsupported chunk type');

        // 0x50 = valid magic+version, then 0x01 = invalid chunk type
        $chunk = "\x50\x01" . str_repeat("\x00", 100);
        OsirisChunkParser::parse($chunk);
    }

    public function testCompressedSubBatchThrowsException(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Compressed sub-batches not supported yet');

        $chunk = $this->createChunk(
            numEntries: 1,
            numRecords: 2,
            timestamp: 1234567890,
            chunkFirstOffset: 0,
            entries: [
                ['type' => 'subbatch', 'codec' => 1, 'entries' => [['data' => 'test']]],
            ]
        );

        OsirisChunkParser::parse($chunk);
    }

    public function testSubBatchHeaderParsedAsOneBytePlusUint16(): void
    {
        $innerEntries = [];
        for ($i = 0; $i < 512; $i++) {
            $innerEntries[] = ['data' => chr($i % 256)];
        }

        $chunk = $this->createChunk(
            numEntries: 1,
            numRecords: 512,
            timestamp: 1234567890,
            chunkFirstOffset: 500,
            entries: [
                ['type' => 'subbatch', 'codec' => 0, 'entries' => $innerEntries],
            ]
        );

        $entries = OsirisChunkParser::parse($chunk);

        $this->assertCount(512, $entries);
        for ($i = 0; $i < 512; $i++) {
            $this->assertSame(500 + $i, $entries[$i]->getOffset());
            $this->assertSame(chr($i % 256), $entries[$i]->getData());
        }
    }

    public function testCompressedSubBatchZstdThrowsException(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Compressed sub-batches not supported yet (codec: 4)');

        $chunk = $this->createChunk(
            numEntries: 1,
            numRecords: 1,
            timestamp: 1234567890,
            chunkFirstOffset: 0,
            entries: [
                ['type' => 'subbatch', 'codec' => 4, 'entries' => [['data' => 'test']]],
            ]
        );

        OsirisChunkParser::parse($chunk);
    }

    public function testTruncatedSimpleEntryThrowsException(): void
    {
        // Valid 48-byte header + a simple entry that claims 11 bytes but only 6 are present.
        // The ReadBuffer underflow guard must fail loud rather than read past the buffer.
        $this->expectException(\RuntimeException::class);

        $chunk = $this->createChunk(
            numEntries: 1,
            numRecords: 1,
            timestamp: 1234567890,
            chunkFirstOffset: 0,
            entries: [
                ['type' => 'simple', 'data' => 'Hello World'],
            ]
        );
        // Header (48) + 4-byte simple-entry header (size = 11) = 52; keep only 6 body bytes.
        $truncated = substr($chunk, 0, 52 + 6);
        OsirisChunkParser::parse($truncated);
    }

    public function testTruncatedSubBatchBodyThrowsException(): void
    {
        // Sub-batch entry whose compressedSize claims more bytes than the chunk carries.
        $this->expectException(\RuntimeException::class);

        $chunk = $this->createChunk(
            numEntries: 1,
            numRecords: 2,
            timestamp: 1234567890,
            chunkFirstOffset: 0,
            entries: [
                ['type' => 'subbatch', 'codec' => 0, 'entries' => [
                    ['data' => 'SubMessage 1'],
                    ['data' => 'SubMessage 2'],
                ]],
            ]
        );
        // Header (48) + sub-batch header (1+2+4+4 = 11) = 59; keep only 2 body bytes.
        $truncated = substr($chunk, 0, 59 + 2);
        OsirisChunkParser::parse($truncated);
    }

    public function testEmptySubBatchProducesNoEntries(): void
    {
        $chunk = $this->createChunk(
            numEntries: 1,
            numRecords: 0,
            timestamp: 1234567890,
            chunkFirstOffset: 42,
            entries: [
                ['type' => 'subbatch', 'codec' => 0, 'entries' => []],
            ]
        );

        $entries = OsirisChunkParser::parse($chunk);

        $this->assertSame([], $entries);
    }

    public function testMixedSimpleAndSubBatchEntries(): void
    {
        $chunk = $this->createChunk(
            numEntries: 3,
            numRecords: 4,
            timestamp: 1111111111,
            chunkFirstOffset: 200,
            entries: [
                ['type' => 'simple', 'data' => 'First'],
                ['type' => 'subbatch', 'codec' => 0, 'entries' => [
                    ['data' => 'Batch 1'],
                    ['data' => 'Batch 2'],
                ]],
                ['type' => 'simple', 'data' => 'Last'],
            ]
        );

        $entries = OsirisChunkParser::parse($chunk);

        $this->assertCount(4, $entries);
        $this->assertSame(200, $entries[0]->getOffset());
        $this->assertSame('First', $entries[0]->getData());
        $this->assertSame(201, $entries[1]->getOffset());
        $this->assertSame('Batch 1', $entries[1]->getData());
        $this->assertSame(202, $entries[2]->getOffset());
        $this->assertSame('Batch 2', $entries[2]->getData());
        $this->assertSame(203, $entries[3]->getOffset());
        $this->assertSame('Last', $entries[3]->getData());
    }

    /**
     * @param array<int, array<string, mixed>> $entries
     */
    private function createChunk(
        int $numEntries,
        int $numRecords,
        int $timestamp,
        int $chunkFirstOffset,
        array $entries
    ): string {
        $dataSection = '';

        foreach ($entries as $entry) {
            if ($entry['type'] === 'simple') {
                $entryData = is_scalar($entry['data']) ? (string) $entry['data'] : '';
                $size = strlen($entryData);
                $dataSection .= pack('N', $size);
                $dataSection .= $entryData;
            } elseif ($entry['type'] === 'subbatch') {
                $codec = is_scalar($entry['codec']) ? (int) $entry['codec'] : 0;
                $innerEntries = is_array($entry['entries']) ? $entry['entries'] : [];
                $count = count($innerEntries);

                // Sub-batch header: 1 byte (T=1, codec in bits 6-4) + numRecords (uint16)
                $dataSection .= pack('C', 0x80 | (($codec & 0x07) << 4));
                $dataSection .= pack('n', $count);

                $innerData = '';
                foreach ($innerEntries as $innerEntry) {
                    $innerEntryArr = is_array($innerEntry) ? $innerEntry : [];
                    $rawData = $innerEntryArr['data'] ?? '';
                    $innerEntryData = is_scalar($rawData) ? (string) $rawData : '';
                    $innerData .= pack('N', strlen($innerEntryData));
                    $innerData .= $innerEntryData;
                }

                $uncompressedSize = strlen($innerData);
                $dataSection .= pack('N', $uncompressedSize);
                $dataSection .= pack('N', $uncompressedSize);
                $dataSection .= $innerData;
            }
        }

        $dataLength = strlen($dataSection);
        $trailerLength = 0;

        $header = '';
        $header .= pack('C', 0x50); // MagicVersion: magic=5, version=0
        $header .= pack('C', 0x00); // ChunkType: 0 = user data
        $header .= pack('n', $numEntries);
        $header .= pack('N', $numRecords);
        $header .= pack('J', $timestamp);
        $header .= pack('J', 1);
        $header .= pack('J', $chunkFirstOffset);
        $header .= pack('N', 0);
        $header .= pack('N', $dataLength);
        $header .= pack('N', $trailerLength);
        $header .= pack('C', 0);   // BloomSize (uint8)
        $header .= "\x00\x00\x00"; // Reserved (3 bytes)

        return $header . $dataSection;
    }
}
