# Iteration 5: AMQP 1.0 Message Decoder

> **For Claude:** REQUIRED SUB-SKILL: Use superpowers:executing-plans to implement this plan task-by-task.

**Goal:** Decode AMQP 1.0 encoded message bytes (from `ChunkEntry::getData()`) into a `Message` object with body, properties, and application-properties. Zero BC — entirely new code.

**Why:** RabbitMQ Stream messages are encoded in AMQP 1.0 binary format. Without a decoder, users get raw bytes. Every official client decodes this automatically.

---

## Protocol Reference

AMQP 1.0 message format (simplified for RabbitMQ Stream usage):

```
Message => Header? DeliveryAnnotations? MessageAnnotations? Properties? ApplicationProperties? Body Footer?
```

Each section is an AMQP 1.0 type with a descriptor. In practice, RabbitMQ Stream messages typically contain:
- **Properties** (optional) — message-id, correlation-id, content-type, etc.
- **Application Properties** (optional) — user-defined key-value pairs
- **Body** — one or more Data sections (binary payload)

### AMQP 1.0 Type System (subset needed)

AMQP 1.0 uses a self-describing binary format. Each value starts with a format code byte:

| Code | Type | Size |
|------|------|------|
| `0x40` | null | 0 |
| `0x41` | boolean true | 0 |
| `0x42` | boolean false | 0 |
| `0x43` | uint zero | 0 |
| `0x44` | ulong zero | 0 |
| `0x50` | ubyte | 1 |
| `0x51` | byte | 1 |
| `0x52` | smalluint | 1 |
| `0x53` | smallulong | 1 |
| `0x54` | smallint | 1 |
| `0x55` | smalllong | 1 |
| `0x56` | boolean | 1 |
| `0x60` | ushort | 2 |
| `0x61` | short | 2 |
| `0x70` | uint | 4 |
| `0x71` | int | 4 |
| `0x72` | float | 4 |
| `0x80` | ulong | 8 |
| `0x81` | long | 8 |
| `0x82` | double | 8 |
| `0x83` | timestamp | 8 |
| `0x98` | uuid | 16 |
| `0xa0` | vbin8 | 1 + n |
| `0xa1` | str8-utf8 | 1 + n |
| `0xa3` | sym8 | 1 + n |
| `0xb0` | vbin32 | 4 + n |
| `0xb1` | str32-utf8 | 4 + n |
| `0xb3` | sym32 | 4 + n |
| `0xc0` | list8 | 1 + 1 + items |
| `0xc1` | map8 | 1 + 1 + pairs |
| `0xd0` | list32 | 4 + 4 + items |
| `0xd1` | map32 | 4 + 4 + pairs |

### Described Types (Sections)

Each message section is a "described type": `0x00` + descriptor + value.

| Descriptor (ulong) | Section |
|--------------------|---------|
| `0x70` (smallulong `0x53 0x70`) | Header |
| `0x71` | DeliveryAnnotations |
| `0x72` | MessageAnnotations |
| `0x73` | Properties |
| `0x74` | ApplicationProperties |
| `0x75` | Data (body) |
| `0x76` | AmqpSequence (body) |
| `0x77` | AmqpValue (body) |
| `0x78` | Footer |

### Properties Section (descriptor `0x73`)

A list with up to 13 fields (in order):
1. message-id (any type)
2. user-id (binary)
3. to (string)
4. subject (string)
5. reply-to (string)
6. correlation-id (any type)
7. content-type (symbol)
8. content-encoding (symbol)
9. absolute-expiry-time (timestamp)
10. creation-time (timestamp)
11. group-id (string)
12. group-sequence (uint)
13. reply-to-group-id (string)

### Application Properties Section (descriptor `0x74`)

A map of string/symbol keys to simple values.

---

## New Classes

### `Message` (`src/Client/Message.php`)

```php
namespace CrazyGoat\RabbitStream\Client;

class Message
{
    public function __construct(
        private readonly int $offset,
        private readonly int $timestamp,
        private readonly string $body,
        private readonly array $properties = [],
        private readonly array $applicationProperties = [],
        private readonly array $messageAnnotations = [],
    ) {}

    public function getOffset(): int;
    public function getTimestamp(): int;
    public function getBody(): string;
    public function getProperties(): array;
    public function getApplicationProperties(): array;
    public function getMessageAnnotations(): array;

    // Convenience getters for common properties
    public function getMessageId(): mixed;
    public function getCorrelationId(): mixed;
    public function getContentType(): ?string;
    public function getSubject(): ?string;
    public function getCreationTime(): ?int;
    public function getGroupId(): ?string;
}
```

### `AmqpDecoder` (`src/Client/AmqpDecoder.php`)

