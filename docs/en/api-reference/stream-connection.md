# StreamConnection

The `StreamConnection` class manages the TCP socket connection to a RabbitMQ Stream server. It handles connection lifecycle, frame I/O, server-push frame dispatching, and provides an event loop for asynchronous message handling.

## Overview

`StreamConnection` is the low-level connection manager for the RabbitMQ Stream Protocol (port 5552). It provides:

- TCP socket connection management
- Frame-level I/O with timeout support
- Server-push frame handling (heartbeats, publish confirms, deliveries)
- Event loop for asynchronous operations
- Publisher/subscriber registration for callbacks

## Constructor

```php
public function __construct(
    private readonly string $host = '127.0.0.1',
    private readonly int $port = 5552,
    private readonly LoggerInterface $logger = new NullLogger(),
    private readonly BinarySerializerInterface $serializer = new PhpBinarySerializer(),
)
```

**Parameters:**

| Parameter | Type | Default | Description |
|-----------|------|---------|-------------|
| `$host` | `string` | `'127.0.0.1'` | RabbitMQ server hostname or IP address |
| `$port` | `int` | `5552` | RabbitMQ Stream protocol port |
| `$logger` | `LoggerInterface` | `NullLogger` | PSR-3 logger for debug output |
| `$serializer` | `BinarySerializerInterface` | `PhpBinarySerializer` | Binary serializer for frame encoding |

## Connection Lifecycle Methods

### connect()

Establishes the TCP socket connection to the RabbitMQ server.

```php
public function connect(): void
```

**Throws:**
- `ConnectionException` - If socket creation or connection fails

**Example:**
```php
$connection = new StreamConnection('localhost', 5552);
$connection->connect();
```

### close()

Closes the socket connection and cleans up resources.

```php
public function close(): void
```

**Note:** This method is also called automatically by the destructor.

### isConnected()

Returns the current connection state.

```php
public function isConnected(): bool
```

**Returns:** `true` if connected, `false` otherwise

## Frame I/O Methods

### sendMessage()

Serializes and sends a request message to the server.

```php
public function sendMessage(object $request, ?float $timeout = null): void
```

**Parameters:**
- `$request` - Request object implementing `ToStreamBufferInterface`
- `$timeout` - Optional timeout in seconds (null = blocking)

**Throws:**
- `ConnectionException` - If socket is not connected or write fails
- `TimeoutException` - If write timeout expires

**Example:**
```php
$request = new OpenRequestV1('my-client');
$connection->sendMessage($request, 5.0);
```

### sendFrame()

Sends a raw frame buffer to the server.

```php
public function sendFrame(string $frame, ?float $timeout = null): int
```

**Parameters:**
- `$frame` - Raw frame data (binary string)
- `$timeout` - Optional timeout in seconds

**Returns:** Number of bytes written

**Throws:**
- `ConnectionException` - If socket is not connected or write fails
- `TimeoutException` - If write timeout expires

### readMessage()

Reads and deserializes a response message from the server.

```php
public function readMessage(float $timeout = 30.0): object
```

**Parameters:**
- `$timeout` - Timeout in seconds (default: 30.0)

**Returns:** Deserialized response object

**Throws:**
- `ConnectionException` - If connection is closed
- `TimeoutException` - If read timeout expires
- `DeserializationException` - If frame parsing fails

**Note:** This method automatically handles server-push frames (heartbeats, publish confirms, etc.) and only returns response frames.

**Example:**
```php
$response = $connection->readMessage(30.0);
// $response is a typed response object (e.g., OpenResponseV1)
```

### readFrame()

Reads a raw frame from the socket.

```php
public function readFrame(float $timeout = 30.0): ?ReadBuffer
```

**Parameters:**
- `$timeout` - Timeout in seconds (default: 30.0, 0 = non-blocking)

**Returns:** `ReadBuffer` with frame data, or `null` on timeout

**Throws:**
- `ConnectionException` - If socket error occurs
- `ConnectionException` - If frame size exceeds `maxFrameSize`

