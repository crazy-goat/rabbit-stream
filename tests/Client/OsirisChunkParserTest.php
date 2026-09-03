<?php

declare(strict_types=1);

namespace CrazyGoat\RabbitStream\Tests\Client;

use CrazyGoat\RabbitStream\Client\ChunkEntry;
use CrazyGoat\RabbitStream\Client\Message;
use CrazyGoat\RabbitStream\Client\OsirisChunkParser;
use CrazyGoat\RabbitStream\Exception\DeserializationException;
use CrazyGoat\RabbitStream\Exception\InvalidArgumentException;
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
        $this->expectException(DeserializationException::class);
        $this->expectExceptionMessage('Invalid chunk magic: expected 5, got 0');

        // 0x00 has magic=0, version=0 → invalid magic
        $chunk = "\x00" . str_repeat("\x00", 100);
        OsirisChunkParser::parse($chunk);
    }

    public function testUnsupportedChunkVersionThrowsException(): void
    {
        $this->expectException(DeserializationException::class);
        $this->expectExceptionMessage('Unsupported chunk version: expected 0, got 1');

        // 0x51 has magic=5, version=1 → unsupported version
        $chunk = "\x51" . str_repeat("\x00", 100);
        OsirisChunkParser::parse($chunk);
    }

    public function testUnsupportedChunkTypeThrowsException(): void
    {
        $this->expectException(DeserializationException::class);
        $this->expectExceptionMessage('Unsupported chunk type');

        // 0x50 = valid magic+version, then 0x01 = invalid chunk type
        $chunk = "\x50\x01" . str_repeat("\x00", 100);
        OsirisChunkParser::parse($chunk);
    }

    public function testCompressedSubBatchThrowsException(): void
    {
        $this->expectException(DeserializationException::class);
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
        $this->expectException(DeserializationException::class);
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
        $this->expectException(DeserializationException::class);

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
        $this->expectException(DeserializationException::class);

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

    public function testDataLengthExceedingChunkSizeThrowsException(): void
    {
        $this->expectException(DeserializationException::class);
        $this->expectExceptionMessage('dataLength');

        $chunk = $this->createChunk(
            numEntries: 1,
            numRecords: 1,
            timestamp: 1234567890,
            chunkFirstOffset: 0,
            entries: [
                ['type' => 'simple', 'data' => 'Hello World'],
            ]
        );
        // Declare 1000 more data bytes than the chunk actually carries.
        $chunk = substr_replace($chunk, pack('N', 1000 + 11), 36, 4);
        OsirisChunkParser::parse($chunk);
    }

    public function testNonzeroTrailerLengthWithoutTrailerBytesParses(): void
    {
        // On the stream-protocol wire, Deliver frames omit the trailer for
        // user-data chunks (osiris select_amount_to_send(user_data, ...) sends
        // data only) while the header still declares its on-disk size. A chunk
        // with a nonzero trailerLength field and no trailer bytes is therefore
        // legitimate and must parse.
        $chunk = $this->createChunk(
            numEntries: 1,
            numRecords: 1,
            timestamp: 1234567890,
            chunkFirstOffset: 0,
            entries: [
                ['type' => 'simple', 'data' => 'Hello World'],
            ]
        );
        // Declare a 512-byte trailer that was never sent.
        $chunk = substr_replace($chunk, pack('N', 512), 40, 4);

        $entries = OsirisChunkParser::parse($chunk);

        $this->assertCount(1, $entries);
        $this->assertSame('Hello World', $entries[0]->getData());
    }

    public function testEntryBeyondDeclaredDataSectionThrows(): void
    {
        // A chunk whose header declares dataLength covering only the first entry.
        // A second entry is physically present after the declared data section —
        // the parser must not read it, because it sits outside the data section;
        // hitting the end of the bounded sub-buffer must throw instead.
        $this->expectException(DeserializationException::class);

        $chunk = $this->createChunk(
            numEntries: 1,
            numRecords: 1,
            timestamp: 1234567890,
            chunkFirstOffset: 0,
            entries: [
                ['type' => 'simple', 'data' => 'Hello'],
            ]
        );
        // Header claims 1 entry, but we append a second entry (size 5, "World")
        // after the declared data section and bump numEntries to 2.
        $chunk = substr_replace($chunk, pack('n', 2), 2, 2);
        $chunk .= pack('N', 5) . 'World';
        OsirisChunkParser::parse($chunk);
    }

    public function testTrailingBytesAfterDeclaredSectionsAreIgnored(): void
    {
        // Tolerant direction: extra bytes after header + data + trailer are not
        // an error (the declared sections still fit), they are simply ignored.
        $chunk = $this->createChunk(
            numEntries: 1,
            numRecords: 1,
            timestamp: 1234567890,
            chunkFirstOffset: 0,
            entries: [
                ['type' => 'simple', 'data' => 'Hello'],
            ]
        );
        $chunk .= "\x00\x00\x00\x05World"; // garbage beyond the declared sections

        $entries = OsirisChunkParser::parse($chunk);

        $this->assertCount(1, $entries);
        $this->assertSame('Hello', $entries[0]->getData());
    }

    public function testSubBatchDeclaringMoreRecordsThanDataThrowsException(): void
    {
        $this->expectException(DeserializationException::class);
        $this->expectExceptionMessage('Sub-batch declares 5 records but only 4 bytes of data');

        // Sub-batch claiming 5 records, but its body is a single 4-byte size
        // prefix (one zero-size record) — 5 records need at least 20 bytes.
        $chunk = $this->createChunk(
            numEntries: 1,
            numRecords: 5,
            timestamp: 1234567890,
            chunkFirstOffset: 0,
            entries: [
                ['type' => 'subbatch', 'codec' => 0, 'entries' => [
                    ['data' => ''],
                ]],
            ]
        );
        $chunk = substr_replace($chunk, pack('n', 5), 49, 2); // numRecords in sub-batch header
        OsirisChunkParser::parse($chunk);
    }

    public function testSubBatchUncompressedSizeCannotHoldRecordsThrowsException(): void
    {
        $this->expectException(DeserializationException::class);
        $this->expectExceptionMessage('uncompressedSize 4 cannot hold them');

        // Sub-batch claiming 5 records with an uncompressedSize of only 4 bytes
        // (one zero-size record's worth) — 5 records need at least 20 bytes.
        // Mirrors the broker's own publish-time validation
        // (check_message_count_fits_uncompressed_size in rabbit_stream_utils.erl).
        $chunk = $this->createChunk(
            numEntries: 1,
            numRecords: 5,
            timestamp: 1234567890,
            chunkFirstOffset: 0,
            entries: [
                ['type' => 'subbatch', 'codec' => 0, 'entries' => array_fill(0, 5, ['data' => ''])],
            ]
        );
        // uncompressedSize field at 51..54 (48-byte chunk header + 1 entry-type
        // byte + 2 bytes of sub-batch numRecords).
        $chunk = substr_replace($chunk, pack('N', 4), 51, 4);
        OsirisChunkParser::parse($chunk);
    }

    public function testCustomMaxEntriesPerChunkAboveDefaultParsesLargerChunk(): void
    {
        // Round-1 review F1: workloads that legitimately exceed the default
        // ceiling must be able to raise it per call. 280 000 records (5 sub-batches
        // x 56 000) sit above the 262 144 default and below the raised cap.
        $chunk = $this->createAmplificationChunk(subBatchCount: 5, recordsPerSubBatch: 56000);

        try {
            OsirisChunkParser::parse($chunk);
            $this->fail('Expected the default ceiling to reject a 280 000-record chunk');
        } catch (DeserializationException) {
            // expected: 280 000 > default 262 144
        }

        $entries = OsirisChunkParser::parse($chunk, maxEntriesPerChunk: 300000);

        $this->assertCount(280000, $entries);
    }

    public function testInLoopCeilingKeepsMemoryBounded(): void
    {
        // Round-1 review F3: when the header under-declares records (numRecords =
        // 0) the up-front check cannot fire, so the in-loop ceiling must bound
        // allocation (at most the cap, ~262 144 entries) and throw.
        $chunk = $this->createAmplificationChunk(subBatchCount: 5, recordsPerSubBatch: 65535);
        $chunk = substr_replace($chunk, pack('N', 0), 4, 4); // header numRecords = 0

        $before = memory_get_usage(true);
        try {
            OsirisChunkParser::parse($chunk);
            $this->fail('Expected DeserializationException from the in-loop ceiling');
        } catch (DeserializationException $e) {
            $allocated = memory_get_usage(true) - $before;
            $this->assertStringContainsString('maximum allowed per chunk', $e->getMessage());
            $this->assertLessThan(
                64 * 1024 * 1024,
                $allocated,
                sprintf(
                    'In-loop ceiling allocated %.1f MB — allocation must stay bounded (issue #399)',
                    $allocated / 1048576
                )
            );
        }
    }

    public function testMaxEntriesPerChunkGuardThrowsException(): void
    {
        $this->expectException(DeserializationException::class);
        $this->expectExceptionMessage('more than 5 entries');

        // Header declares only 5 records (passes the up-front header check), but
        // the sub-batch itself carries 10 — the in-loop ceiling must fire.
        $chunk = $this->createChunk(
            numEntries: 1,
            numRecords: 5,
            timestamp: 1234567890,
            chunkFirstOffset: 0,
            entries: [
                ['type' => 'subbatch', 'codec' => 0, 'entries' => array_fill(0, 10, ['data' => ''])],
            ]
        );

        OsirisChunkParser::parse($chunk, maxEntriesPerChunk: 5);
    }

    public function testMaxEntriesPerChunkExactlyAtLimitParses(): void
    {
        $chunk = $this->createChunk(
            numEntries: 1,
            numRecords: 10,
            timestamp: 1234567890,
            chunkFirstOffset: 0,
            entries: [
                ['type' => 'subbatch', 'codec' => 0, 'entries' => array_fill(0, 10, ['data' => ''])],
            ]
        );

        $entries = OsirisChunkParser::parse($chunk, maxEntriesPerChunk: 10);

        $this->assertCount(10, $entries);
    }

    public function testMaxEntriesPerChunkBelowOneThrowsException(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('maxEntriesPerChunk must be at least 1');

        $chunk = $this->createChunk(
            numEntries: 0,
            numRecords: 0,
            timestamp: 1234567890,
            chunkFirstOffset: 0,
            entries: []
        );

        OsirisChunkParser::parse($chunk, maxEntriesPerChunk: 0);
    }

    public function testAmplificationPayloadRejectedWithBoundedMemory(): void
    {
        // PoC-scale chunk from issue #399: 30 sub-batches x 65 535 zero-size
        // records claim 1 966 050 records in a ~7.86 MB chunk (144 bytes per
        // record would be 144 bytes on the wire). Parsing them all would
        // allocate ~200 MB of ChunkEntry objects. The parser must reject the
        // chunk up front and stay within a small fraction of that.
        $chunk = $this->createAmplificationChunk(subBatchCount: 30, recordsPerSubBatch: 65535);
        $this->assertLessThan(8 * 1024 * 1024, strlen($chunk));

        $before = memory_get_usage(true);
        try {
            OsirisChunkParser::parse($chunk);
            $this->fail('Expected DeserializationException for the amplification payload');
        } catch (DeserializationException) {
            $allocated = memory_get_usage(true) - $before;
            $this->assertLessThan(
                64 * 1024 * 1024,
                $allocated,
                sprintf(
                    'Parsing the amplification chunk allocated %.1f MB — the entry ceiling ' .
                    'must keep allocation bounded (issue #399)',
                    $allocated / 1048576
                )
            );
        }
    }

    /**
     * Builds an amplification chunk: $subBatchCount sub-batches, each carrying
     * $recordsPerSubBatch zero-size records (4 bytes each on the wire).
     */
    private function createAmplificationChunk(int $subBatchCount, int $recordsPerSubBatch): string
    {
        $recordPrefix = pack('N', 0); // zero-size inner entry: 4-byte size prefix
        $subBatchBody = str_repeat($recordPrefix, $recordsPerSubBatch);
        $uncompressedSize = strlen($subBatchBody);

        $dataSection = '';
        for ($i = 0; $i < $subBatchCount; $i++) {
            $dataSection .= pack('C', 0x80);              // sub-batch entry, codec 0
            $dataSection .= pack('n', $recordsPerSubBatch); // records in this sub-batch
            $dataSection .= pack('N', $uncompressedSize);   // uncompressedSize
            $dataSection .= pack('N', $uncompressedSize);   // compressedSize
            $dataSection .= $subBatchBody;
        }

        $header = pack('C', 0x50);
        $header .= pack('C', 0x00);
        $header .= pack('n', $subBatchCount);
        $header .= pack('N', $subBatchCount * $recordsPerSubBatch); // numRecords
        $header .= pack('J', 1234567890);                            // timestamp
        $header .= pack('J', 1);                                     // epoch
        $header .= pack('J', 0);                                     // chunkFirstOffset
        $header .= pack('N', 0);                                     // chunkCrc
        $header .= pack('N', strlen($dataSection));                  // dataLength
        $header .= pack('N', 0);                                     // trailerLength
        $header .= pack('C', 0);                                     // bloomSize
        $header .= "\x00\x00\x00";                                   // reserved

        return $header . $dataSection;
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

    public function testParseEntriesYieldsSameEntriesAsParse(): void
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

        $viaGenerator = iterator_to_array(OsirisChunkParser::parseEntries($chunk), false);
        $viaArray = OsirisChunkParser::parse($chunk);

        $this->assertCount(3, $viaGenerator);
        $this->assertEquals($viaArray, $viaGenerator);
    }

    public function testParseEntriesDoesNotMaterialiseEntriesUntilIterated(): void
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

        $generator = OsirisChunkParser::parseEntries($chunk);
        $this->assertInstanceOf(\Generator::class, $generator);

        // Nothing has been read from the generator yet — pulling only the first
        // entry must not force the rest to be parsed/allocated.
        $first = null;
        foreach ($generator as $entry) {
            $first = $entry;
            break;
        }

        $this->assertInstanceOf(ChunkEntry::class, $first);
        $this->assertSame('Message 1', $first->getData());
        $this->assertTrue($generator->valid(), 'Generator must still have entries left to yield');
    }

    public function testParseEntriesThrowsOnInvalidChunkOnlyWhenIterated(): void
    {
        $invalidChunk = "\x00invalid";

        $generator = OsirisChunkParser::parseEntries($invalidChunk);
        // Constructing the generator must not execute the function body yet.
        $this->addToAssertionCount(1);

        $this->expectException(DeserializationException::class);
        foreach ($generator as $entry) {
            // trigger iteration
        }
    }

    /** Encode a string as a standalone AMQP 1.0 Data section (vbin32), as this library's Producer does. */
    private function encodeAmqpData(string $body): string
    {
        return "\x00\x53\x75\xb0" . pack('N', strlen($body)) . $body;
    }

    public function testParseMessagesYieldsMessagesEquivalentToParseEntries(): void
    {
        $chunk = $this->createChunk(
            numEntries: 3,
            numRecords: 3,
            timestamp: 1234567890,
            chunkFirstOffset: 50,
            entries: [
                ['type' => 'simple', 'data' => $this->encodeAmqpData('Message 1')],
                ['type' => 'simple', 'data' => $this->encodeAmqpData('Message 2')],
                ['type' => 'simple', 'data' => $this->encodeAmqpData('Message 3')],
            ]
        );

        $entries = OsirisChunkParser::parse($chunk);
        $messages = iterator_to_array(OsirisChunkParser::parseMessages($chunk), false);

        $this->assertCount(3, $messages);
        foreach ($messages as $i => $message) {
            $this->assertInstanceOf(Message::class, $message);
            $this->assertSame($entries[$i]->getOffset(), $message->getOffset());
            $this->assertSame($entries[$i]->getTimestamp(), $message->getTimestamp());
        }
        $this->assertSame('Message 1', $messages[0]->getBody());
        $this->assertSame('Message 2', $messages[1]->getBody());
        $this->assertSame('Message 3', $messages[2]->getBody());
    }

    public function testParseMessagesYieldsMessagesForSubBatchEntries(): void
    {
        $innerEntries = [
            ['data' => $this->encodeAmqpData('SubMessage 1')],
            ['data' => $this->encodeAmqpData('SubMessage 2')],
        ];

        $chunk = $this->createChunk(
            numEntries: 1,
            numRecords: 2,
            timestamp: 999999999,
            chunkFirstOffset: 1000,
            entries: [
                ['type' => 'subbatch', 'codec' => 0, 'entries' => $innerEntries],
            ]
        );

        $messages = iterator_to_array(OsirisChunkParser::parseMessages($chunk), false);

        $this->assertCount(2, $messages);
        $this->assertSame(1000, $messages[0]->getOffset());
        $this->assertSame('SubMessage 1', $messages[0]->getBody());
        $this->assertSame(1001, $messages[1]->getOffset());
        $this->assertSame('SubMessage 2', $messages[1]->getBody());
        $this->assertSame(999999999, $messages[0]->getTimestamp());
    }

    public function testParseMessagesDoesNotMaterialiseUntilIterated(): void
    {
        $chunk = $this->createChunk(
            numEntries: 3,
            numRecords: 3,
            timestamp: 1234567890,
            chunkFirstOffset: 50,
            entries: [
                ['type' => 'simple', 'data' => $this->encodeAmqpData('Message 1')],
                ['type' => 'simple', 'data' => $this->encodeAmqpData('Message 2')],
                ['type' => 'simple', 'data' => $this->encodeAmqpData('Message 3')],
            ]
        );

        $generator = OsirisChunkParser::parseMessages($chunk);
        $this->assertInstanceOf(\Generator::class, $generator);

        $first = null;
        foreach ($generator as $message) {
            $first = $message;
            break;
        }

        $this->assertInstanceOf(Message::class, $first);
        $this->assertSame('Message 1', $first->getBody());
        $this->assertTrue($generator->valid(), 'Generator must still have entries left to yield');
    }

    public function testParseMessagesThrowsOnInvalidChunkOnlyWhenIterated(): void
    {
        $invalidChunk = "\x00invalid";

        $generator = OsirisChunkParser::parseMessages($invalidChunk);
        $this->addToAssertionCount(1);

        $this->expectException(DeserializationException::class);
        foreach ($generator as $message) {
            // trigger iteration
        }
    }
}
