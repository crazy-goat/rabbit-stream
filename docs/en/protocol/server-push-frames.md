# Server Push Frames

This document describes asynchronous server-to-client frames in the RabbitMQ Streams protocol.

## Overview

Server-push frames are **asynchronous messages** sent by the server without a corresponding client request. They differ from request/response commands in several ways:

| Characteristic | Request/Response | Server-Push |
|----------------|------------------|-------------|
| **Initiated by** | Client | Server |
| **CorrelationId** | Client-generated, echoed by server | 0 or omitted |
| **Key range** | Response: 0x8000-0xFFFF | Request: 0x0001-0x7FFF |
| **Timing** | Immediate response | Async, event-driven |
| **Handling** | `readMessage()` returns response | `readLoop()` dispatches to callbacks |

## Server-Push Frame Types

### 1. PublishConfirm (0x0003)

Confirms successful message persistence. Sent after messages are written to disk.

**Frame Structure:**
```
Key:        0x0003 (uint16)
Version:    1 (uint16)
publisherId:   uint8
publishingIds[]: Array of uint64
```

**Fields:**
| Field | Type | Description |
|-------|------|-------------|
| `publisherId` | uint8 | Publisher that sent the messages |
| `publishingIds` | uint64[] | Confirmed message IDs |

**When Triggered:**
- After messages are persisted to disk
- May confirm multiple messages in one frame
- Confirmations may be batched

**PHP Implementation:**
```php
use CrazyGoat\RabbitStream\Response\PublishConfirmResponseV1;

// Register the callback before publishing.
$connection->registerPublisher(
    publisherId: 1,
    onConfirm: function (array $publishingIds) {
        echo "Confirmed: " . implode(', ', $publishingIds) . "\n";
        // Update in-flight tracking
        foreach ($publishingIds as $id) {
            unset($this->inFlightMessages[$id]);
        }
    }
);

// Then drive the loop to dispatch server-push frames.
$connection->readLoop(maxFrames: 1);
```

### 2. PublishError (0x0004)

Reports failed message publishing attempts.

**Frame Structure:**
```
Key:        0x0004 (uint16)
Version:    1 (uint16)
publisherId:   uint8
errors[]:      Array of PublishingError
```

**PublishingError Structure:**
```
publishingId:  uint64
errorCode:     uint16
```

**Fields:**
| Field | Type | Description |
|-------|------|-------------|
| `publisherId` | uint8 | Publisher that attempted to send |
| `publishingId` | uint64 | Failed message ID |
| `errorCode` | uint16 | Reason for failure |

**Common Error Codes:**
| Code | Description |
|------|-------------|
| 0x0002 | Stream does not exist |
| 0x0003 | Publisher not found |
| 0x0005 | Publisher closed |

**PHP Implementation:**
```php
use CrazyGoat\RabbitStream\Response\PublishErrorResponseV1;

// Register error callback
$connection->registerPublisher(
    publisherId: 1,
    onConfirm: fn($ids) => null,
    onError: function (array $errors) {
        foreach ($errors as $error) {
            echo "Publish {$error->getPublishingId()} failed: {$error->getCode()}\n";
            // Retry or log error
        }
    }
);
```

### 3. Deliver (0x0008)

Delivers message chunks to consumers.

**Frame v1 Structure:**
```
Key:        0x0008 (uint16)
Version:    1 (uint16)
subscriptionId: uint8
osirisChunk:   bytes
```

**Frame v2 Structure:**
```
Key:        0x0008 (uint16)
Version:    2 (uint16)
subscriptionId: uint8
committedChunkId: uint64
osirisChunk:   bytes
```

**Fields:**
| Field | Type | Description |
|-------|------|-------------|
| `subscriptionId` | uint8 | Target consumer |
| `committedChunkId` | uint64 | (v2 only) Last committed chunk ID |
| `osirisChunk` | bytes | Binary message chunk |