## Server-Push Registration Methods

Server-push frames are sent asynchronously by the server. Register callbacks to handle them.

### registerPublisher()

Registers callbacks for publish confirmation and error frames.

```php
public function registerPublisher(
    int $publisherId,
    callable $onConfirm,
    callable $onError
): void
```

**Parameters:**
- `$publisherId` - Publisher ID from `DeclarePublisherRequestV1`
- `$onConfirm` - Callback receiving `array<int>` of confirmed publishing IDs
- `$onError` - Callback receiving `array<PublishError>` of failed publishes

**Example:**
```php
$connection->registerPublisher(
    publisherId: 1,
    onConfirm: fn(array $ids) => printf("Confirmed: %s\n", implode(', ', $ids)),
    onError: fn(array $errors) => printf("Errors: %d\n", count($errors))
);
```

### registerSubscriber()

Registers a callback for message delivery frames.

```php
public function registerSubscriber(int $subscriptionId, callable $onDeliver): void
```

**Parameters:**
- `$subscriptionId` - Subscription ID from `SubscribeRequestV1`
- `$onDeliver` - Callback receiving `DeliverResponseV1` object

**Example:**
```php
$connection->registerSubscriber(
    subscriptionId: 1,
    onDeliver: fn(DeliverResponseV1 $deliver) => processMessage($deliver)
);
```

### unregisterSubscriber()

Removes a subscriber callback.

```php
public function unregisterSubscriber(int $subscriptionId): void
```

### unregisterPublisher()

Removes a publisher callback.

```php
public function unregisterPublisher(int $publisherId): void
```

### onMetadataUpdate()

Registers a callback for metadata update frames.

```php
public function onMetadataUpdate(callable $callback): void
```

**Parameters:**
- `$callback` - Receives `MetadataUpdateResponseV1` object

### onHeartbeat()

Registers a callback for heartbeat frames.

```php
public function onHeartbeat(?callable $callback = null): void
```

**Parameters:**
- `$callback` - Optional callback invoked when heartbeat is received (null to unregister)

**Note:** Heartbeats are automatically echoed back to the server. The callback is for application-level notification only.

### onConsumerUpdate()

Registers a callback for consumer update query frames.

```php
public function onConsumerUpdate(callable $callback): void
```

**Parameters:**
- `$callback` - Receives `ConsumerUpdateQueryV1`, must return `[int $offsetType, int $offset]`

## Event Loop Methods

### readLoop()

Runs an event loop processing server-push frames.

```php
public function readLoop(?int $maxFrames = null, ?float $timeout = null): void
```

**Parameters:**
- `$maxFrames` - Maximum frames to process before returning (null = unlimited)
- `$timeout` - Maximum time to run in seconds (null = until `stop()` called)

**Example:**
```php
// Process up to 100 frames or for 30 seconds
$connection->readLoop(maxFrames: 100, timeout: 30.0);

// Run until stop() is called
$connection->readLoop();
```

### stop()

Stops the event loop.

```php
public function stop(): void
```

**Example:**
```php
// Run event loop in background, stop after 5 seconds
async(fn() => $connection->readLoop());
sleep(5);
$connection->stop();
```

## Configuration Methods

### setMaxFrameSize()

Sets the maximum allowed frame size.

```php
public function setMaxFrameSize(int $maxFrameSize): void
```

**Parameters:**
- `$maxFrameSize` - Maximum frame size in bytes (0 = no limit)

**Throws:**
- `InvalidArgumentException` - If size is negative

**Example:**
```php
$connection->setMaxFrameSize(16 * 1024 * 1024); // 16MB limit
```

### getMaxFrameSize()

Returns the current maximum frame size.

```php
public function getMaxFrameSize(): int
```

**Returns:** Maximum frame size in bytes (0 = unlimited)

## Constants

### DEFAULT_MAX_FRAME_SIZE

Default maximum frame size: 8MB (8 * 1024 * 1024 bytes)

