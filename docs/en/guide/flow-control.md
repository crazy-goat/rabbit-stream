# Flow Control Guide

This guide covers credit-based flow control, server-push frame handling, and asynchronous processing in RabbitMQ Streams.

## Overview

Flow control in RabbitMQ Streams prevents consumers from being overwhelmed by message delivery. The protocol uses a **credit-based mechanism** where the server tracks how many **chunks** each consumer is allowed to receive (one credit = one chunk, not one message). When credits run out, the server stops sending chunks until the client replenishes them.

This guide explains:
- How credit-based flow control works
- Server-push frames and their handling
- The `readMessage()` transparent dispatch mechanism
- The `readLoop()` for pure async processing
- Heartbeat and ConsumerUpdate handling

## Credit-Based Flow Control

### How Credits Work

RabbitMQ Streams uses a simple but effective credit system, counted in
**chunks**:

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
       │     = 10 future chunk deliveries                  │
       │                                                   │
       │     Deliver [chunk 1: 5000 msgs] ◄── credit 9     │
       │ ◄───────────────────────────────────────────────  │
       │     Deliver [chunk 2: 5000 msgs] ◄── credit 8     │
       │ ◄───────────────────────────────────────────────  │
       │              ...                                  │
       │     Deliver [chunk 10] ◄─────────── credit 0      │
       │ ◄───────────────────────────────────────────────  │
       │                                                   │
       │  Server stops sending (no credits left)           │
       │                                                   │
       │  Credit (credit=5)                                │
       │ ───────────────────────────────────────────────►  │
       │                                                   │
       │     Server adds 5 credits                         │
       │     Deliver [chunk 11] ◄─────────── credit 4      │
       │ ◄───────────────────────────────────────────────  │
```

**Key principle:** One credit equals one **chunk**, not one message. The server
always delivers whole chunks (one Deliver frame = one chunk, atomic on the wire,
from 1 to thousands of messages each), decrements one credit per chunk no matter
how many messages it holds, and stops when credits reach zero.

### Initial Credit and the Adaptive Window

`initialCredit` is the **floor** of the in-flight chunk window. It is passed as
`credit` to `SubscribeRequestV1` (low-level API) and as `initialCredit` to
`Connection::createConsumer()` (high-level), and must be between `1` and
`Consumer::MAX_CREDIT` (`32767`).

It is a floor, not the whole window. Since #500 the `Consumer` also sizes the
window in **bytes**: it measures the chunk sizes it receives and keeps
`ceil(creditWindowBytes / averageChunkSize)` chunks in flight, never fewer than
`initialCredit` and never more than `MAX_CREDIT`:

```
creditTarget = min(MAX_CREDIT, max(initialCredit, ceil(creditWindowBytes / avgChunkBytes)))
```

`creditWindowBytes` defaults to 8 MiB and is what adapts to the producer's
batching — a plain stream fed by one `sendBatch()` producer easily has thousands
of 1 KB messages per chunk, while a super-stream partition fed one message at a
time has a handful. So `initialCredit: 10` with the default window can mean tens
of MB in flight on the first case and a few hundred messages on the second.
Setting `creditWindowBytes: 0` disables the adaptation and pins the window to
exactly `initialCredit` chunks. `Consumer::getCreditTarget()` exposes the current
target for monitoring, and the `maxBufferSize` gate (no credit while too many
unread messages are buffered) still applies on top of the window. Configure it
through the high-level factory, for example:
`$connection->createConsumer('orders', OffsetSpec::first(), maxBufferSize: 100)`.

This guide shows the low-level API — the snippet uses a raw `StreamConnection`
(`$stream`):

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

// Subscribe with an initial credit of 100 chunks
$subscribe = new SubscribeRequestV1(
    subscriptionId: 1,
    stream: 'my-stream',
    offsetSpec: OffsetSpec::next(),
    credit: 100  // initial credit: up to 100 chunks may be sent before any replenishment
);

$stream->sendMessage($subscribe);
$response = $stream->readMessage();
```

> The high-level `Connection::createConsumer()` performs the subscribe and
> manages credits internally — you tune `initialCredit` (the floor) and
> `creditWindowBytes` (the adaptive byte target).

```php
// Default: an 8 MiB adaptive window, at least initialCredit chunks in flight
$consumer = $connection->createConsumer(
    'orders-0',
    OffsetSpec::first(),
    creditWindowBytes: 32 * 1024 * 1024,  // bigger window for small chunks / slow link
);

// Fixed behaviour: exactly initialCredit chunks in flight, adaptation disabled
$consumer = $connection->createConsumer(
    'orders',
    OffsetSpec::first(),
    initialCredit: 10,
    creditWindowBytes: 0,
);
```

### Choosing initialCredit

