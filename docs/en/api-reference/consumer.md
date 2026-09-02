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
        array $filterValues = [],
        bool $matchUnfiltered = false,
        bool $singleActiveConsumer = false,
        ?string $superStream = null,
        int $creditWindowBytes = self::DEFAULT_CREDIT_WINDOW_BYTES,
    );
    
    // Reading methods
    /**
     * @return Message[]
     */
    public function read(float $timeout = 5.0): array;
    public function readOne(float $timeout = 5.0): ?Message;
    public function hasUnread(): bool;
    /** @return Message[] */
    public function drain(): array;
    
    // Offset management
    public function storeOffset(int $offset): void;
    public function queryOffset(): int;
    
    // Single active consumer
    public function isActive(): bool;
    public function onConsumerUpdate(callable $callback): void;
    
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
    int $initialCredit = 10,          // Optional: Initial flow control credits (chunk-granular)
    int $maxBufferSize = 1000,        // Optional: Target buffer bound (message-granular)
    array $filterValues = [],         // Optional: broker-side stream filtering values
    bool $matchUnfiltered = false,    // Optional: also receive messages with no filter value
    bool $singleActiveConsumer = false, // Optional: single active consumer (requires $name)
    ?string $superStream = null,      // Optional: super-stream partition name
    int $creditWindowBytes = 8 * 1024 * 1024, // Optional: adaptive credit window in bytes (0 = fixed initialCredit)
): Consumer
```

### Parameters

| Parameter | Type | Required | Description |
|-----------|------|----------|-------------|
| `$stream` | `string` | Yes | Name of the stream to consume from |
| `$offset` | `OffsetSpec` | Yes | Starting offset specification. Use `OffsetSpec::first()`, `OffsetSpec::last()`, `OffsetSpec::offset()`, etc. |
| `$name` | `?string` | No | Unique consumer name for offset tracking. Required for `storeOffset()` and `queryOffset()`, and for `singleActiveConsumer`. |
| `$autoCommit` | `int` | No | Number of messages between automatic offset commits. `0` disables auto-commit. |
| `$initialCredit` | `int` | No | Initial number of flow control credits. **Chunk-granular**: 1 credit = 1 future chunk delivery, and this is the starting and **minimum** in-flight chunk target; `$creditWindowBytes` may raise it when chunks turn out to be small. Must be 1–32767. |
| `$maxBufferSize` | `int` | No | Target ceiling, in **messages** (not chunks), on unread messages held in the client-side buffer. See [Flow Control](#flow-control) for the exact chunk-vs-message contract — a chunk in flight when the buffer is already full is still accepted in full (messages are never dropped), so the buffer can transiently exceed this by up to one chunk's worth of messages. |
| `$filterValues` | `array<int, string>` | No | Broker-side stream filtering values (sent as `filter.0`, `filter.1`, ... properties). Filtering is **chunk-granular** (bloom filter per chunk) — see [Stream Filtering](../guide/consuming.md#7-stream-filtering). |
| `$matchUnfiltered` | `bool` | No | When `$filterValues` is non-empty, also deliver chunks containing messages published with no filter value. |
| `$singleActiveConsumer` | `bool` | No | Enables single active consumer: the broker activates exactly one consumer per `$name` group at a time. Requires `$name`; throws `InvalidArgumentException` otherwise. See [Single Active Consumer](../guide/consuming.md#8-single-active-consumer). |
| `$superStream` | `?string` | No | Name of the super stream this partition belongs to (sent as the `super-stream` property). |
| `$creditWindowBytes` | `int` | No | Adaptive credit window in **bytes** (default 8 MiB). The consumer keeps `ceil(creditWindowBytes / observed average chunk size)` chunks in flight, never fewer than `$initialCredit`, never more than 32,767. `0` pins the window to `$initialCredit` chunks. See [Flow Control](../guide/flow-control.md#credit-is-counted-in-chunks-not-bytes). |

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
- Every `Message` returned carries this consumer's stream name — see [`Message::getStream()`](message.md#getstream)

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

### hasUnread()

Whether at least one already-buffered, not-yet-read message is currently held in memory.

```php
public function hasUnread(): bool
```

#### Return Value

`bool` - `true` if the in-process buffer holds an unread message

#### Notes

- Purely a check against the in-process buffer — performs **no connection I/O**
- Used internally by `SuperStreamConsumer::read()`/`readOne()` to decide whether a bounded `readLoop()` pass is needed before aggregating across partitions

---

### drain()

Non-blocking drain of whatever messages are already buffered.

```php
/** @return Message[] */
public function drain(): array
```

#### Return Value

`Message[]` - every currently-buffered message, or an empty array if none are buffered

#### Notes

- Performs **no connection I/O** (no `readLoop()` call) — mirrors the tail of `read()` exactly
- Used internally by `SuperStreamConsumer::read()` to collect each partition's buffered messages after one shared bounded read pass

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
| `$offset` | `int` | Yes | The **next** offset to consume, i.e. `lastProcessedOffset + 1` — the same convention auto-commit and the Java/Go/.NET clients use, so the value can be passed straight to `OffsetSpec::offset()` when resuming |

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
    $consumer->storeOffset($message->getOffset() + 1);
}
```

