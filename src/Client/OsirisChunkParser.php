<?php

namespace CrazyGoat\RabbitStream\Client;

use CrazyGoat\RabbitStream\Buffer\ReadBuffer;

class OsirisChunkParser
{
    /**
     * @return ChunkEntry[]
     */
    static public function parse(string $chunkBytes): array
    {
        $buffer = new ReadBuffer($chunkBytes);

        $magicVersion = $buffer->getUint8();
        if ($magicVersion !== 0x00) {
            throw new \RuntimeException(sprintf('Invalid magic version: expected 0x00, got 0x%02x', $magicVersion));
        }

        $chunkType = $buffer->getUint8();
        if ($chunkType !== 0) {
            throw new \RuntimeException(sprintf('Unsupported chunk type: expected 0 (user data), got %d', $chunkType));
        }

        $numEntries = $buffer->getUint16();
        $numRecords = $buffer->getUint32();
        $timestamp = $buffer->getInt64();
        $epoch = $buffer->getUint64();
        $chunkFirstOffset = $buffer->getUint64();
        $chunkCrc = $buffer->getInt32();
        $dataLength = $buffer->getUint32();
        $trailerLength = $buffer->getUint32();
        $reserved = $buffer->getUint32();

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
                    throw new \RuntimeException(sprintf('Compressed sub-batches not supported yet (codec: %d)', $codec));
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