Because the adaptive window does the real work, `initialCredit` is a **floor**:
the number of chunks the consumer is guaranteed to have in flight before it has
measured anything, and the minimum the target ever falls back to. Choose it from
how much data one chunk is and how fast you consume, not from a message count:

| `initialCredit` | When it fits | Trade-off |
|-----------------|--------------|-----------|
| `1` | Slow consumer, or large chunks (a batching producer, a super-stream partition with big batches) | One chunk in flight until the adaptive window measures the stream: lowest starting memory, most initial round trips |
| `10` (default) | General-purpose floor | Good starting point; the adaptive window raises it automatically |
| `50`-`100` | High throughput, small chunks, low-latency link | More memory; mostly a head start before adaptation kicks in |
| `500`+ | Very high throughput where the window must start high | Highest memory; prefer raising `creditWindowBytes` instead |

The floor matters most when chunks are large: `initialCredit: 100` with
5,000-message chunks means up to ~500,000 messages may arrive before any
replenishment, so a slow consumer with large chunks should keep the floor small.
When chunks are small the adaptive window raises the target on its own, so the
floor mainly sets the first round trip.

**Trade-offs:**
- **Low floor**: lower latency (fewer messages buffered before processing), but
  more round-trips until the adaptive window takes over
- **High floor**: a faster start and better throughput, but a higher memory floor
  and potential for a large backlog

### Credit Replenishment

The server consumes one credit per delivered chunk, so replenish **one credit
per chunk you have processed** — not per message. With the high-level `Consumer`
this is automatic; the low-level API sends `CreditRequestV1` inside your
`registerSubscriber()` deliver callback:

```php
<?php

use CrazyGoat\RabbitStream\Request\CreditRequestV1;
use CrazyGoat\RabbitStream\Client\AmqpMessageDecoder;
use CrazyGoat\RabbitStream\Client\OsirisChunkParser;

// Inside your registerSubscriber() callback, once per delivered chunk:
$messages = AmqpMessageDecoder::decodeAll(OsirisChunkParser::parse($deliver->getChunkBytes()));

foreach ($messages as $message) {
    processMessage($message);
}

// Replenish 1 credit: this chunk is done, invite exactly one more chunk
$stream->sendMessage(new CreditRequestV1(1, 1));
```

> **One credit per chunk, never `count($messages)`.** `count($messages)` is a
> *message* count; sending it would grant the server thousands of extra chunk
> deliveries and let the in-memory backlog run away. Credit is chunk-granular on
> the wire.

**Replenishment Strategies:**

1. **Per chunk** (lowest latency) — send one credit for every chunk as you finish it:
   ```php
   $stream->registerSubscriber(1, function (DeliverResponseV1 $deliver) use ($stream): void {
       $messages = AmqpMessageDecoder::decodeAll(OsirisChunkParser::parse($deliver->getChunkBytes()));
       foreach ($messages as $message) {
           processMessage($message);
       }
       // One chunk in, one chunk out
       $stream->sendMessage(new CreditRequestV1(1, 1));
   });
   ```

2. **Batched** (fewer frames, higher throughput) — count completed chunks and replenish every N:
   ```php
   $chunksDone = 0;
   $stream->registerSubscriber(1, function (DeliverResponseV1 $deliver) use ($stream, &$chunksDone): void {
       $messages = AmqpMessageDecoder::decodeAll(OsirisChunkParser::parse($deliver->getChunkBytes()));
       foreach ($messages as $message) {
           processMessage($message);
       }
       
       // Replenish 10 chunk credits every 10 delivered chunks
       if (++$chunksDone >= 10) {
           $stream->sendMessage(new CreditRequestV1(1, $chunksDone));
           $chunksDone = 0;
       }
   });
   ```

3. **Periodic** (time-based) — flush the accumulated chunk count on a timer:
   ```php
   $lastReplenish = microtime(true);
   $chunksDone = 0;
   
   $stream->registerSubscriber(1, function (DeliverResponseV1 $deliver) use ($stream, &$lastReplenish, &$chunksDone): void {
       $messages = AmqpMessageDecoder::decodeAll(OsirisChunkParser::parse($deliver->getChunkBytes()));
       foreach ($messages as $message) {
           processMessage($message);
       }
       $chunksDone++;
       
       // Replenish every 100ms, one credit per chunk delivered in that window
       if ((microtime(true) - $lastReplenish) > 0.1) {
           $stream->sendMessage(new CreditRequestV1(1, $chunksDone));
           $chunksDone = 0;
           $lastReplenish = microtime(true);
       }
   });
   ```

### Running Out of Credits

When credits reach zero, the server stops sending chunks. This is **not an error** — it's the intended backpressure mechanism.

**What happens:**
1. Server tracks credits per subscription
2. Each `Deliver` frame (one chunk) decrements the credit counter by one
3. When credits reach 0, server stops sending chunks
4. Client must send `CreditRequestV1` to resume delivery

