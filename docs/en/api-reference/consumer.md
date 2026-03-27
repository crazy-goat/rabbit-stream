# Consumer API Reference

Complete API reference for the `CrazyGoat\RabbitStream\Client\Consumer` class.

## Class Overview

```php
namespace CrazyGoat\RabbitStream\Client;

class Consumer
{
    // Constructor (via Connection::createConsumer())
    public function __construct(
        StreamConnection $connection,
        string $stream,
        int $subscriptionId,
        OffsetSpec $offset,
        ?string $name = null,
        int $autoCommit = 0,
        int $initialCredit = 10,
        int $maxBufferSize = 1000,
    );
    
    // Reading methods
    /**
     * @return Message[]
     */
    public function read(float $timeout = 5.0): array;
    public function readOne(float $timeout = 5.0): ?Message;
    
    // Offset management
    public function storeOffset(int $offset): void;
    public function queryOffset(): int;
    
    // Lifecycle
    public function close(): void;
}
```

## Constructor

The `Consumer` class is instantiated via `Connection::createConsumer()`. Direct instantiation is not recommended.

```php
use CrazyGoat\RabbitStream\VO\OffsetSpec;

$consumer = $connection->createConsumer(
    string $stream,                    // Required: Stream name
    OffsetSpec $offset,               // Required: Starting offset specification
    ?string $name = null,             // Optional: Consumer name for offset tracking
    int $autoCommit = 0,             // Optional: Auto-commit interval (messages)
    int $initialCredit = 10,          // Optional: Initial flow control credits
): Consumer
```

### Parameters

| Parameter | Type | Required | Description |
|-----------|------|----------|-------------|
| `$stream` | `string` | Yes | Name of the stream to consume from |
| `$offset` | `OffsetSpec` | Yes | Starting offset specification. Use `OffsetSpec::first()`, `OffsetSpec::last()`, `OffsetSpec::offset()`, etc. |
| `$name` | `?string` | No | Unique consumer name for offset tracking. Required for `storeOffset()` and `queryOffset()`. |
| `$autoCommit` | `int` | No | Number of messages between automatic offset commits. `0` disables auto-commit. |
| `$initialCredit` | `int` | No | Initial number of flow control credits. Higher values increase throughput but use more memory. |

### OffsetSpec Factory Methods

| Method | Description |
|--------|-------------|
| `OffsetSpec::first()` | Start from the first message in the stream |
| `OffsetSpec::last()` | Start from the last message (receive next new message) |
| `OffsetSpec::next()` | Start from the next message after the last consumed |
| `OffsetSpec::offset(int $offset)` | Start from a specific offset number |
| `OffsetSpec::timestamp(int $timestamp)` | Start from messages after a specific Unix timestamp |

### Examples

**Basic consumer from the beginning:**
```php
use CrazyGoat\RabbitStream\VO\OffsetSpec;

$consumer = $connection->createConsumer(
    'my-stream',
    OffsetSpec::first()
);
```

**Named consumer with auto-commit:**
```php
$consumer = $connection->createConsumer(
    'my-stream',
    OffsetSpec::last(),
    name: 'my-consumer',
    autoCommit: 100,  // Auto-commit every 100 messages
    initialCredit: 50
);
```

**Consumer from specific offset:**
```php
$consumer = $connection->createConsumer(
    'my-stream',
    OffsetSpec::offset(1000),
    name: 'my-consumer'
);
```

---

## Reading Methods

### read()

Read multiple messages from the stream.

```php
/**
 * @return Message[]
 */
public function read(float $timeout = 5.0): array
```

#### Parameters

| Parameter | Type | Required | Description |
|-----------|------|----------|-------------|
| `$timeout` | `float` | No | Maximum time to wait for messages in seconds. Default: `5.0` |

#### Return Value

`Message[]` - Array of `Message` objects. May be empty if no messages arrive within timeout.

#### Exceptions

- `ConnectionException` - If the connection is lost
- `TimeoutException` - If the timeout is reached (when specified)

#### Example