#### Notes

- Requires a named consumer (name parameter in constructor)
- Stores offset on the server for durability
- Can be retrieved later with `queryOffset()`
- Automatically called on `close()` if auto-commit is enabled, storing `lastProcessedOffset + 1`

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
- Returns the offset last stored via `storeOffset()` or auto-commit — the **next** offset to consume, so it can be passed straight to `OffsetSpec::offset()`
- Useful for resuming consumption after restart
- Makes a round-trip to the server

---

## Single Active Consumer Methods

### isActive()

```php
public function isActive(): bool
```

Whether this consumer is currently allowed to receive messages. Always `true`
for a consumer created without `singleActiveConsumer`. For a single active
consumer, tracks the broker's most recent `ConsumerUpdate` activation state —
starts `false` and flips to `true`/`false` as the broker activates/deactivates
it.

### onConsumerUpdate()

```php
public function onConsumerUpdate(callable $callback): void
```

Overrides the default single active consumer resume logic. The callback
receives `(bool $active, Consumer $consumer): ?OffsetSpec` and its return
value becomes the reply sent to the broker's `ConsumerUpdate` query
(`null` means "keep current position").

Default behavior (used when no callback is registered):
- **Activation** (`$active === true`): calls `queryOffset()` for the
  consumer's `name` and replies `OffsetSpec::offset($stored)`; if nothing
  is stored yet, replies with the consumer's initial `OffsetSpec`.
- **Deactivation** (`$active === false`): if `autoCommit > 0` and at least one
  message was processed, stores `lastProcessedOffset + 1` so the next active
  consumer resumes without gaps and without a duplicate; replies `null` (keep
  position).

```php
$consumer->onConsumerUpdate(function (bool $active, Consumer $consumer): ?OffsetSpec {
    if (!$active) {
        return null;
    }
    return OffsetSpec::first(); // always replay from the start on activation
});
```

---

## Recovery Methods

### isSubscriptionLost()

```php
public function isSubscriptionLost(): bool
```

Whether the broker dropped this subscription because the stream became
unavailable (a `MetadataUpdate` frame: the stream was deleted, or its leader
moved) and it has not been re-established yet. `read()`/`readOne()` keep
trying to re-subscribe while this is `true`.

### getResubscribeCount()

```php
public function getResubscribeCount(): int
```

How many times the subscription has been successfully re-established after a
`MetadataUpdate`.

### resubscribeIfLost()

```php
public function resubscribeIfLost(): bool
```

Attempts one re-subscribe right now, instead of waiting for the next
`read()`/`readOne()`. Returns `true` when the subscription is live (or was
never lost) and `false` when the stream is still missing and the next attempt
has been scheduled (back-off grows from 50 ms to 1 s). Broker errors other
than `STREAM_NOT_EXIST`/`STREAM_NOT_AVAILABLE` are thrown as a
`ProtocolException`.

