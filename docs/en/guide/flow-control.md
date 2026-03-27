# Flow Control Guide

This guide covers credit-based flow control, server-push frame handling, and asynchronous processing in RabbitMQ Streams.

## Overview

Flow control in RabbitMQ Streams prevents consumers from being overwhelmed by message delivery. The protocol uses a **credit-based mechanism** where the server tracks how many messages each consumer is allowed to receive. When credits run out, the server stops sending messages until the client replenishes them.

This guide explains:
- How credit-based flow control works
- Server-push frames and their handling
- The `readMessage()` transparent dispatch mechanism
- The `readLoop()` for pure async processing
- Heartbeat and ConsumerUpdate handling

## Credit-Based Flow Control

### How Credits Work

RabbitMQ Streams uses a simple but effective credit system:

```
┌─────────────────────────────────────────────────────────────────────────────┐
│                    Credit-Based Flow Control                                 │
└─────────────────────────────────────────────────────────────────────────────┘

     Client                                              Server
       │                                                   │
       │  Subscribe (credit=10)                            │
       │ ───────────────────────────────────────────────►  │
       │                                                   │
       │     Server allocates 10 credits                   │
       │     for this subscription                         │
       │                                                   │
       │     Deliver [msg 1]  ◄── credit 9 remaining       │
       │ ◄───────────────────────────────────────────────  │
       │     Deliver [msg 2]  ◄── credit 8 remaining       │
       │ ◄───────────────────────────────────────────────  │
       │              ...                                  │
       │     Deliver [msg 10] ◄── credit 0 remaining       │
       │ ◄───────────────────────────────────────────────  │
       │                                                   │
       │  Server stops sending (no credits left)           │
       │                                                   │
       │  Credit (credit=5)                                  │
       │ ───────────────────────────────────────────────►  │
       │                                                   │
       │     Server adds 5 credits                         │
       │     Deliver [msg 11] ◄── credit 4 remaining       │
       │ ◄───────────────────────────────────────────────  │
```

**Key principle:** One credit equals one message. The server decrements credits for each message sent and stops when credits reach zero.

### Initial Credit

When subscribing to a stream, you specify the initial credit via the `SubscribeRequestV1`:

```php
<?php

declare(strict_types=1);

use CrazyGoat\RabbitStream\Request\SubscribeRequestV1;
use CrazyGoat\RabbitStream\VO\OffsetSpec;

// Subscribe with initial credit of 100
$subscribe = new SubscribeRequestV1(
    subscriptionId: 1,
    stream: 'my-stream',
    offsetSpec: OffsetSpec::next(),
    credit: 100  // Initial credit
);

$connection->sendMessage($subscribe);
$response = $connection->readMessage();
```

**Choosing the right value:**

| Credit Value | Use Case | Trade-off |
|--------------|----------|-----------|
| 1-10 | Low latency, strict ordering | High network overhead |
| 50-100 | Balanced throughput | Good default for most apps |
| 500+ | High throughput, batch processing | Higher memory usage |

**Trade-offs:**
- **Low credit**: Lower latency (messages processed immediately), but more network round-trips for credit replenishment
- **High credit**: Better throughput (fewer credit requests), but higher memory usage and potential for message backlog

### Credit Replenishment

After processing messages, send a `CreditRequestV1` to replenish credits:

```php
<?php

use CrazyGoat\RabbitStream\Request\CreditRequestV1;

// Process 50 messages
foreach ($messages as $message) {
    processMessage($message);
}

// Replenish 50 credits
$creditRequest = new CreditRequestV1(
    subscriptionId: 1,
    credit: 50
);

$connection->sendMessage($creditRequest);
```

**Replenishment Strategies:**

1. **Message-by-message** (low latency):
   ```php
   $connection->onDeliver(function ($deliver) use ($connection) {
       processMessage($deliver);
       // Replenish 1 credit immediately
       $connection->sendMessage(new CreditRequestV1(1, 1));
   });
   ```

2. **Batch replenishment** (high throughput):
   ```php
   $processedCount = 0;
   $connection->onDeliver(function ($deliver) use ($connection, &$processedCount) {
       processMessage($deliver);
       $processedCount++;
       
       // Replenish every 50 messages
       if ($processedCount >= 50) {
           $connection->sendMessage(new CreditRequestV1(1, 50));
           $processedCount = 0;
       }
   });
   ```

