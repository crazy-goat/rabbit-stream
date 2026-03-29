# AMQP Message Decoding

> Understanding AMQP 1.0 message format and the decoding pipeline

## Overview

RabbitMQ Streams stores messages in AMQP 1.0 format. This section covers the AMQP 1.0 type system, message sections, and how the library decodes messages from binary format.

## AMQP 1.0 Type System

### Type Encoding Format

AMQP 1.0 uses a type-tag system where each value is prefixed with a format code:

```
[1 byte: format code] [type-specific data]
```

### Supported Types Table

| Format Code | Type | Description | Size |
|-------------|------|-------------|------|
| 0x40 | null | Null value | 0 bytes |
| 0x41 | bool | Boolean true | 0 bytes |
| 0x42 | bool | Boolean false | 0 bytes |
| 0x43 | uint | Unsigned int zero | 0 bytes |
| 0x44 | ulong | Unsigned long zero | 0 bytes |
| 0x45 | list0 | Empty list | 0 bytes |
| 0x50 | ubyte | Unsigned byte | 1 byte |
| 0x51 | byte | Signed byte | 1 byte |
| 0x52 | smalluint | Small unsigned int | 1 byte |
| 0x53 | smallulong | Small unsigned long | 1 byte |
| 0x54 | smallint | Small signed int | 1 byte |
| 0x55 | smalllong | Small signed long | 1 byte |
| 0x56 | boolean | Boolean with value | 1 byte |
| 0x60 | ushort | Unsigned short | 2 bytes |
| 0x61 | short | Signed short | 2 bytes |
| 0x70 | uint | Unsigned int | 4 bytes |
| 0x71 | int | Signed int | 4 bytes |
| 0x72 | float | IEEE 754 float | 4 bytes |
| 0x80 | ulong | Unsigned long | 8 bytes |
| 0x81 | long | Signed long | 8 bytes |
| 0x82 | double | IEEE 754 double | 8 bytes |
| 0x83 | timestamp | Milliseconds since epoch | 8 bytes |
| 0x98 | uuid | UUID (16 bytes) | 16 bytes |
| 0xa0 | vbin8 | Binary data (8-bit length) | 1 byte + data |
| 0xa1 | str8-utf8 | UTF-8 string (8-bit length) | 1 byte + data |
| 0xa3 | sym8 | Symbol (8-bit length) | 1 byte + data |
| 0xb0 | vbin32 | Binary data (32-bit length) | 4 bytes + data |
| 0xb1 | str32-utf8 | UTF-8 string (32-bit length) | 4 bytes + data |
| 0xb3 | sym32 | Symbol (32-bit length) | 4 bytes + data |
| 0xc0 | list8 | List (8-bit length) | 1 byte header + items |
| 0xc1 | map8 | Map (8-bit length) | 1 byte header + pairs |
| 0xd0 | list32 | List (32-bit length) | 4 bytes header + items |
| 0xd1 | map32 | Map (32-bit length) | 4 bytes header + pairs |
| 0x00 | described | Described type | descriptor + value |

### Fixed-Width Types

Types with no additional length prefix:

```php
// Boolean true (0x41)
$data = "\x41";
[$value, $pos] = AmqpDecoder::decodeValue($data, 0);
// $value = true

// Unsigned int 42 (0x52 = smalluint)
$data = "\x52\x2a";
[$value, $pos] = AmqpDecoder::decodeValue($data, 0);
// $value = 42

// Double (0x82)
$data = "\x82" . pack('d', 3.14159);
[$value, $pos] = AmqpDecoder::decodeValue($data, 0);
// $value = 3.14159
```

### Variable-Width Types

Types with length prefix:

```php
// UTF-8 string "Hi" (0xa1 = str8)
$data = "\xa1\x02Hi";
[$value, $pos] = AmqpDecoder::decodeValue($data, 0);
// $value = "Hi"

// Binary data (0xa0 = vbin8)
$data = "\xa0\x04\x00\x01\x02\x03";
[$value, $pos] = AmqpDecoder::decodeValue($data, 0);
// $value = "\x00\x01\x02\x03"
```

### Compound Types

Lists and maps with count:

```php
// List8 with 3 items (0xc0)
// Format: [size] [count] [items...]
$data = "\xc0\x05\x03\x52\x01\x52\x02\x52\x03";  // [1, 2, 3]
[$value, $pos] = AmqpDecoder::decodeValue($data, 0);
// $value = [1, 2, 3]

// Map8 with 1 pair (0xc1)
// Format: [size] [count*2] [key] [value]
$data = "\xc1\x05\x02\xa1\x01a\x52\x01";  // {"a": 1}
[$value, $pos] = AmqpDecoder::decodeValue($data, 0);
// $value = ["a" => 1]
```

### Described Types

AMQP uses described types for message sections:

```
[0x00] [descriptor] [value]
```

