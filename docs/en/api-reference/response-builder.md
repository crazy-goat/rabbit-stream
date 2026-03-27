# ResponseBuilder

The `ResponseBuilder` class is a static dispatcher that converts raw protocol frames into typed response objects. It reads the command code and version from the buffer, then routes to the appropriate response class.

## Overview

`ResponseBuilder` provides:

- Static frame-to-object conversion
- Protocol version dispatch (V1, V2)
- Command code to response class mapping
- Automatic deserialization via `fromStreamBuffer()`

## Static Dispatcher Method

### fromResponseBuffer()

The main entry point for deserializing response frames.

```php
public static function fromResponseBuffer(ReadBuffer $responseBuffer): object
```

**Parameters:**
- `$responseBuffer` - `ReadBuffer` containing the frame payload (without size prefix)

**Returns:** Typed response object (e.g., `OpenResponseV1`, `SubscribeResponseV1`)

**Process:**
1. Reads command code (uint16) from buffer
2. Converts to `KeyEnum` via `KeyEnum::fromStreamCode()`
3. Reads version (uint16)
4. Rewinds buffer to position 0
5. Dispatches to `getV1()` or `getV2()` based on version
6. Returns deserialized response object

**Throws:**
- `ProtocolException` - If protocol version is unexpected (not 1 or 2)
- `ProtocolException` - If command is unexpected for the version
- `DeserializationException` - If response deserialization fails

**Example:**
```php
use CrazyGoat\RabbitStream\Buffer\ReadBuffer;
use CrazyGoat\RabbitStream\ResponseBuilder;

// Read frame from socket
$frameData = readFrameFromSocket();
$buffer = new ReadBuffer($frameData);

// Convert to typed response
$response = ResponseBuilder::fromResponseBuffer($buffer);

// $response is now a specific type, e.g., OpenResponseV1
if ($response instanceof OpenResponseV1) {
    echo "Connection opened: " . $response->getResponseCode() . "\n";
}
```

## Dispatch Logic

The builder uses a two-level dispatch system:

1. **Version Dispatch:** Routes to `getV1()` or `getV2()` based on the version field
2. **Command Dispatch:** Uses a `match` expression to route to the specific response class

### Version Handling

```php
$result = match ($version) {
    1 => self::getV1($command, $responseBuffer),
    2 => self::getV2($command, $responseBuffer),
    default => throw new ProtocolException('Unexpected protocol version: ' . $version),
};
```

## V1 Dispatch Table

The following commands are supported in protocol version 1:

| Command | KeyEnum | Response Class |
|---------|---------|----------------|
| DeclarePublisher | `DECLARE_PUBLISHER_RESPONSE` | `DeclarePublisherResponseV1` |
| DeletePublisher | `DELETE_PUBLISHER_RESPONSE` | `DeletePublisherResponseV1` |
| Subscribe | `SUBSCRIBE_RESPONSE` | `SubscribeResponseV1` |
| Unsubscribe | `UNSUBSCRIBE_RESPONSE` | `UnsubscribeResponseV1` |
| Create | `CREATE_RESPONSE` | `CreateResponseV1` |
| Delete | `DELETE_RESPONSE` | `DeleteStreamResponseV1` |
| PublishConfirm | `PUBLISH_CONFIRM` | `PublishConfirmResponseV1` |
| PublishError | `PUBLISH_ERROR` | `PublishErrorResponseV1` |
| Deliver | `DELIVER` | `DeliverResponseV1` |
| MetadataUpdate | `METADATA_UPDATE` | `MetadataUpdateResponseV1` |
| Metadata | `METADATA_RESPONSE` | `MetadataResponseV1` |
| QueryPublisherSequence | `QUERY_PUBLISHER_SEQUENCE_RESPONSE` | `QueryPublisherSequenceResponseV1` |
| QueryOffset | `QUERY_OFFSET_RESPONSE` | `QueryOffsetResponseV1` |
| Heartbeat | `HEARTBEAT` | `HeartbeatRequestV1` |
| ConsumerUpdate | `CONSUMER_UPDATE` | `ConsumerUpdateQueryV1` |
| Credit | `CREDIT_RESPONSE` | `CreditResponseV1` |
| Tune | `TUNE` | `TuneRequestV1` |
| SaslHandshake | `SASL_HANDSHAKE_RESPONSE` | `SaslHandshakeResponseV1` |
| SaslAuthenticate | `SASL_AUTHENTICATE_RESPONSE` | `SaslAuthenticateResponseV1` |
| Open | `OPEN_RESPONSE` | `OpenResponseV1` |
| Close | `CLOSE_RESPONSE` | `CloseResponseV1` |
| PeerProperties | `PEER_PROPERTIES_RESPONSE` | `PeerPropertiesResponseV1` |
| StreamStats | `STREAM_STATS_RESPONSE` | `StreamStatsResponseV1` |
| Partitions | `PARTITIONS_RESPONSE` | `PartitionsResponseV1` |
| Route | `ROUTE_RESPONSE` | `RouteResponseV1` |
| CreateSuperStream | `CREATE_SUPER_STREAM_RESPONSE` | `CreateSuperStreamResponseV1` |
| DeleteSuperStream | `DELETE_SUPER_STREAM_RESPONSE` | `DeleteSuperStreamResponseV1` |
| ExchangeCommandVersions | `EXCHANGE_COMMAND_VERSIONS_RESPONSE` | `ExchangeCommandVersionsResponseV1` |
| ResolveOffsetSpec | `RESOLVE_OFFSET_SPEC_RESPONSE` | `ResolveOffsetSpecResponseV1` |

