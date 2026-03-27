# Binary Serialization

> Deep dive into the RabbitMQ Stream Protocol binary format

## Overview

The RabbitMQ Stream Protocol uses a binary wire format with big-endian byte order. This section explains how `WriteBuffer` and `ReadBuffer` implement this format internally.

## Integer Encoding

### Big-Endian Byte Order

All integers are encoded in big-endian (network byte order), where the most significant byte comes first:

```
Value: 0x1234 (4660 in decimal)
Big-endian: [0x12] [0x34]
```

### Supported Integer Types

| Type | Size | Range | PHP pack() Format |
|------|------|-------|-------------------|
| int8 | 1 byte | -128 to 127 | `c` (signed char) |
| uint8 | 1 byte | 0 to 255 | `C` (unsigned char) |
| int16 | 2 bytes | -32768 to 32767 | `n` with two's complement |
| uint16 | 2 bytes | 0 to 65535 | `n` (unsigned short) |
| int32 | 4 bytes | -2147483648 to 2147483647 | `N` with two's complement |
| uint32 | 4 bytes | 0 to 4294967295 | `N` (unsigned long) |
| int64 | 8 bytes | PHP_INT_MIN to PHP_INT_MAX | `J` with two's complement |
| uint64 | 8 bytes | 0 to PHP_INT_MAX | `J` (unsigned long long) |

### Two's Complement for Signed Integers

PHP's `pack()` doesn't have native big-endian signed formats, so we use unsigned formats and rely on two's complement representation:

```php
// Writing -1 as int16
pack('n', -1); // Produces 0xFFFF

// Reading back: convert from unsigned to signed
$value = unpack('n', $data)[1];
if ($value >= 0x8000) {
    $value -= 0x10000; // Convert to negative
}
```

## String Encoding

### Length-Prefixed UTF-8

Strings are encoded with a 16-bit signed length prefix followed by UTF-8 bytes:

```
[2 bytes: length] [N bytes: UTF-8 content]
```

**Length encoding:**
- `-1` (0xFFFF): Null string
- `0` to `32767`: Valid string length
- Maximum string size: 32,767 bytes

**Example:**
```
String: "Hi"
Encoding: [0x00 0x02] [0x48 0x69]
          └─ length ─┘ └─ "Hi" ─┘
```

**Null string:**
```
Encoding: [0xFF 0xFF]
          └─ -1 (null) ┘
```

## Bytes Encoding

### Length-Prefixed Binary Data

Byte arrays use a 32-bit signed length prefix:

```
[4 bytes: length] [N bytes: binary content]
```

**Length encoding:**
- `-1` (0xFFFFFFFF): Null bytes
- `0` to `2147483647`: Valid length
- Maximum size: 2,147,483,647 bytes (~2GB)

**Example:**
```
Bytes: \x00\x01\x02\x03
Encoding: [0x00 0x00 0x00 0x04] [0x00 0x01 0x02 0x03]
          └────── length ──────┘ └────── content ──────┘
```

## Array Encoding

### Object Arrays

Arrays of objects implementing `ToStreamBufferInterface`:

```
[4 bytes: count] [serialized object 1] [serialized object 2] ...
```

**Example:**
```php
class KeyValue implements ToStreamBufferInterface {
    public function toStreamBuffer(): WriteBuffer {
        return (new WriteBuffer())
            ->addString($this->key)
            ->addString($this->value);
    }
}

// Array encoding:
// [0x00 0x00 0x00 0x02]  // count = 2
// [0x00 0x04 key1...]    // first KeyValue
// [0x00 0x04 key2...]    // second KeyValue
```

### String Arrays

Arrays of strings use the same pattern with each string length-prefixed:

```
[4 bytes: count] [string 1] [string 2] ...
```

## Null Handling

### Null String

Encoded as length -1 (0xFFFF):
```php
$buffer->addString(null);
// Output: \xFF\xFF
```

### Null Bytes

Encoded as length -1 (0xFFFFFFFF):
```php
$buffer->addBytes(null);
// Output: \xFF\xFF\xFF\xFF
```

### Reading Null Values

```php
// ReadBuffer automatically returns null for -1 length
$string = $buffer->getString();  // Returns null if length was -1
$bytes = $buffer->getBytes();    // Returns null if length was -1
```

## Frame Structure

Complete protocol frame structure:

```
[4 bytes: frame size] [payload]
                      └─[2 bytes: key] [2 bytes: version] [4 bytes: correlation ID] [content...]
```

**Example frame:**
```
Size:     [0x00 0x00 0x00 0x1A]  (26 bytes)
Key:      [0x00 0x01]            (OPEN command)
Version:  [0x00 0x01]            (version 1)
Corr ID:  [0x00 0x00 0x30 0x39]  (12345)
Content:  [0x00 0x0E my-application] (string: "my-application")
```

## Working with Buffers

### Building a Frame

```php
use CrazyGoat\RabbitStream\Buffer\WriteBuffer;
use CrazyGoat\RabbitStream\Enum\KeyEnum;

$payload = (new WriteBuffer())
    ->addUInt16(KeyEnum::OPEN->value)
    ->addUInt16(1)
    ->addUInt32(12345)
    ->addString('my-application')
    ->getContents();

$frame = (new WriteBuffer())
    ->addUInt32(strlen($payload))
    ->addRaw($payload)
    ->getContents();
```

### Parsing a Frame

```php
use CrazyGoat\RabbitStream\Buffer\ReadBuffer;

$buffer = new ReadBuffer($frameData);

$key = $buffer->getUint16();        // Command key
$version = $buffer->getUint16();  // Protocol version
$correlationId = $buffer->getUint32();  // Request ID
$content = $buffer->getRemainingBytes();  // Rest of payload
```

## Performance Considerations

### Buffer Pre-allocation

For known sizes, pre-allocate to avoid reallocations:
```php
// Start with estimated capacity
$buffer = new WriteBuffer(str_repeat("\0", 1024));
$buffer->addUInt32(42);  // Overwrites from position 0
```

### Avoiding String Concatenation

`WriteBuffer` uses internal string concatenation. For very large payloads, consider:
- Streaming for payloads > 10MB
- Using `addRaw()` for pre-encoded chunks

### Validation Overhead

All integer methods validate ranges. In hot paths where values are trusted:
- Use `addRaw()` with pre-packed data
- Profile to identify bottlenecks

## See Also

- [WriteBuffer API Reference](../api-reference/write-buffer.md)
- [ReadBuffer API Reference](../api-reference/read-buffer.md)
- [Custom Serializer](./custom-serializer.md)
- [Protocol Overview](../protocol/overview.md)
