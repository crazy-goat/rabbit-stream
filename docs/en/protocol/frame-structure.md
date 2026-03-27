# Frame Structure

This document describes the binary format of RabbitMQ Streams protocol frames at the byte level.

## Frame Overview

Every frame consists of a **Size header** followed by a **Payload**:

```
┌─────────────────────────────────────────────────────────────────────────────┐
│                      Protocol Frame Structure                                │
└─────────────────────────────────────────────────────────────────────────────┘

Complete Frame Layout:
┌─────────────────────────────────────────────────────────────────────────────┐
│  Size (4 bytes)  │  Payload (variable)                                      │
│  uint32 BE       │  Key + Version + CorrelationId + Content                 │
└─────────────────────────────────────────────────────────────────────────────┘

Payload Structure:
┌──────────┬──────────┬─────────────────┬──────────────────────────────────────┐
│ Key      │ Version  │ CorrelationId   │ Content                              │
│ (2 bytes)│ (2 bytes)│ (4 bytes)       │ (variable)                           │
│ uint16   │ uint16   │ uint32          │ command-specific                     │
└──────────┴──────────┴─────────────────┴──────────────────────────────────────┘
```

## Field Details

### Size (uint32, 4 bytes)

The total size of the frame **excluding** the Size field itself:

```
Size = Length(Key) + Length(Version) + Length(CorrelationId) + Length(Content)
Size = 2 + 2 + 4 + len(Content)
Size = 8 + len(Content)
```

**Byte order:** Big-endian (network byte order)

**Example:** A frame with 20 bytes of content:
```
Size = 8 + 20 = 28
Bytes: [0x00 0x00 0x00 0x1C]
```

### Key (uint16, 2 bytes)

Identifies the command type:

```
┌─────────────────────────────────────────────────────────────────────────────┐
│  Request Key Range: 0x0001 - 0x7FFF (client-initiated)                       │
│  Response Key Range: 0x8001 - 0xFFFF (server-initiated)                      │
│                                                                              │
│  Response Key = Request Key | 0x8000                                         │
└─────────────────────────────────────────────────────────────────────────────┘
```

**Common Keys:**
| Key | Command | Direction |
|-----|---------|-----------|
| 0x0001 | DeclarePublisher | Request |
| 0x8001 | DeclarePublisherResponse | Response |
| 0x0002 | Publish | Request |
| 0x0007 | Subscribe | Request |
| 0x8007 | SubscribeResponse | Response |
| 0x0017 | Heartbeat | Bidirectional |

### Version (uint16, 2 bytes)

Protocol version for this command:

```
Version = 1  (for most current commands)
Version = 2  (for extended features like Deliver v2)
```

### CorrelationId (uint32, 4 bytes)

Unique identifier to match requests with responses:

```
┌─────────────────────────────────────────────────────────────────────────────┐
│  Request:  Client generates unique CorrelationId                             │
│  Response: Server echoes the same CorrelationId                              │
│  Server-Push: CorrelationId = 0 (or omitted)                                 │
└─────────────────────────────────────────────────────────────────────────────┘
```

**Example flow:**
```
Client: SubscribeRequest (CorrelationId = 42)
Server: SubscribeResponse (CorrelationId = 42) ✓ Match!
```

### Content (variable)

Command-specific data. See individual command documentation for content structure.

## Response Key Formula

```
┌─────────────────────────────────────────────────────────────────────────────┐
│  Response Key = Request Key | 0x8000                                         │
│                                                                              │
│  Example:                                                                    │
│  DeclarePublisher (0x0001) ──► DeclarePublisherResponse (0x8001)            │
│  Subscribe (0x0007) ─────────► SubscribeResponse (0x8007)                     │
│  Tune (0x0014) ─────────────► TuneResponse (0x8014)                        │
└─────────────────────────────────────────────────────────────────────────────┘
```

## Data Type Encodings

All multi-byte integers use **big-endian** byte order.

### Integer Types

| Type | Size | Range | PHP Equivalent |
|------|------|-------|----------------|
| `uint8` | 1 byte | 0 to 255 | `int` |
| `uint16` | 2 bytes | 0 to 65535 | `int` |
| `uint32` | 4 bytes | 0 to 4294967295 | `int` |
| `uint64` | 8 bytes | 0 to 2^64-1 | `int` |
| `int8` | 1 byte | -128 to 127 | `int` |
| `int16` | 2 bytes | -32768 to 32767 | `int` |
| `int32` | 4 bytes | -2147483648 to 2147483647 | `int` |

### String Encoding

Strings are length-prefixed UTF-8:

```
[String: int16-length-prefixed UTF-8]

Example: "Hi"
┌─────────┬─────────┬─────────┐
│ 0x00 02 │ 0x48   │ 0x69   │
│ Length  │ 'H'    │ 'i'    │
└─────────┴─────────┴─────────┘

Special value: -1 (0xFFFF) indicates null string
```

### Bytes Encoding

Raw byte arrays are length-prefixed:

```
[Bytes: int32-length-prefixed raw bytes]

Example: 5 bytes of data [0x01, 0x02, 0x03, 0x04, 0x05]
┌─────────┬─────────┬─────────┬─────────┬─────────┬─────────┐
│ 0x00 00 │ 0x00 05 │ 0x01   │ 0x02   │ 0x03   │ 0x04   │
│ Length  │         │ byte 0 │ byte 1 │ byte 2 │ byte 3 │
└─────────┴─────────┴─────────┴─────────┴─────────┴─────────┘

Special value: -1 (0xFFFFFFFF) indicates null bytes
```

