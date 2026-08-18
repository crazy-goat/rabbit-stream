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

When subscribing to a stream, you specify the initial credit via the `SubscribeRequestV1`. This guide shows the low-level API — the snippets use a raw `StreamConnection` (`$stream`):

```php
<?php

declare(strict_types=1);

use CrazyGoat\RabbitStream\StreamConnection;
use CrazyGoat\RabbitStream\Client\Connection;
use CrazyGoat\RabbitStream\Request\SubscribeRequestV1;
use CrazyGoat\RabbitStream\VO\OffsetSpec;

// Low-level connection, handshaken by the high-level factory
$stream = new StreamConnection('127.0.0.1', 5552);
$stream->connect();
$connection = Connection::create(host: '127.0.0.1', port: 5552, streamConnection: $stream);

// Subscribe with initial credit of 100
$subscribe = new SubscribeRequestV1(
    subscriptionId: 1,
    stream: 'my-stream',
    offsetSpec: OffsetSpec::next(),
    credit: 100  // Initial credit
);

$stream->sendMessage($subscribe);
$response = $stream->readMessage();
```

> The high-level `Connection::createConsumer()` performs the subscribe and
> manages credits internally — you only tune the `initialCredit` parameter.

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

After processing messages, send a `CreditRequestV1` to replenish credits. This happens inside your `registerSubscriber()` deliver callback (low-level API):

```php
<?php

use CrazyGoat\RabbitStream\Request\CreditRequestV1;
use CrazyGoat\RabbitStream\Client\AmqpMessageDecoder;
use CrazyGoat\RabbitStream\Client\OsirisChunkParser;

// Inside your registerSubscriber() callback:
$messages = AmqpMessageDecoder::decodeAll(OsirisChunkParser::parse($deliver->getChunkBytes()));

// Process 50 messages
foreach ($messages as $message) {
    processMessage($message);
}

// Replenish 50 credits
$creditRequest = new CreditRequestV1(
    subscriptionId: 1,
    credit: 50
);

$stream->sendMessage($creditRequest);
```

**Replenishment Strategies:**

1. **Message-by-message** (low latency):
   ```php
   $stream->registerSubscriber(1, function (DeliverResponseV1 $deliver) use ($stream): void {
       $messages = AmqpMessageDecoder::decodeAll(OsirisChunkParser::parse($deliver->getChunkBytes()));
       foreach ($messages as $message) {
           processMessage($message);
       }
       // Replenish 1 credit immediately
       $stream->sendMessage(new CreditRequestV1(1, 1));
   });
   ```

2. **Batch replenishment** (high throughput):
   ```php
   $processedCount = 0;
   $stream->registerSubscriber(1, function (DeliverResponseV1 $deliver) use ($stream, &$processedCount): void {
       $messages = AmqpMessageDecoder::decodeAll(OsirisChunkParser::parse($deliver->getChunkBytes()));
       foreach ($messages as $message) {
           processMessage($message);
           $processedCount++;
       }
       
       // Replenish every 50 messages
       if ($processedCount >= 50) {
           $stream->sendMessage(new CreditRequestV1(1, 50));
           $processedCount = 0;
       }
   });
   ```

