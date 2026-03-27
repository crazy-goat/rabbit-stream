# Value Objects

This section provides API reference documentation for value objects used in the RabbitMQ Streams Protocol client.

All value objects are immutable and located in `src/VO/` and `src/Client/` directories.

---

## OffsetSpec

Specifies where to start consuming from a stream. Located in `src/VO/OffsetSpec.php`.

The `OffsetSpec` value object defines the starting position for a consumer subscription. It supports various offset types including first/last messages, specific offsets, timestamps, and intervals.

### Type Constants

| Constant | Value | Description |
|----------|-------|-------------|
| `TYPE_FIRST` | 0x0001 | Start from the first message in the stream |
| `TYPE_LAST` | 0x0002 | Start from the last message (most recent) |
| `TYPE_NEXT` | 0x0003 | Start from the next message (after last consumed) |
| `TYPE_OFFSET` | 0x0004 | Start from a specific offset value |
| `TYPE_TIMESTAMP` | 0x0005 | Start from messages at or after a specific timestamp |
| `TYPE_INTERVAL` | 0x0006 | Start with an interval offset |

### Constructor

```php
public function __construct(
    private readonly int $type,
    private readonly ?int $value = null
)
```

**Parameters:**

| Parameter | Type | Required | Description |
|-----------|------|----------|-------------|
| `$type` | `int` | Yes | One of the `TYPE_*` constants |
| `$value` | `?int` | No | Offset value (required for `TYPE_OFFSET`, `TYPE_TIMESTAMP`, `TYPE_INTERVAL`) |

**Throws:**

- `InvalidArgumentException` - If an invalid type is provided

### Factory Methods

#### first()

Create an offset spec for the first message.

```php
public static function first(): self
```

**Example:**

```php
use CrazyGoat\RabbitStream\VO\OffsetSpec;

$offset = OffsetSpec::first();
```

#### last()

Create an offset spec for the last message.

```php
public static function last(): self
```

**Example:**

```php
use CrazyGoat\RabbitStream\VO\OffsetSpec;

$offset = OffsetSpec::last();
```

#### next()

Create an offset spec for the next message.

```php
public static function next(): self
```

**Example:**

```php
use CrazyGoat\RabbitStream\VO\OffsetSpec;

$offset = OffsetSpec::next();
```

#### offset()

Create an offset spec for a specific offset value.

```php
public static function offset(int $offset): self
```

**Parameters:**

| Parameter | Type | Description |
|-----------|------|-------------|
| `$offset` | `int` | The specific offset to start from |

**Example:**

```php
use CrazyGoat\RabbitStream\VO\OffsetSpec;

$offset = OffsetSpec::offset(1000); // Start from offset 1000
```

#### timestamp()

Create an offset spec for a specific timestamp.

```php
public static function timestamp(int $timestamp): self
```

**Parameters:**

| Parameter | Type | Description |
|-----------|------|-------------|
| `$timestamp` | `int` | Unix timestamp in milliseconds |

**Example:**

```php
use CrazyGoat\RabbitStream\VO\OffsetSpec;

$timestamp = (int) (microtime(true) * 1000) - 3600000; // 1 hour ago
$offset = OffsetSpec::timestamp($timestamp);
```

#### interval()

Create an offset spec with an interval.

```php
public static function interval(int $interval): self
```

**Parameters:**

| Parameter | Type | Description |
|-----------|------|-------------|
| `$interval` | `int` | Interval value |

**Example:**

```php
use CrazyGoat\RabbitStream\VO\OffsetSpec;

$offset = OffsetSpec::interval(5000);
```

### Getters

#### getType()

```php
public function getType(): int
```

Returns the offset type constant.

#### getValue()

```php
public function getValue(): ?int
```

Returns the offset value (if applicable).

### Implements

- `ToStreamBufferInterface` - Can be serialized to protocol buffer
- `ToArrayInterface` - Can be converted to array

### Usage