**Total:** 25 commands supported in V1

## V2 Dispatch Table

The following commands are supported in protocol version 2:

| Command | KeyEnum | Response Class |
|---------|---------|----------------|
| Deliver | `DELIVER` | `DeliverResponseV1` |

**Note:** V2 currently only supports the Deliver command, using the same `DeliverResponseV1` class as V1.

## Adding New Response Types

To add support for a new protocol command:

### 1. Create Response Class

Create a new class in `src/Response/`:

```php
<?php

namespace CrazyGoat\RabbitStream\Response;

use CrazyGoat\RabbitStream\Buffer\FromStreamBufferInterface;
use CrazyGoat\RabbitStream\Buffer\ReadBuffer;
use CrazyGoat\RabbitStream\Enum\KeyEnum;
use CrazyGoat\RabbitStream\Contract\CorrelationInterface;
use CrazyGoat\RabbitStream\Contract\KeyVersionInterface;
use CrazyGoat\RabbitStream\Trait\CommandTrait;
use CrazyGoat\RabbitStream\Trait\CorrelationTrait;
use CrazyGoat\RabbitStream\Trait\V1Trait;

class NewCommandResponseV1 implements 
    KeyVersionInterface, 
    CorrelationInterface, 
    FromStreamBufferInterface
{
    use CorrelationTrait;
    use CommandTrait;
    use V1Trait;

    public static function fromStreamBuffer(ReadBuffer $buffer): ?object
    {
        self::validateKeyVersion($buffer->getUint16(), $buffer->getUint16());
        $correlationId = $buffer->getUint32();
        self::assertResponseCodeOk($buffer->getUint16());
        
        $object = new self();
        $object->withCorrelationId($correlationId);
        
        // Read additional fields...
        
        return $object;
    }

    public static function getKey(): int
    {
        return KeyEnum::NEW_COMMAND_RESPONSE->value;
    }
}
```

### 2. Register in KeyEnum

Add the response key to `src/Enum/KeyEnum.php`:

```php
case NEW_COMMAND_RESPONSE = 0x80xx;  // Response key = request key | 0x8000
```

### 3. Register in ResponseBuilder

Add to the `getV1()` method in `src/ResponseBuilder.php`:

```php
KeyEnum::NEW_COMMAND_RESPONSE => NewCommandResponseV1::fromStreamBuffer($responseBuffer),
```

### 4. Add Tests

Create tests in `tests/Response/NewCommandResponseV1Test.php` following the existing patterns.

## Error Handling

### ProtocolException

Thrown for the following conditions:

- **Unexpected version:** Protocol version is not 1 or 2
- **Unexpected command:** Command not supported for the version