### Array Encoding

Arrays are count-prefixed followed by elements:

```
[Array: int32-count + elements...]

Example: Array of 3 strings ["a", "b", "c"]
┌─────────┬─────────┬─────────┬─────────┐
│ 0x00 00 │ 0x00 03 │ String1 │ String2 │ String3 │
│ Count   │         │ "a"    │ "b"    │ "c"    │
└─────────┴─────────┴─────────┴─────────┘
```

### Map Encoding

Maps are count-prefixed key-value pairs:

```
[Map: int32-count + (key + value)...]

Example: Map with 2 entries {"key1": "value1", "key2": "value2"}
┌─────────┬─────────┬─────────┬─────────┬─────────┐
│ 0x00 00 │ 0x00 02 │ Key1   │ Value1 │ Key2   │ Value2 │
│ Count   │         │ string │ string │ string │ string │
└─────────┴─────────┴─────────┴─────────┴─────────┘
```

## Complete Frame Example

Here's a complete `DeclarePublisher` request frame:

```
DeclarePublisher Request (0x0001):
┌─────────────────────────────────────────────────────────────────────────────┐
│ Size: 0x00 0x00 0x00 0x1A (26 bytes of payload)                            │
├─────────────────────────────────────────────────────────────────────────────┤
│ Key: 0x00 0x01 (0x0001 = DeclarePublisher)                                 │
│ Version: 0x00 0x01 (version 1)                                             │
│ CorrelationId: 0x00 0x00 0x00 0x01 (1)                                     │
├─────────────────────────────────────────────────────────────────────────────┤
│ Content:                                                                   │
│   publisherId: 0x01 (uint8, value 1)                                       │
│   publisherReference: 0x00 0x04 "test" (string "test")                     │
│   stream: 0x00 0x08 "my-stream" (string "my-stream")                       │
└─────────────────────────────────────────────────────────────────────────────┘

Hex dump:
00 00 00 1A  00 01 00 01  00 00 00 01  01 00 04 74
65 73 74 00  08 6D 79 2D  73 74 72 65  61 6D
```

## PHP Implementation

### Writing Frames

```php
use CrazyGoat\RabbitStream\Buffer\WriteBuffer;

$buffer = new WriteBuffer();
$buffer
    ->addUint16(0x0001)           // Key: DeclarePublisher
    ->addUint16(1)                // Version: 1
    ->addUint32($correlationId)   // CorrelationId
    ->addUint8($publisherId)      // publisherId
    ->addString($reference)        // publisherReference
    ->addString($stream);         // stream name

// Get frame bytes
$frame = $buffer->getBytes();
```

### Reading Frames

```php
use CrazyGoat\RabbitStream\Buffer\ReadBuffer;

$buffer = new ReadBuffer($frameData);

$size = $buffer->getUint32();     // Frame size
$key = $buffer->getUint16();      // Command key
$version = $buffer->getUint16();  // Protocol version
$correlationId = $buffer->getUint32();  // Correlation ID
// ... read command-specific content
```

### Frame Size Calculation

```php
// Calculate frame size before sending
$contentLength = strlen($content);
$frameSize = 8 + $contentLength;  // 8 = Key(2) + Version(2) + CorrelationId(4)

// Write size header followed by payload
$sizeBuffer = new WriteBuffer();
$sizeBuffer->addUint32($frameSize);
$fullFrame = $sizeBuffer->getBytes() . $content;
```

## Big-Endian Byte Order

All multi-byte values use **big-endian** (network byte order):

```
Value: 0x1234 (4660 in decimal)
Big-endian:    [0x12] [0x34]
Little-endian: [0x34] [0x12]  ← NOT used in this protocol

Value: 0x12345678 (305419896 in decimal)
Big-endian:    [0x12] [0x34] [0x56] [0x78]
```

PHP's `pack()` function with format `'n'` (uint16) and `'N'` (uint32) produces big-endian:

```php
$uint16 = pack('n', 0x1234);      // [0x12, 0x34]
$uint32 = pack('N', 0x12345678);  // [0x12, 0x34, 0x56, 0x78]
```

## Server-Push Frame Differences

Server-push frames have slight differences:

```
┌─────────────────────────────────────────────────────────────────────────────┐
│  Server-Push Frame Structure                                                 │
├─────────────────────────────────────────────────────────────────────────────┤
│  Size (4 bytes)                                                              │
│  Key (2 bytes) - in request range (0x0001-0x7FFF)                           │
│  Version (2 bytes)                                                           │
│  [CorrelationId may be 0 or omitted]                                         │
│  Content (variable)                                                          │
└─────────────────────────────────────────────────────────────────────────────┘

Note: Server-push frames use REQUEST keys (0x0003, 0x0004, 0x0008, etc.)
      NOT response keys (0x8003, 0x8004, 0x8008, etc.)
```

**Examples:**
- `PublishConfirm` uses key `0x0003` (not `0x8003`)
- `Deliver` uses key `0x0008` (not `0x8008`)
- `Heartbeat` uses key `0x0017` (bidirectional)

See [Server Push Frames](./server-push-frames.md) for details.

## Next Steps

- Explore [Publishing Commands](./publishing-commands.md) - message production
- Explore [Consuming Commands](./consuming-commands.md) - message consumption
- Explore [Stream Management](./stream-management-commands.md) - administration
- Explore [Server Push Frames](./server-push-frames.md) - async handling
