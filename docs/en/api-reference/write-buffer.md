# WriteBuffer

The `WriteBuffer` class provides binary serialization for the RabbitMQ Stream Protocol. It serializes PHP values into big-endian binary format suitable for network transmission.

## Overview

`WriteBuffer` is a fluent buffer builder that supports:

- All integer types (signed and unsigned, 8-64 bit)
- UTF-8 strings with length prefix
- Binary byte arrays with length prefix
- Arrays of serializable objects
- Raw binary data appending

All methods return `self` for method chaining.

## Constructor

```php
public function __construct(private string $buffer = '')
```

**Parameters:**
- `$buffer` - Initial buffer content (optional, default: empty string)

**Example:**
```php
$buffer = new WriteBuffer();           // Empty buffer
$buffer = new WriteBuffer('prefix');   // Pre-populated buffer
```

## Integer Methods

All integer methods validate that values are within the valid range for the type.

### addInt8()

Adds a signed 8-bit integer (-128 to 127).

```php
public function addInt8(int $value): self
```

**Range:** -128 to 127

**Throws:**
- `InvalidArgumentException` - If value is out of range

**Example:**
```php
$buffer->addInt8(-100)->addInt8(127);
```

### addInt16()

Adds a signed 16-bit integer (-32768 to 32767).

```php
public function addInt16(int $value): self
```

**Range:** -32768 to 32767

**Implementation Note:** Uses unsigned pack format 'n' intentionally. PHP's `pack()` with unsigned formats produces the correct two's complement binary representation for negative values. For example, `pack('n', -1)` produces `0xFFFF`.

**Throws:**
- `InvalidArgumentException` - If value is out of range

**Example:**
```php
$buffer->addInt16(-1000)->addInt16(32767);
```

### addInt32()

Adds a signed 32-bit integer (-2147483648 to 2147483647).

```php
public function addInt32(int $value): self
```

**Range:** -2147483648 to 2147483647

**Implementation Note:** Uses unsigned pack format 'N' intentionally. For example, `pack('N', -1)` produces `0xFFFFFFFF`.

**Throws:**
- `InvalidArgumentException` - If value is out of range

**Example:**
```php
$buffer->addInt32(-50000)->addInt32(2147483647);
```

### addInt64()

Adds a signed 64-bit integer (PHP_INT_MIN to PHP_INT_MAX).

```php
public function addInt64(int $value): self
```

**Range:** PHP_INT_MIN to PHP_INT_MAX

**Implementation Note:** Uses unsigned pack format 'J' intentionally. For example, `pack('J', -1)` produces `0xFFFFFFFFFFFFFFFF`.

**Throws:**
- `InvalidArgumentException` - If value is out of range

**Example:**
```php
$buffer->addInt64(PHP_INT_MIN)->addInt64(PHP_INT_MAX);
```

### addUInt8()

Adds an unsigned 8-bit integer (0 to 255).

```php
public function addUInt8(int $value): self
```

**Range:** 0 to 255

**Throws:**
- `InvalidArgumentException` - If value is out of range

**Example:**
```php
$buffer->addUInt8(0)->addUInt8(255);
```

### addUInt16()

Adds an unsigned 16-bit integer (0 to 65535).

```php
public function addUInt16(int $value): self
```

**Range:** 0 to 65535

**Throws:**
- `InvalidArgumentException` - If value is out of range

**Example:**
```php
$buffer->addUInt16(0)->addUInt16(65535);
```

### addUInt32()

Adds an unsigned 32-bit integer (0 to 4294967295).

```php
public function addUInt32(int $value): self
```

**Range:** 0 to 4294967295

**Throws:**
- `InvalidArgumentException` - If value is out of range

**Example:**
```php
$buffer->addUInt32(0)->addUInt32(4294967295);
```

### addUInt64()

Adds an unsigned 64-bit integer (0 to PHP_INT_MAX).

```php
public function addUInt64(int $value): self
```

**Range:** 0 to PHP_INT_MAX

**Throws:**
- `InvalidArgumentException` - If value is out of range

**Example:**
```php
$buffer->addUInt64(0)->addUInt64(PHP_INT_MAX);
```

## Integer Range Summary

| Method | Type | Min | Max |
|--------|------|-----|-----|
| `addInt8()` | Signed 8-bit | -128 | 127 |
| `addInt16()` | Signed 16-bit | -32768 | 32767 |
| `addInt32()` | Signed 32-bit | -2147483648 | 2147483647 |
| `addInt64()` | Signed 64-bit | PHP_INT_MIN | PHP_INT_MAX |
| `addUInt8()` | Unsigned 8-bit | 0 | 255 |
| `addUInt16()` | Unsigned 16-bit | 0 | 65535 |
| `addUInt32()` | Unsigned 32-bit | 0 | 4294967295 |
| `addUInt64()` | Unsigned 64-bit | 0 | PHP_INT_MAX |

## String/Bytes Methods

### addString()

Adds a UTF-8 string with 16-bit length prefix.

```php
public function addString(?string $value): self
```

**Parameters:**
- `$value` - String to add, or `null` for null string

**Encoding:**
- String must be valid UTF-8
- Maximum length: 32767 bytes (INT16_MAX)
- Null represented as length -1 (`0xFFFF`)

**Throws:**
- `InvalidArgumentException` - If string is not valid UTF-8
- `InvalidArgumentException` - If string exceeds 32767 bytes

**Example:**
```php
$buffer->addString('Hello, World!');
$buffer->addString(null);  // Null string
$buffer->addString('UTF-8: ñ 中文 🎉');  // Multibyte support
```

