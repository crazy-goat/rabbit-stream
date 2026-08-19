<?php

declare(strict_types=1);

namespace CrazyGoat\RabbitStream\Client;

use CrazyGoat\RabbitStream\Buffer\ReadBuffer;
use CrazyGoat\RabbitStream\Exception\DeserializationException;
use CrazyGoat\RabbitStream\Exception\InvalidArgumentException;

/**
 * Parses RabbitMQ Stream chunk binary format (Osiris chunk format).
 *
 * Chunk header layout (48 bytes):
 *   1 byte  - magicVersion: high nibble = magic (must be 5), low nibble = version (must be 0)
 *   1 byte  - chunkType: 0 = user data
 *   2 bytes - numEntries: number of entries in this chunk
 *   4 bytes - numRecords: total records across all entries
 *   8 bytes - timestamp: milliseconds since Unix epoch
 *   8 bytes - epoch: leader epoch
 *   8 bytes - chunkFirstOffset: stream offset of the first entry in this chunk
 *   4 bytes - chunkCrc: CRC-32 of the chunk data
 *   4 bytes - dataLength: length of entries data section
 *   4 bytes - trailerLength: length of trailer section (0 for user data)
 *   1 byte  - reserved
 *   3 bytes - padding (alignment to 4 bytes)
 *
 * The data section (dataLength bytes) follows the header immediately; the trailer
 * (trailerLength bytes) comes after it. Entry parsing is strictly bounded to the
 * data section — bytes outside it (trailer, padding, other frames) are never read.
 *
 * Each entry:
 *   Simple entry: 4-byte header (bit 31 = 0) + size in lower 31 bits + data
 *   Sub-batch entry: 1-byte header (bit 7 = 1, codec in bits 6-4) + numRecords (uint16)
 *                    + uncompressedSize (uint32) + compressedSize (uint32) + sub-batch data
 *
 * @see https://github.com/rabbitmq/rabbitmq-server/blob/main/deps/rabbitmq_stream/docs/PROTOCOL.adoc
 * @see https://github.com/rabbitmq/rabbitmq-server/blob/main/deps/osiris/src/osiris_log.erl
 */
class OsirisChunkParser
{
    /**
     * Default ceiling for entries produced from a single chunk.
     *
     * A chunk is received in a single frame, bounded by the frame size limit
     * (8 MB by default in this client). Entries cost as little as 4 bytes on the
     * wire (a sub-batch inner entry is just a uint32 size prefix), so a legal-
     * sized frame could otherwise expand into millions of ChunkEntry objects
     * (~30x memory amplification). 262 144 entries cap the transient parser
     * memory at roughly 30 MB while staying far above what a compliant broker
     * produces (chunks are well below the frame limit in practice).
     */
    private const DEFAULT_MAX_ENTRIES_PER_CHUNK = 262144;

