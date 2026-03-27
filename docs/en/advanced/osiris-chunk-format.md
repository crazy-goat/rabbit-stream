# Osiris Chunk Format

> Understanding RabbitMQ's internal stream storage format

## Overview

Osiris is RabbitMQ's internal stream storage engine. Messages are stored in "chunks" — binary blocks that contain multiple entries. This section explains the chunk format and how the library parses it.

## What is OsirisChunk

When consuming messages from RabbitMQ Streams, the server sends chunks of data via the `Deliver` (0x0008) command. Each chunk contains:

- **Chunk header** — Metadata about the chunk
- **Entries** — Individual messages (or sub-batches of messages)

The `OsirisChunkParser` class decodes these chunks into `ChunkEntry` objects.

## Chunk Structure

### Binary Layout

```
┌─────────────────────────────────────────────────────────────┐
│                        CHUNK HEADER                         │
├─────────────────────────────────────────────────────────────┤
│  Byte 0     │ Magic (4 bits) + Version (4 bits)              │
│  Byte 1     │ Chunk Type                                     │
│  Bytes 2-3  │ Number of Entries (uint16)                     │
│  Bytes 4-7  │ Reserved (uint32)                              │
│  Bytes 8-15 │ Timestamp (int64)                              │
│  Bytes 16-23│ Reserved (uint64)                              │
│  Bytes 24-31│ Chunk First Offset (uint64)                    │
│  Bytes 32-35│ Reserved (int32)                               │
│  Bytes 36-39│ Reserved (uint32)                              │
│  Bytes 40-43│ Reserved (uint32)                              │
│  Byte 44    │ Reserved (uint8)                               │
│  Bytes 45-47│ Reserved (3 bytes)                             │
├─────────────────────────────────────────────────────────────┤
│                        ENTRIES                              │
├─────────────────────────────────────────────────────────────┤
│  Entry 1    │ [Header] [Data]                                │
│  Entry 2    │ [Header] [Data]                                │
│  ...        │ ...                                            │
│  Entry N    │ [Header] [Data]                                │
└─────────────────────────────────────────────────────────────┘
```

### Header Details

**Magic and Version (Byte 0):**
```
Bits 7-4: Magic number (must be 5)
Bits 3-0: Version (must be 0)

Example: 0x50 = Magic 5, Version 0
```

**Chunk Type (Byte 1):**
```
0: User data chunk (normal messages)
1: Offset tracking chunk
2: Snapshot chunk
```

**Number of Entries (Bytes 2-3):**
- Unsigned 16-bit integer
- Count of entries in the chunk (not messages — sub-batches count as 1 entry)

**Timestamp (Bytes 8-15):**
- Signed 64-bit integer (milliseconds since Unix epoch)
- Applied to all entries in the chunk

**Chunk First Offset (Bytes 24-31):**
- Unsigned 64-bit integer
- Offset of the first message in this chunk

## Entry Types

### Simple Entry

A single message entry:

```
┌────────────────────────────────────────┐
│  Header (4 bytes)                      │
│  ├─ Bit 31: 0 (simple entry flag)      │
│  └─ Bits 30-0: Entry size (31 bits)    │
├────────────────────────────────────────┤
│  Data (N bytes)                        │
│  └─ Raw AMQP 1.0 message bytes         │
└────────────────────────────────────────┘
```

**Header format:**
```
0xxx xxxx xxxx xxxx xxxx xxxx xxxx xxxx
└┬┘ └────────── size (31 bits) ─────────┘
  │
  └─ 0 = simple entry
```

**Example:**
```
Header: 0x00 0x00 0x01 0xF4  (size = 500 bytes)
Data:   [500 bytes of AMQP message]
```

### Sub-Batch Entry

A compressed batch of multiple messages:

```
┌────────────────────────────────────────┐
│  Header (4 bytes)                      │
│  ├─ Bit 31: 1 (sub-batch flag)         │
│  ├─ Bits 28-25: Codec (4 bits)         │
│  └─ Bits 23-0: Uncompressed count     │
├────────────────────────────────────────┤
│  Uncompressed Size (4 bytes)           │
├────────────────────────────────────────┤
│  Compressed Size (4 bytes)             │
├────────────────────────────────────────┤
│  Compressed Data (N bytes)             │
└────────────────────────────────────────┘
```

**Header format:**
```
1ccc cnnn nnnn nnnn nnnn nnnn nnnn nnnn
└┬┘ └┬┘ └──────── count (24 bits) ─────┘
  │   │
  │   └─ Codec (4 bits): 0=none, 1=gzip, 2=snappy, 3=lz4, 4=zstd
  │
  └─ 1 = sub-batch entry
```

**Example:**
```
Header:             0x80 0x00 0x00 0x64  (sub-batch, codec=0, count=100)
Uncompressed:     0x00 0x10 0x00 0x00  (65536 bytes)
Compressed:       0x00 0x08 0x00 0x00  (32768 bytes)
Data:             [32768 bytes of uncompressed message data]
```

## Compression Support

### Current Status

**Only uncompressed sub-batches are supported.**

The library currently supports:
- ✅ Simple entries (uncompressed single messages)
- ✅ Sub-batches with codec = 0 (no compression)
- ❌ Sub-batches with compression (gzip, snappy, lz4, zstd)

### Codec Values

| Codec | Value | Status |
|-------|-------|--------|
| None | 0 | ✅ Supported |
| Gzip | 1 | ❌ Not supported |
| Snappy | 2 | ❌ Not supported |
| LZ4 | 3 | ❌ Not supported |
| Zstd | 4 | ❌ Not supported |

### Handling Compressed Chunks

If a compressed sub-batch is received, the parser throws:

```php
throw new DeserializationException(sprintf(
    'Compressed sub-batches not supported yet (codec: %d)',
    $codec
));
```

## OsirisChunkParser

### Parsing a Chunk

```php
use CrazyGoat\RabbitStream\Client\OsirisChunkParser;
use CrazyGoat\RabbitStream\Client\ChunkEntry;

$chunkBytes = /* ... from Deliver response ... */;

/** @var ChunkEntry[] $entries */
$entries = OsirisChunkParser::parse($chunkBytes);

foreach ($entries as $entry) {
    echo "Offset: {$entry->getOffset()}\n";
    echo "Timestamp: {$entry->getTimestamp()}\n";
    echo "Data size: " . strlen($entry->getData()) . " bytes\n";
}
```

### ChunkEntry Object

```php
class ChunkEntry
{
    public function __construct(
        private int $offset,
        private string $data,
        private int $timestamp,
    )
    
    public function getOffset(): int;
    public function getData(): string;      // Raw AMQP bytes
    public function getTimestamp(): int;    // Milliseconds since epoch
}
```

### Integration with Consumer

The `Consumer` class automatically uses `OsirisChunkParser`:

```php
use CrazyGoat\RabbitStream\Client\Consumer;
use CrazyGoat\RabbitStream\Client\AmqpMessageDecoder;

$consumer = new Consumer(
    connection: $connection,
    stream: 'my-stream',
    subscriptionId: 1,
    offset: OffsetSpec::next(),
);

// Internally, the Consumer:
// 1. Receives Deliver response with chunk bytes
// 2. Calls OsirisChunkParser::parse() to get ChunkEntry[]
// 3. Calls AmqpMessageDecoder::decodeAll() to get Message[]
// 4. Buffers messages for consumption

$messages = $consumer->read();
```

## Complete Parsing Example

### Manual Chunk Parsing