3. **Periodic replenishment** (time-based):
   ```php
   $lastReplenish = microtime(true);
   $processedCount = 0;
   
   $connection->onDeliver(function ($deliver) use ($connection, &$lastReplenish, &$processedCount) {
       processMessage($deliver);
       $processedCount++;
       
       // Replenish every 100ms or 100 messages
       if ($processedCount >= 100 || (microtime(true) - $lastReplenish) > 0.1) {
           $connection->sendMessage(new CreditRequestV1(1, $processedCount));
           $processedCount = 0;
           $lastReplenish = microtime(true);
       }
   });
   ```

### Running Out of Credits

When credits reach zero, the server stops sending messages. This is **not an error** — it's the intended backpressure mechanism.

**What happens:**
1. Server tracks credits per subscription
2. Each `Deliver` frame decrements the credit counter
3. When credits reach 0, server stops sending
4. Client must send `CreditRequestV1` to resume delivery

**How to detect:**
- No new `Deliver` frames arrive
- `readLoop()` or `readMessage()` blocks waiting for data
- Other operations (heartbeats, confirms) continue normally

**Recovery:**
Simply send a `CreditRequestV1` to add more credits:

```php
// Check if we need more credits
if ($messagesProcessed > 0) {
    $connection->sendMessage(new CreditRequestV1($subscriptionId, $messagesProcessed));
}
```

## Server-Push Frames

Server-push frames are **asynchronous messages** sent by the server without a corresponding client request. They are handled transparently by the client library.

### All 7 Server-Push Frame Types

| Key | Command | Routed By | Trigger |
|-----|---------|-----------|---------|
| `0x0003` | PublishConfirm | `publisherId` | Message persisted to disk |
| `0x0004` | PublishError | `publisherId` | Message publish failed |
| `0x0008` | Deliver | `subscriptionId` | Message delivery to consumer |
| `0x0010` | MetadataUpdate | Stream name | Stream topology changed |
| `0x0016` | Close | — | Server-initiated close |
| `0x0017` | Heartbeat | — | Connection health check |
| `0x001a` | ConsumerUpdate | `subscriptionId` | Single Active Consumer activation |

**Important:** Server-push frames use **request keys** (`0x0001-0x7FFF`), not response keys (`0x8000+`).

For detailed protocol documentation, see [Server Push Frames](../protocol/server-push-frames.md).

## readMessage() Transparent Dispatch

The `readMessage()` method handles server-push frames transparently using an internal loop:

```
┌─────────────────────────────────────────────────────────────────────────────┐
│                    readMessage() Internal Loop                               │
└─────────────────────────────────────────────────────────────────────────────┘

   ┌─────────────┐
   │  Start      │
   └──────┬──────┘
          │
          ▼
   ┌─────────────┐     No     ┌─────────────┐
   │ socket_     │ ─────────► │  Timeout    │
   │ select()    │            │  Exception  │
   └──────┬──────┘            └─────────────┘
          │ Yes
          ▼
   ┌─────────────┐
   │ Read Frame  │
   └──────┬──────┘
          │
          ▼
   ┌─────────────┐     No     ┌─────────────┐
   │ Server-Push │ ─────────► │  Return to  │
   │ Frame?      │            │  Caller     │
   └──────┬──────┘            └─────────────┘
          │ Yes
          ▼
   ┌─────────────┐
   │ Dispatch to │
   │ Callback    │
   └──────┬──────┘
          │
          └───────────────────┐
                              ▼
                       ┌─────────────┐
                       │   Loop      │
                       └─────────────┘
```

**Key behavior:**
- Server-push frames are dispatched to registered callbacks
- The loop continues until a non-server-push frame arrives
- Your code only sees the response it was waiting for
- Heartbeats are automatically echoed back

**Example:**