Descriptor can be:
- Small ulong (0x53): `0x53 0x70` = descriptor 0x70 (Header)
- Symbol: `0xa3 0x08 "amqp:header"`

## AMQP 1.0 Message Sections

### Section Overview

An AMQP message consists of multiple sections, each with a descriptor:

| Descriptor | Section | Description |
|------------|---------|-------------|
| 0x70 | Header | Message header (durable, priority, ttl) |
| 0x71 | DeliveryAnnotations | Delivery-specific annotations |
| 0x72 | MessageAnnotations | Message metadata annotations |
| 0x73 | Properties | Message properties (13 fields) |
| 0x74 | ApplicationProperties | Application-defined properties |
| 0x75 | Data | Binary body content |
| 0x76 | AmqpValue | AMQP-typed body value |
| 0x77 | AmqpSequence | Body as AMQP sequence |
| 0x78 | Footer | Message footer (annotations) |

### Section Binary Format

Each section follows this pattern:

```
[0x00] [descriptor] [value]
```

**Example - Properties section:**
```
0x00                    # Described type marker
0x53 0x73               # Descriptor: smallulong 0x73 (Properties)
0xc0 0x10 0x02          # List8: size=16, count=2
  0xa1 0x0a message-id  # Field 0: message-id = "message-id"
  0xa1 0x07 text/plain  # Field 1: content-type = "text/plain"
```

### Properties Field Mapping

The Properties section (0x73) contains 13 ordered fields:

| Index | Field Name | Type | Description |
|-------|------------|------|-------------|
| 0 | message-id | * | Unique message identifier |
| 1 | user-id | binary | User ID of the sender |
| 2 | to | string | Destination address |
| 3 | subject | string | Message subject/topic |
| 4 | reply-to | string | Reply address |
| 5 | correlation-id | * | Correlation identifier |
| 6 | content-type | string | MIME content type |
| 7 | content-encoding | string | Content encoding |
| 8 | absolute-expiry-time | timestamp | Expiration time |
| 9 | creation-time | timestamp | Creation timestamp |
| 10 | group-id | string | Group identifier |
| 11 | group-sequence | uint | Group sequence number |
| 12 | reply-to-group-id | string | Reply-to group ID |

*Note: Fields are positional in the list. Null values are skipped.*

### Complete Message Example

```
# Header section (0x70)
00 53 70                    # Described type, descriptor 0x70
C0 03 01 42                 # List8: size=3, count=1, boolean false
                            # Header: durable=false

# Properties section (0x73)  
00 53 73                    # Described type, descriptor 0x73
C0 15 03                    # List8: size=21, count=3
  A1 0A 6D6573736167652D31  # message-id = "message-1"
  A1 0A 746578742F706C6169 # content-type = "text/plain"
  A1 08 7574662D38          # content-encoding = "utf-8"

# ApplicationProperties section (0x74)
00 53 74                    # Described type, descriptor 0x74
C1 0E 04                    # Map8: size=14, count=4 (2 pairs)
  A1 07 636F756E747279     # key: "country"
  A1 02 5553               # value: "US"
  A1 04 63697479           # key: "city"
  A1 08 4E657720596F726B   # value: "New York"

# Data section (0x75)
00 53 75                    # Described type, descriptor 0x75
A0 0C                       # Vbin8: length=12
  48656C6C6F2C20576F726C6421  # "Hello, World!"
```

## AmqpDecoder

### Decoding Single Values

```php
use CrazyGoat\RabbitStream\Client\AmqpDecoder;

$data = "\x52\x2a";  # smalluint 42
[$value, $newPosition] = AmqpDecoder::decodeValue($data, 0);
// $value = 42
// $newPosition = 2
```

### Decoding Complete Messages

```php
use CrazyGoat\RabbitStream\Client\AmqpDecoder;

$binaryMessage = /* ... AMQP message bytes ... */;
$sections = AmqpDecoder::decodeMessage($binaryMessage);

// Returns:
// [
//   'header' => ['durable' => false, 'priority' => 4],
//   'deliveryAnnotations' => null,
//   'messageAnnotations' => ['x-opt-sequence-number' => 12345],
//   'properties' => [
//     'message-id' => 'msg-001',
//     'content-type' => 'application/json',
//   ],
//   'applicationProperties' => ['tenant' => 'acme'],
//   'body' => '{"order": 123}',
//   'footer' => null,
// ]
```

### Section Access

```php
$sections = AmqpDecoder::decodeMessage($data);

// Access specific sections
$properties = $sections['properties'] ?? [];
$body = $sections['body'] ?? null;
$appProps = $sections['applicationProperties'] ?? [];

// Common property access
$messageId = $properties['message-id'] ?? null;
$contentType = $properties['content-type'] ?? 'application/octet-stream';
```

## AmqpMessageDecoder

### ChunkEntry to Message Pipeline

