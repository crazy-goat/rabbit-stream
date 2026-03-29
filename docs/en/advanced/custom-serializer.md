# Custom Serializer

> Implementing custom serialization for testing, debugging, and alternative wire formats

## Overview

The RabbitStream library uses a pluggable serializer architecture. By default, it uses `PhpBinarySerializer` which implements the `BinarySerializerInterface`. You can create custom serializers for testing, debugging, or alternative wire formats.

## BinarySerializerInterface

### Interface Definition

```php
namespace CrazyGoat\RabbitStream\Serializer;

interface BinarySerializerInterface
{
    /**
     * Converts a Request object to a binary frame (without the 4-byte length prefix).
     * Uses Request::toArray() to get the data, then serializes to binary.
     */
    public function serialize(object $request): string;

    /**
     * Converts a binary frame to a Response object.
     * Deserializes binary to array, then uses Response::fromArray() to build the object.
     */
    public function deserialize(string $frame): object;
}
```

### Key Responsibilities

1. **Serialization** — Convert request objects to binary wire format
2. **Deserialization** — Convert binary responses to typed objects
3. **Protocol Compliance** — Maintain RabbitMQ Stream Protocol compatibility

## PhpBinarySerializer (Default)

### How It Works

The default serializer uses the protocol's native binary format:

```php
namespace CrazyGoat\RabbitStream\Serializer;

use CrazyGoat\RabbitStream\Buffer\ReadBuffer;
use CrazyGoat\RabbitStream\Buffer\ToStreamBufferInterface;
use CrazyGoat\RabbitStream\Exception\InvalidArgumentException;
use CrazyGoat\RabbitStream\ResponseBuilder;

class PhpBinarySerializer implements BinarySerializerInterface
{
    public function serialize(object $request): string
    {
        if (!$request instanceof ToStreamBufferInterface) {
            throw new InvalidArgumentException(
                'Request must implement ToStreamBufferInterface'
            );
        }

        return $request->toStreamBuffer()->getContents();
    }

    public function deserialize(string $frame): object
    {
        return ResponseBuilder::fromResponseBuffer(new ReadBuffer($frame));
    }
}
```

### Usage with StreamConnection

```php
use CrazyGoat\RabbitStream\StreamConnection;
use CrazyGoat\RabbitStream\Serializer\PhpBinarySerializer;

$connection = new StreamConnection(
    host: '127.0.0.1',
    port: 5552,
    logger: new NullLogger(),
    serializer: new PhpBinarySerializer(),  // Default, explicit here
);
```

## Array-Based Serialization Interfaces

### ToArrayInterface

For array-based serialization, implement `ToArrayInterface`:

```php
namespace CrazyGoat\RabbitStream\Buffer;

interface ToArrayInterface
{
    /** @return array<string, mixed> */
    public function toArray(): array;
}
```

### FromArrayInterface

For deserialization from arrays:

```php
namespace CrazyGoat\RabbitStream\Buffer;

interface FromArrayInterface
{
    /** @param array<string, mixed> $data */
    public static function fromArray(array $data): static;
}
```

## Creating a Custom Serializer

### Example: JSON Serializer for Testing

```php
<?php

namespace MyApp\Serializer;

use CrazyGoat\RabbitStream\Serializer\BinarySerializerInterface;
use CrazyGoat\RabbitStream\Buffer\ToArrayInterface;
use CrazyGoat\RabbitStream\Buffer\FromArrayInterface;

class JsonSerializer implements BinarySerializerInterface
{
    public function serialize(object $request): string
    {
        if (!$request instanceof ToArrayInterface) {
            throw new \InvalidArgumentException(
                'Request must implement ToArrayInterface'
            );
        }

        $data = $request->toArray();
        $data['__class'] = get_class($request);  // Store class name
        
        return json_encode($data, JSON_THROW_ON_ERROR);
    }

    public function deserialize(string $frame): object
    {
        $data = json_decode($frame, true, 512, JSON_THROW_ON_ERROR);
        
        $className = $data['__class'] ?? null;
        unset($data['__class']);
        
        if ($className === null || !class_exists($className)) {
            throw new \InvalidArgumentException('Cannot determine target class');
        }
        
        if (!is_subclass_of($className, FromArrayInterface::class)) {
            throw new \InvalidArgumentException(
                "Class {$className} must implement FromArrayInterface"
            );
        }
        
        return $className::fromArray($data);
    }
}
```

### Example: Debug Serializer (Hex Dump)

