# Consuming Commands

This document provides detailed protocol reference for consuming messages from RabbitMQ Streams.

## Overview

Consuming in RabbitMQ Streams involves:

1. **Subscribing** - Register as a consumer with a subscription ID and starting offset
2. **Receiving deliveries** - Server pushes messages asynchronously
3. **Managing credit** - Flow control to prevent overwhelming the consumer
4. **Storing offsets** - Track consumption progress for resumption
5. **Unsubscribing** - Clean up when done

## Protocol Commands

### 1. Subscribe (0x0007)

Registers a consumer for a specific stream with a starting offset.

**Request Frame Structure:**
```
Key:        0x0007 (uint16)
Version:    1 (uint16)
CorrelationId: (uint32)
subscriptionId: uint8 (0-255, unique per connection)
stream:        string (target stream name)
offsetSpec:    OffsetSpec (variable encoding)
credit:        uint16 (initial credit, in chunks; typically 1-10)
```

**OffsetSpec Encoding:**
```
OffsetSpec Type (uint16) + Optional Value (uint64)

Types:
  0x0001 = FIRST (no value)
  0x0002 = LAST (no value)
  0x0003 = NEXT (no value)
  0x0004 = OFFSET (followed by uint64 offset value)
  0x0005 = TIMESTAMP (followed by uint64 timestamp in milliseconds)
```

**Request Fields:**
| Field | Type | Description |
|-------|------|-------------|
| `subscriptionId` | uint8 | Unique identifier (0-255) for this consumer |
| `stream` | string | Name of the stream to consume from |
| `offsetSpec` | OffsetSpec | Where to start consuming (FIRST, LAST, NEXT, OFFSET, TIMESTAMP) |
| `credit` | uint16 | Initial flow control credit, in **chunks** (1 credit = 1 chunk delivery, not 1 message) |

**OffsetSpec Types:**
| Type | Value | Description |
|------|-------|-------------|
| `FIRST` | 0x0001 | Start from first message in stream |
| `LAST` | 0x0002 | Start from last message (receive only new messages) |
| `NEXT` | 0x0003 | Start after last message (receive only future messages) |
| `OFFSET` | 0x0004 | Start from specific offset (followed by uint64) |
| `TIMESTAMP` | 0x0005 | Start at the first chunk with chunk timestamp >= the value (chunk-granular), followed by uint64 ms |

**Response Frame Structure:**
```
Key:        0x8007 (uint16)
Version:    1 (uint16)
CorrelationId: (uint32) - matches request
ResponseCode:  uint16 (0x0001 = OK)
```

**Common Response Codes:**
| Code | Description |
|------|-------------|
| 0x0001 | OK - Subscription successful |
| 0x0002 | Stream does not exist |
| 0x0003 | Subscription ID already in use |
| 0x0004 | Invalid offset specification |

**PHP Implementation:**
```php
use CrazyGoat\RabbitStream\Request\SubscribeRequestV1;
use CrazyGoat\RabbitStream\Response\SubscribeResponseV1;
use CrazyGoat\RabbitStream\VO\OffsetSpec;

// Subscribe from first message
$stream->sendMessage(new SubscribeRequestV1(
    subscriptionId: 1,
    stream: 'my-stream',
    offsetSpec: OffsetSpec::first(),
    credit: 10
));

$response = $stream->readMessage();
assert($response instanceof SubscribeResponseV1);
// Non-OK ResponseCode throws ProtocolException during deserialization (0x0001 = OK).

// Subscribe from specific offset
$stream->sendMessage(new SubscribeRequestV1(
    subscriptionId: 2,
    stream: 'my-stream',
    offsetSpec: OffsetSpec::offset(1000),
    credit: 5
));
```

### 2. Deliver (0x0008) - Server Push

Server sends this frame containing message chunks. **No correlation ID** - this is a server-push frame.

**Frame v1 Structure:**
```
Key:        0x0008 (uint16)
Version:    1 (uint16)
subscriptionId: uint8
osirisChunk:   bytes (encoded chunk data)
```

**Frame v2 Structure:**
```
Key:        0x0008 (uint16)
Version:    2 (uint16)
subscriptionId: uint8
committedChunkId: uint64 (last committed chunk ID)
osirisChunk:   bytes (encoded chunk data)
```

