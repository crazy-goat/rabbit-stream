# Offset Tracking

This guide covers managing message offsets in RabbitMQ Streams, including storage mechanisms, resume patterns, and best practices.

## Overview

Offset tracking is the mechanism that allows consumers to persist their position in a stream and resume consumption from that point after a restart or failure. RabbitMQ Streams provides **server-side** offset storage (persisted on the broker), eliminating the need for external databases or file-based **client-side** tracking.

## Key Concepts

### What is an Offset?

An offset is a monotonically increasing integer that represents the position of a message in a stream:

- **Sequential**: Offsets start at 0 and increase by 1 for each message
- **Immutable**: Once assigned, an offset never changes
- **Durable**: Offsets survive server restarts
- **Per-stream**: Each stream has its own offset sequence

```
Stream: "events"
┌─────┬─────┬─────┬─────┬─────┬─────┐
│  0  │  1  │  2  │  3  │  4  │  5  │
├─────┼─────┼─────┼─────┼─────┼─────┤
│ msg │ msg │ msg │ msg │ msg │ msg │
└─────┴─────┴─────┴─────┴─────┴─────┘
  ▲                           ▲
  │                           │
Offset 0 (first)         Offset 5 (latest)
```

### Named Consumers

Offset tracking requires a **named consumer** - a unique identifier that associates stored offsets with a specific consumer instance:

```php
$consumer = $connection->createConsumer(
    'events',
    OffsetSpec::first(),
    name: 'payment-processor-v1'  // Unique consumer name
);
```

**Naming Best Practices:**
- Use descriptive names: `{service}-{version}`
- Include version numbers for compatibility: `order-processor-v2`
- Use consistent naming across restarts
- Avoid dynamic or random names

## Offset Types

RabbitMQ Streams supports 6 offset specification types:

### 1. First

Start from the first message in the stream:

```php
$consumer = $connection->createConsumer(
    'events',
    OffsetSpec::first()
);
```

**Use Cases:**
- Initial data load
- Full stream replay
- Backfilling historical data
- Testing and debugging

### 2. Last

Start from the last message (receive only new messages):

```php
$consumer = $connection->createConsumer(
    'events',
    OffsetSpec::last()
);
```

**Use Cases:**
- Real-time processing
- Event streaming
- Live monitoring
- Forward-only processing

### 3. Next

Start from the message after the last consumed:

```php
$consumer = $connection->createConsumer(
    'events',
    OffsetSpec::next(),
    name: 'my-consumer'  // Requires named consumer
);
```

**Use Cases:**
- Resume after disconnect
- Exactly-once processing
- Consumer restart recovery

**Important**: `OffsetSpec::next()` uses the stored offset to determine the next message. It requires a named consumer with a previously stored offset.

### 4. Offset

Start from a specific offset number:

```php
$consumer = $connection->createConsumer(
    'events',
    OffsetSpec::offset(1000)  // Start from offset 1000
);
```

**Use Cases:**
- Resume from known position
- Skip corrupted messages
- Partial replay
- Debugging specific messages

### 5. Timestamp

Start from messages published after a specific Unix timestamp:

```php
$yesterday = time() - 86400;
$consumer = $connection->createConsumer(
    'events',
    OffsetSpec::timestamp($yesterday)
);
```

**Use Cases:**
- Time-based replay
- Recovery from specific time
- Processing recent data only
- Archival and cleanup

### 6. Interval

Start from messages within a time interval (in seconds):

```php
$oneHourAgo = 3600;
$consumer = $connection->createConsumer(
    'events',
    OffsetSpec::interval($oneHourAgo)  // Last hour only
);
```

**Use Cases:**
- Sliding time windows
- Recent data processing
- Time-based filtering
- Relative time queries

### 7. Server-Side Resolution (RabbitMQ 4.3+)

Resolve an OffsetSpec to a concrete offset value before subscribing. There is no high-level wrapper for this command yet — use a raw `StreamConnection` (`$stream`), i.e. the low-level API:

```php
use CrazyGoat\RabbitStream\Request\ResolveOffsetSpecRequestV1;
use CrazyGoat\RabbitStream\Response\ResolveOffsetSpecResponseV1;

// $stream is a handshaken StreamConnection
// (see docs/en/examples/low-level-protocol.md)
$stream->sendMessage(new ResolveOffsetSpecRequestV1(
    stream: 'events',
    offsetSpec: OffsetSpec::last()
));

$response = $stream->readMessage();
if ($response instanceof ResolveOffsetSpecResponseV1) {
    $concreteOffset = $response->getOffset();
    echo "Last offset is: {$concreteOffset}\n";
}
```

**Use Cases:**
- Pre-flight offset validation
- Converting relative specs to concrete values
- Determining exact resume positions
- Offset lag monitoring

