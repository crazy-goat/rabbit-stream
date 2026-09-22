# Iteration 4: OsirisChunk Parser

> **For Claude:** REQUIRED SUB-SKILL: Use superpowers:executing-plans to implement this plan task-by-task.

**Goal:** Parse the raw binary chunk bytes from `DeliverResponseV1::getChunkBytes()` into individual message entries. Zero BC — entirely new code.

**Why:** Without this, consumers get a binary blob and must decode it themselves. Every official RabbitMQ Stream client has a chunk parser. This is a prerequisite for the `Consumer` class.

---

## Protocol Reference

RabbitMQ Stream uses the "Osiris" chunk format. Full spec: https://github.com/rabbitmq/rabbitmq-server/blob/main/deps/rabbitmq_stream/docs/PROTOCOL.adoc#deliver

### Chunk Structure

```
Chunk =>
  MagicVersion     uint8   (0x00 for Osiris)
  ChunkType        uint8   (0 = user data, 1 = tracking delta, 2 = tracking snapshot)
  NumEntries       uint16  (number of entries in the chunk)
  NumRecords       uint32  (total number of records, may differ from entries if sub-batching)
  Timestamp        int64   (chunk timestamp, milliseconds since epoch)
  Epoch            uint64  (epoch of the chunk)
  ChunkFirstOffset uint64  (offset of the first record in the chunk)
  ChunkCrc         int32   (CRC32 of the chunk data)
  DataLength       uint32  (length of the data section)
  TrailerLength    uint32  (length of the trailer section)
  Reserved         uint32  (reserved, must be 0)
  Data             bytes[DataLength]
  Trailer          bytes[TrailerLength]  (currently unused)
```

### Entry Types (inside Data section)

Each entry starts with a size/type indicator:

**Simple entry (bit 7 of first byte = 0):**
```
EntrySize  uint32  (top bit 0 = simple entry)
EntryData  bytes[EntrySize]  (the AMQP 1.0 encoded message)
```

**Sub-batch entry (bit 7 of first byte = 1):**
```
Header           uint32  (top bit 1 = sub-batch; bits 28-25 = compression codec; bits 15-0 = uncompressed count)
UncompressedSize uint32
CompressedSize   uint32
CompressedData   bytes[CompressedSize]
```

Compression codecs:
- 0 = none (data is uncompressed, contains `count` simple entries)
- 1 = gzip
- 2 = snappy
- 3 = lz4
- 4 = zstd

For the initial implementation, support **codec 0 (none)** only. Throw on compressed sub-batches.

---

## New Classes

### `ChunkEntry` (`src/Client/ChunkEntry.php`)

Simple value object for a single entry extracted from a chunk.

```php
namespace CrazyGoat\RabbitStream\Client;

class ChunkEntry
{
    public function __construct(
        private readonly int $offset,
        private readonly string $data,
        private readonly int $timestamp,
    ) {}

    public function getOffset(): int;
    public function getData(): string;       // raw AMQP 1.0 encoded message bytes
    public function getTimestamp(): int;      // chunk timestamp (ms since epoch)
}
```

### `OsirisChunkParser` (`src/Client/OsirisChunkParser.php`)

Static parser that takes raw chunk bytes and returns an array of `ChunkEntry`.

```php
namespace CrazyGoat\RabbitStream\Client;

class OsirisChunkParser
{
    /**
     * @return ChunkEntry[]
     */
    static public function parse(string $chunkBytes): array;
}
```

---

## Implementation Details

### Task 4.1: Create `ChunkEntry` value object

Simple readonly DTO with constructor and getters.

### Task 4.2: Implement `OsirisChunkParser::parse()`

Algorithm:
1. Read chunk header (52 bytes total):
   - `magicVersion` (uint8) — assert `0x00`
   - `chunkType` (uint8) — assert `0` (user data)
   - `numEntries` (uint16)
   - `numRecords` (uint32)
   - `timestamp` (int64)
   - `epoch` (uint64)
   - `chunkFirstOffset` (uint64)
   - `chunkCrc` (int32)
   - `dataLength` (uint32)
   - `trailerLength` (uint32)
   - `reserved` (uint32)
2. Read `numEntries` entries from the data section:
   - Read first uint32
   - If top bit is 0: simple entry — read `entrySize` bytes as data
   - If top bit is 1: sub-batch entry — read header, sizes, data; parse inner entries
3. Track offset: start at `chunkFirstOffset`, increment by 1 for each record
4. Return array of `ChunkEntry(offset, data, timestamp)`

Use `ReadBuffer` for parsing (it already has all the uint reading methods).

### Task 4.3: Handle sub-batch entries (uncompressed only)

For sub-batch with codec 0:
1. Read `uncompressedSize` (uint32)
2. Read `compressedSize` (uint32) — equals `uncompressedSize` for codec 0
3. Read `compressedSize` bytes
4. Parse inner data as a sequence of simple entries (uint32 size + bytes)

For codecs 1-4: throw `\RuntimeException('Compressed sub-batches not supported yet')`.

### Task 4.4: Tests with real chunk data

1. **Simple chunk test:** Construct a minimal chunk with 1 simple entry, verify parsing.
2. **Multi-entry chunk test:** Construct a chunk with multiple simple entries, verify offsets increment correctly.
3. **Sub-batch test (uncompressed):** Construct a chunk with a sub-batch entry (codec 0), verify inner entries are extracted.
4. **Offset tracking test:** Verify that `chunkFirstOffset` is used correctly and offsets increment per record.
5. **Invalid magic test:** Verify exception on wrong magic byte.
6. **Compressed sub-batch test:** Verify exception is thrown for compressed sub-batches.

### Task 4.5: Integration test with real RabbitMQ data (E2E)

If possible, capture real chunk bytes from a RabbitMQ stream (via the existing `DeliverResponseV1`) and verify the parser produces correct entries. This can be added to the E2E test suite.

---

## Implementation Notes

- Use `ReadBuffer` internally for parsing — it already handles big-endian integers
- The chunk header is always 52 bytes (5 + 2 + 2 + 4 + 8 + 8 + 8 + 4 + 4 + 4 + 4 = 52... actually count carefully from the spec)
- `chunkFirstOffset` is the offset of the FIRST record. Each subsequent record has offset + 1, + 2, etc.
- `timestamp` from the chunk header applies to all entries in the chunk
- The `Data` section starts immediately after the header
- CRC validation is optional for now — can be added later

---

## File Structure After This Iteration

```
src/
├── Client/
│   ├── ChunkEntry.php
│   └── OsirisChunkParser.php
tests/
├── Client/
│   └── OsirisChunkParserTest.php
```