```php
public const DEFAULT_MAX_FRAME_SIZE = 8 * 1024 * 1024;
```

### SERVER_PUSH_KEYS

Array of server-push frame command keys that are handled automatically:

| Key | Command | Description |
|-----|---------|-------------|
| `0x0003` | PublishConfirm | Async publish confirmation |
| `0x0004` | PublishError | Async publish error |
| `0x0008` | Deliver | Message delivery to consumer |
| `0x0010` | MetadataUpdate | Stream topology changed |
| `0x0016` | Close | Server-initiated close |
| `0x0017` | Heartbeat | Keepalive heartbeat |
| `0x001a` | ConsumerUpdate | Server asks for offset |

## Error Handling

### ConnectionException

Thrown for socket-level errors:
- Connection refused
- Socket read/write failures
- Frame size exceeded

### TimeoutException

Thrown when I/O operations exceed the specified timeout.

### DeserializationException

Thrown when frame parsing fails.

## Examples

### Basic Connection

```php
use CrazyGoat\RabbitStream\StreamConnection;
use CrazyGoat\RabbitStream\Request\OpenRequestV1;

$connection = new StreamConnection('localhost', 5552);
$connection->connect();

// Open the connection
$connection->sendMessage(new OpenRequestV1('my-app'));
$response = $connection->readMessage(5.0);
```

### Publishing with Confirmations

```php
use CrazyGoat\RabbitStream\Request\DeclarePublisherRequestV1;
use CrazyGoat\RabbitStream\Request\PublishRequestV1;

// Register publisher with callbacks
$connection->registerPublisher(
    publisherId: 1,
    onConfirm: function (array $ids) {
        echo "Confirmed messages: " . implode(', ', $ids) . "\n";
    },
    onError: function (array $errors) {
        foreach ($errors as $error) {
            echo "Publish error: {$error->getCode()}\n";
        }
    }
);

// Declare publisher
$connection->sendMessage(new DeclarePublisherRequestV1(1, 'my-stream'));
$connection->readMessage(5.0);

// Publish messages
$connection->sendMessage(new PublishRequestV1(1, [new Message('hello')]));

// Process confirmations
$connection->readLoop(maxFrames: 1, timeout: 5.0);
```

### Consuming Messages

```php
use CrazyGoat\RabbitStream\Request\SubscribeRequestV1;

// Register subscriber
$connection->registerSubscriber(
    subscriptionId: 1,
    onDeliver: function (DeliverResponseV1 $deliver) {
        foreach ($deliver->getMessages() as $message) {
            echo "Received: " . $message->getBody() . "\n";
        }
    }
);

// Subscribe to stream
$connection->sendMessage(new SubscribeRequestV1(
    subscriptionId: 1,
    stream: 'my-stream',
    offsetType: OffsetType::NEXT,
    credit: 10
));
$connection->readMessage(5.0);

// Run event loop to receive messages
$connection->readLoop(timeout: 60.0);
```

### Handling Metadata Updates

```php
$connection->onMetadataUpdate(function (MetadataUpdateResponseV1 $update) {
    echo "Stream updated: " . $update->getStreamName() . "\n";
    echo "Update type: " . $update->getUpdateType() . "\n";
});

// Run event loop to receive updates
$connection->readLoop();
```

### Connection with Custom Logger

```php
use Psr\Log\LoggerInterface;

class DebugLogger implements LoggerInterface
{
    public function debug(string $message, array $context = []): void
    {
        echo "[DEBUG] $message\n";
    }
    // ... implement other LoggerInterface methods
}

$connection = new StreamConnection(
    host: 'localhost',
    port: 5552,
    logger: new DebugLogger()
);
```

### Non-blocking Operations

```php
// Non-blocking frame read
$frame = $connection->readFrame(timeout: 0.0);
if ($frame !== null) {
    // Process frame
}

// Non-blocking message send
$connection->sendMessage($request, timeout: 0.0);
```