**Fields:**
| Field | Type | Description |
|-------|------|-------------|
| `subscriptionId` | uint8 | Consumer that should receive these messages |
| `committedChunkId` | uint64 | (v2 only) Last chunk ID committed to disk |
| `osirisChunk` | bytes | Binary chunk containing one or more messages |

**OsirisChunk Format:**
The chunk contains multiple messages in a binary format. See [Osiris Chunk Format](../advanced/osiris-chunk-format.md) for details.

**PHP Implementation:**
```php
use CrazyGoat\RabbitStream\Client\OsirisChunkParser;
use CrazyGoat\RabbitStream\Response\DeliverResponseV1;

// Register callback
$connection->registerSubscriber(
    subscriptionId: 1,
    onDeliver: function (DeliverResponseV1 $deliver) {
        // A Deliver frame carries a raw Osiris chunk, not decoded messages.
        [$chunk, $offset, $length] = $deliver->getChunkView();
        foreach (OsirisChunkParser::parseMessages($chunk, offset: $offset, length: $length) as $message) {
            echo "Received: " . $message->getBody() . "\n";
        }
    }
);

// Then drive the loop to dispatch server-push frames.
$connection->readLoop(maxFrames: 1);
```

### 3. Credit (0x0009)

Adds flow control credit for a consumer. Server stops sending when credit reaches 0.

**Request Frame Structure:**
```
Key:        0x0009 (uint16)
Version:    1 (uint16)
subscriptionId: uint8
credit:        int16 (additional credit to grant)
```

**Request Fields:**
| Field | Type | Description |
|-------|------|-------------|
| `subscriptionId` | uint8 | Consumer to grant credit to |
| `credit` | int16 | Additional **chunks** server can send (1 credit = 1 chunk) |

Credit is **fire-and-forget**: the request carries **no CorrelationId** and the
server normally sends nothing back. The `CreditResponse` frame (key `0x8009`)
is an **error-only** frame — the broker sends it solely when the Credit request
is rejected (for example, crediting an unknown subscription). It carries no
correlation ID and is handled internally by `StreamConnection`; callers never
read it themselves.

**CreditResponse (0x8009) — error only:**
```
Key:        0x8009 (uint16)
Version:    1 (uint16)
ResponseCode:  uint16
subscriptionId: uint8
```

**Flow Control Pattern:**
```
1. Subscribe with initial credit (e.g., 10 chunks)
2. Server sends up to 10 Deliver frames (one chunk each)
3. When the consumer processes a chunk, send Credit to replenish (one credit per chunk)
4. Credit is cumulative - sending 10 twice = 20 total credit
```

> **Cap: 32767.** RabbitMQ decodes the Credit field as a signed 16-bit integer,
> so values of 32768 and above go negative and silently stop the subscription.
> The client caps every credit frame at `Consumer::MAX_CREDIT = 32767`.

**PHP Implementation:**
```php
use CrazyGoat\RabbitStream\Request\CreditRequestV1;

// Grant more credit after processing a chunk. Fire-and-forget: no response to read.
$stream->sendMessage(new CreditRequestV1(
    subscriptionId: 1,
    credit: 10  // Allow 10 more chunks
));
```

### 4. StoreOffset (0x000a) - Fire-and-Forget

Stores the current consumption offset for later resumption. **No response** - fire-and-forget.

**Request Frame Structure:**
```
Key:        0x000a (uint16)
Version:    1 (uint16)
offsetReference: string (consumer group name)
stream:        string
offset:        uint64 (message offset to store)
```

**Request Fields:**
| Field | Type | Description |
|-------|------|-------------|
| `offsetReference` | string | Reference name (e.g., consumer group) |
| `stream` | string | Stream name |
| `offset` | uint64 | Offset value to store |

**No Response:** This command has no response. Offset storage is best-effort.

**PHP Implementation:**
```php
use CrazyGoat\RabbitStream\Request\StoreOffsetRequestV1;

// Store offset after processing a chunk
$stream->sendMessage(new StoreOffsetRequestV1(
    reference: 'my-consumer-group',
    stream: 'my-stream',
    offset: $lastProcessedOffset
));

// No response to wait for
```