**Note:** Requires RabbitMQ 4.3 or later. Falls back to client-side resolution on older versions.

## Offset Storage

### What a Stored Offset Means

A stored offset is the **next** offset to consume — `lastProcessedOffset + 1`,
not the offset of the last processed message. That is what auto-commit writes,
what `storeOffset()` expects, and what the Java, Go and .NET clients store, so
offsets are portable between clients and resuming needs no arithmetic:

```php
$stored = $consumer->queryOffset();               // e.g. 42
$consumer = $connection->createConsumer(
    'events',
    OffsetSpec::offset($stored),                  // inclusive: starts AT 42
    name: 'payment-processor-v1'
);
```

> **Changed in v1.3.0.** Auto-commit used to store the last *consumed* offset,
> which redelivered that message on every resume (#396). Offsets written by an
> older version are one too low: resume those consumers once with
> `OffsetSpec::offset($stored + 1)`, or accept a single duplicate.

### Manual Storage

Store offsets explicitly after successful message processing:

```php
$messages = $consumer->read();

foreach ($messages as $message) {
    // Process the message
    $success = processMessage($message);
    
    if ($success) {
        // Store offset only after successful processing
        $consumer->storeOffset($message->getOffset() + 1);
    } else {
        // Handle failure - don't store offset
        handleFailure($message);
        break; // Stop processing
    }
}
```

**Characteristics:**
- **Exactly-once semantics**: Offset stored only after successful processing
- **Granular control**: Store offset per message or batch
- **Synchronous**: `storeOffset()` makes a round-trip to the server
- **Durable**: Offsets are persisted on the server

### Auto-Commit Storage

Enable automatic offset storage at regular intervals:

```php
$consumer = $connection->createConsumer(
    'events',
    OffsetSpec::first(),
    name: 'auto-consumer',
    autoCommit: 100  // Store every 100 messages
);
```

**Characteristics:**
- **At-least-once semantics**: May reprocess up to N messages after crash
- **Counter-based**: Stores offset every N messages
- **Final commit**: Stores offset on `close()`
- **Performance**: Fewer server round-trips than manual storage

### Storage Comparison

| Approach | Durability | Performance | Complexity | Use Case |
|----------|-----------|-------------|------------|----------|
| Manual | High | Lower | Higher | Exactly-once processing |
| Auto-commit | Medium | Higher | Lower | At-least-once processing |

## Querying Offsets

### Query Stored Offset

Retrieve the last stored offset for a named consumer:

```php
$consumer = $connection->createConsumer(
    'events',
    OffsetSpec::first(),
    name: 'my-consumer'
);

try {
    $lastOffset = $consumer->queryOffset();
    echo "Last stored offset: {$lastOffset}\n";
} catch (\Exception $e) {
    echo "No stored offset found\n";
}
```

**Important Notes:**
- Requires a named consumer
- Returns the offset last stored via `storeOffset()` or auto-commit — the **next** offset to consume, so it can be passed straight to `OffsetSpec::offset()`
- Throws exception if no offset has been stored
- Makes a round-trip to the server

## Resume Patterns

### Pattern 1: Simple Resume

Basic resume from stored offset:

```php
<?php

declare(strict_types=1);

use CrazyGoat\RabbitStream\Client\Connection;
use CrazyGoat\RabbitStream\VO\OffsetSpec;

function createResumingConsumer(
    Connection $connection,
    string $stream,
    string $consumerName
): \CrazyGoat\RabbitStream\Client\Consumer {
    // Create temporary consumer to query offset
    $tempConsumer = $connection->createConsumer(
        $stream,
        OffsetSpec::first(),
        name: $consumerName
    );
    
    try {
        $lastOffset = $tempConsumer->queryOffset();
        $tempConsumer->close();
        
        // Resume from next offset
        return $connection->createConsumer(
            $stream,
            OffsetSpec::offset($lastOffset),
            name: $consumerName
        );
    } catch (\Exception $e) {
        $tempConsumer->close();
        
        // No stored offset, start from beginning
        return $connection->createConsumer(
            $stream,
            OffsetSpec::first(),
            name: $consumerName
        );
    }
}

// Usage
$connection = Connection::create('127.0.0.1');
$consumer = createResumingConsumer($connection, 'events', 'processor-v1');

try {
    while ($message = $consumer->readOne()) {
        processEvent($message);
        $consumer->storeOffset($message->getOffset() + 1);
    }
} finally {
    $consumer->close();
}
```

### Pattern 2: Resume with OffsetSpec::next()

Use the built-in `next()` offset type for automatic resume:

```php
<?php

declare(strict_types=1);

use CrazyGoat\RabbitStream\Client\Connection;
use CrazyGoat\RabbitStream\VO\OffsetSpec;

$connection = Connection::create('127.0.0.1');

// Create consumer with OffsetSpec::next()
// It will automatically use the stored offset
$consumer = $connection->createConsumer(
    'events',
    OffsetSpec::next(),
    name: 'my-consumer'
);

try {
    while ($message = $consumer->readOne()) {
        processEvent($message);
        $consumer->storeOffset($message->getOffset() + 1);
    }
} finally {
    $consumer->close();
}
```

**Note**: `OffsetSpec::next()` requires the consumer to be named and have a previously stored offset. If no offset exists, it starts from the beginning.

### Pattern 3: Resume with Auto-Commit

Combine auto-commit with resume for automatic offset management:

```php
<?php

declare(strict_types=1);

use CrazyGoat\RabbitStream\Client\Connection;
use CrazyGoat\RabbitStream\VO\OffsetSpec;

$connection = Connection::create('127.0.0.1');

// Query last offset
$tempConsumer = $connection->createConsumer(
    'events',
    OffsetSpec::first(),
    name: 'auto-consumer'
);

$startOffset = OffsetSpec::first();
try {
    $lastOffset = $tempConsumer->queryOffset();
    $startOffset = OffsetSpec::offset($lastOffset);
    echo "Resuming from offset: {$lastOffset}\n";
} catch (\Exception $e) {
    echo "Starting from beginning\n";
} finally {
    $tempConsumer->close();
}

// Create consumer with auto-commit
$consumer = $connection->createConsumer(
    'events',
    $startOffset,
    name: 'auto-consumer',
    autoCommit: 100
);

try {
    $processed = 0;
    while ($message = $consumer->readOne(timeout: 10.0)) {
        processEvent($message);
        $processed++;
    }
    echo "Processed {$processed} messages\n";
} finally {
    // Final offset automatically stored on close()
    $consumer->close();
}
```

### Pattern 4: Batch Processing with Offset Tracking

Process messages in batches and store offset after each batch:

```php
<?php

declare(strict_types=1);

use CrazyGoat\RabbitStream\Client\Connection;
use CrazyGoat\RabbitStream\VO\OffsetSpec;

$connection = Connection::create('127.0.0.1');

// Resume from last offset
$tempConsumer = $connection->createConsumer(
    'events',
    OffsetSpec::first(),
    name: 'batch-processor'
);

$startOffset = OffsetSpec::first();
try {
    $lastOffset = $tempConsumer->queryOffset();
    $startOffset = OffsetSpec::offset($lastOffset);
} catch (\Exception $e) {
    // No stored offset
} finally {
    $tempConsumer->close();
}

$consumer = $connection->createConsumer(
    'events',
    $startOffset,
    name: 'batch-processor',
    initialCredit: 100
);

$batch = [];
$batchSize = 50;
$lastOffset = 0;

try {
    while (true) {
        $messages = $consumer->read(timeout: 5.0);
        
        foreach ($messages as $message) {
            $batch[] = $message;
            $lastOffset = $message->getOffset();
            
            if (count($batch) >= $batchSize) {
                // Process batch
                processBatch($batch);
                
                // Store offset after successful batch processing
                $consumer->storeOffset($lastOffset + 1);
                
                $batch = [];
            }
        }
        
        // Process remaining messages
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

## Best Practices

### 1. Choose the Right Offset Type

```php
// For initial data load
OffsetSpec::first()

// For real-time processing
OffsetSpec::last()

// For resume after restart
OffsetSpec::next()  // or OffsetSpec::offset($storedOffset)

// For time-based replay
OffsetSpec::timestamp($timestamp)
```

### 2. Use Descriptive Consumer Names

```php
// Good: descriptive and versioned
name: 'order-processor-v2'
name: 'payment-service-prod'
name: 'analytics-consumer-2024'

// Bad: non-descriptive or dynamic
name: 'consumer-' . uniqid()  // Changes every restart!
name: 'my-consumer'           // Too generic
```

### 3. Store Offsets After Successful Processing

```php
foreach ($messages as $message) {
    try {
        // Process first
        processMessage($message);
        
        // Store offset only after success
        $consumer->storeOffset($message->getOffset() + 1);
    } catch (\Exception $e) {
        // Don't store offset on failure
        // Message will be reprocessed on restart
        handleFailure($e, $message);
        break;
    }
}
```

### 4. Handle Missing Offsets Gracefully

```php
try {
    $lastOffset = $consumer->queryOffset();
    $startOffset = OffsetSpec::offset($lastOffset);
} catch (\Exception $e) {
    // No stored offset - this is normal for first run
    $startOffset = OffsetSpec::first();
}
```

### 5. Use Auto-Commit for High Throughput

```php
// When processing thousands of messages per second
$consumer = $connection->createConsumer(
    'events',
    OffsetSpec::first(),
    name: 'high-throughput-consumer',
    autoCommit: 1000  // Store every 1000 messages
);
```

### 6. Monitor Offset Lag

```php
// Periodically check how far behind the consumer is
function getOffsetLag(Connection $connection, string $stream, string $consumerName): int {
    // Query stored offset
    $tempConsumer = $connection->createConsumer($stream, OffsetSpec::first(), name: $consumerName);
    $storedOffset = $tempConsumer->queryOffset();
    $tempConsumer->close();
    
    // Get latest offset (would need to query stream stats)
    // This is a simplified example
    $latestOffset = getLatestStreamOffset($connection, $stream);
    
    return $latestOffset - $storedOffset;
}

// Alert if lag is too high
$lag = getOffsetLag($connection, 'events', 'my-consumer');
if ($lag > 10000) {
    alert("Consumer is {$lag} messages behind!");
}
```

### 7. Clean Up Old Consumer Names

When deploying new versions, clean up old consumer names to free server resources:

```php
// Old consumer names may accumulate over time
// Consider a cleanup strategy for obsolete names

// Example: versioned naming with cleanup
$version = getenv('CONSUMER_VERSION') ?: 'v1';
$consumerName = "processor-{$version}";

// On deployment, clean up old versions
if ($version === 'v2') {
    // Delete offset storage for v1
    deleteConsumerOffset('processor-v1');
}
```

## Common Pitfalls

### 1. Forgetting to Name the Consumer

```php
// Wrong: unnamed consumer can't store offsets
$consumer = $connection->createConsumer('events', OffsetSpec::first());
$consumer->storeOffset(100);  // Throws exception!

// Right: named consumer can store offsets
$consumer = $connection->createConsumer(
    'events',
    OffsetSpec::first(),
    name: 'my-consumer'
);
$consumer->storeOffset(100);  // Works!
```

### 2. Storing Offset Before Processing

```php
// Wrong: may lose messages on crash
foreach ($messages as $message) {
    $consumer->storeOffset($message->getOffset() + 1);  // Stored before processing!
    processMessage($message);  // If crash here, message is lost
}

// Right: store after successful processing
foreach ($messages as $message) {
    processMessage($message);
    $consumer->storeOffset($message->getOffset() + 1);  // Stored after success
}
```

### 3. Using Dynamic Consumer Names

```php
// Wrong: new name every restart = no resume possible
$consumer = $connection->createConsumer(
    'events',
    OffsetSpec::first(),
    name: 'consumer-' . gethostname()  // Changes on every host!
);

// Right: consistent name across restarts
$consumer = $connection->createConsumer(
    'events',
    OffsetSpec::first(),
    name: 'payment-processor-v1'  // Consistent name
);
```

### 4. Not Handling Missing Offset Exception

```php
// Wrong: exception on first run
$lastOffset = $consumer->queryOffset();  // Throws if no offset stored!

// Right: handle missing offset gracefully
try {
    $lastOffset = $consumer->queryOffset();
} catch (\Exception $e) {
    $lastOffset = -1;  // No offset stored yet
}
```

## Low-Level Offset Operations

For advanced use cases, use protocol-level commands. The snippets below use a raw `StreamConnection` (`$stream`) — the low-level API. If you only need to store or query an offset by reference, the high-level `Connection` already covers it:

```php
// High-level, reference-based (no consumer instance needed)
$connection->storeOffset('my-consumer', 'events', 1000);

$stored = $connection->queryOffset('my-consumer', 'events');
```

### Store Offset (Fire-and-Forget)

```php
use CrazyGoat\RabbitStream\Request\StoreOffsetRequestV1;

// $stream is a handshaken StreamConnection (low-level API)
$storeOffset = new StoreOffsetRequestV1(
    reference: 'my-consumer',
    stream: 'events',
    offset: 1000
);

$stream->sendMessage($storeOffset);
// No response expected
```

### Query Offset

```php
use CrazyGoat\RabbitStream\Request\QueryOffsetRequestV1;
use CrazyGoat\RabbitStream\Response\QueryOffsetResponseV1;

// $stream is a handshaken StreamConnection (low-level API)
$queryOffset = new QueryOffsetRequestV1(
    reference: 'my-consumer',
    stream: 'events'
);

$stream->sendMessage($queryOffset);
$response = $stream->readMessage();

if ($response instanceof QueryOffsetResponseV1) {
    $offset = $response->getOffset();
    echo "Stored offset: {$offset}\n";
}
```

## See Also

- [Consuming Guide](consuming.md) - Main consuming documentation
- [Consumer API Reference](../api-reference/consumer.md) - Complete Consumer API
- [Message API Reference](../api-reference/message.md) - Message object documentation
- [Offset Resume Example](../examples/offset-resume.md) - Complete resume example
- [Consumer Auto-Commit Example](../examples/consumer-auto-commit.md) - Auto-commit example
