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
            throw new DeserializationException(sprintf('Unsupported chunk type: expected 0 (user data), got %d', $chunkType));
        }

        $numEntries = $buffer->getUint16();
        $buffer->getUint32();
        $timestamp = $buffer->getInt64();
        $buffer->getUint64();
        $chunkFirstOffset = $buffer->getUint64();
        $buffer->getInt32();
        $buffer->getUint32();
        $buffer->getUint32();
        $buffer->getUint8();
        $buffer->readBytes(3);

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

                $uncompressedSize = $buffer->getUint32();
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
