# Connection API Reference

Complete API reference for the `CrazyGoat\RabbitStream\Client\Connection` class.

## Class Overview

```php
namespace CrazyGoat\RabbitStream\Client;

class Connection
{
    // Static factory method
    public static function create(
        string $host = '127.0.0.1',
        int $port = 5552,
        string $user = 'guest',
        string $password = 'guest',
        string $vhost = '/',
        ?BinarySerializerInterface $serializer = null,
        ?LoggerInterface $logger = null,
        ?int $requestedFrameMax = null,
        ?int $requestedHeartbeat = null,
        ?int $maxDeliverFrameSize = null,
        ?StreamConnection $streamConnection = null,
    ): self;
    
    // Stream management
    public function createStream(string $name, array $arguments = []): void;
    public function deleteStream(string $name): void;
    public function streamExists(string $name): bool;
    public function getStreamStats(string $name): array;
    public function getMetadata(array $streams): MetadataResponseV1;
    
    // Offset management
    public function queryOffset(string $reference, string $stream): int;
    public function storeOffset(string $reference, string $stream, int $offset): void;
    
    // Super streams
    public function createSuperStream(
        string $name,
        array $partitions = [],
        array $bindingKeys = [],
        array $arguments = []
    ): void;
    public function deleteSuperStream(string $name): void;
    public function route(string $routingKey, string $superStream): array;
    public function partitions(string $superStream): array;
    
    // Factory methods
    public function createProducer(
        string $stream,
        ?string $name = null,
        ?callable $onConfirm = null,
    ): Producer;
    
    public function createConsumer(
        string $stream,
        OffsetSpec $offset,
        ?string $name = null,
        int $autoCommit = 0,
        int $initialCredit = 10,
        array $filterValues = [],
        bool $matchUnfiltered = false,
        bool $singleActiveConsumer = false,
        ?string $superStream = null,
    ): Consumer;
    
    public function createSuperStreamProducer(
        string $superStream,
        ?RoutingStrategy $strategy = null,
        ?string $name = null,
        ?callable $onConfirm = null,
    ): SuperStreamProducerInterface;
    
    public function createSuperStreamConsumer(
        string $superStream,
        OffsetSpec $offset,
        ?string $name = null,
        int $autoCommit = 0,
        int $initialCredit = 10,
        bool $singleActiveConsumer = false,
    ): SuperStreamConsumerInterface;
    
    // Lifecycle
    public function readLoop(?int $maxFrames = null, ?float $timeout = null): void;
    public function close(): void;
}
```

## Connection::create()

Static factory method to create and establish a connection to RabbitMQ Streams.

```php
public static function create(
    string $host = '127.0.0.1',
    int $port = 5552,
    string $user = 'guest',
    string $password = 'guest',
    string $vhost = '/',
    ?BinarySerializerInterface $serializer = null,
    ?LoggerInterface $logger = null,
    ?int $requestedFrameMax = null,
    ?int $requestedHeartbeat = null,
    ?int $maxDeliverFrameSize = null,
    ?StreamConnection $streamConnection = null,
): self
```

### Parameters

