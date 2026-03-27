# Consuming Messages

This guide covers receiving messages from RabbitMQ Streams using both the high-level Consumer API and low-level protocol commands.

## Overview

RabbitMQ Streams provides durable, append-only message streams. Consumers read messages from these streams using an offset-based subscription model. The client library offers two approaches:

1. **High-level Consumer API** - Simple, convenient interface for most use cases
2. **Low-level Protocol** - Direct protocol access for advanced scenarios

## 1. Basic Consuming

### Creating a Consumer

Use `Connection::createConsumer()` to create a high-level consumer:

```php
<?php

declare(strict_types=1);

use CrazyGoat\RabbitStream\Client\Connection;
use CrazyGoat\RabbitStream\VO\OffsetSpec;

require_once __DIR__ . '/../../vendor/autoload.php';

$connection = Connection::create('127.0.0.1');

// Create a consumer starting from the first message
$consumer = $connection->createConsumer(
    stream: 'my-stream',
    offset: OffsetSpec::first()
);
```

### Reading Messages

The Consumer provides two methods for reading messages:

**Read multiple messages:**
```php
// Read up to available messages with 5-second timeout
$messages = $consumer->read(timeout: 5.0);

foreach ($messages as $message) {
    echo "Offset: {$message->getOffset()}\n";
    echo "Body: {$message->getBody()}\n";
}
```

**Read a single message:**
```php
// Read one message with 5-second timeout
$message = $consumer->readOne(timeout: 5.0);

if ($message !== null) {
    echo "Received: {$message->getBody()}\n";
} else {
    echo "No message received within timeout\n";
}
```

### Consumer Lifecycle

Always close the consumer when done to free resources:

```php
try {
    while ($message = $consumer->readOne()) {
        processMessage($message);
    }
} finally {
    $consumer->close();  // Unsubscribes and cleans up
}
```

The `close()` method:
- Sends `Unsubscribe` command to the server
- Stores final offset if auto-commit is enabled
- Clears the internal message buffer
- Frees the subscription ID for reuse

### Complete Basic Example

```php
<?php

declare(strict_types=1);

use CrazyGoat\RabbitStream\Client\Connection;
use CrazyGoat\RabbitStream\VO\OffsetSpec;

require_once __DIR__ . '/../../vendor/autoload.php';

$connection = Connection::create('127.0.0.1');

// Create consumer starting from the beginning
$consumer = $connection->createConsumer(
    'events',
    OffsetSpec::first()
);

try {
    echo "Consuming messages...\n";
    
    while (true) {
        $messages = $consumer->read(timeout: 5.0);
        
        if (empty($messages)) {
            echo "No new messages, waiting...\n";
            continue;
        }
        
        foreach ($messages as $message) {
            echo "[{$message->getOffset()}] {$message->getBody()}\n";
        }
    }
} catch (\Exception $e) {
    echo "Error: {$e->getMessage()}\n";
} finally {
    $consumer->close();
    $connection->close();
}
```

## 2. OffsetSpec - Where to Start

The `OffsetSpec` determines where consumption begins in the stream. There are 6 offset types:

| Method | Description | Use Case |
|--------|-------------|----------|
| `OffsetSpec::first()` | Start from the first message | Initial data load, full replay |
| `OffsetSpec::last()` | Start from the last message | Real-time processing, new messages only |
| `OffsetSpec::next()` | Start after the last consumed message | Resume after disconnect |
| `OffsetSpec::offset(int $offset)` | Start at a specific offset | Resume from known position |
| `OffsetSpec::timestamp(int $timestamp)` | Start after a specific timestamp | Time-based replay |
| `OffsetSpec::interval(int $interval)` | Start based on time interval | Relative time windows |

### Offset Type Examples

**First - Full Replay:**
```php
// Process all messages from the beginning
$consumer = $connection->createConsumer(
    'events',
    OffsetSpec::first()
);
```

**Last - Real-time Only:**
```php
// Only receive new messages published after subscription
$consumer = $connection->createConsumer(
    'events',
    OffsetSpec::last()
);
```

**Next - Resume After Disconnect:**
```php
// Continue from where you left off (requires named consumer)
$consumer = $connection->createConsumer(
    'events',
    OffsetSpec::next(),
    name: 'my-consumer'
);
```

**Specific Offset:**
```php
// Start from a known offset
$consumer = $connection->createConsumer(
    'events',
    OffsetSpec::offset(1000)
);
```

**Timestamp-based:**
```php
// Start from messages published after a specific time
$yesterday = time() - 86400;
$consumer = $connection->createConsumer(
    'events',
    OffsetSpec::timestamp($yesterday)
);
```

