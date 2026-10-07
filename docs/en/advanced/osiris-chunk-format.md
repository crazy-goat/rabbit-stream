# Osiris Chunk Format

> Understanding RabbitMQ's internal stream storage format

## Overview

Osiris is RabbitMQ's internal stream storage engine. Messages are stored in "chunks" — binary blocks that contain multiple entries. This section explains the chunk format and how the library parses it.

## What is OsirisChunk

When consuming messages from RabbitMQ Streams, the server sends chunks of data via the `Deliver` (0x0008) command. Each chunk contains:

- **Chunk header** — Metadata about the chunk
- **Entries** — Individual messages (or sub-batches of messages)

The `OsirisChunkParser` class decodes these chunks: `parse()` and
`parseEntries()` return `ChunkEntry` objects, while `parseMessages()` returns
zero-copy `Message` views (see [Memory Usage](#memory-usage)).

## Chunk Structure

### Binary Layout

```
ON DISK (a user-data chunk):
┌─────────────────────────────────────────────────────────────┐
│                  CHUNK HEADER (48 bytes)                    │
├─────────────────────────────────────────────────────────────┤
│  Byte 0     │ Magic (4 bits) + Version (4 bits)              │
│  Byte 1     │ Chunk Type                                     │
│  Bytes 2-3  │ Number of Entries (uint16)                     │
│  Bytes 4-7  │ Number of Records (uint32)                     │
│  Bytes 8-15 │ Timestamp (int64, ms since Unix epoch)         │
│  Bytes 16-23│ Epoch (uint64)                                 │
│  Bytes 24-31│ Chunk First Offset / ChunkId (uint64)          │
│  Bytes 32-35│ Chunk CRC (uint32, CRC-32 of the data section) │
│  Bytes 36-39│ Data Length (uint32)                           │
│  Bytes 40-43│ Trailer Length (uint32)                        │
│  Byte 44    │ Bloom Size (uint8)                             │
│  Bytes 45-47│ Reserved (3 bytes)                             │
├─────────────────────────────────────────────────────────────┤
│  BLOOM FILTER (Bloom Size bytes)                            │
├─────────────────────────────────────────────────────────────┤
│  DATA SECTION (Data Length bytes)                           │
│  Entry 1    │ [Header] [Data]                                │
│  Entry 2    │ [Header] [Data]                                │
│  ...        │ ...                                            │
│  Entry N    │ [Header] [Data]                                │
├─────────────────────────────────────────────────────────────┤
│  TRAILER (Trailer Length bytes)                             │
└─────────────────────────────────────────────────────────────┘
```

The bloom filter sits **between** the header and the data section on disk
(`osiris_log.erl`: `DataPos = Pos + HEADER_SIZE_B + FilterSize`), and the trailer
follows the data.

On the stream-protocol wire a `Deliver` (0x0008) frame carries the header and the
data section **only** for user-data chunks — the bloom filter and trailer bytes
are not transmitted. The header still declares their on-disk sizes (`Trailer
Length`, `Bloom Size`), so those two fields are informational in a delivered
chunk and a nonzero value with no bytes behind it is legitimate.
`OsirisChunkParser` bounds entry parsing to exactly `Data Length` bytes and
never reads past them.

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
- Count of entries in the data section (not messages — a sub-batch counts as 1 entry)

**Number of Records (Bytes 4-7):**
- Unsigned 32-bit integer
- Total records across all entries; a sub-batch contributes its inner record count, not 1
- Verified against the records parsed from the data section; a mismatch is rejected

**Timestamp (Bytes 8-15):**
- Signed 64-bit integer (milliseconds since Unix epoch)
- Applied to all entries in the chunk

**Epoch (Bytes 16-23):**
- Unsigned 64-bit integer
- Leader epoch that wrote the chunk; used by the broker for replication and recovery

**Chunk First Offset (Bytes 24-31):**
- Unsigned 64-bit integer
- Offset of the first record in this chunk

**Chunk CRC (Bytes 32-35):**
- Unsigned 32-bit integer
- CRC-32 of the data section that follows the header (the same standard
  CRC-32 as `erlang:crc32` / PHP's `crc32()`)
- Verified by `OsirisChunkParser` on every delivered chunk since #403;
  see the `verifyCrc` option on `Consumer` to disable it

**Data Length (Bytes 36-39):**
- Unsigned 32-bit integer
- Length in bytes of the data section (the entries) that follows the header

**Trailer Length (Bytes 40-43):**
- Unsigned 32-bit integer
- On-disk length of the trailer section
- Informational only: a `Deliver` frame for a user-data chunk omits the trailer
  bytes, so the field can be nonzero with no bytes behind it. The parser never
  reads it.

**Bloom Size (Byte 44):**
- Unsigned 8-bit integer
- On-disk size of the bloom filter section (used for stream filtering)
- Informational only for the same reason as `Trailer Length`; the parser never
  reads the bloom bytes

**Reserved (Bytes 45-47):**
- 3 bytes reserved for future extensions (alignment to 4 bytes)

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

A sub-batch packs multiple messages into one entry. The entry header is a single
byte, followed by a uint16 record count, the uncompressed and compressed sizes,
and then the sub-batch body:

```
┌────────────────────────────────────────┐
│  Header (1 byte)                       │
│  ├─ Bit 7: 1 (sub-batch flag)          │
│  ├─ Bits 6-4: Codec (3 bits)           │
│  └─ Bits 3-0: Reserved (0)             │
├────────────────────────────────────────┤
│  Number of Records (uint16, 2 bytes)   │
├────────────────────────────────────────┤
│  Uncompressed Size (uint32, 4 bytes)   │
├────────────────────────────────────────┤
│  Compressed Size (uint32, 4 bytes)     │
├────────────────────────────────────────┤
│  Sub-Batch Data (Compressed Size bytes)│
└────────────────────────────────────────┘
```

**Header format:**
```
1ccc rrrr
└┬┘ └─┬┘
 │    └─ Reserved bits (0)
 │
 └─ Codec (3 bits): 0=none, 1=gzip, 2=snappy, 3=lz4, 4=zstd
```

The record count is the uint16 that immediately follows the header byte; it is
**not** packed into the header. Each inner record is a uint32 length prefix
followed by that many bytes of raw AMQP message data.

**Example:**
```
Header:            0x80             (sub-batch, codec=0, reserved=0)
Number of records: 0x00 0x64        (100)
Uncompressed:      0x00 0x01 0x00 0x00  (65536 bytes)
Compressed:        0x00 0x00 0x80 0x00  (32768 bytes)
Data:              [32768 bytes of uncompressed message data]
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
        private readonly int $offset,
        private readonly string $data,
        private readonly int $timestamp,
    ) {
    }

    public function getOffset(): int;
    public function getData(): string;      // Raw AMQP bytes
    public function getTimestamp(): int;    // Milliseconds since epoch
}
```

### Integration with Consumer

The `Consumer` class automatically uses `OsirisChunkParser`:

```php
use CrazyGoat\RabbitStream\VO\OffsetSpec;

// $connection is a high-level CrazyGoat\RabbitStream\Client\Connection
$consumer = $connection->createConsumer(
    stream: 'my-stream',
    offset: OffsetSpec::next(),
);

// Internally, the Consumer:
// 1. Receives a Deliver frame with chunk bytes
// 2. Calls OsirisChunkParser::parseMessages() to build zero-copy Message views
// 3. Buffers messages for consumption

$messages = $consumer->read();
```

## Complete Parsing Example

### Manual Chunk Parsing

```php
<?php

use CrazyGoat\RabbitStream\Client\OsirisChunkParser;
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

Offsets are assigned sequentially within a chunk. `ChunkId` (the Chunk First
Offset at header bytes 24-31) is the offset of the first record; every record
after it — including each inner record of a sub-batch — advances the cursor by
one:

```php
// ChunkId (Chunk First Offset) is header bytes 24-31
$chunkFirstOffset = $buffer->getUint64();
$currentOffset = $chunkFirstOffset;

foreach ($entries as $data) {
    // Each record gets the current offset, then the cursor advances
    $entry = new ChunkEntry($currentOffset, $data, $timestamp);
    $currentOffset++;
}
```

### Sub-Batch Offset Handling

For a sub-batch, each inner record gets its own offset. The record count is the
uint16 that follows the 1-byte entry header (see [Sub-Batch
Entry](#sub-batch-entry)):

```php
if ($isSubBatch) {
    $recordCount = $buffer->getUint16();       // Number of records (uint16)
    $uncompressedSize = $buffer->getUint32();
    $compressedSize = $buffer->getUint32();

    for ($j = 0; $j < $recordCount; $j++) {
        // Each inner record is a uint32 length prefix + that many bytes
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

Chunks can be large (up to several MB). How much the parser copies depends on
the entry point:

- `parseMessages()` builds each `Message` as a zero-copy view
  (`Message::fromChunkView()`), sharing the chunk buffer. PHP strings are
  refcounted, so every message from one chunk just bumps that one buffer's
  refcount instead of copying its own payload out — including sub-batch inner
  records. The entry bytes are copied only once, later, when a message is
  actually decoded.
- `parse()` / `parseEntries()` return `ChunkEntry` objects that hold real byte
  strings: each entry's payload is copied out of the chunk (`substr()`), and a
  sub-batch payload is copied as well before its inner records are split. Use
  this path only when you need `ChunkEntry` instances.
- AMQP decoding is deferred on both paths until a `Message` accessor (or
  `AmqpMessageDecoder`) actually needs the sections.

Entry parsing is always bounded to the header's `Data Length`; the parser never
reads into the bloom filter or trailer.

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