```php
<?php

// Register callbacks before calling readMessage()
$connection->registerPublisher(
    publisherId: 1,
    onConfirm: function (array $publishingIds) {
        echo "Confirmed: " . implode(', ', $publishingIds) . "\n";
    },
    onError: function (array $errors) {
        foreach ($errors as $error) {
            echo "Error: #{$error->getPublishingId()}\n";
        }
    }
);

// Publish a message
$connection->sendMessage(new PublishRequestV1(1, $message));

// readMessage() will:
// 1. Wait for data
// 2. If PublishConfirm arrives first → dispatch to onConfirm, keep looping
// 3. If PublishError arrives first → dispatch to onError, keep looping
// 4. When the actual response arrives → return it to caller
$response = $connection->readMessage();
```

For a visual diagram of this flow, see [Server-Push Dispatch Diagram](../assets/diagrams/server-push-dispatch.md).

## readLoop() for Async Processing

For pure asynchronous processing (e.g., driving publish confirms without blocking), use `readLoop()`:

### Basic Usage

```php
<?php

// Register a publisher with callbacks
$connection->registerPublisher(
    publisherId: 1,
    onConfirm: function (array $publishingIds) {
        echo "Confirmed: " . implode(', ', $publishingIds) . "\n";
    },
    onError: function (array $errors) {
        foreach ($errors as $error) {
            echo "Error: #{$error->getPublishingId()}\n";
        }
    }
);

// Publish messages
for ($i = 1; $i <= 100; $i++) {
    $connection->sendMessage(new PublishRequestV1(1, new PublishedMessage($i, "Message {$i}")));
}

// Process up to 100 server-push frames (confirms/errors)
$connection->readLoop(maxFrames: 100);
```

### Parameters

| Parameter | Type | Description |
|-----------|------|-------------|
| `maxFrames` | `?int` | Process up to N server-push frames, then return |
| `timeout` | `?float` | Process for up to N seconds, then return |

**Examples:**

```php
// Process for 5 seconds
$connection->readLoop(timeout: 5.0);

// Process up to 10 frames or until 2 seconds pass
$connection->readLoop(maxFrames: 10, timeout: 2.0);

// Process indefinitely (until connection closes)
$connection->readLoop();
```

### Stopping the Loop

Call `stop()` from within a callback to interrupt the loop:

```php
<?php

$confirmedCount = 0;
$targetCount = 100;

$connection->registerPublisher(
    publisherId: 1,
    onConfirm: function (array $publishingIds) use ($connection, &$confirmedCount, $targetCount) {
        $confirmedCount += count($publishingIds);
        echo "Progress: {$confirmedCount}/{$targetCount}\n";
        
        // Stop when all messages are confirmed
        if ($confirmedCount >= $targetCount) {
            $connection->stop();
        }
    }
);

// Publish and wait for all confirms
for ($i = 1; $i <= $targetCount; $i++) {
    $connection->sendMessage(new PublishRequestV1(1, new PublishedMessage($i, "Message {$i}")));
}

// Loop until stop() is called or timeout
$connection->readLoop(timeout: 30.0);
echo "All messages confirmed!\n";
```

### Use Cases

1. **Publishing with confirms:**
   ```php
   // Publish without blocking, then process confirms
   foreach ($messages as $msg) {
       $publisher->send($msg);
   }
   $connection->readLoop(maxFrames: count($messages));
   ```

2. **Consumer message processing:**
   ```php
   // Process messages for 30 seconds
   $connection->registerSubscriber(1, function ($deliver) {
       processMessage($deliver);
       // Replenish credit
       $connection->sendMessage(new CreditRequestV1(1, 1));
   });
   $connection->readLoop(timeout: 30.0);
   ```

3. **Event-driven architecture:**
   ```php
   // Run indefinitely, handling all async events
   while ($running) {
       $connection->readLoop(maxFrames: 100, timeout: 1.0);
       // Do other work between batches
       doOtherWork();
   }
   ```

## Heartbeat Handling

Heartbeats keep connections alive during idle periods. The server sends heartbeat frames at the negotiated interval, and the client must echo them back.

### Automatic Handling

By default, heartbeats are handled automatically:

```php
<?php

// Heartbeats are transparent - you never see them
// The client auto-echoes heartbeat frames back to the server
$response = $connection->readMessage(); // Heartbeats handled internally
```