### addBytes()

Adds binary data with 32-bit length prefix.

```php
public function addBytes(?string $value): self
```

**Parameters:**
- `$value` - Binary data to add, or `null` for null bytes

**Encoding:**
- Maximum length: 2147483647 bytes (INT32_MAX)
- Null represented as length -1 (`0xFFFFFFFF`)
- No encoding validation performed

**Throws:**
- `InvalidArgumentException` - If data exceeds 2147483647 bytes

**Example:**
```php
$buffer->addBytes("\x00\x01\x02\x03");  // Binary data
$buffer->addBytes(null);  // Null bytes
$buffer->addBytes(file_get_contents('large.bin'));  // Large files
```

## Array Methods

### addArray()

Adds an array of objects implementing `ToStreamBufferInterface`.

```php
public function addArray(ToStreamBufferInterface ...$items): self
```

**Parameters:**
- `$items` - Variable number of serializable objects

**Encoding:**
- Array length as int32
- Each item serialized via `toStreamBuffer()`

**Example:**
```php
use CrazyGoat\RabbitStream\Buffer\ToStreamBufferInterface;
use CrazyGoat\RabbitStream\Buffer\WriteBuffer;

class Message implements ToStreamBufferInterface
{
    public function toStreamBuffer(): WriteBuffer
    {
        return (new WriteBuffer())->addString('content');
    }
}

$messages = [new Message(), new Message()];
$buffer->addArray(...$messages);
```

### addStringArray()

Adds an array of strings.

```php
public function addStringArray(string ...$strings): self
```

**Parameters:**
- `$strings` - Variable number of strings

**Encoding:**
- Array length as int32
- Each string encoded with `addString()`

**Example:**
```php
$buffer->addStringArray('stream1', 'stream2', 'stream3');
```

## Raw Buffer Methods

### addRaw()

Appends raw binary data directly to the buffer.

```php
public function addRaw(string $value): self
```

**Parameters:**
- `$value` - Raw binary data

**Note:** No length prefix is added. Use this for pre-encoded data.

**Example:**
```php
$buffer->addRaw("\x00\x01\x02");
$buffer->addRaw($otherBuffer->getContents());
```

### getContents()

Returns the complete buffer contents.

```php
public function getContents(): string
```

**Returns:** Binary string containing all serialized data

**Example:**
```php
$frame = $buffer->getContents();
socket_write($socket, $frame, strlen($frame));
```

## Error Handling

### InvalidArgumentException

Thrown for the following conditions:

- **Integer out of range:** Value exceeds the min/max for the specified type
- **Invalid UTF-8:** String contains invalid UTF-8 sequences
- **String too long:** String exceeds 32767 bytes
- **Bytes too long:** Binary data exceeds 2147483647 bytes

**Error Message Format:**
```
Value {value} is out of range for {type} ({min} to {max})
String must be valid UTF-8
```

## Examples

### Building a Protocol Frame

```php
use CrazyGoat\RabbitStream\Buffer\WriteBuffer;
use CrazyGoat\RabbitStream\Enum\KeyEnum;

$frame = (new WriteBuffer())
    ->addUInt16(KeyEnum::OPEN->value)  // Command key
    ->addUInt16(1)                      // Version
    ->addUInt32(12345)                  // Correlation ID
    ->addString('my-application')       // Client properties
    ->getContents();
```

### Fluent Interface Chaining

```php
$buffer = (new WriteBuffer())
    ->addUInt8(1)                       // Flags
    ->addUInt16(1000)                   // Port
    ->addString('localhost')              // Host
    ->addBytes("\x00\x01\x02")           // Binary data
    ->addInt32(-500)                    // Signed offset
    ->addUInt64(PHP_INT_MAX);           // Large value
```

### Handling Null Values

```php
// Null strings
$buffer->addString(null);  // Encoded as 0xFFFF

// Null bytes
$buffer->addBytes(null);   // Encoded as 0xFFFFFFFF

// These decode to null in ReadBuffer
```

### Complex Message Structure

```php
use CrazyGoat\RabbitStream\Buffer\WriteBuffer;
use CrazyGoat\RabbitStream\Buffer\ToStreamBufferInterface;

class KeyValue implements ToStreamBufferInterface
{
    public function __construct(
        private string $key,
        private string $value
    ) {}

    public function toStreamBuffer(): WriteBuffer
    {
        return (new WriteBuffer())
            ->addString($this->key)
            ->addString($this->value);
    }
}

$properties = [
    new KeyValue('product', 'MyApp'),
    new KeyValue('version', '1.0.0'),
];

$buffer = (new WriteBuffer())
    ->addUInt32(count($properties))
    ->addArray(...$properties)
    ->getContents();
```

### Range Validation

```php
try {
    $buffer = (new WriteBuffer())
        ->addInt8(200);  // Throws: out of range (max is 127)
} catch (InvalidArgumentException $e) {
    echo $e->getMessage();  // "Value 200 is out of range for int8 (-128 to 127)"
}

try {
    $buffer = (new WriteBuffer())
        ->addString("\xFF\xFE");  // Throws: invalid UTF-8
} catch (InvalidArgumentException $e) {
    echo $e->getMessage();  // "String must be valid UTF-8"
}
```

### Pre-populated Buffer

```php
// Start with existing data
$prefix = "\x00\x01\x02";
$buffer = new WriteBuffer($prefix);

// Append more data
$buffer
    ->addUInt16(42)
    ->addString('additional');

$result = $buffer->getContents();
// Contains: \x00\x01\x02 + \x00\x2a + \x00\x0aadditional
```
