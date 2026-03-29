<?php

declare(strict_types=1);

namespace CrazyGoat\RabbitStream\Client;

use CrazyGoat\RabbitStream\Buffer\ReadBuffer;
use CrazyGoat\RabbitStream\Exception\DeserializationException;

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
            $header = $buffer->getUint32();
            $isSubBatch = ($header & 0x80000000) !== 0;

            if (!$isSubBatch) {
                $entrySize = $header & 0x7FFFFFFF;
                $entryData = substr($chunkBytes, $buffer->getPosition(), $entrySize);
                $buffer->skip($entrySize);
                $entries[] = new ChunkEntry($currentOffset, $entryData, $timestamp);
                $currentOffset++;
            } else {
                $codec = ($header >> 25) & 0x0F;
                $uncompressedCount = $header & 0xFFFF;

                if ($codec !== 0) {
                    throw new DeserializationException(sprintf(
                        'Compressed sub-batches not supported yet (codec: %d)',
                        $codec
                    ));
                }

                $buffer->getUint32(); // uncompressedSize — read but not needed
                $compressedSize = $buffer->getUint32();
                $subBatchData = substr($chunkBytes, $buffer->getPosition(), $compressedSize);
                $buffer->skip($compressedSize);

                $subBuffer = new ReadBuffer($subBatchData);
                for ($j = 0; $j < $uncompressedCount; $j++) {
                    $innerSize = $subBuffer->getUint32();
                    $innerData = substr($subBatchData, $subBuffer->getPosition(), $innerSize);
                    $subBuffer->skip($innerSize);
                    $entries[] = new ChunkEntry($currentOffset, $innerData, $timestamp);
                    $currentOffset++;
                }
            }
        }

        return $entries;
    }
}