```php
// Read with default 5 second timeout
$messages = $consumer->read();

foreach ($messages as $message) {
    echo "Offset: {$message->getOffset()}\n";
    echo "Body: {$message->getBody()}\n";
}

// Read with custom timeout
$messages = $consumer->read(timeout: 10.0);
```

#### Notes

- Returns immediately if messages are already buffered
- Automatically manages flow control credits
- Triggers auto-commit if enabled and threshold reached
- May return an empty array if timeout expires with no messages

---

### readOne()

Read a single message from the stream.

```php
public function readOne(float $timeout = 5.0): ?Message
```

#### Parameters

| Parameter | Type | Required | Description |
|-----------|------|----------|-------------|
| `$timeout` | `float` | No | Maximum time to wait for a message in seconds. Default: `5.0` |

#### Return Value

`?Message` - A single `Message` object, or `null` if no message arrives within timeout.

#### Exceptions

- `ConnectionException` - If the connection is lost

#### Example

```php
// Read one message with default timeout
$message = $consumer->readOne();

if ($message !== null) {
    echo "Received: {$message->getBody()}\n";
} else {
    echo "No message received within timeout\n";
}

// Read with custom timeout
$message = $consumer->readOne(timeout: 1.0);
```

#### Notes

- More convenient than `read()` when processing one message at a time
- Automatically manages flow control credits
- Triggers auto-commit if enabled and threshold reached
- Returns `null` on timeout, not an exception

---

## Offset Management Methods

### storeOffset()

Store the current offset for a named consumer.

```php
public function storeOffset(int $offset): void
```

#### Parameters

| Parameter | Type | Required | Description |
|-----------|------|----------|-------------|
| `$offset` | `int` | Yes | The offset value to store |

#### Return Value

`void`

#### Exceptions

- `ProtocolException` - If called on an unnamed consumer (no name provided in constructor)

#### Example

```php
// Create a named consumer
$consumer = $connection->createConsumer(
    'my-stream',
    OffsetSpec::first(),
    name: 'my-consumer'  // Name is required for offset tracking
);

// Process messages
$messages = $consumer->read();
foreach ($messages as $message) {
    processMessage($message);
    
    // Store offset after successful processing
    $consumer->storeOffset($message->getOffset());
}
```

#### Notes

- Requires a named consumer (name parameter in constructor)
- Stores offset on the server for durability
- Can be retrieved later with `queryOffset()`
- Automatically called on `close()` if auto-commit is enabled

---

### queryOffset()

Query the last stored offset for this consumer.

```php
public function queryOffset(): int
```

#### Parameters

None

#### Return Value

`int` - The last stored offset for this consumer on this stream

#### Exceptions

- `ProtocolException` - If called on an unnamed consumer
- `UnexpectedResponseException` - If the server returns an unexpected response

#### Example

```php
// Create a named consumer
$consumer = $connection->createConsumer(
    'my-stream',
    OffsetSpec::first(),
    name: 'my-consumer'
);

try {
    $lastOffset = $consumer->queryOffset();
    echo "Resuming from offset: {$lastOffset}\n";
} catch (\Exception $e) {
    echo "No stored offset found, starting from beginning\n";
}
```

#### Notes

- Requires a named consumer (name parameter in constructor)
- Returns the offset last stored via `storeOffset()` or auto-commit
- Useful for resuming consumption after restart
- Makes a round-trip to the server

---

## Lifecycle Methods

### close()

Close the consumer and unsubscribe from the stream.

```php
public function close(): void
```

#### Parameters

None

#### Return Value

`void`

#### Exceptions

- `ConnectionException` - If the connection is already closed

#### Example

```php
try {
    while ($message = $consumer->readOne()) {
        processMessage($message);
    }
} finally {
    $consumer->close();
}
```

#### Notes

- Sends `Unsubscribe` command to the server
- Stores final offset if auto-commit is enabled
- Clears the internal message buffer
- Frees the subscription ID for reuse
- Does not close the underlying connection
- Safe to call multiple times (idempotent)

---

## Usage Patterns

### Pattern 1: Basic Consumer Loop

Simple message consumption loop:

```php
use CrazyGoat\RabbitStream\Client\Connection;
use CrazyGoat\RabbitStream\VO\OffsetSpec;

$connection = Connection::create('localhost');
$consumer = $connection->createConsumer('events', OffsetSpec::last());

try {
    while (true) {
        $messages = $consumer->read(timeout: 5.0);
        
        foreach ($messages as $message) {
            echo "Received: {$message->getBody()}\n";
        }
    }
} catch (\Exception $e) {
    echo "Error: {$e->getMessage()}\n";
} finally {
    $consumer->close();
    $connection->close();
}
```

**Pros:** Simple, straightforward  
**Cons:** No offset tracking, messages may be reprocessed after restart

### Pattern 2: Named Consumer with Offset Tracking

Resume from last position after restart:

```php
use CrazyGoat\RabbitStream\Client\Connection;
use CrazyGoat\RabbitStream\VO\OffsetSpec;

$connection = Connection::create('localhost');

// Try to resume from last offset
$consumer = $connection->createConsumer(
    'events',
    OffsetSpec::first(),  // Will be overridden if offset exists
    name: 'event-processor'
);

try {
    // Query last stored offset
    $lastOffset = $consumer->queryOffset();
    echo "Resuming from offset: {$lastOffset}\n";
    
    // Note: In a real implementation, you'd recreate the consumer
    // with OffsetSpec::offset($lastOffset + 1) here
} catch (\Exception $e) {
    echo "Starting from beginning\n";
}

// Process and track offsets
while ($message = $consumer->readOne()) {
    processEvent($message->getBody());
    
    // Store offset after successful processing
    $consumer->storeOffset($message->getOffset());
}

$consumer->close();
```

**Pros:** Exactly-once processing, fault-tolerant  
**Cons:** More complex, requires careful offset management

### Pattern 3: Auto-Commit Consumer

Automatic offset tracking:

```php
use CrazyGoat\RabbitStream\Client\Connection;
use CrazyGoat\RabbitStream\VO\OffsetSpec;

$connection = Connection::create('localhost');

// Auto-commit every 100 messages
$consumer = $connection->createConsumer(
    'events',
    OffsetSpec::first(),
    name: 'auto-commit-consumer',
    autoCommit: 100,
    initialCredit: 50
);

$processed = 0;
while ($message = $consumer->readOne(timeout: 10.0)) {
    processEvent($message->getBody());
    $processed++;
    
    // Offset is automatically stored every 100 messages
    if ($processed % 100 === 0) {
        echo "Processed {$processed} messages\n";
    }
}

// Final offset is stored on close()
$consumer->close();
echo "Total processed: {$processed}\n";
```

**Pros:** Automatic offset management, good performance  
**Cons:** May reprocess up to `autoCommit` messages after crash

### Pattern 4: Batch Processing

Process messages in batches for efficiency:

```php
use CrazyGoat\RabbitStream\Client\Connection;
use CrazyGoat\RabbitStream\VO\OffsetSpec;

$connection = Connection::create('localhost');
$consumer = $connection->createConsumer(
    'events',
    OffsetSpec::first(),
    name: 'batch-processor',
    initialCredit: 100  // Request 100 messages at a time
);

$batch = [];
$batchSize = 50;
$lastOffset = 0;

try {
    while (true) {
        // Read up to batchSize messages
        $messages = $consumer->read(timeout: 5.0);
        
        foreach ($messages as $message) {
            $batch[] = $message->getBody();
            $lastOffset = $message->getOffset();
            
            if (count($batch) >= $batchSize) {
                // Process batch
                processBatch($batch);
                
                // Store offset after successful batch processing
                $consumer->storeOffset($lastOffset);
                
                $batch = [];
            }
        }
        
        // Process remaining messages in partial batch
        if (!empty($batch)) {
            processBatch($batch);
            $consumer->storeOffset($lastOffset);
            $batch = [];
        }
    }
} finally {
    $consumer->close();
}
```

**Pros:** Efficient batch processing, controlled offset commits  
**Cons:** More complex, requires batch handling logic

---

## Flow Control

### Credit Mechanism

RabbitMQ Streams uses a credit-based flow control system:

