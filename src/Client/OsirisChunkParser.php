<?php

declare(strict_types=1);

namespace CrazyGoat\RabbitStream\Client;

use CrazyGoat\RabbitStream\Buffer\ReadBuffer;
use CrazyGoat\RabbitStream\Exception\DeserializationException;

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
     * @return ChunkEntry[]
     */
    public static function parse(string $chunkBytes): array
    {
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
        $buffer->getUint32();                     // numRecords (total records)
        $timestamp = $buffer->getInt64();        // Chunk timestamp
        $buffer->getUint64();                     // epoch
        $chunkFirstOffset = $buffer->getUint64();  // First offset in chunk
        $buffer->getInt32();                      // chunkCrc
        $buffer->getUint32();                     // dataLength
        $buffer->getUint32();                     // trailerLength
        $buffer->getUint8();                      // reserved
        $buffer->readBytes(3);                    // padding (alignment)

        $entries = [];
        $currentOffset = $chunkFirstOffset;

        for ($i = 0; $i < $numEntries; $i++) {
            $entryType = $buffer->getUint8();
            $isSubBatch = ($entryType & 0x80) !== 0;

            if (!$isSubBatch) {
                $entrySize = (($entryType & 0x7F) << 24) | ($buffer->getUint16() << 8) | $buffer->getUint8();
                $entryData = $buffer->readBytes($entrySize);
                $entries[] = new ChunkEntry($currentOffset, $entryData, $timestamp);
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
                $subBatchData = $buffer->readBytes($compressedSize);

                $subBuffer = new ReadBuffer($subBatchData);
                for ($j = 0; $j < $numRecords; $j++) {
                    $innerSize = $subBuffer->getUint32();
                    $innerData = $subBuffer->readBytes($innerSize);
                    $entries[] = new ChunkEntry($currentOffset, $innerData, $timestamp);
                    $currentOffset++;
                }
            }
        }

        return $entries;
    }
}
