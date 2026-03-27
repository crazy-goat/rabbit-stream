# ReadBuffer

The `ReadBuffer` class provides binary deserialization for the RabbitMQ Stream Protocol. It reads big-endian binary data from a buffer and converts it to PHP values.

## Overview

`ReadBuffer` is a cursor-based buffer reader that supports:

- All integer types (signed and unsigned, 8-64 bit)
- UTF-8 strings with length prefix
- Binary byte arrays with length prefix
- Arrays of deserializable objects
- Position tracking and navigation
- Peek operations (non-consuming reads)

## Constructor

```php
public function __construct(private readonly string $buffer)
```

**Parameters:**
- `$buffer` - Binary data to read from

**Example:**
```php
$buffer = new ReadBuffer($frameData);
```

## Integer Read Methods

All integer methods read from the current position and advance the cursor.

### getUint8()

Reads an unsigned 8-bit integer.

```php
public function getUint8(): int
```

**Returns:** Integer in range 0 to 255

**Throws:**
- `DeserializationException` - If buffer underflow (less than 1 byte available)

**Example:**
```php
$value = $buffer->getUint8();  // e.g., 255
```

### getUint16()

Reads an unsigned 16-bit integer (big-endian).

```php
public function getUint16(): int
```

**Returns:** Integer in range 0 to 65535

**Throws:**
- `DeserializationException` - If buffer underflow (less than 2 bytes available)

**Example:**
```php
$value = $buffer->getUint16();  // e.g., 65535
```

### getUint32()

Reads an unsigned 32-bit integer (big-endian).

```php
public function getUint32(): int
```

**Returns:** Integer in range 0 to 4294967295

**Throws:**
- `DeserializationException` - If buffer underflow (less than 4 bytes available)

**Example:**
```php
$value = $buffer->getUint32();  // e.g., 4294967295
```

### getUint64()

Reads an unsigned 64-bit integer (big-endian).

```php
public function getUint64(): int
```

**Returns:** Integer in range 0 to PHP_INT_MAX

**Throws:**
- `DeserializationException` - If buffer underflow (less than 8 bytes available)

**Example:**
```php
$value = $buffer->getUint64();  // e.g., 9223372036854775807
```

### getInt16()