**Interval-based:**
```php
// Start from messages within a time interval (in seconds)
$oneHourAgo = 3600;
$consumer = $connection->createConsumer(
    'events',
    OffsetSpec::interval($oneHourAgo)
);
```

### Choosing the Right Offset

```
┌─────────────────────────────────────────────────────────────────┐
│                    Offset Selection Guide                       │
└─────────────────────────────────────────────────────────────────┘

First time consuming?          →  OffsetSpec::first()
                              →  OffsetSpec::last() (for new data only)

Resuming after restart?        →  Query stored offset
                              →  OffsetSpec::offset($storedOffset + 1)

Processing recent data only?   →  OffsetSpec::timestamp(time() - 3600)
                              →  OffsetSpec::interval(3600)

Exactly-once processing?       →  Named consumer with OffsetSpec::next()
```

## 3. Message Object

When consuming, you receive `Message` objects containing the message data and metadata.

### Core Properties

```php
$message = $consumer->readOne();

// Stream position
$offset = $message->getOffset();        // int: position in stream
$timestamp = $message->getTimestamp();    // int: Unix timestamp (server time)

// Message content
$body = $message->getBody();              // string|array|int|float|bool|null
```

### AMQP Properties

Messages may include AMQP 1.0 properties set by the producer:

```php
// Get all properties
$properties = $message->getProperties();

// Common convenience getters
$messageId = $message->getMessageId();           // mixed
$correlationId = $message->getCorrelationId();   // mixed
$contentType = $message->getContentType();       // ?string
$subject = $message->getSubject();               // ?string
$creationTime = $message->getCreationTime();     // ?int
$groupId = $message->getGroupId();               // ?string
```

### Application Properties

Custom headers set by the producer:

```php
$headers = $message->getApplicationProperties();

// Access custom headers
$eventType = $headers['event-type'] ?? 'unknown';
$priority = $headers['priority'] ?? 'normal';
```

### Working with JSON Messages

```php
$message = $consumer->readOne();

if ($message->getContentType() === 'application/json') {
    $data = json_decode($message->getBody(), true);
    
    // Process with metadata
    $metadata = [
        'offset' => $message->getOffset(),
        'timestamp' => $message->getTimestamp(),
        'message-id' => $message->getMessageId(),
    ];
    
    processEvent($data, $metadata);
}
```

For complete Message API documentation, see [Message API Reference](../api-reference/message.md).

## 4. Offset Tracking and Resume

Offset tracking allows consumers to persist their position in a stream and resume from that point after a restart.

### Named Consumers

To enable offset tracking, provide a unique name when creating the consumer:

```php
$consumer = $connection->createConsumer(
    'events',
    OffsetSpec::first(),
    name: 'event-processor-v1'  // Unique consumer name
);
```

### Storing Offsets

Manually store the current offset after successful processing:

```php
$messages = $consumer->read();

foreach ($messages as $message) {
    // Process the message
    processEvent($message);
    
    // Store offset after successful processing
    $consumer->storeOffset($message->getOffset());
}
```

### Querying Stored Offsets

Retrieve the last stored offset to resume consumption:

```php
// Create a temporary consumer to query the offset
$tempConsumer = $connection->createConsumer(
    'events',
    OffsetSpec::first(),
    name: 'event-processor-v1'
);

try {
    $lastOffset = $tempConsumer->queryOffset();
    echo "Last processed offset: {$lastOffset}\n";
    
    // Close temp consumer and create new one from offset+1
    $tempConsumer->close();
    
    $consumer = $connection->createConsumer(
        'events',
        OffsetSpec::offset($lastOffset + 1),
        name: 'event-processor-v1'
    );
} catch (\Exception $e) {
    // No stored offset found, start from beginning
    $consumer = $connection->createConsumer(
        'events',
        OffsetSpec::first(),
        name: 'event-processor-v1'
    );
}
```

### Resume Pattern

Complete pattern for resuming consumption:

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
    // Try to query existing offset
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
            OffsetSpec::offset($lastOffset + 1),
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
        $consumer->storeOffset($message->getOffset());
    }
} finally {
    $consumer->close();
}
```

For detailed offset tracking documentation, see [Offset Tracking Guide](offset-tracking.md).

## 5. Auto-Commit

Auto-commit automatically stores offsets at regular intervals, reducing the need for manual `storeOffset()` calls.

### Enabling Auto-Commit

Set the `autoCommit` parameter when creating the consumer:

```php
$consumer = $connection->createConsumer(
    'events',
    OffsetSpec::first(),
    name: 'auto-consumer',
    autoCommit: 100  // Store offset every 100 messages
);
```

### How It Works

1. **Counter-based**: The consumer counts messages and stores the offset every N messages
2. **Final commit on close**: When `close()` is called, the final offset is stored
3. **Requires named consumer**: Auto-commit only works with named consumers

### Trade-offs

| Approach | Pros | Cons |
|----------|------|------|
| Manual commit | Exactly-once processing | More code, slower |
| Auto-commit | Less code, faster | May reprocess up to N messages after crash |

### Best Practices

```php
// Choose auto-commit interval based on your requirements:

// High reliability: small interval
$consumer = $connection->createConsumer(
    'events',
    OffsetSpec::first(),
    name: 'reliable-consumer',
    autoCommit: 10   // Store every 10 messages
);

// High throughput: larger interval
$consumer = $connection->createConsumer(
    'events',
    OffsetSpec::first(),
    name: 'fast-consumer',
    autoCommit: 1000  // Store every 1000 messages
);
```

### Complete Auto-Commit Example

```php
<?php

declare(strict_types=1);

use CrazyGoat\RabbitStream\Client\Connection;
use CrazyGoat\RabbitStream\VO\OffsetSpec;

$connection = Connection::create('127.0.0.1');

// Create consumer with auto-commit every 100 messages
$consumer = $connection->createConsumer(
    'events',
    OffsetSpec::first(),
    name: 'auto-commit-consumer',
    autoCommit: 100,
    initialCredit: 50
);

try {
    $processed = 0;
    
    while ($message = $consumer->readOne(timeout: 10.0)) {
        processEvent($message->getBody());
        $processed++;
        
        // Offset is automatically stored every 100 messages
        if ($processed % 100 === 0) {
            echo "Processed {$processed} messages\n";
        }
    }
    
    echo "Total processed: {$processed}\n";
} finally {
    // Final offset is automatically stored on close()
    $consumer->close();
}
```

## 6. Flow Control

RabbitMQ Streams uses a credit-based flow control system to prevent consumers from being overwhelmed.

### How It Works

1. **Initial Credit**: Specified when creating the consumer
2. **Credit Consumption**: Each delivered message consumes one credit
3. **Credit Replenishment**: The client automatically sends more credits as messages are processed
4. **Backpressure**: When credits run out, the server stops sending messages

### Configuration

```php
$consumer = $connection->createConsumer(
    'events',
    OffsetSpec::last(),
    initialCredit: 100  // Request 100 messages at a time
);
```

### Credit Guidelines

| Credit Value | Use Case | Trade-off |
|--------------|----------|-----------|
| 1-10 | Low latency, strict ordering | High network overhead |
| 50-100 | Balanced throughput | Good default for most apps |
| 500+ | High throughput, batch processing | Higher memory usage |

For detailed flow control documentation, see [Flow Control Guide](flow-control.md).

## 7. Low-Level Consuming

For advanced use cases, you can use the protocol-level commands directly.

### Subscribe

```php
use CrazyGoat\RabbitStream\Request\SubscribeRequestV1;
use CrazyGoat\RabbitStream\VO\OffsetSpec;

$subscribe = new SubscribeRequestV1(
    subscriptionId: 1,
    stream: 'my-stream',
    offsetSpec: OffsetSpec::first(),
    credit: 100
);

$connection->sendMessage($subscribe);
$response = $connection->readMessage();
```

### Handle Deliver Frames

Deliver frames are server-push messages containing the actual data:

```php
use CrazyGoat\RabbitStream\Response\DeliverResponseV1;

// Register handler for deliver frames
$connection->registerSubscriber(
    subscriptionId: 1,
    callback: function (DeliverResponseV1 $deliver) use ($connection) {
        $messages = $deliver->getMessages();
        
        foreach ($messages as $message) {
            echo "Offset: {$message->getOffset()}\n";
            echo "Body: {$message->getBody()}\n";
        }
        
        // Replenish credits
        $connection->sendMessage(new CreditRequestV1(1, count($messages)));
    }
);

// Process deliver frames
$connection->readLoop(maxFrames: 100);
```

### Store Offset (Fire-and-Forget)

```php
use CrazyGoat\RabbitStream\Request\StoreOffsetRequestV1;

$storeOffset = new StoreOffsetRequestV1(
    offset: 1000,
    reference: 'my-consumer',
    stream: 'my-stream'
);

$connection->sendMessage($storeOffset);
// No response expected - fire and forget
```

### Query Offset

```php
use CrazyGoat\RabbitStream\Request\QueryOffsetRequestV1;