```php
<?php

use CrazyGoat\RabbitStream\Client\OsirisChunkParser;
use CrazyGoat\RabbitStream\Client\AmqpMessageDecoder;
use CrazyGoat\RabbitStream\Client\AmqpDecoder;

// Raw chunk bytes from Deliver response
$chunkBytes = $deliverResponse->getChunkBytes();

// Step 1: Parse chunk into entries
$entries = OsirisChunkParser::parse($chunkBytes);

echo "Parsed " . count($entries) . " entries from chunk\n";

// Step 2: Decode each entry as AMQP message
foreach ($entries as $entry) {
    echo "\n--- Entry at offset {$entry->getOffset()} ---\n";
    
    // Raw AMQP data
    $amqpData = $entry->getData();
    
    // Decode AMQP sections
    $sections = AmqpDecoder::decodeMessage($amqpData);
    
    // Access message properties
    $props = $sections['properties'] ?? [];
    echo "Message ID: " . ($props['message-id'] ?? 'N/A') . "\n";
    echo "Content-Type: " . ($props['content-type'] ?? 'N/A') . "\n";
    
    // Access body
    $body = $sections['body'] ?? null;
    echo "Body: " . (is_string($body) ? substr($body, 0, 100) : json_encode($body)) . "\n";
}
```

### Using High-Level API

```php
// Simpler approach using AmqpMessageDecoder
$entries = OsirisChunkParser::parse($chunkBytes);
$messages = AmqpMessageDecoder::decodeAll($entries);

foreach ($messages as $message) {
    echo "Offset: {$message->getOffset()}\n";
    echo "Body: {$message->getBody()}\n";
    print_r($message->getProperties());
}
```

## Chunk Offset Calculation

### Offset Assignment

Offsets are assigned sequentially within a chunk:

```php
$chunkFirstOffset = $buffer->getUint64();  // From header
$currentOffset = $chunkFirstOffset;

foreach ($entries as $entry) {
    // Each entry gets the next offset
    $entry = new ChunkEntry($currentOffset, $data, $timestamp);
    $currentOffset++;
}
```

### Sub-Batch Offset Handling

For sub-batches, each inner message gets its own offset:

```php
if ($isSubBatch) {
    $uncompressedCount = $header & 0xFFFF;
    
    for ($j = 0; $j < $uncompressedCount; $j++) {
        $entries[] = new ChunkEntry($currentOffset, $innerData, $timestamp);
        $currentOffset++;
    }
}
```

## Error Handling

### Invalid Magic

```php
$magic = ($magicVersion >> 4) & 0x0F;
if ($magic !== 5) {
    throw new DeserializationException(sprintf(
        'Invalid chunk magic: expected 5, got %d (raw byte: 0x%02x)',
        $magic,
        $magicVersion
    ));
}
```

### Unsupported Version

```php
$version = $magicVersion & 0x0F;
if ($version !== 0) {
    throw new DeserializationException(sprintf(
        'Unsupported chunk version: expected 0, got %d',
        $version
    ));
}
```

### Unsupported Chunk Type

```php
$chunkType = $buffer->getUint8();
if ($chunkType !== 0) {
    throw new DeserializationException(
        sprintf('Unsupported chunk type: expected 0 (user data), got %d', $chunkType)
    );
}
```

## Performance Considerations

### Memory Usage

Chunks can be large (up to several MB). The parser:
- Uses `substr()` to slice data without copying when possible
- Creates `ChunkEntry` objects with references to data slices
- Defers AMQP decoding until needed

### Batch Processing

For high-throughput scenarios:

```php
// Process entries in batches
$batchSize = 100;
$entries = OsirisChunkParser::parse($chunkBytes);

foreach (array_chunk($entries, $batchSize) as $batch) {
    $messages = AmqpMessageDecoder::decodeAll($batch);
    
    // Process batch
    foreach ($messages as $message) {
        processMessage($message);
    }
    
    // Free memory periodically
    unset($messages);
}
```

## See Also

- [AMQP Message Decoding](./amqp-message-decoding.md)
- [Consuming Guide](../guide/consuming.md)
- [Consumer API Reference](../api-reference/consumer.md)
- [RabbitMQ Streams Documentation](https://www.rabbitmq.com/docs/streams)