Low-level AMQP 1.0 type decoder.

```php
namespace CrazyGoat\RabbitStream\Client;

class AmqpDecoder
{
    /**
     * Decode a single AMQP 1.0 value from the binary data at the given position.
     * Returns [value, newPosition].
     */
    static public function decodeValue(string $data, int $position): array;

    /**
     * Decode a full AMQP 1.0 message into sections.
     * Returns ['header' => [...], 'properties' => [...], 'applicationProperties' => [...],
     *          'messageAnnotations' => [...], 'body' => string|mixed]
     */
    static public function decodeMessage(string $data): array;
}
```

### `AmqpMessageDecoder` (`src/Client/AmqpMessageDecoder.php`)

High-level decoder that combines `ChunkEntry` + `AmqpDecoder` into a `Message`.

```php
namespace CrazyGoat\RabbitStream\Client;

class AmqpMessageDecoder
{
    /**
     * Decode a ChunkEntry into a Message.
     */
    static public function decode(ChunkEntry $entry): Message;

    /**
     * Decode multiple ChunkEntries into Messages.
     * @param ChunkEntry[] $entries
     * @return Message[]
     */
    static public function decodeAll(array $entries): array;
}
```

---

## Implementation Tasks

### Task 5.1: Create `Message` value object

Readonly DTO with constructor, getters, and convenience methods for common properties.

### Task 5.2: Implement `AmqpDecoder::decodeValue()`

Core type decoder. Reads format code byte, then decodes the value based on the type:
- Null, booleans, integers (various sizes), strings, binary, symbols
- Lists (list8, list32) — recursive
- Maps (map8, map32) — recursive, returns associative array
- Described types (`0x00` prefix) — returns `['descriptor' => $desc, 'value' => $val]`

### Task 5.3: Implement `AmqpDecoder::decodeMessage()`

Iterates through the binary data, decoding described types:
1. Read described type
2. Match descriptor to section (header=0x70, properties=0x73, etc.)
3. For Properties (0x73): decode list, map positional fields to named keys
4. For ApplicationProperties (0x74): decode map
5. For Data (0x75): extract binary payload
6. For AmqpValue (0x77): decode the value
7. Skip unknown sections

### Task 5.4: Implement `AmqpMessageDecoder::decode()`

```php
static public function decode(ChunkEntry $entry): Message
{
    $sections = AmqpDecoder::decodeMessage($entry->getData());
    return new Message(
        offset: $entry->getOffset(),
        timestamp: $entry->getTimestamp(),
        body: $sections['body'] ?? '',
        properties: $sections['properties'] ?? [],
        applicationProperties: $sections['applicationProperties'] ?? [],
        messageAnnotations: $sections['messageAnnotations'] ?? [],
    );
}
```

### Task 5.5: Tests for `AmqpDecoder::decodeValue()`

Test each AMQP type:
- null, true, false
- ubyte, byte, ushort, short, uint, int, ulong, long
- smalluint, smallulong, uint-zero, ulong-zero
- str8, str32, sym8, sym32, vbin8, vbin32
- timestamp, uuid
- list8, list32 (with nested values)
- map8, map32 (with string keys)
- described types

### Task 5.6: Tests for `AmqpDecoder::decodeMessage()`

1. **Simple message:** Data section only (just a body)
2. **Message with properties:** Properties + Data
3. **Message with application properties:** ApplicationProperties + Data
4. **Full message:** Properties + ApplicationProperties + Data
5. **AmqpValue body:** Message with AmqpValue instead of Data section
6. **Real-world message:** Capture a message from RabbitMQ and verify decoding

### Task 5.7: Tests for `AmqpMessageDecoder`

1. Create `ChunkEntry` with known AMQP data, decode to `Message`, verify all fields
2. Test `decodeAll()` with multiple entries

---

## Implementation Notes

- Use `unpack()` for reading binary values — big-endian format codes
- AMQP 1.0 is always big-endian
- Properties section is a fixed-position list — field 0 is message-id, field 1 is user-id, etc. Missing fields at the end are null.
- Multiple Data sections should be concatenated into a single body
- For the initial implementation, focus on the most common types. Rare types (decimal, array) can throw `\RuntimeException('Unsupported AMQP type')`.
- The Go client's `amqp` package and the Python client's `amqp_decoder` are good references for the exact byte sequences.

---

## File Structure After This Iteration

```
src/
├── Client/
│   ├── Message.php
│   ├── AmqpDecoder.php
│   └── AmqpMessageDecoder.php
tests/
├── Client/
│   ├── AmqpDecoderTest.php
│   └── AmqpMessageDecoderTest.php
```