Used in:
- `SubscribeRequestV1` - `src/Request/SubscribeRequestV1.php`
- `Consumer` - `src/Client/Consumer.php`
- `Connection::subscribe()` - `src/Client/Connection.php`
- `ResolveOffsetSpecRequestV1` - `src/Request/ResolveOffsetSpecRequestV1.php`

**Example:**

```php
use CrazyGoat\RabbitStream\VO\OffsetSpec;
use CrazyGoat\RabbitStream\Client\Connection;

$connection = new Connection('localhost', 5552);
$connection->connect();

// Subscribe from the beginning
$consumer = $connection->subscribe(
    'my-stream',
    offset: OffsetSpec::first()
);

// Subscribe from the most recent message
$consumer = $connection->subscribe(
    'my-stream',
    offset: OffsetSpec::last()
);

// Subscribe from a specific offset
$consumer = $connection->subscribe(
    'my-stream',
    offset: OffsetSpec::offset(5000)
);
```

---

## KeyValue

Simple key-value pair for protocol properties. Located in `src/VO/KeyValue.php`.

The `KeyValue` value object represents a string key-value pair used throughout the protocol for properties, metadata, and configuration.

### Constructor

```php
public function __construct(
    private readonly string $key,
    private readonly ?string $value
)
```

**Parameters:**

| Parameter | Type | Required | Description |
|-----------|------|----------|-------------|
| `$key` | `string` | Yes | The property key |
| `$value` | `?string` | Yes | The property value (can be null) |

### Getters

#### getKey()

```php
public function getKey(): string
```

Returns the key.

#### getValue()

```php
public function getValue(): ?string
```

Returns the value (may be null).

### Implements

- `FromStreamBufferInterface` - Can be deserialized from protocol buffer
- `ToStreamBufferInterface` - Can be serialized to protocol buffer
- `ToArrayInterface` - Can be converted to array
- `FromArrayInterface` - Can be created from array

### Usage

Used in:
- `OpenResponseV1` - Connection properties - `src/Response/OpenResponseV1.php`
- `PeerPropertiesResponseV1` - Peer properties - `src/Response/PeerPropertiesResponseV1.php`
- `PeerPropertiesToStreamBufferV1` - Client properties - `src/Request/PeerPropertiesToStreamBufferV1.php`
- `CreateRequestV1` - Stream arguments - `src/Request/CreateRequestV1.php`
- `CreateSuperStreamRequestV1` - Super stream arguments - `src/Request/CreateSuperStreamRequestV1.php`

**Example:**

```php
use CrazyGoat\RabbitStream\VO\KeyValue;

// Create a key-value pair
$property = new KeyValue('product', 'MyApplication');
echo $property->getKey();   // "product"
echo $property->getValue(); // "MyApplication"

// With null value
$property = new KeyValue('empty', null);
echo $property->getValue(); // null

// Convert to array
$array = $property->toArray();
// ['key' => 'empty', 'value' => null]
```

---

## PublishedMessage

Message to be published (v1 protocol). Located in `src/VO/PublishedMessage.php`.

The `PublishedMessage` value object represents a single message to be published to a stream using the v1 protocol format.

### Constructor

```php
public function __construct(
    private readonly int $publishingId,
    private readonly string $message,
)
```

**Parameters:**

| Parameter | Type | Required | Description |
|-----------|------|----------|-------------|
| `$publishingId` | `int` | Yes | Unique publishing ID for deduplication |
| `$message` | `string` | Yes | Message body as binary string |

### Getters

#### getPublishingId()

```php
public function getPublishingId(): int
```

Returns the publishing ID.

### Implements

- `ToStreamBufferInterface` - Can be serialized to protocol buffer
- `ToArrayInterface` - Can be converted to array

### Usage

Used in:
- `PublishRequestV1` - `src/Request/PublishRequestV1.php`
- `Producer::send()` - `src/Client/Producer.php`

**Example:**

```php
use CrazyGoat\RabbitStream\VO\PublishedMessage;

// Create a message with publishing ID
$message = new PublishedMessage(
    publishingId: 1,
    message: 'Hello, World!'
);

// Get the publishing ID
$id = $message->getPublishingId(); // 1

// Convert to array
$array = $message->toArray();
// ['publishingId' => 1, 'data' => 'Hello, World!']
```