1. **Initial Credit** - Specified when creating the consumer (`initialCredit` parameter)
2. **Credits Consumed** - Each delivered message consumes one credit
3. **Credits Replenished** - The client automatically sends more credits as messages are processed
4. **Backpressure** - When the buffer reaches `maxBufferSize`, credits are held back

### Buffer Management

The consumer maintains an internal buffer of messages:

- **Default Size**: 1000 messages (`maxBufferSize`)
- **Automatic Replenishment**: Credits are sent as the buffer drains
- **Pending Credits**: Credits are accumulated when the buffer is full and sent when space is available

### Backpressure Handling

When the consumer cannot keep up with the message rate:

1. The internal buffer fills up
2. New credits are not sent to the server
3. The server stops sending messages
4. As the buffer drains, credits are replenished

```php
// High-throughput consumer with large buffer
$consumer = $connection->createConsumer(
    'high-volume-stream',
    OffsetSpec::last(),
    initialCredit: 100,  // Request 100 messages at a time
    // maxBufferSize is 1000 by default
);

// Slow consumer with small buffer
$slowConsumer = $connection->createConsumer(
    'slow-stream',
    OffsetSpec::last(),
    initialCredit: 1,  // Request one message at a time
    // maxBufferSize is 1000 by default
);
```

---

## Error Handling

### Common Errors

```php
use CrazyGoat\RabbitStream\Client\Connection;
use CrazyGoat\RabbitStream\VO\OffsetSpec;
use CrazyGoat\RabbitStream\Exception\ConnectionException;
use CrazyGoat\RabbitStream\Exception\ProtocolException;

$connection = Connection::create('localhost');

try {
    $consumer = $connection->createConsumer(
        'non-existent-stream',
        OffsetSpec::first()
    );
} catch (ProtocolException $e) {
    echo "Stream does not exist: {$e->getMessage()}\n";
}

$consumer = $connection->createConsumer(
    'my-stream',
    OffsetSpec::first(),
    name: 'my-consumer'
);

try {
    while (true) {
        $messages = $consumer->read(timeout: 5.0);
        
        foreach ($messages as $message) {
            try {
                processMessage($message);
                $consumer->storeOffset($message->getOffset());
            } catch (\Exception $e) {
                echo "Failed to process message: {$e->getMessage()}\n";
                // Decide whether to continue or stop
            }
        }
    }
} catch (ConnectionException $e) {
    echo "Connection lost: {$e->getMessage()}\n";
    // Reconnect logic here
} finally {
    $consumer->close();
}
```

### Consumer Recovery

```php
use CrazyGoat\RabbitStream\Client\Connection;
use CrazyGoat\RabbitStream\VO\OffsetSpec;

function consumeWithRetry(
    Connection $connection,
    string $stream,
    string $consumerName,
    callable $processor,
    int $maxRetries = 3
): void {
    $retries = 0;
    
    while ($retries < $maxRetries) {
        try {
            // Try to resume from last offset
            $offset = OffsetSpec::first();
            try {
                $tempConsumer = $connection->createConsumer($stream, $offset, name: $consumerName);
                $lastOffset = $tempConsumer->queryOffset();
                $tempConsumer->close();
                $offset = OffsetSpec::offset($lastOffset + 1);
                echo "Resuming from offset: {$lastOffset}\n";
            } catch (\Exception $e) {
                echo "Starting from beginning\n";
            }
            
            $consumer = $connection->createConsumer($stream, $offset, name: $consumerName);
            
            while ($message = $consumer->readOne()) {
                $processor($message);
                $consumer->storeOffset($message->getOffset());
            }
            
            $consumer->close();
            return;
            
        } catch (\Exception $e) {
            $retries++;
            echo "Error: {$e->getMessage()}. Retry {$retries}/{$maxRetries}\n";
            sleep(1);
        }
    }
    
    throw new \Exception("Failed to consume after {$maxRetries} retries");
}
```

---

## See Also

- [Connection API Reference](connection.md)
- [Producer API Reference](producer.md)
- [Message API Reference](message.md)
- [Consuming Guide](../guide/consuming.md)
- [OffsetSpec Reference](../../src/VO/OffsetSpec.php)