**When Triggered:**
- When new messages are available in the stream
- When consumer has credit and messages exist
- May contain multiple messages per chunk

**PHP Implementation:**
```php
use CrazyGoat\RabbitStream\Client\OsirisChunkParser;
use CrazyGoat\RabbitStream\Request\CreditRequestV1;
use CrazyGoat\RabbitStream\Response\DeliverResponseV1;

// Register subscriber callback
$connection->registerSubscriber(
    subscriptionId: 1,
    onDeliver: function (DeliverResponseV1 $deliver) use ($connection): void {
        // A Deliver frame carries a raw Osiris chunk, not decoded messages.
        [$chunk, $offset, $length] = $deliver->getChunkView();

        foreach (OsirisChunkParser::parseMessages($chunk, offset: $offset, length: $length) as $message) {
            processMessage($message);
        }

        // Replenish one chunk of credit for the chunk just delivered.
        $connection->sendMessage(new CreditRequestV1(
            subscriptionId: 1,
            credit: 1
        ));
    }
);
```

### 4. MetadataUpdate (0x0010)

Notifies clients of stream topology changes.

**Frame Structure:**
```
Key:        0x0010 (uint16)
Version:    1 (uint16)
metadataInfo:  short string
```

**Fields:**
| Field | Type | Description |
|-------|------|-------------|
| `metadataInfo` | short string | Description of the change |

**When Triggered:**
- Stream created or deleted
- Partition added or removed
- Leader/replica changes
- Topology rebalancing

**PHP Implementation:**
```php
use CrazyGoat\RabbitStream\Response\MetadataUpdateResponseV1;
use CrazyGoat\RabbitStream\Request\MetadataRequestV1;

// Handle metadata updates: MetadataUpdate is server-push, so register a handler.
$connection->onMetadataUpdate(function (MetadataUpdateResponseV1 $response) use ($connection): void {
    echo "Topology changed for stream {$response->getStream()} (code {$response->getCode()})\n";

    // Re-query metadata to get latest topology
    $connection->sendMessage(new MetadataRequestV1(
        streams: ['my-stream']
    ));
    $metadata = $connection->readMessage();
    // Update routing tables...
});

// Drive the loop to dispatch server-push frames.
$connection->readLoop();
```

### 5. Heartbeat (0x0017)

Connection keepalive frame. Must be echoed immediately.

**Frame Structure:**
```
Key:        0x0017 (uint16)
Version:    1 (uint16)
CorrelationId: (uint32) - 0 for heartbeats
```

**Behavior:**
1. Server sends heartbeat at intervals (heartbeat/2 seconds)
2. Client must echo identical frame back immediately
3. If no frames received within heartbeat interval, connection is dead

**Automatic Handling:**
```php
// StreamConnection handles heartbeats transparently in readMessage():
// it echoes each one back immediately and your code never sees the frame.

// To observe heartbeats, register a callback (it runs after each echo):
$connection->onHeartbeat(function (): void {
    echo "Heartbeat echoed\n";
});

$connection->readLoop();
```

### 6. ConsumerUpdate (0x001a)

Server queries consumer for offset information during rebalancing.

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
ResponseCode:  uint16
offsetSpec:    OffsetSpec
```

**Fields:**
| Field | Type | Description |
|-------|------|-------------|
| `subscriptionId` | uint8 | Consumer being updated |
| `active` | uint8 | Whether consumer is active in group |
| `offsetSpec` | OffsetSpec | Where to resume consumption |

**When Triggered:**
- Consumer group rebalancing
- Partition assignment changes
- Consumer joins or leaves group

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

### 7. Close (0x0016)

Server-initiated connection close.

**Frame Structure:**
```
Key:        0x0016 (uint16)
Version:    1 (uint16)
ClosingCode:   uint16
ClosingReason: string
```

**Fields:**
| Field | Type | Description |
|-------|------|-------------|
| `ClosingCode` | uint16 | Reason code |
| `ClosingReason` | string | Human-readable reason |

**Common Closing Codes:**
| Code | Description |
|------|-------------|
| 0 | Normal shutdown |
| 1 | Resource error |
| 2 | Forced close by admin |
| 3 | Connection blocked |

**PHP Implementation:**
```php
// Handled internally by StreamConnection
// Connection will throw exception on server close