| Parameter | Type | Required | Description |
|-----------|------|----------|-------------|
| `$host` | `string` | No | RabbitMQ server hostname or IP. Default: `127.0.0.1` |
| `$port` | `int` | No | RabbitMQ Streams port. Default: `5552` |
| `$user` | `string` | No | Username for authentication. Default: `guest` |
| `$password` | `string` | No | Password for authentication. Default: `guest` |
| `$vhost` | `string` | No | Virtual host. Default: `/` |
| `$serializer` | `?BinarySerializerInterface` | No | Custom binary serializer. Default: `PhpBinarySerializer` |
| `$logger` | `?LoggerInterface` | No | PSR-3 logger for debugging. Default: `NullLogger` |
| `$requestedFrameMax` | `?int` | No | Requested maximum frame size for the *outgoing* protocol negotiation. `0` means unlimited. Default: `null` (use server value, capped by `StreamConnection::DEFAULT_MAX_FRAME_SIZE` unless you pass this explicitly — see [negotiation caveat](#frame_max-negotiation-only-lowers-the-default) below) |
| `$requestedHeartbeat` | `?int` | No | Requested heartbeat interval in seconds. `0` disables heartbeats. Default: `null` (use server value) |
| `$maxDeliverFrameSize` | `?int` | No | Max size in bytes for incoming **Deliver** frames (key `0x0008`), which the broker does not bound by the negotiated `frame_max` — see [Deliver frames need their own cap](#deliver-frames-need-their-own-cap) below. Default: `null` (`StreamConnection::DEFAULT_MAX_DELIVER_FRAME_SIZE`, 64MB) |
| `$streamConnection` | `?StreamConnection` | No | Pre-configured stream connection (advanced use). Default: `null` |

### Return Value

`Connection` - A fully established and authenticated connection

### Exceptions

- `InvalidArgumentException` - If `requestedFrameMax`, `requestedHeartbeat`, or `maxDeliverFrameSize` is negative
- `AuthenticationException` - If PLAIN SASL mechanism is not supported or credentials are invalid
- `UnexpectedResponseException` - If the server returns an unexpected response during handshake
- `ConnectionException` - If the TCP connection cannot be established

### Deliver frames need their own cap

The broker does not enforce the negotiated `frame_max` on Deliver frames: a
stream chunk is sent whole, however big it grew due to server-side
coalescing (e.g. a fast producer publishing several batches without calling
`waitForConfirms()` between them). `StreamConnection` therefore caps Deliver
frames with a separate, larger limit (`maxDeliverFrameSize`, default 64MB)
instead of the same limit used for every other incoming frame
(`StreamConnection::setMaxFrameSize()` / `DEFAULT_MAX_FRAME_SIZE`, 8MB). See
[Performance Tuning → Frame Size Limits](../advanced/performance-tuning.md#frame-size-limits)
for the full write-up and a reproduction of the underlying broker behavior.

### `frame_max` negotiation only lowers the default

If you don't pass `requestedFrameMax`, a broker advertising a huge `frame_max`
during Tune (including `0xFFFFFFFF`) cannot raise the effective control-frame
cap above `StreamConnection::DEFAULT_MAX_FRAME_SIZE` — the negotiated value is
only ever used to *lower* it. Pass `requestedFrameMax` explicitly to raise (or
lower) the cap deliberately.

### Outgoing frames over frame_max fail fast

A frame larger than the negotiated `frame_max` is now rejected before
anything is written to the socket, raising
`CrazyGoat\RabbitStream\Exception\InvalidArgumentException` with the frame
size and the limit in the message — the connection stays connected and
usable. Previously the oversized frame was written, the broker closed the
connection, and the failure surfaced later as an unrelated-looking "Cannot
read: socket is not connected".

### Connection Handshake Flow

When `create()` is called, it performs the following protocol handshake:

1. **PeerProperties** - Exchange client/server capabilities
2. **SaslHandshake** - Negotiate authentication mechanism (requires PLAIN)
3. **SaslAuthenticate** - Authenticate with username/password
4. **Tune** - Negotiate frame max and heartbeat settings
5. **Open** - Open the virtual host

### Examples

**Basic connection:**
```php
use CrazyGoat\RabbitStream\Client\Connection;

$connection = Connection::create(
    host: 'localhost',
    port: 5552,
    user: 'guest',
    password: 'guest'
);
```

**Connection with custom serializer:**
```php
use CrazyGoat\RabbitStream\Serializer\JsonBinarySerializer;

$connection = Connection::create(
    host: 'rabbitmq.example.com',
    serializer: new JsonBinarySerializer()
);
```

**Connection with PSR-3 logger:**
```php
use Monolog\Logger;
use Monolog\Handler\StreamHandler;

$logger = new Logger('rabbitmq');
$logger->pushHandler(new StreamHandler('php://stderr'));

$connection = Connection::create(
    host: 'localhost',
    logger: $logger
);
```

**Connection with custom frame size and heartbeat:**
```php
$connection = Connection::create(
    host: 'localhost',
    requestedFrameMax: 1048576,  // 1MB
    requestedHeartbeat: 30         // 30 seconds
);
```

**Connection with a raised Deliver frame cap:**
```php
// Only needed if you expect broker-coalesced chunks bigger than the 64MB
// default (see "Deliver frames need their own cap" above).
$connection = Connection::create(
    host: 'localhost',
    maxDeliverFrameSize: 128 * 1024 * 1024,
);
```

---

## Stream Management Methods

### createStream()

Create a new stream with optional arguments.

```php
/**
 * @param array<string, string> $arguments
 */
public function createStream(string $name, array $arguments = []): void
```

#### Parameters

| Parameter | Type | Required | Description |
|-----------|------|----------|-------------|
| `$name` | `string` | Yes | Name of the stream to create |
| `$arguments` | `array<string, string>` | No | Stream configuration arguments (e.g., `max-length-bytes`, `max-age`) |

#### Return Value

`void`

#### Exceptions

- `UnexpectedResponseException` - If the server returns an unexpected response
- `ProtocolException` - If the stream already exists or arguments are invalid

#### Example

```php
// Create a simple stream
$connection->createStream('my-stream');

// Create a stream with retention policy
$connection->createStream('events', [
    'max-length-bytes' => '1073741824',  // 1GB max size
    'max-age' => '7D',                   // 7 days retention
]);
```

---

### deleteStream()

Delete an existing stream.

```php
public function deleteStream(string $name): void
```

#### Parameters

| Parameter | Type | Required | Description |
|-----------|------|----------|-------------|
| `$name` | `string` | Yes | Name of the stream to delete |

#### Return Value

`void`

#### Exceptions

- `UnexpectedResponseException` - If the server returns an unexpected response
- `ProtocolException` - If the stream does not exist

#### Example

```php
$connection->deleteStream('my-stream');
```

---

### streamExists()

Check if a stream exists.

```php
public function streamExists(string $name): bool
```

#### Parameters

| Parameter | Type | Required | Description |
|-----------|------|----------|-------------|
| `$name` | `string` | Yes | Name of the stream to check |

#### Return Value

`bool` - `true` if the stream exists, `false` otherwise

#### Exceptions

- `UnexpectedResponseException` - If the server returns an unexpected response

#### Example

```php
if (!$connection->streamExists('my-stream')) {
    $connection->createStream('my-stream');
}
```

---

### getStreamStats()

Get statistics for a stream.

```php
/**
 * @return array<string, int>
 */
public function getStreamStats(string $name): array
```

#### Parameters

| Parameter | Type | Required | Description |
|-----------|------|----------|-------------|
| `$name` | `string` | Yes | Name of the stream |

#### Return Value

`array<string, int>` - Associative array of statistics. Common keys include:
- `publishers` - Number of active publishers
- `consumers` - Number of active consumers
- `messages` - Total number of messages in the stream
- `bytes` - Total size of the stream in bytes

#### Exceptions

- `UnexpectedResponseException` - If the server returns an unexpected response
- `ProtocolException` - If the stream does not exist

#### Example

```php
$stats = $connection->getStreamStats('my-stream');
echo "Messages: {$stats['messages']}\n";
echo "Size: {$stats['bytes']} bytes\n";
echo "Publishers: {$stats['publishers']}\n";
echo "Consumers: {$stats['consumers']}\n";
```

---

### getMetadata()

Get metadata for one or more streams.

```php
/**
 * @param array<int, string> $streams
 */
public function getMetadata(array $streams): MetadataResponseV1
```

#### Parameters

| Parameter | Type | Required | Description |
|-----------|------|----------|-------------|
| `$streams` | `array<int, string>` | Yes | Array of stream names to query |

#### Return Value

`MetadataResponseV1` - Response object containing metadata for each stream:
- `getStreamMetadata()` - Returns array of `StreamMetadata` objects
- Each `StreamMetadata` has:
  - `getStreamName()` - Stream name
  - `getResponseCode()` - Response code (OK or error)
  - `getLeader()` - Leader node information
  - `getReplicas()` - Replica node information

#### Exceptions

- `UnexpectedResponseException` - If the server returns an unexpected response

#### Example

```php
use CrazyGoat\RabbitStream\Enum\ResponseCodeEnum;

$metadata = $connection->getMetadata(['stream1', 'stream2']);

foreach ($metadata->getStreamMetadata() as $streamMeta) {
    $name = $streamMeta->getStreamName();
    $code = $streamMeta->getResponseCode();
    
    if ($code === ResponseCodeEnum::OK->value) {
        echo "Stream {$name} exists\n";
        echo "Leader: {$streamMeta->getLeader()}\n";
    } else {
        echo "Stream {$name} error: {$code}\n";
    }
}
```

---

## Super Stream Methods

See the [Super Streams Guide](../guide/super-streams.md) for a full walkthrough; this section is the reference for the four `Connection` methods it uses.

### createSuperStream()

Create a super stream: a logical stream backed by several physical partition streams, with broker-side exchange bindings between them.

```php
/**
 * @param string[] $partitions
 * @param string[] $bindingKeys
 * @param array<string, string> $arguments
 */
public function createSuperStream(
    string $name,
    array $partitions = [],
    array $bindingKeys = [],
    array $arguments = []
): void
```

#### Parameters

| Parameter | Type | Required | Description |
|-----------|------|----------|-------------|
| `$name` | `string` | Yes | Name of the super stream |
| `$partitions` | `string[]` | No | Names of the physical partition streams to create (e.g. `['orders-0', 'orders-1']`) |
| `$bindingKeys` | `string[]` | No | Exchange binding key per partition, same order as `$partitions` — matched by `route()` |
| `$arguments` | `array<string, string>` | No | Per-partition stream arguments, same keys as `createStream()` (e.g. `max-length-bytes`, `max-age`) |

#### Return Value

`void`

#### Exceptions

- `UnexpectedResponseException` - If the server returns an unexpected response
- `ProtocolException` - If the super stream already exists or arguments are invalid

#### Example

```php
$connection->createSuperStream(
    'orders',
    ['orders-0', 'orders-1', 'orders-2'],
    ['0', '1', '2'],
    ['max-age' => '24h']
);
```

---

### deleteSuperStream()

Delete a super stream and all of its partitions.

```php
public function deleteSuperStream(string $name): void
```

#### Parameters

| Parameter | Type | Required | Description |
|-----------|------|----------|-------------|
| `$name` | `string` | Yes | Name of the super stream to delete |

#### Return Value

`void`

#### Exceptions

- `UnexpectedResponseException` - If the server returns an unexpected response
- `ProtocolException` - If the super stream does not exist

#### Example

```php
$connection->deleteSuperStream('orders');
```

---

### route()

Ask the broker which partition(s) a routing key maps to, via the exchange bindings created with `createSuperStream()`'s `$bindingKeys`.

```php
/** @return string[] */
public function route(string $routingKey, string $superStream): array
```

#### Parameters

| Parameter | Type | Required | Description |
|-----------|------|----------|-------------|
| `$routingKey` | `string` | Yes | The key to route |
| `$superStream` | `string` | Yes | Name of the super stream |

#### Return Value

`string[]` - The partition stream name(s) matching this routing key. Like the Java client, more than one partition can legitimately be returned for a single key (overlapping binding keys).

#### Exceptions

- `UnexpectedResponseException` - If the server returns an unexpected response

#### Example

```php
$streams = $connection->route('customer-123', 'orders');
echo implode(', ', $streams); // e.g. "orders-1"
```

#### Notes

- One broker round trip per call. `KeyRoutingStrategy` (used by `createSuperStreamProducer()`) wraps this with an in-memory per-key cache.

---

### partitions()

Resolve a super stream's partition (physical stream) names.

```php
/** @return list<string> */
public function partitions(string $superStream): array
```

#### Parameters

| Parameter | Type | Required | Description |
|-----------|------|----------|-------------|
| `$superStream` | `string` | Yes | Name of the super stream |

#### Return Value

`list<string>` - The partition stream names, e.g. `['orders-0', 'orders-1', 'orders-2']`

#### Exceptions

- `UnexpectedResponseException` - If the server returns an unexpected response
- `ProtocolException` - If the super stream does not exist, or exists but currently has zero partitions

#### Example

```php
$partitions = $connection->partitions('orders');
foreach ($partitions as $partition) {
    echo "Partition: {$partition}\n";
}
```

#### Notes

- `createSuperStreamProducer()` and `createSuperStreamConsumer()` call this internally to resolve partitions

---

## Offset Management Methods

### queryOffset()

Query the last stored offset for a named consumer.

```php
public function queryOffset(string $reference, string $stream): int
```

#### Parameters

| Parameter | Type | Required | Description |
|-----------|------|----------|-------------|
| `$reference` | `string` | Yes | Consumer name (reference) |
| `$stream` | `string` | Yes | Stream name |

#### Return Value

`int` - The last stored offset for this consumer on this stream

#### Exceptions

- `UnexpectedResponseException` - If the server returns an unexpected response
- `ProtocolException` - If no offset is stored for this reference/stream combination

#### Example

```php
$offset = $connection->queryOffset('my-consumer', 'my-stream');
echo "Last consumed offset: {$offset}\n";
```

---

### storeOffset()

Store an offset for a named consumer.

```php
public function storeOffset(string $reference, string $stream, int $offset): void
```

#### Parameters

| Parameter | Type | Required | Description |
|-----------|------|----------|-------------|
| `$reference` | `string` | Yes | Consumer name (reference) |
| `$stream` | `string` | Yes | Stream name |
| `$offset` | `int` | Yes | Offset value to store |

#### Return Value

`void`

#### Exceptions

- `UnexpectedResponseException` - If the server returns an unexpected response

#### Example

```php
// Store offset 12345 for consumer 'my-consumer' on stream 'my-stream'
$connection->storeOffset('my-consumer', 'my-stream', 12345);
```

---

## Factory Methods

### createProducer()

Create a new producer for publishing messages to a stream.

```php
public function createProducer(
    string $stream,
    ?string $name = null,
    ?callable $onConfirm = null,
): Producer
```

#### Parameters

| Parameter | Type | Required | Description |
|-----------|------|----------|-------------|
| `$stream` | `string` | Yes | Name of the stream to publish to |
| `$name` | `?string` | No | Producer name for deduplication. Enables exactly-once semantics. |
| `$onConfirm` | `?callable` | No | Callback invoked for each publish confirmation. Receives `ConfirmationStatus`. |

#### Return Value

`Producer` - A producer instance ready to send messages

#### Exceptions

- `ProtocolException` - If the stream does not exist

#### Example

```php
use CrazyGoat\RabbitStream\Client\ConfirmationStatus;

// Anonymous producer
$producer = $connection->createProducer('my-stream');

// Named producer with confirm callback
$producer = $connection->createProducer(
    'my-stream',
    name: 'my-producer',
    onConfirm: function (ConfirmationStatus $status) {
        if ($status->isConfirmed()) {
            echo "Confirmed: #{$status->getPublishingId()}\n";
        }
    }
);
```

**See Also:** [Producer API Reference](producer.md)

---

### createConsumer()

Create a new consumer for reading messages from a stream.

```php
use CrazyGoat\RabbitStream\VO\OffsetSpec;

public function createConsumer(
    string $stream,
    OffsetSpec $offset,
    ?string $name = null,
    int $autoCommit = 0,
    int $initialCredit = 10,
    array $filterValues = [],
    bool $matchUnfiltered = false,
    bool $singleActiveConsumer = false,
    ?string $superStream = null,
): Consumer
```

#### Parameters

| Parameter | Type | Required | Description |
|-----------|------|----------|-------------|
| `$stream` | `string` | Yes | Name of the stream to consume from |
| `$offset` | `OffsetSpec` | Yes | Starting offset specification (see `OffsetSpec` factory methods) |
| `$name` | `?string` | No | Consumer name for offset tracking. Required for `storeOffset()`, `queryOffset()`, and `singleActiveConsumer`. |
| `$autoCommit` | `int` | No | Auto-commit interval (number of messages). `0` disables auto-commit. |
| `$initialCredit` | `int` | No | Initial flow control credits. Default: `10` |
| `$filterValues` | `array<int, string>` | No | Broker-side stream filtering values (`filter.0`, `filter.1`, ... properties). Chunk-granular — see the [Consumer API reference](consumer.md). |
| `$matchUnfiltered` | `bool` | No | When `$filterValues` is non-empty, also deliver messages published with no filter value. |
| `$singleActiveConsumer` | `bool` | No | Enables single active consumer for this subscription. Requires `$name`. |
| `$superStream` | `?string` | No | Name of the super stream this partition belongs to. |

#### OffsetSpec Factory Methods

- `OffsetSpec::first()` - Start from the first message
- `OffsetSpec::last()` - Start from the last message (next new message)
- `OffsetSpec::next()` - Start from the next message after the last consumed
- `OffsetSpec::offset(int $offset)` - Start from a specific offset
- `OffsetSpec::timestamp(int $timestamp)` - Start from a specific Unix timestamp

#### Return Value

`Consumer` - A consumer instance ready to read messages

#### Exceptions

- `ProtocolException` - If the stream does not exist or offset is invalid

#### Example

```php
use CrazyGoat\RabbitStream\VO\OffsetSpec;

// Consumer from the beginning
$consumer = $connection->createConsumer(
    'my-stream',
    OffsetSpec::first()
);

// Named consumer with auto-commit
$consumer = $connection->createConsumer(
    'my-stream',
    OffsetSpec::last(),
    name: 'my-consumer',
    autoCommit: 100,  // Auto-commit every 100 messages
    initialCredit: 50
);

// Consumer from specific offset
$consumer = $connection->createConsumer(
    'my-stream',
    OffsetSpec::offset(1000)
);
```

**See Also:** [Consumer API Reference](consumer.md)

---

### createSuperStreamProducer()

Create a producer that publishes to a super stream's partitions, routing each message via a `RoutingStrategy`.

```php
use CrazyGoat\RabbitStream\Client\Routing\RoutingStrategy;

public function createSuperStreamProducer(
    string $superStream,
    ?RoutingStrategy $strategy = null,
    ?string $name = null,
    ?callable $onConfirm = null,
    int $maxPendingConfirms = Producer::DEFAULT_MAX_PENDING_CONFIRMS,
): SuperStreamProducerInterface
```

#### Parameters

| Parameter | Type | Required | Description |
|-----------|------|----------|-------------|
| `$superStream` | `string` | Yes | Name of the super stream to publish to |
| `$strategy` | `?RoutingStrategy` | No | Routing strategy. Default: `HashRoutingStrategy` — MurmurHash3 x86_32, seed `104729`, matching the Java/.NET clients exactly, so a routing key lands on the same partition regardless of which client language published it. See `KeyRoutingStrategy` for broker-resolved (exchange binding) routing instead. |
| `$name` | `?string` | No | Base producer name. Each partition's underlying `Producer` gets the derived name `"{$name}-{$partition}"`, so per-partition sequence dedup still works. |
| `$onConfirm` | `?callable` | No | Confirmation callback, passed through to every partition's `Producer`. |
| `$maxPendingConfirms` | `int` | No | Back-pressure cap, passed through to every partition's `Producer`. Default: `Producer::DEFAULT_MAX_PENDING_CONFIRMS`. |

#### Return Value

`SuperStreamProducerInterface` - resolves the super stream's partitions immediately (one `partitions()` round trip), but opens each partition's `Producer` lazily on first publish to that partition

#### Exceptions

- `ProtocolException` - If the super stream does not exist or has zero partitions

#### Example

```php
use CrazyGoat\RabbitStream\Client\Routing\KeyRoutingStrategy;

// Default hash routing
$producer = $connection->createSuperStreamProducer('orders');
$producer->send('Order payload', routingKey: 'customer-123');

// Broker-resolved key routing instead
$producer = $connection->createSuperStreamProducer(
    'orders',
    new KeyRoutingStrategy($connection, 'orders'),
    name: 'order-service',
);
```

#### Notes

- Partition membership is resolved once, at creation time — a topology change (partition leader change, or the super stream's partition set itself changing) is **not** automatically detected; recreate the `SuperStreamProducer` if you need to react to that.

**See Also:** [SuperStreamProducer API Reference](super-stream-producer.md)

---

### createSuperStreamConsumer()

Create a consumer that subscribes to every partition of a super stream, all sharing the same consumer `$name`.

```php
public function createSuperStreamConsumer(
    string $superStream,
    OffsetSpec $offset,
    ?string $name = null,
    int $autoCommit = 0,
    int $initialCredit = 10,
    bool $singleActiveConsumer = false,
): SuperStreamConsumerInterface
```

#### Parameters

| Parameter | Type | Required | Description |
|-----------|------|----------|-------------|
| `$superStream` | `string` | Yes | Name of the super stream to consume from |
| `$offset` | `OffsetSpec` | Yes | Starting offset specification, applied to every partition |
| `$name` | `?string` | No | Consumer name, shared by every partition's subscription. Required for `singleActiveConsumer` — the broker groups the per-partition subscriptions into one single-active-consumer group *per partition* using this shared name. |
| `$autoCommit` | `int` | No | Auto-commit interval (messages), passed through to every partition's `Consumer` |
| `$initialCredit` | `int` | No | Initial flow control credits, passed through to every partition's `Consumer` |
| `$singleActiveConsumer` | `bool` | No | Enables single active consumer per partition. Requires `$name`. |

#### Return Value

`SuperStreamConsumerInterface` - one plain `Consumer` per partition, aggregated

#### Exceptions

- `ProtocolException` - If the super stream does not exist or has zero partitions

#### Example

```php
use CrazyGoat\RabbitStream\VO\OffsetSpec;

$consumer = $connection->createSuperStreamConsumer('orders', OffsetSpec::first(), name: 'order-processor');

foreach ($consumer->read(timeout: 5.0) as $message) {
    echo "[{$message->getStream()}] {$message->getBody()}\n";
    $consumer->storeOffset($message->getStream(), $message->getOffset());
}
```

#### Notes

- Offset tracking is entirely per-partition — there is no aggregate, super-stream-wide offset. `storeOffset()`/`queryOffset()` on the returned consumer always take the partition name explicitly (`Message::getStream()` names it).

**See Also:** [SuperStreamConsumer API Reference](super-stream-consumer.md)

---

## Lifecycle Methods

### readLoop()

Process incoming server-push frames (deliveries, heartbeats, confirms).

```php
public function readLoop(?int $maxFrames = null, ?float $timeout = null): void
```

#### Parameters

| Parameter | Type | Required | Description |
|-----------|------|----------|-------------|
| `$maxFrames` | `?int` | No | Maximum number of frames to process. `null` means process indefinitely. |
| `$timeout` | `?float` | No | Timeout in seconds. `null` means block indefinitely. |

#### Return Value

`void`

#### Exceptions

- `ConnectionException` - If the connection is lost
- `TimeoutException` - If the timeout is reached (when specified)

#### Example

```php
// Process one frame (useful in consumer loops)
$connection->readLoop(maxFrames: 1, timeout: 5.0);

// Process frames for up to 10 seconds
$connection->readLoop(timeout: 10.0);

// Run indefinitely (until connection closes)
$connection->readLoop();
```

#### Notes

- Automatically handles heartbeats by echoing them back
- Dispatches deliver frames to registered consumers
- Dispatches confirm/error frames to registered producers
- This method blocks until the specified condition is met

---

### close()

Close the connection and all associated producers and consumers.

```php
public function close(): void
```

#### Parameters

None

#### Return Value

`void`

#### Exceptions

- `UnexpectedResponseException` - If the server returns an unexpected response during close

#### Example

```php
try {
    // Use connection...
    $producer->send('message');
    $producer->waitForConfirms();
} finally {
    $connection->close();
}
```

#### Notes

- Closes all producers and consumers first
- Sends `Close` command to the server
- Closes the underlying TCP connection
- Safe to call multiple times (idempotent)
- Automatically called in `__destruct()` if not already closed

---

## Usage Patterns

### Pattern 1: Basic Connection

Simple connection with automatic cleanup:

```php
use CrazyGoat\RabbitStream\Client\Connection;

$connection = Connection::create('localhost', 5552, 'guest', 'guest');

// Use connection...
$producer = $connection->createProducer('my-stream');
$producer->send('Hello');

// Cleanup
$connection->close();
```

### Pattern 2: Named Connection with Custom Serializer

Using a custom serializer for message encoding:

```php
use CrazyGoat\RabbitStream\Client\Connection;
use CrazyGoat\RabbitStream\Serializer\JsonBinarySerializer;

$connection = Connection::create(
    host: 'rabbitmq.example.com',
    port: 5552,
    user: 'app',
    password: 'secret',
    vhost: '/app',
    serializer: new JsonBinarySerializer()
);

// All messages will be JSON-encoded
$producer = $connection->createProducer('events');
$producer->send(json_encode(['type' => 'user_login', 'user_id' => 123]));
```

### Pattern 3: Connection with PSR-3 Logger

Enable debug logging for troubleshooting:

```php
use CrazyGoat\RabbitStream\Client\Connection;
use Monolog\Logger;
use Monolog\Handler\StreamHandler;

$logger = new Logger('rabbitmq');
$logger->pushHandler(new StreamHandler('php://stderr', Logger::DEBUG));

$connection = Connection::create(
    host: 'localhost',
    logger: $logger
);

// All protocol operations will be logged
```

### Pattern 4: Connection Pool Pattern

Managing multiple connections for high availability:

```php
use CrazyGoat\RabbitStream\Client\Connection;

class ConnectionPool
{
    /** @var Connection[] */
    private array $connections = [];
    private int $currentIndex = 0;
    
    public function __construct(array $hosts)
    {
        foreach ($hosts as $host) {
            $this->connections[] = Connection::create($host);
        }
    }
    
    public function getConnection(): Connection
    {
        $connection = $this->connections[$this->currentIndex];
        $this->currentIndex = ($this->currentIndex + 1) % count($this->connections);
        return $connection;
    }
    
    public function closeAll(): void
    {
        foreach ($this->connections as $conn) {
            $conn->close();
        }
    }
}

// Usage
$pool = new ConnectionPool(['rabbit1.local', 'rabbit2.local', 'rabbit3.local']);
$producer = $pool->getConnection()->createProducer('events');
```

### Pattern 5: Stream Management Workflow

Creating and managing streams programmatically:

```php
use CrazyGoat\RabbitStream\Client\Connection;

$connection = Connection::create('localhost');

// Ensure stream exists with proper configuration
$streamName = 'events';
if (!$connection->streamExists($streamName)) {
    $connection->createStream($streamName, [
        'max-length-bytes' => '1073741824',  // 1GB
        'max-age' => '7D',                   // 7 days
    ]);
    echo "Created stream: {$streamName}\n";
}

// Get stream statistics
$stats = $connection->getStreamStats($streamName);
echo "Stream has {$stats['messages']} messages\n";

// Cleanup when needed
// $connection->deleteStream($streamName);
```

---

## Error Handling

### Common Errors

```php
use CrazyGoat\RabbitStream\Client\Connection;
use CrazyGoat\RabbitStream\Exception\ConnectionException;
use CrazyGoat\RabbitStream\Exception\AuthenticationException;
use CrazyGoat\RabbitStream\Exception\UnexpectedResponseException;

try {
    $connection = Connection::create(
        host: 'rabbitmq.example.com',
        user: 'invalid',
        password: 'wrong'
    );
} catch (AuthenticationException $e) {
    echo "Authentication failed: {$e->getMessage()}\n";
    exit(1);
} catch (ConnectionException $e) {
    echo "Cannot connect: {$e->getMessage()}\n";
    exit(1);
}

try {
    // Try to create a stream that already exists
    $connection->createStream('existing-stream');
} catch (UnexpectedResponseException $e) {
    echo "Server error: {$e->getMessage()}\n";
}
```

### Connection Recovery

```php
use CrazyGoat\RabbitStream\Client\Connection;

function getConnectionWithRetry(
    string $host,
    int $maxRetries = 3,
    int $retryDelayMs = 1000
): Connection {
    $lastException = null;
    
    for ($i = 0; $i < $maxRetries; $i++) {
        try {
            return Connection::create(host: $host);
        } catch (\Exception $e) {
            $lastException = $e;
            if ($i < $maxRetries - 1) {
                usleep($retryDelayMs * 1000);
            }
        }
    }
    
    throw $lastException;
}

$connection = getConnectionWithRetry('rabbitmq.example.com');
```

---

## See Also

- [Consumer API Reference](consumer.md)
- [Producer API Reference](producer.md)
- [Message API Reference](message.md)
- [SuperStreamProducer API Reference](super-stream-producer.md)
- [SuperStreamConsumer API Reference](super-stream-consumer.md)
- [Publishing Guide](../guide/publishing.md)
- [Consuming Guide](../guide/consuming.md)
- [Super Streams Guide](../guide/super-streams.md)