---

## PublishedMessageV2

Message to be published with filter support (v2 protocol). Located in `src/VO/PublishedMessageV2.php`.

The `PublishedMessageV2` value object extends `PublishedMessage` with filter value support for the v2 protocol, enabling message filtering on the server side.

### Constructor

```php
public function __construct(
    private readonly int $publishingId,
    private readonly string $filterValue,
    private readonly string $message,
)
```

**Parameters:**

| Parameter | Type | Required | Description |
|-----------|------|----------|-------------|
| `$publishingId` | `int` | Yes | Unique publishing ID for deduplication |
| `$filterValue` | `string` | Yes | Filter value for server-side filtering |
| `$message` | `string` | Yes | Message body as binary string |

### Getters

#### getPublishingId()

```php
public function getPublishingId(): int
```

Returns the publishing ID.

### Implements

- `ToStreamBufferInterface` - Can be serialized to protocol buffer
- `ToArrayInterface` - Can be converted to array

### Usage

Used in:
- `PublishRequestV2` - `src/Request/PublishRequestV2.php`

**Example:**

```php
use CrazyGoat\RabbitStream\VO\PublishedMessageV2;

// Create a filtered message
$message = new PublishedMessageV2(
    publishingId: 1,
    filterValue: 'order-type:priority',
    message: json_encode(['order_id' => 123, 'priority' => 'high'])
);

// Get the publishing ID
$id = $message->getPublishingId(); // 1

// Convert to array
$array = $message->toArray();
// ['publishingId' => 1, 'filterValue' => 'order-type:priority', 'data' => '{...}']
```

---

## PublishingError

Error information for failed publish operations. Located in `src/VO/PublishingError.php`.

The `PublishingError` value object contains details about a failed publish operation, including the publishing ID and error code.

### Constructor

```php
public function __construct(
    private readonly int $publishingId,
    private readonly int $code,
)
```

**Parameters:**

| Parameter | Type | Required | Description |
|-----------|------|----------|-------------|
| `$publishingId` | `int` | Yes | The publishing ID that failed |
| `$code` | `int` | Yes | Error code (see `ResponseCodeEnum`) |

### Getters

#### getPublishingId()

```php
public function getPublishingId(): int
```

Returns the publishing ID that failed.

#### getCode()

```php
public function getCode(): int
```

Returns the error code.

### Implements

- `FromStreamBufferInterface` - Can be deserialized from protocol buffer
- `ToArrayInterface` - Can be converted to array
- `FromArrayInterface` - Can be created from array

### Usage

Used in:
- `PublishErrorResponseV1` - `src/Response/PublishErrorResponseV1.php`

**Example:**

```php
use CrazyGoat\RabbitStream\VO\PublishingError;
use CrazyGoat\RabbitStream\Enum\ResponseCodeEnum;

// Create from response
$error = new PublishingError(
    publishingId: 42,
    code: ResponseCodeEnum::STREAM_NOT_EXIST->value
);

// Get error details
$publishingId = $error->getPublishingId(); // 42
$errorCode = $error->getCode(); // 2

// Convert to array
$array = $error->toArray();
// ['publishingId' => 42, 'code' => 2]
```

---

## Broker

Represents a RabbitMQ broker node. Located in `src/VO/Broker.php`.

The `Broker` value object contains information about a RabbitMQ broker node, including its reference ID, host, and port.

### Constructor

```php
public function __construct(
    private readonly int $reference,
    private readonly string $host,
    private readonly int $port
)
```

**Parameters:**

| Parameter | Type | Required | Description |
|-----------|------|----------|-------------|
| `$reference` | `int` | Yes | Broker reference ID |
| `$host` | `string` | Yes | Broker hostname or IP address |
| `$port` | `int` | Yes | Broker port number |

### Getters

#### getReference()

```php
public function getReference(): int
```

Returns the broker reference ID.

#### getHost()

```php
public function getHost(): string
```

Returns the broker hostname.

#### getPort()

```php
public function getPort(): int
```