try {
    $response = $connection->readMessage();
} catch (ConnectionException $e) {
    // The broker closed the connection; there is no reconnect() on the client.
    // Re-establish it with Connection::create() and re-create producers/consumers.
}
```

## Dispatch Mechanism

### How readMessage() Handles Server-Push Frames

```php
public function readMessage(float $timeout = 30.0): object
{
    if ($this->pendingResponses !== []) {
        return array_shift($this->pendingResponses);
    }

    return $this->readResponse($timeout, null);
}

private function readResponse(float $timeout, ?int $expectedCorrelationId): object
{
    while (true) {
        $frame = $this->readFrame($remainingTimeout);
        $key = $frame->peekUint16();

        // Check if server-push frame
        if (isset(self::SERVER_PUSH_KEYS[$key])) {
            // Dispatch to the registered handler for that key
            $this->dispatchServerPush($frame);

            // Continue reading for actual response
            continue;
        }

        // Return the decoded response to the caller
        return ResponseBuilder::fromResponseBuffer($frame);
    }
}
```

### Server-Push Frame Detection

Server-push keys are listed in the `StreamConnection::SERVER_PUSH_KEYS`
constant and looked up by the frame's 2-byte key:

```php
private const SERVER_PUSH_KEYS = [
    KeyEnum::PUBLISH_CONFIRM->value => true,  // 0x0003
    KeyEnum::PUBLISH_ERROR->value   => true,  // 0x0004
    KeyEnum::DELIVER->value         => true,  // 0x0008
    KeyEnum::METADATA_UPDATE->value => true,  // 0x0010
    KeyEnum::CLOSE->value           => true,  // 0x0016
    KeyEnum::HEARTBEAT->value       => true,  // 0x0017
    KeyEnum::CONSUMER_UPDATE->value => true,  // 0x001a
];
```

### Dispatch Table

| Key | Command | Routed By | PHP Class |
|-----|---------|-----------|-----------|
| 0x0003 | PublishConfirm | publisherId | `PublishConfirmResponseV1` |
| 0x0004 | PublishError | publisherId | `PublishErrorResponseV1` |
| 0x0008 | Deliver | subscriptionId | `DeliverResponseV1` |
| 0x0010 | MetadataUpdate | stream name | `MetadataUpdateResponseV1` |
| 0x0017 | Heartbeat | connection | `HeartbeatRequestV1` |
| 0x001a | ConsumerUpdate | subscriptionId | `ConsumerUpdateResponseV1` |
| 0x0016 | Close | connection | (handled internally) |

## Using readLoop() for Pure Async

For applications that want to drive the event loop:

```php
// Register handlers
$connection->registerPublisher(1, 
    onConfirm: fn($ids) => handleConfirm($ids),
    onError: fn($errs) => handleError($errs)
);

$connection->registerSubscriber(1,
    onDeliver: fn($deliver) => handleDeliver($deliver)
);

// Process one server-push frame
$connection->readLoop(maxFrames: 1);

// Or process continuously
while ($running) {
    $connection->readLoop(maxFrames: 10, timeout: 1.0);
}
```

## Key Differences from Request/Response

### 1. Key Values

Server-push frames use **request keys** (0x0001-0x7FFF), NOT response keys:

```
❌ Wrong: PublishConfirm uses 0x8003
✅ Correct: PublishConfirm uses 0x0003

❌ Wrong: Deliver uses 0x8008
✅ Correct: Deliver uses 0x0008
```

### 2. CorrelationId

Server-push frames have no correlation ID (or it's 0):

```
Request/Response:
  Client: CorrelationId = 42
  Server: CorrelationId = 42 (echoed)