**How to detect:**
- No new `Deliver` frames arrive
- `readLoop()` or `readMessage()` blocks waiting for data
- Other operations (heartbeats, confirms) continue normally

**Recovery:**
Send a `CreditRequestV1` with the number of chunks consumed since the last
replenishment (low-level API):

```php
// One credit per chunk processed
if ($chunksProcessed > 0) {
    $stream->sendMessage(new CreditRequestV1($subscriptionId, $chunksProcessed));
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

For a visual diagram of this flow, see [Server-Push Dispatch Diagram](../../assets/diagrams/server-push-dispatch.md).

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
| `maxFrames` | `?int` | Process up to N frames (dispatched or discarded), then return |
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
       // Replenish one credit for this chunk (credit is chunk-granular)
       $stream->sendMessage(new CreditRequestV1(1, 1));
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

By default, the client automatically replies to `ConsumerUpdate` with offset type 0 (none, keep the current position) and offset 0. A high-level `Consumer` with `singleActiveConsumer: true` instead resumes from its stored offset (or the initial `OffsetSpec` when nothing is stored). The subscribe command itself does not carry a consumer reference in this client (single-active-consumer groups are not supported yet), but a subscription may still receive `ConsumerUpdate` frames:

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
use CrazyGoat\RabbitStream\VO\OffsetSpec;

$stream->onConsumerUpdate(function (ConsumerUpdateResponseV1 $query): array {
    echo "Becoming active consumer!\n";
    echo "Subscription ID: {$query->getSubscriptionId()}\n";
    
    // Return [offsetType, offset]
    // Offset types (see CrazyGoat\RabbitStream\VO\OffsetSpec):
    //   OffsetSpec::TYPE_NONE      = 0 (keep current position)
    //   OffsetSpec::TYPE_FIRST     = 1 (start from beginning)
    //   OffsetSpec::TYPE_LAST      = 2 (start from the last chunk of messages)
    //   OffsetSpec::TYPE_NEXT      = 3 (start at the end of the stream)
    //   OffsetSpec::TYPE_OFFSET    = 4 (start from specific offset)
    //   OffsetSpec::TYPE_TIMESTAMP = 5 (start from timestamp, in milliseconds)

    // Start from offset 100
    return [OffsetSpec::TYPE_OFFSET, 100];
});
```

The returned array is validated when the `ConsumerUpdate` frame is dispatched: it must be a two-element list of ints, or `readLoop()` throws `InvalidArgumentException` rather than sending a malformed reply.

**Offset Types:**

| Type | Value | Description |
|------|-------|-------------|
| `OffsetSpec::TYPE_NONE` | 0 | Keep the current position (valid only in a `ConsumerUpdate` reply) |
| `OffsetSpec::TYPE_FIRST` | 1 | Start from first message in stream |
| `OffsetSpec::TYPE_LAST` | 2 | Start from the last chunk of messages (delivered in full) |
| `OffsetSpec::TYPE_NEXT` | 3 | Start at the next offset to be written (end of the stream) |
| `OffsetSpec::TYPE_OFFSET` | 4 | Start from specific offset (must provide offset, inclusive) |
| `OffsetSpec::TYPE_TIMESTAMP` | 5 | Start at the first chunk with chunk timestamp >= the value, in **milliseconds** since the epoch (chunk-granular) |

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
    // Start from where we left off (TYPE_OFFSET, offset 0).
    return [OffsetSpec::TYPE_OFFSET, 0];
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
    
    // Replenish one credit for this chunk (credit is chunk-granular)
    $stream->sendMessage(new CreditRequestV1(1, 1));
});

// Run event loop
$stream->readLoop();
```

## Best Practices

### Credit Tuning

Credits are **chunks**, so size the window from chunk size and consume speed,
not from a message count.

1. **Let the adaptive window do the work** — the default `creditWindowBytes`
   (8 MiB) already targets a byte volume; only lower `initialCredit` (the floor)
   for a slow consumer or large chunks
2. **Monitor memory usage** — a high `initialCredit` with large chunks means a
   large backlog; use `maxBufferSize` to bound unread messages
3. **Adjust based on processing time**:
   - Fast processing (< 10 ms per chunk): raise `creditWindowBytes` or `initialCredit`
   - Slow processing (> 100 ms per chunk): keep `initialCredit` small, lower `maxBufferSize`
4. **Replenish one credit per chunk** — never `count($messages)`; the high-level
   `Consumer` does this automatically

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
- [Server-Push Dispatch Diagram](../../assets/diagrams/server-push-dispatch.md) — Visual flow diagrams
- [Publishing Guide](publishing.md) — Publish confirms and error handling
- [Connection Lifecycle](connection-lifecycle.md) — Connection handshake and heartbeats
- [Consuming Guide](consuming.md) — Message consumption patterns