Reads a signed 16-bit integer (big-endian, two's complement).

```php
public function getInt16(): int
```

**Returns:** Integer in range -32768 to 32767

**Implementation Note:** Reads as unsigned and converts negative values using two's complement. For example, `0xFFFF` becomes -1.

**Throws:**
- `DeserializationException` - If buffer underflow (less than 2 bytes available)

**Example:**
```php
$value = $buffer->getInt16();  // e.g., -1 (from 0xFFFF)
```

### getInt32()

Reads a signed 32-bit integer (big-endian, two's complement).

```php
public function getInt32(): int
```

**Returns:** Integer in range -2147483648 to 2147483647

**Implementation Note:** Reads as unsigned and converts negative values using two's complement. For example, `0xFFFFFFFF` becomes -1.

**Throws:**
- `DeserializationException` - If buffer underflow (less than 4 bytes available)

**Example:**
```php
$value = $buffer->getInt32();  // e.g., -2147483648
```

### getInt64()

Reads a signed 64-bit integer (big-endian, two's complement).

```php
public function getInt64(): int
```

**Returns:** Integer in range PHP_INT_MIN to PHP_INT_MAX

**Implementation Note:** Reads as unsigned and converts negative values using two's complement. For example, `0xFFFFFFFFFFFFFFFF` becomes -1.

**Throws:**
- `DeserializationException` - If buffer underflow (less than 8 bytes available)

**Example:**
```php
$value = $buffer->getInt64();  // e.g., PHP_INT_MIN
```

## String/Bytes Read Methods

### getString()

Reads a UTF-8 string with 16-bit length prefix.

```php
public function getString(): ?string
```

**Returns:** String value, or `null` if length is -1 (0xFFFF)

**Encoding:**
- Length is read as signed 16-bit integer
- Length -1 (0xFFFF) represents null
- Maximum length: 32767 bytes
- Content is read as raw bytes (assumed UTF-8)

**Throws:**
- `DeserializationException` - If buffer underflow

**Example:**
```php
$string = $buffer->getString();  // e.g., "Hello, World!"
$nullString = $buffer->getString();  // null (if length was -1)
```

### getBytes()

Reads binary data with 32-bit length prefix.

```php
public function getBytes(): ?string
```

**Returns:** Binary data, or `null` if length is -1 (0xFFFFFFFF)

**Encoding:**
- Length is read as signed 32-bit integer
- Length -1 (0xFFFFFFFF) represents null
- Maximum length: 2147483647 bytes

**Throws:**
- `DeserializationException` - If buffer underflow

**Example:**
```php
$data = $buffer->getBytes();  // e.g., "\x00\x01\x02\x03"
$nullData = $buffer->getBytes();  // null (if length was -1)
```

## Array Methods

### getObjectArray()

Reads an array of objects from the buffer.

```php
/**
 * @template T of FromStreamBufferInterface
 * @param class-string<T> $class
 * @return array<int, T>
 */
public function getObjectArray(string $class): array
```

**Parameters:**
- `$class` - Fully-qualified class name implementing `FromStreamBufferInterface`

**Returns:** Array of deserialized objects

**Encoding:**
- Array length as uint32
- Each item deserialized via `fromStreamBuffer()`

**Throws:**
- `DeserializationException` - If buffer underflow or deserialization fails

**Example:**
```php
use CrazyGoat\RabbitStream\Response\MessageResponse;

$messages = $buffer->getObjectArray(MessageResponse::class);
```

### getStringArray()

Reads an array of strings from the buffer.

```php
/**
 * @return array<int, string|null>
 */
public function getStringArray(): array
```

**Returns:** Array of strings (may contain null values)

**Encoding:**
- Array length as uint32
- Each string decoded with `getString()`

**Throws:**
- `DeserializationException` - If buffer underflow

**Example:**
```php
$streams = $buffer->getStringArray();  // e.g., ['stream1', 'stream2']
```

## Position and Navigation Methods

### getPosition()

Returns the current cursor position.

```php
public function getPosition(): int
```

**Returns:** Current position in bytes (0 = start of buffer)

**Example:**
```php
$pos = $buffer->getPosition();  // e.g., 42
```

### skip()

Advances the cursor by the specified number of bytes.

```php
public function skip(int $bytes): void
```

**Parameters:**
- `$bytes` - Number of bytes to skip

**Throws:**
- `DeserializationException` - If skipping would exceed buffer bounds

**Example:**
```php
$buffer->skip(10);  // Skip 10 bytes
```

### rewind()

Resets the cursor to the beginning of the buffer.

```php
public function rewind(): void
```

**Example:**
```php
$buffer->rewind();  // Position is now 0
```

### readBytes()

Reads a fixed number of raw bytes.

```php
public function readBytes(int $length): string
```

**Parameters:**
- `$length` - Number of bytes to read

**Returns:** Raw binary data

**Throws:**
- `DeserializationException` - If buffer underflow

**Example:**
```php
$raw = $buffer->readBytes(16);  // Read 16 raw bytes
```

### getRemainingBytes()

Reads all remaining bytes from the current position to the end.

```php
public function getRemainingBytes(): string
```

**Returns:** All remaining buffer content

**Throws:**
- `DeserializationException` - If position is past buffer end

**Example:**
```php
$remaining = $buffer->getRemainingBytes();
// Position is now at end of buffer
```

## Peek Methods

### peekUint16()

Reads an unsigned 16-bit integer without advancing the cursor.

```php
public function peekUint16(): int
```

**Returns:** Integer in range 0 to 65535

**Throws:**
- `DeserializationException` - If buffer underflow (less than 2 bytes available)

**Example:**
```php
$key = $buffer->peekUint16();  // Peek at command key
// Position unchanged
```

## Error Handling

### DeserializationException

Thrown for the following conditions:

- **Buffer underflow:** Attempting to read more bytes than available
- **Unpack failure:** PHP's `unpack()` failed (rare, indicates corrupted data)
- **Position past end:** Cursor position exceeds buffer length
- **Object deserialization failure:** `fromStreamBuffer()` returned null

**Error Message Format:**
```
Buffer underflow: need {needed} bytes at position {position}, but only {available} available
Buffer underflow: position {position} is past buffer end {end}
Failed to unpack {type} at position {position}
Failed to deserialize object of class {class}
```

## Examples

### Reading a Protocol Frame

```php
use CrazyGoat\RabbitStream\Buffer\ReadBuffer;
use CrazyGoat\RabbitStream\Enum\KeyEnum;

$buffer = new ReadBuffer($frameData);

$key = $buffer->getUint16();       // Command key (e.g., 0x8015 for OpenResponse)
$version = $buffer->getUint16();   // Protocol version
$correlationId = $buffer->getUint32();  // Correlation ID
$responseCode = $buffer->getUint16();   // Response code (1 = OK)

// Map key to enum
$command = KeyEnum::fromStreamCode($key);
echo "Received: {$command->name}\n";
```

### Sequential Reading

```php
$buffer = new ReadBuffer($data);

// Read header
$magic = $buffer->getUint16();
$flags = $buffer->getUint8();

// Read string
$clientName = $buffer->getString();

// Read array of properties
$propertyCount = $buffer->getUint32();
for ($i = 0; $i < $propertyCount; $i++) {
    $key = $buffer->getString();
    $value = $buffer->getString();
    $properties[$key] = $value;
}
```

### Position Tracking

```php
$buffer = new ReadBuffer($data);

$startPos = $buffer->getPosition();  // 0

$key = $buffer->getUint16();         // Reads 2 bytes
$version = $buffer->getUint16();     // Reads 2 more bytes

currentPos = $buffer->getPosition();  // 4

// Skip correlation ID for now
$buffer->skip(4);

// Read response code
$responseCode = $buffer->getUint16();

// Go back and read correlation ID
$buffer->rewind();
$buffer->skip(4);  // Skip key + version
$correlationId = $buffer->getUint32();
```

### Handling Null Values

```php
$buffer = new ReadBuffer($data);

// String that might be null
$streamName = $buffer->getString();
if ($streamName === null) {
    echo "Stream name is null\n";
} else {
    echo "Stream: $streamName\n";
}

// Binary data that might be null
$metadata = $buffer->getBytes();
if ($metadata === null) {
    echo "No metadata\n";
}
```

### Peek Operations

```php
use CrazyGoat\RabbitStream\Enum\KeyEnum;

$buffer = new ReadBuffer($frameData);

// Peek at command key without consuming
$key = $buffer->peekUint16();

// Check if it's a server-push frame
$isServerPush = in_array($key, [
    KeyEnum::PUBLISH_CONFIRM->value,
    KeyEnum::PUBLISH_ERROR->value,
    KeyEnum::DELIVER->value,
], true);

if ($isServerPush) {
    // Handle server-push frame
} else {
    // Now actually read the key and continue
    $key = $buffer->getUint16();
    // ... process response
}
```

### Error Handling

```php
use CrazyGoat\RabbitStream\Exception\DeserializationException;

try {
    $buffer = new ReadBuffer($incompleteData);
    $value = $buffer->getUint32();  // May throw if < 4 bytes
} catch (DeserializationException $e) {
    echo "Parse error: " . $e->getMessage() . "\n";
    // "Buffer underflow: need 4 bytes at position 0, but only 2 available"
}
```

### Reading Complex Structures

```php
use CrazyGoat\RabbitStream\Buffer\ReadBuffer;
use CrazyGoat\RabbitStream\Buffer\FromStreamBufferInterface;

class KeyValue implements FromStreamBufferInterface
{
    private string $key;
    private ?string $value;

    public static function fromStreamBuffer(ReadBuffer $buffer): ?object
    {
        $obj = new self();
        $obj->key = $buffer->getString();
        $obj->value = $buffer->getString();
        return $obj;
    }

    public function getKey(): string { return $this->key; }
    public function getValue(): ?string { return $this->value; }
}

// Read array of KeyValue objects
$properties = $buffer->getObjectArray(KeyValue::class);
foreach ($properties as $prop) {
    echo "{$prop->getKey()}: {$prop->getValue()}\n";
}
```

### Working with Remaining Data

```php
$buffer = new ReadBuffer($frameData);

// Read fixed header
$key = $buffer->getUint16();
$version = $buffer->getUint16();
$correlationId = $buffer->getUint32();

// Everything else is the payload
$payload = $buffer->getRemainingBytes();

// Pass payload to another parser
$parser = new ResponseParser();
$response = $parser->parse($key, $payload);
```