### Custom Heartbeat Callback

Register a callback to be notified when heartbeats arrive:

```php
<?php

// Called every time a heartbeat is received (and echoed)
$connection->onHeartbeat(function () {
    echo "Heartbeat received at " . date('Y-m-d H:i:s') . "\n";
});

// Now readMessage() and readLoop() will call your callback
$connection->readLoop(timeout: 60.0); // Will trigger callback multiple times
```

**Use cases for custom callbacks:**
- Logging connection health
- Updating last-activity timestamps
- Triggering keepalive checks in load balancers

### Heartbeat Flow

```
┌─────────────────────────────────────────────────────────────────────────────┐
│                    Heartbeat Flow                                            │
└─────────────────────────────────────────────────────────────────────────────┘

  Server ──► Heartbeat (0x0017) ──► Client
                                    │
                                    ▼
                             Echo immediately
                             Heartbeat (0x0017)
                                    │
                                    ▼
  Server ◄──────────────────────────┘

  Heartbeat keeps connection alive during idle periods
  Both sides send heartbeats at negotiated interval
```

## ConsumerUpdate (Single Active Consumer)

The **Single Active Consumer** feature ensures only one consumer processes messages from a stream at a time, while others wait as backups.

### How It Works

1. Multiple consumers subscribe to the same stream with the same `consumerReference`
2. Only one consumer is **active** and receives messages
3. Others are **inactive** and wait
4. When the active consumer disconnects, the server promotes an inactive one
5. The server sends `ConsumerUpdate` to ask the newly active consumer for its offset

### ConsumerUpdate Flow

```
┌─────────────────────────────────────────────────────────────────────────────┐
│                    Single Active Consumer Handoff                            │
└─────────────────────────────────────────────────────────────────────────────┘

  Consumer A (active)          Server          Consumer B (inactive)
       │                           │                    │
       │  Receiving messages       │                    │
       │◄──────────────────────────│                    │
       │                           │                    │
       │  Disconnects              │                    │
       ╳──────────────────────────►│                    │
       │                           │                    │
       │                           │  ConsumerUpdate    │
       │                           │───────────────────►│
       │                           │  (asking for offset)
       │                           │                    │
       │                           │  ConsumerUpdateReply
       │                           │◄───────────────────│
       │                           │  (offset to start from)
       │                           │                    │
       │                           │  Deliver messages  │
       │                           │───────────────────►│
       │                           │  Consumer B now active
```

### Auto-Reply Mechanism

By default, the client automatically replies to `ConsumerUpdate` with offset type 1 (OFFSET) and offset 0:

```php
<?php

// Subscribe as single active consumer
$subscribe = new SubscribeRequestV1(
    subscriptionId: 1,
    stream: 'my-stream',
    offsetSpec: OffsetSpec::next(),
    credit: 100,
    consumerReference: 'my-consumer-group'  // Same reference = single active
);

// Auto-reply is handled internally - no code needed!
```

### Custom ConsumerUpdate Callback

For custom offset selection, register a callback:

```php
<?php

use CrazyGoat\RabbitStream\Response\ConsumerUpdateQueryV1;

$connection->onConsumerUpdate(function (ConsumerUpdateQueryV1 $query): array {
    echo "Becoming active consumer!\n";
    echo "Subscription ID: {$query->getSubscriptionId()}\n";
    echo "Stream: {$query->getStreamName()}\n";
    
    // Return [offsetType, offset]
    // Offset types:
    // 0 = FIRST (start from beginning)
    // 1 = OFFSET (start from specific offset)
    // 2 = NEXT (start from next offset)
    // 3 = LAST (start from last message)
    // 4 = TIMESTAMP (start from timestamp)
    
    // Start from offset 100
    return [1, 100];
});
```

**Offset Types:**

| Type | Value | Description |
|------|-------|-------------|
| `FIRST` | 0 | Start from first message in stream |
| `OFFSET` | 1 | Start from specific offset (must provide offset) |
| `NEXT` | 2 | Start from next offset (after last consumed) |
| `LAST` | 3 | Start from last message |
| `TIMESTAMP` | 4 | Start from messages after timestamp |

### Complete Example