The `AmqpMessageDecoder` bridges Osiris chunks to high-level Message objects:

```
┌─────────────┐     ┌─────────────┐     ┌─────────────┐
│ ChunkEntry  │────▶│ AmqpDecoder │────▶│   Message   │
│  (binary)   │     │  (sections) │     │  (object)   │
└─────────────┘     └─────────────┘     └─────────────┘
```

### Decoding a Single Entry

```php
use CrazyGoat\RabbitStream\Client\AmqpMessageDecoder;
use CrazyGoat\RabbitStream\Client\ChunkEntry;

$entry = new ChunkEntry(
    offset: 12345,
    data: $amqpBinaryData,
    timestamp: 1699999999000
);

$message = AmqpMessageDecoder::decode($entry);

// Access message properties
echo $message->getOffset();      // 12345
echo $message->getTimestamp();   // 1699999999000
echo $message->getBody();        // Decoded body content
print_r($message->getProperties()); // AMQP properties
```

### Decoding Multiple Entries

```php
use CrazyGoat\RabbitStream\Client\AmqpMessageDecoder;

/** @var ChunkEntry[] $entries */
$entries = /* ... from OsirisChunkParser ... */;

$messages = AmqpMessageDecoder::decodeAll($entries);

foreach ($messages as $message) {
    echo "Offset: {$message->getOffset()}\n";
    echo "Body: {$message->getBody()}\n";
}
```

### Message Object Structure

```php
class Message
{
    public function __construct(
        private int $offset,
        private int $timestamp,
        private mixed $body,
        private array $properties,
        private array $applicationProperties,
        private array $messageAnnotations,
    )
    
    public function getOffset(): int;
    public function getTimestamp(): int;
    public function getBody(): mixed;
    public function getProperties(): array;
    public function getApplicationProperties(): array;
    public function getMessageAnnotations(): array;
}
```

## Working with Message Properties

### Common Property Patterns

```php
$message = AmqpMessageDecoder::decode($entry);
$props = $message->getProperties();

// Message identification
$messageId = $props['message-id'] ?? null;
$correlationId = $props['correlation-id'] ?? null;

// Routing information
$to = $props['to'] ?? null;
$replyTo = $props['reply-to'] ?? null;
$subject = $props['subject'] ?? null;

// Content metadata
$contentType = $props['content-type'] ?? 'application/octet-stream';
$contentEncoding = $props['content-encoding'] ?? null;

// Timestamps
$creationTime = $props['creation-time'] ?? null;
$expiryTime = $props['absolute-expiry-time'] ?? null;

// Grouping
$groupId = $props['group-id'] ?? null;
$groupSequence = $props['group-sequence'] ?? null;
```

### Application Properties

```php
$appProps = $message->getApplicationProperties();

// Access custom application metadata
$tenantId = $appProps['tenant-id'] ?? 'default';
$eventType = $appProps['event-type'] ?? 'unknown';
$version = $appProps['schema-version'] ?? '1.0';
```

### Message Annotations

```php
$annotations = $message->getMessageAnnotations();

// RabbitMQ-specific annotations
$sequenceNumber = $annotations['x-opt-sequence-number'] ?? null;
$offset = $annotations['x-opt-offset'] ?? null;
$partition = $annotations['x-opt-partition-key'] ?? null;
```

## Error Handling

### Decoding Errors

```php
use CrazyGoat\RabbitStream\Exception\DeserializationException;

try {
    $sections = AmqpDecoder::decodeMessage($data);
} catch (DeserializationException $e) {
    // Handle malformed AMQP data
    echo "Failed to decode: " . $e->getMessage();
}
```

### Common Error Cases

1. **Unexpected end of data** — Truncated message
2. **Unsupported type** — Unknown format code
3. **Invalid described type** — Missing 0x00 marker
4. **List/map count mismatch** — Corrupted compound type

## Performance Considerations

### Lazy Decoding

For high-throughput scenarios, decode only needed sections:

```php
$sections = AmqpDecoder::decodeMessage($data);

// Only access what you need
if (isset($sections['applicationProperties']['priority'])) {
    // Process high-priority messages
}
```

### Body Handling

The body can be:
- **Data (0x75):** Binary string — most common
- **AmqpValue (0x76):** Any AMQP type
- **AmqpSequence (0x77):** Array of AMQP values

```php
$body = $sections['body'];

if (is_string($body)) {
    // Binary data section
    $json = json_decode($body, true);
} elseif (is_array($body)) {
    // AmqpSequence or AmqpValue array
    foreach ($body as $item) {
        // Process each item
    }
}
```

## See Also

- [Osiris Chunk Format](./osiris-chunk-format.md)
- [Consuming Guide](../guide/consuming.md)
- [Message API Reference](../api-reference/message.md)
- [AMQP 1.0 Specification](https://docs.oasis-open.org/amqp/core/v1.0/os/amqp-core-overview-v1.0-os.html)