```php
<?php

namespace MyApp\Serializer;

use CrazyGoat\RabbitStream\Serializer\BinarySerializerInterface;
use CrazyGoat\RabbitStream\Buffer\ToStreamBufferInterface;
use CrazyGoat\RabbitStream\ResponseBuilder;
use CrazyGoat\RabbitStream\Buffer\ReadBuffer;

class DebugSerializer implements BinarySerializerInterface
{
    private BinarySerializerInterface $inner;
    private LoggerInterface $logger;
    
    public function __construct(
        ?BinarySerializerInterface $inner = null,
        ?LoggerInterface $logger = null
    ) {
        $this->inner = $inner ?? new PhpBinarySerializer();
        $this->logger = $logger ?? new NullLogger();
    }
    
    public function serialize(object $request): string
    {
        $binary = $this->inner->serialize($request);
        
        $this->logger->debug(sprintf(
            "Serializing %s:\n%s",
            get_class($request),
            $this->hexDump($binary)
        ));
        
        return $binary;
    }
    
    public function deserialize(string $frame): object
    {
        $this->logger->debug(sprintf(
            "Deserializing frame:\n%s",
            $this->hexDump($frame)
        ));
        
        return $this->inner->deserialize($frame);
    }
    
    private function hexDump(string $data): string
    {
        $output = '';
        $length = strlen($data);
        
        for ($i = 0; $i < $length; $i += 16) {
            $chunk = substr($data, $i, 16);
            $hex = implode(' ', str_split(bin2hex($chunk), 2));
            $ascii = preg_replace('/[^\x20-\x7E]/', '.', $chunk);
            $output .= sprintf("%08x  %-48s  %s\n", $i, $hex, $ascii);
        }
        
        return $output;
    }
}
```

### Example: MessagePack Serializer

```php
<?php

namespace MyApp\Serializer;

use CrazyGoat\RabbitStream\Serializer\BinarySerializerInterface;

class MessagePackSerializer implements BinarySerializerInterface
{
    public function serialize(object $request): string
    {
        if (!$request instanceof ToArrayInterface) {
            throw new \InvalidArgumentException(
                'Request must implement ToArrayInterface'
            );
        }
        
        return msgpack_pack($request->toArray());
    }
    
    public function deserialize(string $frame): object
    {
        $data = msgpack_unpack($frame);
        
        // Determine class and reconstruct
        // Implementation depends on your protocol
        return $this->reconstructObject($data);
    }
}
```

## Use Cases

### Testing and Debugging

**Problem:** Binary protocol is hard to inspect during tests

**Solution:** Use DebugSerializer to log all frames

```php
use MyApp\Serializer\DebugSerializer;
use Monolog\Logger;
use Monolog\Handler\StreamHandler;

$logger = new Logger('debug');
$logger->pushHandler(new StreamHandler('php://stdout'));

$connection = new StreamConnection(
    host: '127.0.0.1',
    port: 5552,
    serializer: new DebugSerializer(logger: $logger),
);
```

### Alternative Wire Formats

**Problem:** Need to integrate with non-RabbitMQ systems

**Solution:** Create a serializer that speaks your custom protocol

```php
class CustomWireFormatSerializer implements BinarySerializerInterface
{
    public function serialize(object $request): string
    {
        // Convert to your custom format
        // e.g., gRPC, Thrift, or proprietary format
    }
    
    public function deserialize(string $frame): object
    {
        // Parse from your custom format
    }
}
```

### Protocol Version Bridging

**Problem:** Migrating between protocol versions

**Solution:** Create a version-adapter serializer

```php
class V1ToV2AdapterSerializer implements BinarySerializerInterface
{
    public function serialize(object $request): string
    {
        // Convert V1 requests to V2 format
        if ($request instanceof V1Request) {
            $request = $this->convertToV2($request);
        }
        return $request->toStreamBuffer()->getContents();
    }
    
    // ... deserialize similarly
}
```

## Best Practices

### 1. Always Validate Input

```php
public function serialize(object $request): string
{
    if (!$request instanceof ToStreamBufferInterface) {
        throw new InvalidArgumentException(
            'Expected ToStreamBufferInterface, got ' . get_class($request)
        );
    }
    // ... proceed with serialization
}
```

### 2. Handle Errors Gracefully

```php
public function deserialize(string $frame): object
{
    try {
        // Attempt deserialization
    } catch (\Exception $e) {
        throw new DeserializationException(
            'Failed to deserialize frame: ' . $e->getMessage(),
            0,
            $e
        );
    }
}
```

### 3. Document Format Changes

When creating custom formats, document the wire format:

```php
/**
 * Custom JSON Wire Format:
 * {
 *   "__class": "Fully\Qualified\ClassName",
 *   "field1": "value1",
 *   "field2": 123
 * }
 */
```

### 4. Test Round-Trip Serialization

```php
public function testRoundTrip(): void
{
    $original = new MyRequest(field: 'value');
    
    $serialized = $this->serializer->serialize($original);
    $deserialized = $this->serializer->deserialize($serialized);
    
    assert($original->equals($deserialized));
}
```

## See Also

- [Binary Serialization](./binary-serialization.md)
- [WriteBuffer API Reference](../api-reference/write-buffer.md)
- [PSR Logging](./psr-logging.md)
- [Protocol Overview](../protocol/overview.md)