### 5. QueryOffset (0x000b)

Retrieves the last stored offset for a reference.

**Request Frame Structure:**
```
Key:        0x000b (uint16)
Version:    1 (uint16)
CorrelationId: (uint32)
offsetReference: string
stream:        string
```

**Request Fields:**
| Field | Type | Description |
|-------|------|-------------|
| `offsetReference` | string | Reference name to query |
| `stream` | string | Stream name |

**Response Frame Structure:**
```
Key:        0x800b (uint16)
Version:    1 (uint16)
CorrelationId: (uint32) - matches request
ResponseCode:  uint16
offset:        uint64 (stored offset value)
```

**Response Fields:**
| Field | Type | Description |
|-------|------|-------------|
| `ResponseCode` | uint16 | `OK` (`0x01`) or `NO_OFFSET` (`0x13`) — the latter is normal when nothing is stored |
| `offset` | uint64 | Last stored offset for this reference (`0` when `NO_OFFSET`) |

**PHP Implementation:**
```php
use CrazyGoat\RabbitStream\Request\QueryOffsetRequestV1;
use CrazyGoat\RabbitStream\Response\QueryOffsetResponseV1;

// Query stored offset
$stream->sendMessage(new QueryOffsetRequestV1(
    reference: 'my-consumer-group',
    stream: 'my-stream'
));

$response = $stream->readMessage();
assert($response instanceof QueryOffsetResponseV1);
$storedOffset = $response->getOffset(); // null when NO_OFFSET (nothing stored)
echo "Resume from offset: " . ($storedOffset ?? 'beginning') . "\n";

// Subscribe from stored offset (or from the beginning when null)
$stream->sendMessage(new SubscribeRequestV1(
    subscriptionId: 1,
    stream: 'my-stream',
    offsetSpec: $storedOffset === null ? OffsetSpec::first() : OffsetSpec::offset($storedOffset),
    credit: 10
));
```

### 6. Unsubscribe (0x000c)

Unregisters a consumer and stops message delivery.

**Request Frame Structure:**
```
Key:        0x000c (uint16)
Version:    1 (uint16)
CorrelationId: (uint32)
subscriptionId: uint8
```

**Request Fields:**
| Field | Type | Description |
|-------|------|-------------|
| `subscriptionId` | uint8 | Consumer ID to unsubscribe |

**Response Frame Structure:**
```
Key:        0x800c (uint16)
Version:    1 (uint16)
CorrelationId: (uint32) - matches request
ResponseCode:  uint16 (0x0001 = OK)
```

**PHP Implementation:**
```php
use CrazyGoat\RabbitStream\Request\UnsubscribeRequestV1;
use CrazyGoat\RabbitStream\Response\UnsubscribeResponseV1;

// Unsubscribe
$stream->sendMessage(new UnsubscribeRequestV1(subscriptionId: 1));

$response = $stream->readMessage();
assert($response instanceof UnsubscribeResponseV1);
// Non-OK ResponseCode throws ProtocolException during deserialization (0x0001 = OK).
```

### 7. ConsumerUpdate (0x001a) - Bidirectional

Server queries the client for offset information during consumer group rebalancing. Client must reply.

**Server Query Frame:**
```
Key:        0x001a (uint16)
Version:    1 (uint16)
CorrelationId: (uint32)
subscriptionId: uint8
active:        uint8 (0 = inactive, 1 = active)
```

**Client Reply Frame:**
```
Key:        0x801a (uint16)
Version:    1 (uint16)
CorrelationId: (uint32) - matches query
ResponseCode:  uint16 (0x0001 = OK)
offsetSpec:    OffsetSpec (where to resume)
```

**Fields:**
| Field | Type | Description |
|-------|------|-------------|
| `subscriptionId` | uint8 | Consumer being updated |
| `active` | uint8 | Whether consumer is active in group |
| `offsetSpec` | OffsetSpec | Offset specification for resumption |