3. **Periodic replenishment** (time-based):
   ```php
   $lastReplenish = microtime(true);
   $processedCount = 0;
   
   $stream->registerSubscriber(1, function (DeliverResponseV1 $deliver) use ($stream, &$lastReplenish, &$processedCount): void {
       $messages = AmqpMessageDecoder::decodeAll(OsirisChunkParser::parse($deliver->getChunkBytes()));
       foreach ($messages as $message) {
           processMessage($message);
           $processedCount++;
       }
       
       // Replenish every 100ms or 100 messages
       if ($processedCount >= 100 || (microtime(true) - $lastReplenish) > 0.1) {
           $stream->sendMessage(new CreditRequestV1(1, $processedCount));
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
Simply send a `CreditRequestV1` to add more credits (low-level API):

```php
// Check if we need more credits
if ($messagesProcessed > 0) {
    $stream->sendMessage(new CreditRequestV1($subscriptionId, $messagesProcessed));
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

**Example (low-level API, `$stream` is a handshaken StreamConnection):**

```php
<?php

use CrazyGoat\RabbitStream\Request\PublishRequestV1;
use CrazyGoat\RabbitStream\VO\PublishedMessage;
use CrazyGoat\RabbitStream\Client\AmqpMessageEncoder;

// Register callbacks before calling readMessage()
$stream->registerPublisher(
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
$message = new PublishedMessage(1, AmqpMessageEncoder::encodeDataSection('Hello'));
$stream->sendMessage(new PublishRequestV1(1, $message));

// readMessage() will:
// 1. Wait for data
// 2. If PublishConfirm arrives first → dispatch to onConfirm, keep looping
// 3. If PublishError arrives first → dispatch to onError, keep looping
// 4. When the actual response arrives → return it to caller
$response = $stream->readMessage();
```

For a visual diagram of this flow, see [Server-Push Dispatch Diagram](../assets/diagrams/server-push-dispatch.md).

## readLoop() for Async Processing

For pure asynchronous processing (e.g., driving publish confirms without blocking), use `readLoop()`:

### Basic Usage

```php
<?php

use CrazyGoat\RabbitStream\Request\PublishRequestV1;
use CrazyGoat\RabbitStream\VO\PublishedMessage;
use CrazyGoat\RabbitStream\Client\AmqpMessageEncoder;

// Register a publisher with callbacks (low-level API, $stream is a
// handshaken StreamConnection)
$stream->registerPublisher(
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
    $stream->sendMessage(new PublishRequestV1(
        1,
        new PublishedMessage($i, AmqpMessageEncoder::encodeDataSection("Message {$i}"))
    ));
}

// Process up to 100 server-push frames (confirms/errors)
$stream->readLoop(maxFrames: 100);
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

Call `stop()` from within a callback to interrupt the loop (low-level API):

```php
<?php

use CrazyGoat\RabbitStream\Request\PublishRequestV1;
use CrazyGoat\RabbitStream\VO\PublishedMessage;
use CrazyGoat\RabbitStream\Client\AmqpMessageEncoder;

$confirmedCount = 0;
$targetCount = 100;

$stream->registerPublisher(
    publisherId: 1,
    onConfirm: function (array $publishingIds) use ($stream, &$confirmedCount, $targetCount) {
        $confirmedCount += count($publishingIds);
        echo "Progress: {$confirmedCount}/{$targetCount}\n";
        
        // Stop when all messages are confirmed
        if ($confirmedCount >= $targetCount) {
            $stream->stop();
        }
    }
);

// Publish and wait for all confirms
for ($i = 1; $i <= $targetCount; $i++) {
    $stream->sendMessage(new PublishRequestV1(
        1,
        new PublishedMessage($i, AmqpMessageEncoder::encodeDataSection("Message {$i}"))
    ));
}

// Loop until stop() is called or timeout
$stream->readLoop(timeout: 30.0);
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
   // Low-level API
   $stream->registerSubscriber(1, function (DeliverResponseV1 $deliver) use ($stream): void {
       $messages = AmqpMessageDecoder::decodeAll(OsirisChunkParser::parse($deliver->getChunkBytes()));
       foreach ($messages as $message) {
           processMessage($message);
       }
       // Replenish credit
       $stream->sendMessage(new CreditRequestV1(1, count($messages)));
   });
   $stream->readLoop(timeout: 30.0);
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

By default, heartbeats are handled automatically (low-level API):

```php
<?php

// Heartbeats are transparent - you never see them
// The client auto-echoes heartbeat frames back to the server
$response = $stream->readMessage(); // Heartbeats handled internally
```

### Custom Heartbeat Callback

Register a callback to be notified when heartbeats arrive:

```php
<?php

// Called every time a heartbeat is received (and echoed)
$stream->onHeartbeat(function () {
    echo "Heartbeat received at " . date('Y-m-d H:i:s') . "\n";
});

// Now readMessage() and readLoop() will call your callback
$stream->readLoop(timeout: 60.0); // Will trigger callback multiple times
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

1. Multiple consumers subscribe to the same stream as a **group** (the server-side concept of a consumer group; the protocol calls it a consumer reference)
2. Only one consumer is **active** and receives messages
3. Others are **inactive** and wait
4. When the active consumer disconnects, the server promotes an inactive one
5. The server sends `ConsumerUpdate` to ask the newly active consumer for its offset

> Note: the current client cannot subscribe with a consumer reference, so
> group-based coordination is not available through `SubscribeRequestV1`
> yet. The `ConsumerUpdate` handling below still applies to any
> subscription that receives such frames.

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

By default, the client automatically replies to `ConsumerUpdate` with offset type 1 (OFFSET) and offset 0. The subscribe command itself does not carry a consumer reference in this client (single-active-consumer groups are not supported yet), but a subscription may still receive `ConsumerUpdate` frames:

```php
<?php

// Low-level API
$subscribe = new SubscribeRequestV1(
    subscriptionId: 1,
    stream: 'my-stream',
    offsetSpec: OffsetSpec::next(),
    credit: 100
);

// Auto-reply is handled internally - no code needed!
```

### Custom ConsumerUpdate Callback

For custom offset selection, register a callback (low-level API):

```php
<?php

use CrazyGoat\RabbitStream\Response\ConsumerUpdateResponseV1;

$stream->onConsumerUpdate(function (ConsumerUpdateResponseV1 $query): array {
    echo "Becoming active consumer!\n";
    echo "Subscription ID: {$query->getSubscriptionId()}\n";
    
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

use CrazyGoat\RabbitStream\StreamConnection;
use CrazyGoat\RabbitStream\Client\AmqpMessageDecoder;
use CrazyGoat\RabbitStream\Client\Connection;
use CrazyGoat\RabbitStream\Client\OsirisChunkParser;
use CrazyGoat\RabbitStream\Request\SubscribeRequestV1;
use CrazyGoat\RabbitStream\Request\CreditRequestV1;
use CrazyGoat\RabbitStream\Response\DeliverResponseV1;
use CrazyGoat\RabbitStream\VO\OffsetSpec;

require_once __DIR__ . '/vendor/autoload.php';

// Low-level connection, handshaken by the high-level factory
$stream = new StreamConnection('127.0.0.1', 5552);
$stream->connect();
$connection = Connection::create(host: '127.0.0.1', port: 5552, streamConnection: $stream);

// Custom handler for becoming active
$stream->onConsumerUpdate(function ($query) {
    echo "Promoted to active consumer!\n";
    // Start from where we left off (offset type 1 = OFFSET)
    return [1, 0];
});

// Subscribe
$subscribe = new SubscribeRequestV1(
    subscriptionId: 1,
    stream: 'my-stream',
    offsetSpec: OffsetSpec::next(),
    credit: 100
);

$stream->sendMessage($subscribe);
$stream->readMessage(); // SubscribeResponse

// Register message handler
$stream->registerSubscriber(1, function (DeliverResponseV1 $deliver) use ($stream): void {
    $messages = AmqpMessageDecoder::decodeAll(OsirisChunkParser::parse($deliver->getChunkBytes()));
    echo "Received " . count($messages) . " messages\n";
    
    // Process messages
    foreach ($messages as $message) {
        processOrder($message);
    }
    
    // Replenish credits
    $stream->sendMessage(new CreditRequestV1(1, count($messages)));
});

// Run event loop
$stream->readLoop();
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
// Low-level API ($stream is a handshaken StreamConnection)
// Setup phase - use readMessage()
$stream->sendMessage(new DeclarePublisherRequestV1(1, null, 'my-stream'));
$stream->readMessage(); // Wait for DeclarePublisherResponse

// Runtime phase - use readLoop()
$stream->readLoop(maxFrames: 1000, timeout: 60.0);
```

### Error Handling

1. **Always handle `PublishError`** — Messages can fail for various reasons
2. **Monitor credit exhaustion** — If no messages arrive, you may be out of credits
3. **Handle server-initiated close** — The server can close connections anytime

```php
// Low-level API ($stream is a handshaken StreamConnection)
$stream->registerPublisher(
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
3. **Handle timeouts gracefully** — `readMessage()` can time out (it throws `TimeoutException`); `readLoop()` returns silently when its timeout expires

```php
use CrazyGoat\RabbitStream\Exception\TimeoutException;

try {
    // Low-level API: wait up to 30s for the next response frame
    $response = $stream->readMessage(timeout: 30.0);
} catch (TimeoutException $e) {
    echo "No activity for 30 seconds, checking connection...\n";
    echo "Connection still " . ($stream->isConnected() ? 'alive' : 'lost') . "\n";
}
```

## See Also

- [Server Push Frames](../protocol/server-push-frames.md) — Detailed protocol reference
- [Server-Push Dispatch Diagram](../assets/diagrams/server-push-dispatch.md) — Visual flow diagrams
- [Publishing Guide](publishing.md) — Publish confirms and error handling
- [Connection Lifecycle](connection-lifecycle.md) — Connection handshake and heartbeats
- [Consuming Guide](consuming.md) — Message consumption patterns