Server-Push:
  Server: CorrelationId = 0 (or omitted)
```

### 3. Routing

Server-push frames are routed by entity ID, not correlation:

```
PublishConfirm → routed by publisherId
Deliver        → routed by subscriptionId
MetadataUpdate → routed by stream name
Heartbeat      → handled by connection
```

## Complete Example

```php
use CrazyGoat\RabbitStream\StreamConnection;
use CrazyGoat\RabbitStream\Client\OsirisChunkParser;
use CrazyGoat\RabbitStream\Request\DeclarePublisherRequestV1;
use CrazyGoat\RabbitStream\Request\PublishRequestV1;
use CrazyGoat\RabbitStream\Request\SubscribeRequestV1;
use CrazyGoat\RabbitStream\Request\CreditRequestV1;
use CrazyGoat\RabbitStream\Response\DeliverResponseV1;
use CrazyGoat\RabbitStream\VO\PublishedMessage;
use CrazyGoat\RabbitStream\VO\OffsetSpec;

$connection = new StreamConnection('localhost', 5552);
$connection->connect();

// Track state
$inFlight = [];
$receivedCount = 0;

// Server-push frames are dispatched to callbacks registered by id, not returned
// from readLoop() (which returns the number of frames dispatched).
$connection->registerPublisher(
    1,
    onConfirm: function (array $ids) use (&$inFlight): void {
        foreach ($ids as $id) {
            unset($inFlight[$id]);
            echo "Confirmed: $id\n";
        }
    },
    onError: function (array $errors) use (&$inFlight): void {
        foreach ($errors as $error) {
            unset($inFlight[$error->getPublishingId()]);
            echo "Failed: {$error->getPublishingId()} (code {$error->getCode()})\n";
        }
    }
);

$connection->registerSubscriber(
    1,
    onDeliver: function (DeliverResponseV1 $deliver) use ($connection, &$receivedCount): void {
        [$chunk, $offset, $length] = $deliver->getChunkView();
        $count = 0;
        foreach (OsirisChunkParser::parseMessages($chunk, offset: $offset, length: $length) as $message) {
            $count++;
            $receivedCount++;
        }
        echo "Received $count messages\n";

        // Replenish one chunk of credit for the chunk just delivered.
        $connection->sendMessage(new CreditRequestV1(
            subscriptionId: 1,
            credit: 1
        ));
    }
);

// Setup publisher
$connection->sendMessage(new DeclarePublisherRequestV1(
    publisherId: 1,
    publisherReference: 'producer-1',
    stream: 'my-stream'
));
$connection->readMessage();

// Setup consumer
$connection->sendMessage(new SubscribeRequestV1(
    subscriptionId: 1,
    stream: 'my-stream',
    offsetSpec: OffsetSpec::first(),
    credit: 10
));
$connection->readMessage();

// Event loop
for ($i = 0; $i < 100; $i++) {
    // Publish some messages
    $messages = [];
    for ($j = 0; $j < 5; $j++) {
        $id = $i * 5 + $j;
        $messages[] = new PublishedMessage(
            publishingId: $id,
            message: "Message $id"
        );
        $inFlight[$id] = true;
    }

    $connection->sendMessage(new PublishRequestV1(
        publisherId: 1,
        messages: $messages
    ));

    // Process one server-push frame, dispatching it to the callbacks above.
    $connection->readLoop(maxFrames: 1, timeout: 1.0);
}

echo "In-flight: " . count($inFlight) . "\n";
echo "Received: $receivedCount\n";
```

## Next Steps

- Learn about [Publishing Commands](./publishing-commands.md) - message production
- Learn about [Consuming Commands](./consuming-commands.md) - message consumption
- See [Connection Management](./connection-management-commands.md) - heartbeat handling