Returns the broker port.

### Implements

- `FromStreamBufferInterface` - Can be deserialized from protocol buffer
- `ToArrayInterface` - Can be converted to array
- `FromArrayInterface` - Can be created from array

### Usage

Used in:
- `MetadataResponseV1` - `src/Response/MetadataResponseV1.php`

**Example:**

```php
use CrazyGoat\RabbitStream\VO\Broker;

// Create a broker reference
$broker = new Broker(
    reference: 1,
    host: 'rabbitmq.example.com',
    port: 5552
);

// Get broker details
echo $broker->getReference(); // 1
echo $broker->getHost();      // "rabbitmq.example.com"
echo $broker->getPort();      // 5552

// Convert to array
$array = $broker->toArray();
// ['reference' => 1, 'host' => 'rabbitmq.example.com', 'port' => 5552]
```

---

## StreamMetadata

Metadata about a stream including leader and replicas. Located in `src/VO/StreamMetadata.php`.

The `StreamMetadata` value object contains comprehensive metadata about a stream, including its name, response code, leader broker reference, and replica broker references.

### Constructor

```php
/**
 * @param array<int, int> $replicasReferences
 */
public function __construct(
    private readonly string $streamName,
    private readonly int $responseCode,
    private readonly int $leaderReference,
    private readonly array $replicasReferences
)
```

**Parameters:**

| Parameter | Type | Required | Description |
|-----------|------|----------|-------------|
| `$streamName` | `string` | Yes | Name of the stream |
| `$responseCode` | `int` | Yes | Response code (0 for success) |
| `$leaderReference` | `int` | Yes | Reference ID of the leader broker |
| `$replicasReferences` | `array<int, int>` | Yes | Array of replica broker reference IDs |

### Getters

#### getStreamName()

```php
public function getStreamName(): string
```

Returns the stream name.

#### getResponseCode()

```php
public function getResponseCode(): int
```

Returns the response code (0 indicates success).

#### getLeaderReference()

```php
public function getLeaderReference(): int
```

Returns the leader broker reference ID.

#### getReplicasReferences()

```php
/**
 * @return array<int, int>
 */
public function getReplicasReferences(): array
```

Returns an array of replica broker reference IDs.

### Implements

- `FromStreamBufferInterface` - Can be deserialized from protocol buffer
- `ToArrayInterface` - Can be converted to array
- `FromArrayInterface` - Can be created from array

### Usage

Used in:
- `MetadataResponseV1` - `src/Response/MetadataResponseV1.php`
- `Connection::createStream()` - `src/Client/Connection.php`

**Example:**

```php
use CrazyGoat\RabbitStream\VO\StreamMetadata;

// Create stream metadata
$metadata = new StreamMetadata(
    streamName: 'my-stream',
    responseCode: 0,
    leaderReference: 1,
    replicasReferences: [2, 3]
);

// Get metadata
echo $metadata->getStreamName();        // "my-stream"
echo $metadata->getResponseCode();    // 0
echo $metadata->getLeaderReference(); // 1
print_r($metadata->getReplicasReferences()); // [2, 3]

// Convert to array
$array = $metadata->toArray();
// ['stream' => 'my-stream', 'responseCode' => 0, 'leaderReference' => 1, 'replicasReferences' => [2, 3]]
```

---

## CommandVersion

Supported protocol command versions. Located in `src/VO/CommandVersion.php`.

The `CommandVersion` value object represents the version range supported for a specific protocol command, used during version negotiation.

### Constructor

```php
public function __construct(
    private readonly int $key,
    private readonly int $minVersion,
    private readonly int $maxVersion
)
```

**Parameters:**

| Parameter | Type | Required | Description |
|-----------|------|----------|-------------|
| `$key` | `int` | Yes | Command key (from `KeyEnum`) |
| `$minVersion` | `int` | Yes | Minimum supported version |
| `$maxVersion` | `int` | Yes | Maximum supported version |

### Getters

#### getKey()

```php
public function getKey(): int
```

Returns the command key.

#### getMinVersion()

```php
public function getMinVersion(): int
```

