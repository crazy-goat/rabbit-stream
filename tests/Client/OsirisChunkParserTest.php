<?php

declare(strict_types=1);

namespace CrazyGoat\RabbitStream\Tests\Client;

use CrazyGoat\RabbitStream\Client\ChunkEntry;
use CrazyGoat\RabbitStream\Client\Message;
use CrazyGoat\RabbitStream\Client\OsirisChunkParser;
use CrazyGoat\RabbitStream\Exception\DeserializationException;
use CrazyGoat\RabbitStream\Exception\InvalidArgumentException;
use CrazyGoat\RabbitStream\Tests\Support\AmqpFixtures;
use DeflateContext;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunInSeparateProcess;
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

    public function testBothParsingPathsRejectRecordCountMismatches(): void
    {
        foreach ([false, true] as $useViews) {
            foreach ([false, true] as $subBatch) {
                $actualRecords = 2;
                $entries = $subBatch
                    ? [['type' => 'subbatch', 'codec' => 0, 'entries' => [
                        ['data' => 'First'],
                        ['data' => 'Second'],
                    ]]]
                    : [
                        ['type' => 'simple', 'data' => 'First'],
                        ['type' => 'simple', 'data' => 'Second'],
                    ];
                $numEntries = $subBatch ? 1 : 2;

                foreach ([$actualRecords - 1, $actualRecords + 1] as $declaredRecords) {
                    $chunk = $this->createChunk(
                        numEntries: $numEntries,
                        numRecords: $declaredRecords,
                        timestamp: 1234567890,
                        chunkFirstOffset: 0,
                        entries: $entries
                    );

                    try {
                        if ($useViews) {
                            iterator_to_array(OsirisChunkParser::parseMessages($chunk), false);
                        } else {
                            OsirisChunkParser::parse($chunk);
                        }
                        $this->fail(sprintf(
                            'Expected mismatch for %s entries through %s parsing path',
                            $subBatch ? 'sub-batch' : 'simple',
                            $useViews ? 'view' : 'raw'
                        ));
                    } catch (DeserializationException $e) {
                        $this->assertSame(
                            sprintf(
                                'Chunk declares %d records, but parsed %d records',
                                $declaredRecords,
                                $actualRecords
                            ),
                            $e->getMessage()
                        );
                    }
                }
            }
        }
    }

    public function testCorruptedChunkCrcThrowsDeserializationExceptionNamingFirstOffset(): void
    {
        $chunk = $this->createChunk(
            numEntries: 1,
            numRecords: 1,
            timestamp: 1234567890,
            chunkFirstOffset: 42,
            entries: [
                ['type' => 'simple', 'data' => 'Hello World'],
            ]
        );

        // Corrupt one byte inside the data section (past the 48-byte header),
        // leaving the header — and its declared CRC — untouched.
        $corrupted = $chunk;
        $corrupted[60] = $corrupted[60] === 'X' ? 'Y' : 'X';

        try {
            OsirisChunkParser::parse($corrupted);
            $this->fail('Expected DeserializationException for a corrupted chunk CRC');
        } catch (DeserializationException $e) {
            $this->assertStringContainsString('CRC mismatch', $e->getMessage());
            $this->assertStringContainsString('offset 42', $e->getMessage());
        }
    }

    public function testCrcVerificationCanBeDisabled(): void
    {
        $chunk = $this->createChunk(
            numEntries: 1,
            numRecords: 1,
            timestamp: 1234567890,
            chunkFirstOffset: 7,
            entries: [
                ['type' => 'simple', 'data' => 'Hello World'],
            ]
        );

        $corrupted = $chunk;
        $corrupted[60] = $corrupted[60] === 'X' ? 'Y' : 'X';

        $entries = OsirisChunkParser::parse($corrupted, verifyCrc: false);

        $this->assertCount(1, $entries);
        $this->assertSame(7, $entries[0]->getOffset());
    }

    public function testParseMessagesAlsoVerifiesChunkCrc(): void
    {
        $chunk = $this->createChunk(
            numEntries: 1,
            numRecords: 1,
            timestamp: 1234567890,
            chunkFirstOffset: 9,
            entries: [
                ['type' => 'simple', 'data' => 'Hello World'],
            ]
        );

        $corrupted = $chunk;
        $corrupted[60] = $corrupted[60] === 'X' ? 'Y' : 'X';

        $this->expectException(DeserializationException::class);
        $this->expectExceptionMessage('CRC mismatch');

        iterator_to_array(OsirisChunkParser::parseMessages($corrupted), false);
    }

    public function testEmptyChunkCrcVerifiesOverEmptyDataSection(): void
    {
        // #403 round-1 review: a chunk with dataLength = 0 has an empty data
        // section; crc32('') = 0, and the header must declare exactly that.
        // The chunk must parse cleanly with CRC verification on.
        $chunk = $this->createChunk(
            numEntries: 0,
            numRecords: 0,
            timestamp: 1234567890,
            chunkFirstOffset: 0,
            entries: []
        );

        $entries = OsirisChunkParser::parse($chunk);

        $this->assertSame([], $entries);
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
        $this->expectExceptionMessage('Unsupported sub-batch codec 2 (SNAPPY)');

        $chunk = $this->createChunk(
            numEntries: 1,
            numRecords: 2,
            timestamp: 1234567890,
            chunkFirstOffset: 0,
            entries: [
                ['type' => 'subbatch', 'codec' => 2, 'entries' => [['data' => 'test']]],
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
        $this->expectExceptionMessage('Unsupported sub-batch codec 4 (ZSTD): only NONE (0) and GZIP (1) are supported');

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

    public function testGzipSubBatchDecodesToSameRecordsAsUncompressedSubBatch(): void
    {
        $this->requireZlib();
        $records = [
            $this->encodeAmqpData('first'),
            $this->encodeAmqpData('second'),
            $this->encodeAmqpData('third'),
        ];
        $inner = $this->innerRecords($records);

        $gzipChunk = $this->chunkWithDataSection(
            $this->subBatchEntry(1, count($records), strlen($inner), self::gzipBytes($inner)),
            numEntries: 1,
            numRecords: count($records)
        );
        $plainChunk = $this->chunkWithDataSection(
            $this->subBatchEntry(0, count($records), strlen($inner), $inner),
            numEntries: 1,
            numRecords: count($records)
        );

        $expected = OsirisChunkParser::parse($plainChunk);
        $entries = OsirisChunkParser::parse($gzipChunk);

        $this->assertCount(3, $entries);
        foreach ($expected as $i => $expectedEntry) {
            $this->assertSame($expectedEntry->getOffset(), $entries[$i]->getOffset());
            $this->assertSame($expectedEntry->getData(), $entries[$i]->getData());
            $this->assertSame($expectedEntry->getTimestamp(), $entries[$i]->getTimestamp());
        }

        $messages = iterator_to_array(OsirisChunkParser::parseMessages($gzipChunk), false);

        $this->assertCount(3, $messages);
        $offsets = [];
        $bodies = [];
        foreach ($messages as $message) {
            $offsets[] = $message->getOffset();
            $bodies[] = $message->getBody();
        }
        $this->assertSame(['first', 'second', 'third'], $bodies);
        $this->assertSame([0, 1, 2], $offsets);
        $this->assertSame(1234567890, $messages[0]->getTimestamp());
    }

    public function testGzipSubBatchMixedWithSimpleEntriesKeepsOffsetsInOrder(): void
    {
        $this->requireZlib();
        $inner = $this->innerRecords([$this->encodeAmqpData('Batch 1'), $this->encodeAmqpData('Batch 2')]);
        $dataSection = $this->simpleEntry($this->encodeAmqpData('First'))
            . $this->subBatchEntry(1, 2, strlen($inner), self::gzipBytes($inner))
            . $this->simpleEntry($this->encodeAmqpData('Last'));

        $chunk = $this->chunkWithDataSection($dataSection, numEntries: 3, numRecords: 4);

        $entries = OsirisChunkParser::parse($chunk);

        $this->assertCount(4, $entries);
        $entryOffsets = [];
        $entryData = [];
        foreach ($entries as $entry) {
            $entryOffsets[] = $entry->getOffset();
            $entryData[] = $entry->getData();
        }
        $this->assertSame([0, 1, 2, 3], $entryOffsets);
        $this->assertSame(
            [
                $this->encodeAmqpData('First'),
                $this->encodeAmqpData('Batch 1'),
                $this->encodeAmqpData('Batch 2'),
                $this->encodeAmqpData('Last'),
            ],
            $entryData
        );

        $messages = iterator_to_array(OsirisChunkParser::parseMessages($chunk), false);

        $messageOffsets = [];
        $bodies = [];
        foreach ($messages as $message) {
            $messageOffsets[] = $message->getOffset();
            $bodies[] = $message->getBody();
        }
        $this->assertSame([0, 1, 2, 3], $messageOffsets);
        $this->assertSame(['First', 'Batch 1', 'Batch 2', 'Last'], $bodies);
    }

    public function testGzipSubBatchWithManySmallRecordsIsNotRejectedByCompressedSizeCheck(): void
    {
        $this->requireZlib();
        // 200 zero-size records: 800 bytes of records, but gzip shrinks them well below
        // 4 bytes per record, which must not trip the codec-0 minimum-size check.
        $inner = $this->innerRecords(array_fill(0, 200, ''));
        $body = self::gzipBytes($inner);
        $this->assertLessThan(4 * 200, strlen($body));

        $chunk = $this->chunkWithDataSection(
            $this->subBatchEntry(1, 200, strlen($inner), $body),
            numEntries: 1,
            numRecords: 200
        );

        $entries = OsirisChunkParser::parse($chunk);

        $this->assertCount(200, $entries);
        $this->assertSame(199, $entries[199]->getOffset());
    }

    public function testGzipSubBatchWithZeroRecordsAndZeroSizeParsesToNoEntries(): void
    {
        $this->requireZlib();
        $chunk = $this->chunkWithDataSection(
            $this->subBatchEntry(1, 0, 0, self::gzipBytes('')),
            numEntries: 1,
            numRecords: 0
        );

        $this->assertSame([], OsirisChunkParser::parse($chunk));
    }

    public function testGzipSubBatchInflatingPastZeroDeclaredSizeIsRejected(): void
    {
        $this->requireZlib();
        // A declared size of 0 must not disable the cap: gzdecode() reads a max length
        // of 0 as no limit. The body inflates to 1 byte, which must be rejected.
        $chunk = $this->chunkWithDataSection(
            $this->subBatchEntry(1, 0, 0, self::gzipBytes('x')),
            numEntries: 1,
            numRecords: 0
        );

        $this->assertDecodeFailsOnBothPaths($chunk, 'inflates to 1 bytes but declares uncompressedSize 0');
    }

    /**
     * @return iterable<string, array{0: int, 1: string}>
     */
    public static function unsupportedCodecProvider(): iterable
    {
        yield 'snappy' => [2, 'Unsupported sub-batch codec 2 (SNAPPY): only NONE (0) and GZIP (1) are supported'];
        yield 'lz4' => [3, 'Unsupported sub-batch codec 3 (LZ4): only NONE (0) and GZIP (1) are supported'];
        yield 'reserved 5' => [5, 'Unsupported sub-batch codec 5 (UNKNOWN): only NONE (0) and GZIP (1) are supported'];
        yield 'reserved 7' => [7, 'Unsupported sub-batch codec 7 (UNKNOWN): only NONE (0) and GZIP (1) are supported'];
    }

    #[DataProvider('unsupportedCodecProvider')]
    public function testUnsupportedSubBatchCodecIsNamedOnBothPaths(int $codec, string $expectedMessage): void
    {
        $inner = $this->innerRecords(['test']);
        $chunk = $this->chunkWithDataSection(
            $this->subBatchEntry($codec, 1, strlen($inner), $inner),
            numEntries: 1,
            numRecords: 1
        );

        $this->assertDecodeFailsOnBothPaths($chunk, $expectedMessage);
    }

    /**
     * @return iterable<string, array{0: int, 1: string}>
     */
    public static function gzipSizeMismatchProvider(): iterable
    {
        // The body inflates to 8 bytes. A larger declared size fails the length check.
        yield 'declared larger than inflated' => [12, 'inflates to 8 bytes but declares uncompressedSize 12'];
        // A smaller declared size stops the inflate at the cap, which fails as corrupt.
        yield 'declared smaller than inflated' => [4, 'corrupt or inflates to more than its uncompressedSize 4'];
    }

    #[DataProvider('gzipSizeMismatchProvider')]
    public function testGzipSubBatchSizeMismatchIsRejectedOnBothPaths(int $declaredSize, string $expectedMessage): void
    {
        $this->requireZlib();
        $inner = $this->innerRecords(['test']);
        $chunk = $this->chunkWithDataSection(
            $this->subBatchEntry(1, 1, $declaredSize, self::gzipBytes($inner)),
            numEntries: 1,
            numRecords: 1
        );

        $this->assertDecodeFailsOnBothPaths($chunk, $expectedMessage);
    }

    /**
     * @return iterable<string, array{0: string}>
     */
    public static function corruptGzipBodyProvider(): iterable
    {
        yield 'not a gzip stream' => ['this is not gzip data at all'];
        yield 'truncated gzip stream' => [substr(self::gzipBytes(str_repeat('a', 200)), 0, 20)];
    }

    #[DataProvider('corruptGzipBodyProvider')]
    public function testCorruptGzipSubBatchIsRejectedOnBothPaths(string $body): void
    {
        $this->requireZlib();
        $chunk = $this->chunkWithDataSection(
            $this->subBatchEntry(1, 1, 256, $body),
            numEntries: 1,
            numRecords: 1
        );

        $this->assertDecodeFailsOnBothPaths(
            $chunk,
            'GZIP sub-batch is corrupt or inflates to more than its uncompressedSize 256'
        );
    }

    /**
     * The memory limit is changed below, so the test runs in its own process. A failed
     * run then cannot leave the limit changed for the other tests.
     */
    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function testGzipDecompressionBombIsCappedByDeclaredSize(): void
    {
        $this->requireZlib();
        // 20 MiB of zeros compresses to roughly 20 KB. The sub-batch declares 1 KiB.
        $bomb = $this->gzipZeros(20 * 1024 * 1024);
        $this->assertLessThan(64 * 1024, strlen($bomb));

        $chunk = $this->chunkWithDataSection(
            $this->subBatchEntry(1, 1, 1024, $bomb),
            numEntries: 1,
            numRecords: 1
        );

        // Cap the memory limit a little above the current usage. An uncapped inflate of
        // 20 MiB then exhausts it. A capped inflate stops near 1 KiB and stays well under it.
        $previousLimit = ini_set('memory_limit', (string) (memory_get_usage(true) + 8 * 1024 * 1024));
        try {
            $this->assertDecodeFailsOnBothPaths(
                $chunk,
                'GZIP sub-batch is corrupt or inflates to more than its uncompressedSize 1024'
            );
        } finally {
            ini_set('memory_limit', is_string($previousLimit) ? $previousLimit : '-1');
        }
    }

    public function testGzipSubBatchOverPerChunkBudgetIsRejectedBeforeInflate(): void
    {
        $this->requireZlib();
        $budget = 32 * 1024 * 1024;
        // The declared size is one byte over the budget and the body is tiny. An inflate
        // would fail with a size mismatch instead, so the budget message shows that the
        // size check runs before the inflate.
        $chunk = $this->chunkWithDataSection(
            $this->subBatchEntry(1, 1, $budget + 1, self::gzipBytes('tiny')),
            numEntries: 1,
            numRecords: 1
        );

        $this->assertDecodeFailsOnBothPaths(
            $chunk,
            'GZIP sub-batch declares uncompressedSize 33554433; the chunk total of declared GZIP sizes ' .
            'would be 33554433, above the per-chunk budget of 33554432 bytes'
        );
    }

    public function testGzipSubBatchesEachWithinBudgetThatTogetherExceedItAreRejectedOnBothPaths(): void
    {
        $this->requireZlib();
        $size = 17 * 1024 * 1024;
        // Each sub-batch fits the budget alone. The first inflates to 17 MiB of zeros. Its one
        // record is 0 bytes long, and the parser does not read the other zeros.
        $first = $this->subBatchEntry(1, 1, $size, $this->gzipZeros($size));
        $this->assertCount(
            1,
            OsirisChunkParser::parse($this->chunkWithDataSection($first, numEntries: 1, numRecords: 1))
        );

        // The second body is tiny. The budget check must reject it before any inflate.
        $second = $this->subBatchEntry(1, 1, $size, self::gzipBytes('tiny'));
        $chunk = $this->chunkWithDataSection($first . $second, numEntries: 2, numRecords: 2);

        $this->assertDecodeFailsOnBothPaths(
            $chunk,
            'GZIP sub-batch declares uncompressedSize 17825792; the chunk total of declared GZIP sizes ' .
            'would be 35651584, above the per-chunk budget of 33554432 bytes'
        );
    }

    public function testGzipSubBatchAtExactlyTheBudgetParsesOnBothPaths(): void
    {
        $this->requireZlib();
        $budget = 32 * 1024 * 1024;
        // The sub-batch inflates to exactly the budget. Its one record is 0 bytes long, and
        // the other inflated bytes are not read, as in
        // testExtraBytesInsideSubBatchAfterTheDeclaredRecordAreIgnoredTolerantly.
        $chunk = $this->chunkWithDataSection(
            $this->subBatchEntry(1, 1, $budget, $this->gzipZeros($budget)),
            numEntries: 1,
            numRecords: 1
        );

        $this->assertCount(1, OsirisChunkParser::parse($chunk));
        $this->assertCount(1, iterator_to_array(OsirisChunkParser::parseMessages($chunk), false));
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
        // The mutation above is deliberate (it is the case under test), so the
        // data section no longer matches the CRC computed at build time — CRC
        // verification is disabled to reach the sub-batch validation itself.
        OsirisChunkParser::parse($chunk, verifyCrc: false);
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
        // Deliberate mutation (the case under test) invalidates the build-time
        // CRC — verification is disabled to reach the sub-batch validation.
        OsirisChunkParser::parse($chunk, verifyCrc: false);
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
        $header .= pack('N', crc32($dataSection));                   // chunkCrc
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
        $header .= pack('N', crc32($dataSection));
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

    private function requireZlib(): void
    {
        if (!function_exists('gzencode')) {
            self::markTestSkipped('ext-zlib is needed to build gzip sub-batch fixtures');
        }
    }

    /**
     * @param list<string> $records Record bytes; each is written with a uint32 size prefix.
     */
    private function innerRecords(array $records): string
    {
        $inner = '';
        foreach ($records as $record) {
            $inner .= pack('N', strlen($record)) . $record;
        }

        return $inner;
    }

    private function simpleEntry(string $data): string
    {
        return pack('N', strlen($data)) . $data;
    }

    /**
     * One sub-batch entry with the given header fields and body bytes, written as is.
     */
    private function subBatchEntry(int $codec, int $numRecords, int $uncompressedSize, string $body): string
    {
        return pack('C', 0x80 | (($codec & 0x07) << 4))
            . pack('n', $numRecords)
            . pack('N', $uncompressedSize)
            . pack('N', strlen($body))
            . $body;
    }

    /**
     * Streams $size zero bytes through a gzip encoder in small pieces, so the test
     * itself never holds the uncompressed payload in memory.
     */
    private function gzipZeros(int $size): string
    {
        $context = deflate_init(ZLIB_ENCODING_GZIP);
        if ($context === false) {
            self::fail('deflate_init() failed');
        }
        $piece = str_repeat("\0", 65536);
        $output = '';
        for ($written = 0; $written < $size; $written += strlen($piece)) {
            $output .= $this->deflateStep($context, $piece, ZLIB_NO_FLUSH);
        }

        return $output . $this->deflateStep($context, '', ZLIB_FINISH);
    }

    private function deflateStep(DeflateContext $context, string $data, int $flush): string
    {
        $chunk = deflate_add($context, $data, $flush);
        if ($chunk === false) {
            self::fail('deflate_add() failed');
        }

        return $chunk;
    }

    /**
     * RFC 1952 gzip stream, as the Java client's GZIPOutputStream writes it.
     */
    private static function gzipBytes(string $data): string
    {
        $encoded = gzencode($data);
        if ($encoded === false) {
            self::fail('gzencode() failed');
        }

        return $encoded;
    }

    /**
     * Both parse paths must reject the chunk with a DeserializationException whose
     * message contains $expectedMessage.
     */
    private function assertDecodeFailsOnBothPaths(string $chunk, string $expectedMessage): void
    {
        $decoders = [
            'parse' => static fn (string $bytes): array => OsirisChunkParser::parse($bytes),
            'parseMessages' => static fn (string $bytes): array =>
                iterator_to_array(OsirisChunkParser::parseMessages($bytes), false),
        ];

        foreach ($decoders as $path => $decode) {
            try {
                $decode($chunk);
                self::fail(sprintf('%s accepted a chunk that must be rejected', $path));
            } catch (DeserializationException $exception) {
                self::assertStringContainsString($expectedMessage, $exception->getMessage(), $path);
            }
        }
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

    public function testParseMessagesPassesMaxDepthToEachMessage(): void
    {
        // #450: parseMessages() is the Consumer hot path and builds every Message
        // itself, so the operator's depth limit has to be forwarded here too.
        $entry = AmqpFixtures::messageWithNestedBody(4);
        $chunk = $this->createChunk(
            numEntries: 1,
            numRecords: 1,
            timestamp: 1234567890,
            chunkFirstOffset: 0,
            entries: [['type' => 'simple', 'data' => $entry]]
        );

        $shallow = iterator_to_array(OsirisChunkParser::parseMessages($chunk, maxDepth: 2), false);
        $this->assertCount(1, $shallow);
        try {
            $shallow[0]->getBody();
            $this->fail('Expected a depth limit of 2 to reject a 4-deep body');
        } catch (DeserializationException $e) {
            $this->assertStringContainsString('recursion depth limit exceeded (max 2)', $e->getMessage());
        }

        $default = iterator_to_array(OsirisChunkParser::parseMessages($chunk), false);
        $this->assertSame([[[[null]]]], $default[0]->getBody());
    }

    // ========== Extra bytes inside the declared data section (issue #944, cases 5 and 6) ==========

    public function testExtraBytesInsideDataSectionAfterTheDeclaredEntryAreIgnoredTolerantly(): void
    {
        // Case 5: 8 bytes follow the one declared entry, inside dataLength and the CRC.
        // The parser reads the declared count and ignores the rest.
        $dataSection = pack('N', 5) . 'Hello' . 'JUNKJUNK';
        $chunk = $this->chunkWithDataSection($dataSection, numEntries: 1, numRecords: 1);

        $entries = OsirisChunkParser::parse($chunk);

        $this->assertCount(1, $entries);
        $this->assertSame('Hello', $entries[0]->getData());
    }

    public function testExtraBytesInsideSubBatchAfterTheDeclaredRecordAreIgnoredTolerantly(): void
    {
        // Case 6: the sub-batch declares one record, and 6 bytes follow it inside the
        // compressed size. The parser reads the declared record count and ignores the rest.
        $subBatchBody = pack('N', 5) . 'Hello' . "\xaa\xbb\xcc\xdd\xee\xff";
        $dataSection = "\x80" . pack('n', 1)
            . pack('N', strlen($subBatchBody))
            . pack('N', strlen($subBatchBody))
            . $subBatchBody;
        $chunk = $this->chunkWithDataSection($dataSection, numEntries: 1, numRecords: 1);

        $entries = OsirisChunkParser::parse($chunk);

        $this->assertCount(1, $entries);
        $this->assertSame('Hello', $entries[0]->getData());
    }

    /**
     * Build a chunk whose header matches $dataSection exactly, so the CRC and dataLength
     * are valid. The caller controls the bytes inside the data section.
     */
    private function chunkWithDataSection(string $dataSection, int $numEntries, int $numRecords): string
    {
        $header = pack('C', 0x50);
        $header .= pack('C', 0x00);
        $header .= pack('n', $numEntries);
        $header .= pack('N', $numRecords);
        $header .= pack('J', 1234567890);                // timestamp
        $header .= pack('J', 1);                         // epoch
        $header .= pack('J', 0);                         // chunkFirstOffset
        $header .= pack('N', crc32($dataSection));       // chunkCrc
        $header .= pack('N', strlen($dataSection));      // dataLength
        $header .= pack('N', 0);                         // trailerLength
        $header .= pack('C', 0);                         // bloomSize
        $header .= "\x00\x00\x00";                       // reserved

        return $header . $dataSection;
    }

    public function testOffsetOverflowPastIntMaxThrowsDeserializationExceptionOnBothPaths(): void
    {
        // #927: a second record at PHP_INT_MAX + 1 must not reach the int-typed
        // ChunkEntry and Message constructors (that raised a TypeError).
        $chunk = $this->createChunk(
            numEntries: 2,
            numRecords: 2,
            timestamp: 1234567890,
            chunkFirstOffset: PHP_INT_MAX,
            entries: [
                ['type' => 'simple', 'data' => 'First'],
                ['type' => 'simple', 'data' => 'Second'],
            ]
        );

        try {
            OsirisChunkParser::parse($chunk);
            $this->fail('Expected parse() to reject offsets past PHP_INT_MAX');
        } catch (DeserializationException $e) {
            $this->assertStringContainsString('PHP_INT_MAX', $e->getMessage());
        }

        try {
            iterator_to_array(OsirisChunkParser::parseMessages($chunk), false);
            $this->fail('Expected parseMessages() to reject offsets past PHP_INT_MAX');
        } catch (DeserializationException $e) {
            $this->assertStringContainsString('PHP_INT_MAX', $e->getMessage());
        }
    }

    /**
     * #927 review round 1: a chunk that holds more records than it declares must
     * also fail with a DeserializationException, not a float-offset TypeError.
     *
     * @return array<string, array{int, array<int, array<string, mixed>>, int}>
     */
    public static function underDeclaredRecordProvider(): array
    {
        $simple = [
            ['type' => 'simple', 'data' => 'First'],
            ['type' => 'simple', 'data' => 'Second'],
        ];
        $subBatch = [[
            'type' => 'subbatch',
            'codec' => 0,
            'entries' => [['data' => 'First'], ['data' => 'Second']],
        ]];

        return [
            'zero declared, two simple entries' => [2, $simple, 0],
            'one declared, two simple entries' => [2, $simple, 1],
            'one declared, sub-batch of two records' => [1, $subBatch, 1],
        ];
    }

    /**
     * @param array<int, array<string, mixed>> $entries
     */
    #[DataProvider('underDeclaredRecordProvider')]
    public function testUnderDeclaredRecordCountPastIntMaxThrowsDeserializationException(
        int $numEntries,
        array $entries,
        int $numRecords
    ): void {
        $chunk = $this->createChunk(
            numEntries: $numEntries,
            numRecords: $numRecords,
            timestamp: 1234567890,
            chunkFirstOffset: PHP_INT_MAX,
            entries: $entries
        );

        try {
            OsirisChunkParser::parse($chunk);
            $this->fail('Expected parse() to reject records past PHP_INT_MAX');
        } catch (DeserializationException $e) {
            $this->assertStringContainsString('exceeds PHP_INT_MAX', $e->getMessage());
        }

        try {
            iterator_to_array(OsirisChunkParser::parseMessages($chunk), false);
            $this->fail('Expected parseMessages() to reject records past PHP_INT_MAX');
        } catch (DeserializationException $e) {
            $this->assertStringContainsString('exceeds PHP_INT_MAX', $e->getMessage());
        }
    }

    public function testOffsetsEndingExactlyAtIntMaxParse(): void
    {
        // #927: the boundary cases that fit in an int keep their offsets.
        $single = $this->createChunk(
            numEntries: 1,
            numRecords: 1,
            timestamp: 1234567890,
            chunkFirstOffset: PHP_INT_MAX,
            entries: [['type' => 'simple', 'data' => 'Only']]
        );
        $pair = $this->createChunk(
            numEntries: 2,
            numRecords: 2,
            timestamp: 1234567890,
            chunkFirstOffset: PHP_INT_MAX - 1,
            entries: [
                ['type' => 'simple', 'data' => 'First'],
                ['type' => 'simple', 'data' => 'Second'],
            ]
        );

        $this->assertSame([PHP_INT_MAX], array_map(
            static fn (ChunkEntry $e): int => $e->getOffset(),
            OsirisChunkParser::parse($single)
        ));
        $this->assertSame([PHP_INT_MAX - 1, PHP_INT_MAX], array_map(
            static fn (ChunkEntry $e): int => $e->getOffset(),
            OsirisChunkParser::parse($pair)
        ));
        $this->assertSame([PHP_INT_MAX - 1, PHP_INT_MAX], array_map(
            static fn (Message $m): int => $m->getOffset(),
            iterator_to_array(OsirisChunkParser::parseMessages($pair), false)
        ));
    }
}