```php
<?php

declare(strict_types=1);

use CrazyGoat\RabbitStream\Client\Connection;
use CrazyGoat\RabbitStream\Request\SubscribeRequestV1;
use CrazyGoat\RabbitStream\Request\CreditRequestV1;
use CrazyGoat\RabbitStream\VO\OffsetSpec;

require_once __DIR__ . '/vendor/autoload.php';

$connection = Connection::create(
    host: '127.0.0.1',
    port: 5552,
    user: 'guest',
    password: 'guest',
);

// Custom handler for becoming active
$connection->onConsumerUpdate(function ($query) {
    echo "Promoted to active consumer!\n";
    // Start from where we left off (offset type 1 = OFFSET)
    return [1, 0];
});

// Subscribe as part of a consumer group
$subscribe = new SubscribeRequestV1(
    subscriptionId: 1,
    stream: 'my-stream',
    offsetSpec: OffsetSpec::next(),
    credit: 100,
    consumerReference: 'order-processor-group'
);

$connection->sendMessage($subscribe);
$connection->readMessage(); // SubscribeResponse

// Register message handler
$connection->registerSubscriber(1, function ($deliver) use ($connection) {
    $messages = $deliver->getMessages();
    echo "Received " . count($messages) . " messages\n";
    
    // Process messages
    foreach ($messages as $message) {
        processOrder($message);
    }
    
    // Replenish credits
    $connection->sendMessage(new CreditRequestV1(1, count($messages)));
});

// Run event loop
$connection->readLoop();
```

## Best Practices

### Credit Tuning

1. **Start with 100 credits** — Good default for most applications
2. **Monitor memory usage** — High credits = more messages buffered
3. **Adjust based on processing time**:
   - Fast processing (< 10ms): Use 200-500 credits
   - Slow processing (> 100ms): Use 10-50 credits
4. **Replenish promptly** — Don't wait too long to send `CreditRequestV1`

### Async Patterns

1. **Use `readLoop()` for pure async** — When you don't need to wait for specific responses
2. **Use `readMessage()` for request/response** — When you need a specific response
3. **Combine both** — Use `readMessage()` for setup, `readLoop()` for runtime

```php
// Setup phase - use readMessage()
$connection->sendMessage(new DeclarePublisherRequestV1(1, null, 'my-stream'));
$connection->readMessage(); // Wait for DeclarePublisherResponse

// Runtime phase - use readLoop()
$connection->readLoop(maxFrames: 1000, timeout: 60.0);
```

### Error Handling

1. **Always handle `PublishError`** — Messages can fail for various reasons
2. **Monitor credit exhaustion** — If no messages arrive, you may be out of credits
3. **Handle server-initiated close** — The server can close connections anytime

```php
$connection->registerPublisher(
    publisherId: 1,
    onConfirm: function ($ids) { /* ... */ },
    onError: function ($errors) {
        foreach ($errors as $error) {
            $code = $error->getCode();
            $id = $error->getPublishingId();
            
            if ($code === ResponseCodeEnum::STREAM_NOT_EXIST->value) {
                echo "Stream does not exist!\n";
            } else {
                echo "Publish error for #{$id}: code={$code}\n";
            }
        }
    }
);
```

### Connection Health

1. **Enable heartbeats** — Prevents connection timeouts during idle periods
2. **Use `onHeartbeat()` callback** — Log connection health for monitoring
3. **Handle timeouts gracefully** — `readMessage()` and `readLoop()` can timeout

```php
use CrazyGoat\RabbitStream\Exception\TimeoutException;

try {
    $connection->readLoop(timeout: 30.0);
} catch (TimeoutException $e) {
    echo "No activity for 30 seconds, checking connection...\n";
    // Send heartbeat or reconnect
}
```

## See Also

- [Server Push Frames](../protocol/server-push-frames.md) — Detailed protocol reference
- [Server-Push Dispatch Diagram](../assets/diagrams/server-push-dispatch.md) — Visual flow diagrams
- [Publishing Guide](publishing.md) — Publish confirms and error handling
- [Connection Lifecycle](connection-lifecycle.md) — Connection handshake and heartbeats
- [Consuming Guide](consuming.md) — Message consumption patterns