The resume position is chosen automatically: right after the last processed
message, or the consumer's initial `OffsetSpec` when the stream's committed
offset shows it was recreated from scratch.

See [Consuming → Stream Deleted or Leader Moved](../guide/consuming.md#9-stream-deleted-or-leader-moved-metadataupdate).

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
- Stores the final offset (`lastProcessedOffset + 1`) if auto-commit is enabled
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
    // with OffsetSpec::offset($lastOffset) here
} catch (\Exception $e) {
    echo "Starting from beginning\n";
}

// Process and track offsets
while ($message = $consumer->readOne()) {
    processEvent($message->getBody());
    
    // Store offset after successful processing
    $consumer->storeOffset($message->getOffset() + 1);
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
                $consumer->storeOffset($lastOffset + 1);
                
                $batch = [];
            }
        }
        
        // Process remaining messages in partial batch
        if (!empty($batch)) {
            processBatch($batch);
            $consumer->storeOffset($lastOffset + 1);
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

### Chunk vs. message semantics

This is the part of flow control that is easy to get wrong, so it's worth
stating precisely:

- The server always delivers whole **chunks** (one Deliver frame = one chunk,
  atomic on the wire) — anywhere from 1 to thousands of messages each.
- The server's credit system is **chunk-granular**: 1 credit grants exactly 1
  future chunk delivery, regardless of how many messages that chunk turns out
  to contain.
- `maxBufferSize` is a **message** bound: the target ceiling on unread
  messages held in the client-side buffer.

Because a delivered chunk is never split or dropped (at-least-once delivery —
no message is ever discarded once it arrives), the buffer can transiently hold
more than `maxBufferSize` messages, by at most one chunk's worth, right after a
chunk lands. What `maxBufferSize` actually controls is credit: once the unread
count reaches or exceeds it, no further credit is granted, so the server stops
delivering new chunks until the buffer drains back below the limit.

### Credit Mechanism

RabbitMQ Streams uses a credit-based flow control system:

1. **Initial Credit** - Specified when creating the consumer (`initialCredit` parameter); this is also the hard cap on outstanding (in-flight, i.e. sent-but-not-yet-consumed) credit — the server can never have more than `initialCredit` chunks in flight at once
2. **Credits Consumed** - Each delivered chunk consumes one credit, no matter how many messages it contains
3. **Credits Replenished** - The client automatically sends more credit as the buffer drains, one credit per chunk's worth of headroom that reopens
4. **Backpressure** - While the unread count is at or over `maxBufferSize`, no new credit is granted at all; credit withheld this way is remembered and granted once the buffer drains

### Buffer Management

The consumer maintains an internal buffer of messages:

- **Default Size**: 1000 messages (`maxBufferSize`) — a target, not a hard cap (see above)
- **Automatic Replenishment**: Credits are sent as the buffer drains
- **Pending Credits**: Credit units withheld while the buffer was full are remembered and sent once space is available, bounded by the `initialCredit` cap on outstanding credit

### Backpressure Handling

When the consumer cannot keep up with the message rate:

1. The internal buffer's unread count reaches `maxBufferSize`
2. No new credit is granted to the server (a chunk already in flight may still land — it is never dropped)
3. Once un-replenished, the server eventually runs out of credit and stops sending new chunks
4. As the buffer drains via `read()`/`readOne()`, withheld credit is granted back, up to the `initialCredit` cap on outstanding credit

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
                $consumer->storeOffset($message->getOffset() + 1);
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
                $offset = OffsetSpec::offset($lastOffset);
                echo "Resuming from offset: {$lastOffset}\n";
            } catch (\Exception $e) {
                echo "Starting from beginning\n";
            }
            
            $consumer = $connection->createConsumer($stream, $offset, name: $consumerName);
            
            while ($message = $consumer->readOne()) {
                $processor($message);
                $consumer->storeOffset($message->getOffset() + 1);
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