    /**
     * @param int $maxEntriesPerChunk Hard ceiling for entries produced from one chunk
     * @return ChunkEntry[]
     * @throws DeserializationException If the chunk violates the declared sizes, declares
     *                                  implausible record counts, or exceeds the entry ceiling
     * @throws InvalidArgumentException If $maxEntriesPerChunk is less than 1
     */
    public static function parse(
        string $chunkBytes,
        int $maxEntriesPerChunk = self::DEFAULT_MAX_ENTRIES_PER_CHUNK
    ): array {
        if ($maxEntriesPerChunk < 1) {
            throw new InvalidArgumentException('maxEntriesPerChunk must be at least 1');
        }

        $buffer = new ReadBuffer($chunkBytes);

        $magicVersion = $buffer->getUint8();
        $magic = ($magicVersion >> 4) & 0x0F;
        $version = $magicVersion & 0x0F;
        if ($magic !== 5) {
            throw new DeserializationException(sprintf(
                'Invalid chunk magic: expected 5, got %d (raw byte: 0x%02x)',
                $magic,
                $magicVersion
            ));
        }
        if ($version !== 0) {
            throw new DeserializationException(sprintf('Unsupported chunk version: expected 0, got %d', $version));
        }

        $chunkType = $buffer->getUint8();
        if ($chunkType !== 0) {
            throw new DeserializationException(
                sprintf('Unsupported chunk type: expected 0 (user data), got %d', $chunkType)
            );
        }

        $numEntries = $buffer->getUint16();      // Number of entries in chunk
        $numRecords = $buffer->getUint32();      // Total record count across all entries
        $timestamp = $buffer->getInt64();        // Chunk timestamp
        $buffer->getUint64();                     // epoch
        $chunkFirstOffset = $buffer->getUint64();  // First offset in chunk
        $buffer->getInt32();                      // chunkCrc
        $dataLength = $buffer->getUint32();      // Size of the data (entries) section
        $trailerLength = $buffer->getUint32();   // Size of the trailer section
        $buffer->getUint8();                      // bloomSize
        $buffer->readBytes(3);                    // reserved

        $headerSize = $buffer->getPosition();

        // A chunk that declares more records than the client will ever accept is
        // rejected up front, before any entry is allocated.
        if ($numRecords > $maxEntriesPerChunk) {
            throw new DeserializationException(sprintf(
                'Chunk declares %d records, exceeding the maximum of %d entries per chunk',
                $numRecords,
                $maxEntriesPerChunk
            ));
        }

        // The declared data and trailer sections must fit inside the received chunk.
        $chunkSize = strlen($chunkBytes);
        $declaredChunkSize = $headerSize + $dataLength + $trailerLength;
        if ($declaredChunkSize > $chunkSize) {
            throw new DeserializationException(sprintf(
                'Chunk size mismatch: header (%d) + dataLength (%d) + trailerLength (%d) ' .
                '= %d exceeds the %d received bytes',
                $headerSize,
                $dataLength,
                $trailerLength,
                $declaredChunkSize,
                $chunkSize
            ));
        }

        // Bound entry parsing to exactly the declared data section, so entries
        // can never spill into the trailer or past the received bytes.
        $buffer = new ReadBuffer(substr($chunkBytes, $headerSize, $dataLength));

        $entries = [];
        $entryCount = 0;
        $currentOffset = $chunkFirstOffset;

        for ($i = 0; $i < $numEntries; $i++) {
            if ($entryCount >= $maxEntriesPerChunk) {
                throw self::entryLimitExceeded($maxEntriesPerChunk);
            }

            $entryType = $buffer->getUint8();
            $isSubBatch = ($entryType & 0x80) !== 0;

            if (!$isSubBatch) {
                $entrySize = (($entryType & 0x7F) << 24) | ($buffer->getUint16() << 8) | $buffer->getUint8();
                $entryData = $buffer->readBytes($entrySize);
                $entries[] = new ChunkEntry($currentOffset, $entryData, $timestamp);
                $entryCount++;
                $currentOffset++;
            } else {
                $codec = ($entryType >> 4) & 0x07;

                if ($codec !== 0) {
                    throw new DeserializationException(sprintf(
                        'Compressed sub-batches not supported yet (codec: %d)',
                        $codec
                    ));
                }

                $numRecords = $buffer->getUint16();
                $buffer->getUint32(); // uncompressedSize — read but not needed
                $compressedSize = $buffer->getUint32();

                // Each record costs at least 4 bytes in the sub-batch body (a
                // uint32 size prefix), so anything claiming more is implausible.
                if ($numRecords * 4 > $compressedSize) {
                    throw new DeserializationException(sprintf(
                        'Sub-batch declares %d records but only %d bytes of data (minimum 4 bytes per record)',
                        $numRecords,
                        $compressedSize
                    ));
                }

                $subBatchData = $buffer->readBytes($compressedSize);

                $subBuffer = new ReadBuffer($subBatchData);
                for ($j = 0; $j < $numRecords; $j++) {
                    if ($entryCount >= $maxEntriesPerChunk) {
                        throw self::entryLimitExceeded($maxEntriesPerChunk);
                    }
                    $innerSize = $subBuffer->getUint32();
                    $innerData = $subBuffer->readBytes($innerSize);
                    $entries[] = new ChunkEntry($currentOffset, $innerData, $timestamp);
                    $entryCount++;
                    $currentOffset++;
                }
            }
        }

        return $entries;
    }

    private static function entryLimitExceeded(int $maxEntriesPerChunk): DeserializationException
    {
        return new DeserializationException(sprintf(
            'Chunk contains more than %d entries (the maximum allowed per chunk)',
            $maxEntriesPerChunk
        ));
    }
}