**Error Message Format:**
```
Unexpected protocol version: {version}
Unexpected command: {commandName} (0x{hexCode})
Unexpected command in V2: {commandName}
```

### DeserializationException

Thrown when response deserialization fails:

- Response class `fromStreamBuffer()` returns `null`
- Buffer underflow during parsing
- Invalid data format

**Error Message Format:**
```
Failed to deserialize response for command: {commandName}
```

## Examples

### Basic Frame Parsing

```php
use CrazyGoat\RabbitStream\Buffer\ReadBuffer;
use CrazyGoat\RabbitStream\ResponseBuilder;

// Frame data from socket (without size prefix)
$frameData = "\x80\x15\x00\x01\x00\x00\x00\x01\x00\x01";
$buffer = new ReadBuffer($frameData);

// Parse frame
$response = ResponseBuilder::fromResponseBuffer($buffer);

// $response is OpenResponseV1
```

### Handling Different Response Types

```php
$response = ResponseBuilder::fromResponseBuffer($buffer);

match (true) {
    $response instanceof OpenResponseV1 => handleOpen($response),
    $response instanceof SubscribeResponseV1 => handleSubscribe($response),
    $response instanceof PublishConfirmResponseV1 => handleConfirm($response),
    $response instanceof DeliverResponseV1 => handleDeliver($response),
    default => throw new \Exception('Unknown response type'),
};
```

### Error Handling

```php
use CrazyGoat\RabbitStream\Exception\ProtocolException;
use CrazyGoat\RabbitStream\Exception\DeserializationException;

try {
    $response = ResponseBuilder::fromResponseBuffer($buffer);
} catch (ProtocolException $e) {
    // Unknown command or version
    echo "Protocol error: " . $e->getMessage() . "\n";
} catch (DeserializationException $e) {
    // Frame parsing failed
    echo "Parse error: " . $e->getMessage() . "\n";
}
```

### Integration with StreamConnection

```php
use CrazyGoat\RabbitStream\StreamConnection;
use CrazyGoat\RabbitStream\Request\OpenRequestV1;

$connection = new StreamConnection('localhost', 5552);
$connection->connect();

// Send request
$connection->sendMessage(new OpenRequestV1('my-client'));

// Read response (ResponseBuilder is used internally by the serializer)
$response = $connection->readMessage(5.0);

// $response is already the correct type
assert($response instanceof OpenResponseV1);
```

### Manual Frame Inspection

```php
use CrazyGoat\RabbitStream\Buffer\ReadBuffer;
use CrazyGoat\RabbitStream\Enum\KeyEnum;

$buffer = new ReadBuffer($frameData);

// Peek at command code
$commandCode = $buffer->peekUint16();
$command = KeyEnum::fromStreamCode($commandCode);

echo "Command: {$command->name} (0x" . dechex($commandCode) . ")\n";

// Now parse via ResponseBuilder
$buffer->rewind();
$response = ResponseBuilder::fromResponseBuffer($buffer);
```

### Version-Specific Handling

```php
// For protocol version 2 features
$buffer = new ReadBuffer($frameData);
$commandCode = $buffer->peekUint16();
$buffer->skip(2);
$version = $buffer->getUint16();

if ($version === 2) {
    // Handle V2-specific features
    $buffer->rewind();
    $response = ResponseBuilder::fromResponseBuffer($buffer);
}
```

## Implementation Details

### Buffer Rewinding

The builder rewinds the buffer after reading command and version:

```php
$commandCode = $responseBuffer->getUint16();
$command = KeyEnum::fromStreamCode($commandCode);
$version = $responseBuffer->getUint16();

$responseBuffer->rewind();  // Reset to position 0
```

This allows the individual response classes to read the full frame from the beginning, including key and version fields.

### Response Key Mapping

Response keys follow the protocol convention:

```
Response Key = Request Key | 0x8000
```

For example:
- Open request: `0x0015`
- Open response: `0x8015` (0x0015 | 0x8000)

### Null Handling

If a response class `fromStreamBuffer()` returns `null`, a `DeserializationException` is thrown:

```php
if ($result === null) {
    throw new DeserializationException('Failed to deserialize response for command: ' . $command->name);
}
```

This ensures that the caller always receives a valid response object or an exception.