Returns the minimum supported version.

#### getMaxVersion()

```php
public function getMaxVersion(): int
```

Returns the maximum supported version.

### Implements

- `FromStreamBufferInterface` - Can be deserialized from protocol buffer
- `ToStreamBufferInterface` - Can be serialized to protocol buffer
- `ToArrayInterface` - Can be converted to array
- `FromArrayInterface` - Can be created from array

### Usage

Used in:
- `ExchangeCommandVersionsRequestV1` - `src/Request/ExchangeCommandVersionsRequestV1.php`
- `ExchangeCommandVersionsResponseV1` - `src/Response/ExchangeCommandVersionsResponseV1.php`

**Example:**

```php
use CrazyGoat\RabbitStream\VO\CommandVersion;
use CrazyGoat\RabbitStream\Enum\KeyEnum;

// Create a command version
$version = new CommandVersion(
    key: KeyEnum::PUBLISH->value,
    minVersion: 1,
    maxVersion: 2
);

// Get version info
echo $version->getKey();        // 2 (PUBLISH)
echo $version->getMinVersion(); // 1
echo $version->getMaxVersion(); // 2

// Convert to array
$array = $version->toArray();
// ['key' => 2, 'minVersion' => 1, 'maxVersion' => 2]
```

---

## Statistic

Stream statistics key-value pair. Located in `src/VO/Statistic.php`.

The `Statistic` value object represents a single stream statistic as a key-value pair, where the value is an integer (typically a count or size).

### Constructor

```php
public function __construct(
    private readonly string $key,
    private readonly int $value
)
```

**Parameters:**

| Parameter | Type | Required | Description |
|-----------|------|----------|-------------|
| `$key` | `string` | Yes | Statistic name/key |
| `$value` | `int` | Yes | Statistic value |

### Getters

#### getKey()

```php
public function getKey(): string
```

Returns the statistic key.

#### getValue()

```php
public function getValue(): int
```

Returns the statistic value.

### Implements

- `FromStreamBufferInterface` - Can be deserialized from protocol buffer
- `ToStreamBufferInterface` - Can be serialized to protocol buffer
- `ToArrayInterface` - Can be converted to array
- `FromArrayInterface` - Can be created from array

### Usage

Used in:
- `StreamStatsResponseV1` - `src/Response/StreamStatsResponseV1.php`

**Example:**

```php
use CrazyGoat\RabbitStream\VO\Statistic;

// Create a statistic
$stat = new Statistic(
    key: 'publishers',
    value: 5
);

// Get statistic info
echo $stat->getKey();   // "publishers"
echo $stat->getValue(); // 5

// Convert to array
$array = $stat->toArray();
// ['key' => 'publishers', 'value' => 5]
```

---

## ConfirmationStatus

Status of a publish confirmation. Located in `src/Client/ConfirmationStatus.php`.

The `ConfirmationStatus` value object is passed to the `onConfirm` callback in the `Producer` class to indicate whether a message was successfully confirmed by the server.

### Constructor

```php
public function __construct(
    private readonly bool $confirmed,
    private readonly ?int $errorCode = null,
    private readonly ?int $publishingId = null,
)
```

**Parameters:**

| Parameter | Type | Required | Description |
|-----------|------|----------|-------------|
| `$confirmed` | `bool` | Yes | Whether the message was confirmed |
| `$errorCode` | `?int` | No | Error code if confirmation failed |
| `$publishingId` | `?int` | No | Publishing ID of the message |

### Getters

#### isConfirmed()

```php
public function isConfirmed(): bool
```

Returns `true` if the message was successfully confirmed by the server.

#### getErrorCode()

```php
public function getErrorCode(): ?int
```

Returns the error code if the message failed, or `null` if confirmed successfully.

Common error codes:
- `0x02` - `STREAM_NOT_EXIST` - Stream does not exist
- `0x12` - `PUBLISHER_NOT_EXIST` - Publisher ID is invalid
- `0x10` - `ACCESS_REFUSED` - No write permission

See `ResponseCodeEnum` for all error codes.

#### getPublishingId()