$queryOffset = new QueryOffsetRequestV1(
    reference: 'my-consumer',
    stream: 'my-stream'
);

$connection->sendMessage($queryOffset);
$response = $connection->readMessage();

if ($response instanceof QueryOffsetResponseV1) {
    $offset = $response->getOffset();
    echo "Stored offset: {$offset}\n";
}
```

### Unsubscribe

```php
use CrazyGoat\RabbitStream\Request\UnsubscribeRequestV1;

$unsubscribe = new UnsubscribeRequestV1(subscriptionId: 1);
$connection->sendMessage($unsubscribe);
$response = $connection->readMessage();
```

### Complete Low-Level Example

```php
<?php

declare(strict_types=1);

use CrazyGoat\RabbitStream\Client\Connection;
use CrazyGoat\RabbitStream\Request\SubscribeRequestV1;
use CrazyGoat\RabbitStream\Request\CreditRequestV1;
use CrazyGoat\RabbitStream\Request\StoreOffsetRequestV1;
use CrazyGoat\RabbitStream\Request\QueryOffsetRequestV1;
use CrazyGoat\RabbitStream\Request\UnsubscribeRequestV1;
use CrazyGoat\RabbitStream\Response\DeliverResponseV1;
use CrazyGoat\RabbitStream\Response\QueryOffsetResponseV1;
use CrazyGoat\RabbitStream\VO\OffsetSpec;

require_once __DIR__ . '/../../vendor/autoload.php';

$connection = Connection::create('127.0.0.1');

// Query existing offset
$queryOffset = new QueryOffsetRequestV1(
    reference: 'low-level-consumer',
    stream: 'events'
);
$connection->sendMessage($queryOffset);
$queryResponse = $connection->readMessage();

$startOffset = OffsetSpec::first();
if ($queryResponse instanceof QueryOffsetResponseV1) {
    $storedOffset = $queryResponse->getOffset();
    $startOffset = OffsetSpec::offset($storedOffset + 1);
    echo "Resuming from offset: {$storedOffset}\n";
}

// Subscribe
$subscribe = new SubscribeRequestV1(
    subscriptionId: 1,
    stream: 'events',
    offsetSpec: $startOffset,
    credit: 100
);
$connection->sendMessage($subscribe);
$connection->readMessage(); // SubscribeResponse

// Register deliver handler
$processed = 0;
$connection->registerSubscriber(1, function ($deliver) use ($connection, &$processed) {
    $messages = $deliver->getMessages();
    
    foreach ($messages as $message) {
        echo "[{$message->getOffset()}] {$message->getBody()}\n";
        $processed++;
    }
    
    // Store offset every 50 messages
    if ($processed % 50 === 0) {
        $lastOffset = $messages[count($messages) - 1]->getOffset();
        $connection->sendMessage(new StoreOffsetRequestV1(
            offset: $lastOffset,
            reference: 'low-level-consumer',
            stream: 'events'
        ));
    }
    
    // Replenish credits
    $connection->sendMessage(new CreditRequestV1(1, count($messages)));
});

// Process 100 deliver frames
$connection->readLoop(maxFrames: 100);

// Unsubscribe
$unsubscribe = new UnsubscribeRequestV1(subscriptionId: 1);
$connection->sendMessage($unsubscribe);
$connection->readMessage(); // UnsubscribeResponse

$connection->close();
echo "Processed {$processed} messages\n";
```

## Error Handling

### Common Consumer Errors

```php
use CrazyGoat\RabbitStream\Exception\ConnectionException;
use CrazyGoat\RabbitStream\Exception\ProtocolException;
use CrazyGoat\RabbitStream\Exception\TimeoutException;

try {
    $consumer = $connection->createConsumer(
        'non-existent-stream',
        OffsetSpec::first()
    );
} catch (ProtocolException $e) {
    echo "Stream does not exist: {$e->getMessage()}\n";
}

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
} catch (TimeoutException $e) {
    echo "Read timeout: {$e->getMessage()}\n";
} finally {
    $consumer->close();
}
```

### Consumer Recovery Pattern

```php
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

## See Also

- [Basic Consumer Example](../examples/basic-consumer.md) - Simple, complete example
- [Consumer Auto-Commit Example](../examples/consumer-auto-commit.md) - Automatic offset management
- [Offset Resume Example](../examples/offset-resume.md) - Resume from stored offset
- [Consumer API Reference](../api-reference/consumer.md) - Complete API documentation
- [Message API Reference](../api-reference/message.md) - Message object documentation
- [Offset Tracking Guide](offset-tracking.md) - Detailed offset management
- [Flow Control Guide](flow-control.md) - Credit-based flow control