**PHP Implementation:**
```php
use CrazyGoat\RabbitStream\VO\OffsetSpec;

// ConsumerUpdate is handled through a per-subscription callback. The callback
// returns the OffsetSpec the broker should resume from (or null to keep the
// current position); the library builds and sends the ConsumerUpdateReplyV1.
$connection->registerConsumerUpdateHandler(
    1,
    function ($update) use (&$lastProcessedOffset): ?OffsetSpec {
        return OffsetSpec::offset($lastProcessedOffset);
    }
);

// Drive the loop to dispatch the server-push frames.
$connection->readLoop();
```

## Consuming Flow

```
┌─────────┐     Subscribe             ┌─────────┐
│ Client  │ ────────────────────────► │ Server  │
│         │ ◄──────────────────────── │         │
│         │     SubscribeResponse (OK)│         │
│         │                           │         │
│         │ ◄──────────────────────── │         │
│         │     Deliver (server-push) │         │
│         │     (multiple times)      │         │
│         │                           │         │
│         │     Credit (replenish)    │         │
│         │ ────────────────────────► │         │
│         │     (fire-and-forget;     │         │
│         │      reply only on error) │         │
│         │                           │         │
│         │     StoreOffset             │         │
│         │ ────────────────────────► │         │
│         │     (fire-and-forget)     │         │
│         │                           │         │
│         │     Unsubscribe           │         │
│         │ ────────────────────────► │         │
│         │ ◄──────────────────────── │         │
│         │     UnsubscribeResponse   │         │
└─────────┘                           └─────────┘
```

## Complete Consuming Example

```php
use CrazyGoat\RabbitStream\StreamConnection;
use CrazyGoat\RabbitStream\Client\OsirisChunkParser;
use CrazyGoat\RabbitStream\Request\SubscribeRequestV1;
use CrazyGoat\RabbitStream\Request\CreditRequestV1;
use CrazyGoat\RabbitStream\Request\StoreOffsetRequestV1;
use CrazyGoat\RabbitStream\Request\UnsubscribeRequestV1;
use CrazyGoat\RabbitStream\Response\DeliverResponseV1;
use CrazyGoat\RabbitStream\VO\OffsetSpec;

$connection = new StreamConnection('localhost', 5552);
$connection->connect();

$subscriptionId = 1;
$stream = 'my-stream';
$offsetReference = 'my-consumer-group';
$processedCount = 0;
$lastOffset = 0;

// 1. Register a subscriber callback: it runs for every Deliver frame.
$connection->registerSubscriber(
    subscriptionId: $subscriptionId,
    onDeliver: function (DeliverResponseV1 $deliver) use (
        &$processedCount,
        &$lastOffset,
        $connection,
        $subscriptionId
    ): void {
        [$chunk, $offset, $length] = $deliver->getChunkView();

        foreach (OsirisChunkParser::parseMessages($chunk, offset: $offset, length: $length) as $message) {
            processMessage($message);
            $lastOffset = $message->getOffset();
            $processedCount++;
        }

        // 2. Replenish one chunk of credit for the chunk just delivered.
        $connection->sendMessage(new CreditRequestV1(
            subscriptionId: $subscriptionId,
            credit: 1
        ));
    }
);

// 3. Subscribe
$connection->sendMessage(new SubscribeRequestV1(
    subscriptionId: $subscriptionId,
    stream: $stream,
    offsetSpec: OffsetSpec::first(),
    credit: 10
));
$response = $connection->readMessage();
// Non-OK ResponseCode throws ProtocolException during deserialization (0x0001 = OK).

// 4. Drive the loop until 100 messages have been processed.
while ($processedCount < 100) {
    $connection->readLoop(maxFrames: 1);
}

// 5. Store the next offset to consume, then unsubscribe.
$connection->sendMessage(new StoreOffsetRequestV1(
    reference: $offsetReference,
    stream: $stream,
    offset: $lastOffset + 1
));
$connection->sendMessage(new UnsubscribeRequestV1($subscriptionId));
$response = $connection->readMessage();
// Non-OK ResponseCode throws ProtocolException during deserialization (0x0001 = OK).
```

## Next Steps

- Learn about [Stream Management](./stream-management-commands.md) - creating and managing streams
- Explore [Server Push Frames](./server-push-frames.md) - async delivery handling
- See [Offset Tracking](../guide/offset-tracking.md) - consumer guide