```php
public function getPublishingId(): ?int
```

Returns the publishing ID of the message, or `null` if not available.

### Usage

Used in:
- `Producer` confirmation callback - `src/Client/Producer.php`

**Example:**

```php
use CrazyGoat\RabbitStream\Client\Connection;
use CrazyGoat\RabbitStream\Client\ConfirmationStatus;

$connection = new Connection('localhost', 5552);
$connection->connect();

$producer = $connection->createProducer(
    'my-stream',
    onConfirm: function (ConfirmationStatus $status) {
        if ($status->isConfirmed()) {
            echo "Message #{$status->getPublishingId()} confirmed\n";
        } else {
            $errorCode = $status->getErrorCode();
            echo "Message failed with error code: {$errorCode}\n";
        }
    }
);

$producer->send('Hello, World!');
$producer->waitForConfirms(timeout: 5.0);
```

---

## ChunkEntry

Single entry within an Osiris chunk. Located in `src/Client/ChunkEntry.php`.

The `ChunkEntry` value object represents a single message entry within a chunk delivered by the server. It contains the message offset, data, and timestamp.

### Constructor

```php
public function __construct(
    private readonly int $offset,
    private readonly string $data,
    private readonly int $timestamp,
)
```

**Parameters:**

| Parameter | Type | Required | Description |
|-----------|------|----------|-------------|
| `$offset` | `int` | Yes | Message offset in the stream |
| `$data` | `string` | Yes | Message data as binary string |
| `$timestamp` | `int` | Yes | Message timestamp (Unix timestamp in milliseconds) |

### Getters

#### getOffset()

```php
public function getOffset(): int
```

Returns the message offset in the stream.

#### getData()

```php
public function getData(): string
```

Returns the message data as a binary string.

#### getTimestamp()

```php
public function getTimestamp(): int
```

Returns the message timestamp (Unix timestamp in milliseconds).

### Usage

Used in:
- `OsirisChunkParser::parse()` - `src/Client/OsirisChunkParser.php`
- `AmqpMessageDecoder::decode()` - `src/Client/AmqpMessageDecoder.php`

**Example:**

```php
use CrazyGoat\RabbitStream\Client\ChunkEntry;

// Create a chunk entry (typically created by parser)
$entry = new ChunkEntry(
    offset: 1000,
    data: 'Hello, World!',
    timestamp: (int) (microtime(true) * 1000)
);

// Get entry info
echo $entry->getOffset();     // 1000
echo $entry->getData();      // "Hello, World!"
echo $entry->getTimestamp(); // 1234567890123

// Decode AMQP message
use CrazyGoat\RabbitStream\Client\AmqpMessageDecoder;

$message = AmqpMessageDecoder::decode($entry->getData());
```

---

## Summary

### Value Objects by Category

#### Publishing
- `PublishedMessage` - Single message for v1 protocol
- `PublishedMessageV2` - Message with filter support for v2 protocol
- `PublishingError` - Error details for failed publishes
- `ConfirmationStatus` - Confirmation status for published messages

#### Consuming
- `OffsetSpec` - Starting position specification for consumers
- `ChunkEntry` - Individual message within a delivered chunk

#### Stream Management
- `StreamMetadata` - Stream metadata including leader and replicas
- `Statistic` - Stream statistics key-value pair

#### Connection
- `Broker` - RabbitMQ broker node information
- `KeyValue` - Generic key-value pair for properties
- `CommandVersion` - Protocol command version information

### Common Interfaces

Most value objects implement one or more of these interfaces:

- `FromStreamBufferInterface` - Deserialization from protocol buffer
- `ToStreamBufferInterface` - Serialization to protocol buffer
- `ToArrayInterface` - Conversion to array
- `FromArrayInterface` - Creation from array

### See Also

- [Producer API Reference](producer.md) - Uses PublishedMessage, ConfirmationStatus
- [Consumer API Reference](consumer.md) - Uses OffsetSpec, ChunkEntry
- [Enums Reference](enums.md) - ResponseCodeEnum for error codes
- Source: `src/VO/` and `src/Client/` directories
