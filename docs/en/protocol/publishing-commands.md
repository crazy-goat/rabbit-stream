# Publishing Commands

This document provides detailed protocol reference for publishing messages in RabbitMQ Streams.

## Overview

Publishing in RabbitMQ Streams involves:

1. **Declaring a publisher** - Register with a unique ID and optional reference
2. **Publishing messages** - Send messages in batches
3. **Receiving confirmations** - Server confirms successful persistence
4. **Handling errors** - Server reports failed publishes
5. **Deleting the publisher** - Clean up when done

## Protocol Commands

### 1. DeclarePublisher (0x0001)

Registers a publisher for a specific stream. Each publisher gets a unique `publisherId` (0-255).

**Request Frame Structure:**
```
Key:        0x0001 (uint16)
Version:    1 (uint16)
CorrelationId: (uint32)
publisherId:   uint8 (0-255, unique per connection)
publisherReference: string (optional, for deduplication)
stream:        string (target stream name)
```

**Request Fields:**
| Field | Type | Description |
|-------|------|-------------|
| `publisherId` | uint8 | Unique identifier (0-255) for this publisher on the connection |
| `publisherReference` | string | Optional name for deduplication (empty string if not used) |
| `stream` | string | Name of the stream to publish to |

**Response Frame Structure:**
```
Key:        0x8001 (uint16)
Version:    1 (uint16)
CorrelationId: (uint32) - matches request
ResponseCode:  uint16 (0x0001 = OK)
```

**Common Response Codes:**
| Code | Description |
|------|-------------|
| 0x0001 | OK - Publisher declared successfully |
| 0x0002 | Stream does not exist |
| 0x0003 | Publisher ID already in use |
| 0x0004 | Publisher reference already in use |

**PHP Implementation:**
```php
use CrazyGoat\RabbitStream\Request\DeclarePublisherRequestV1;
use CrazyGoat\RabbitStream\Response\DeclarePublisherResponseV1;

// Send
$publisherId = 1;
$publisherReference = 'my-producer';  // Optional, for deduplication
$stream = 'my-stream';

$stream->sendMessage(new DeclarePublisherRequestV1(
    publisherId: $publisherId,
    publisherReference: $publisherReference,
    stream: $stream
));

// Receive
$response = $stream->readMessage();
assert($response instanceof DeclarePublisherResponseV1);
assert($response->getResponseCode()->value === 0x0001);  // OK
```

### 2. Publish v1 (0x0002)

Sends messages to a stream. This is a **fire-and-forget** command - no immediate response. Confirmations arrive asynchronously via `PublishConfirm`.

**Request Frame Structure:**
```
Key:        0x0002 (uint16)
Version:    1 (uint16)
publisherId:   uint8 (must match declared publisher)
messages[]:    Array of PublishedMessage
```

**PublishedMessage Structure:**
```
publishingId:  uint64 (client-assigned, used for confirmation matching)
messageBody:   bytes (raw message data)
```

**Request Fields:**
| Field | Type | Description |
|-------|------|-------------|
| `publisherId` | uint8 | Publisher ID from DeclarePublisher |
| `messages` | Array | One or more messages to publish |
| `publishingId` | uint64 | Unique ID per message (for confirmation matching) |
| `messageBody` | bytes | Raw message bytes (AMQP 1.0 encoded) |

**No Response:** This command has no immediate response. Use `PublishConfirm` for confirmation.

**PHP Implementation:**
```php
use CrazyGoat\RabbitStream\Request\PublishRequestV1;
use CrazyGoat\RabbitStream\VO\PublishedMessage;

// Create messages with unique publishing IDs
$messages = [
    new PublishedMessage(
        publishingId: 1,
        messageBody: $encodedMessage1
    ),
    new PublishedMessage(
        publishingId: 2,
        messageBody: $encodedMessage2
    ),
];

// Send (fire-and-forget)
$stream->sendMessage(new PublishRequestV1(
    publisherId: 1,
    messages: $messages
));

// Confirmations arrive asynchronously via PublishConfirm
```

### 3. Publish v2 (0x0002, Version 2)

Same as v1 but with different internal batching structure. Used for compatibility with newer server versions.

**Request Frame Structure:**
```
Key:        0x0002 (uint16)
Version:    2 (uint16)
publisherId:   uint8
messages[]:    Array of PublishedMessageV2
```

**PHP Implementation:**
```php
use CrazyGoat\RabbitStream\Request\PublishRequestV2;
use CrazyGoat\RabbitStream\VO\PublishedMessageV2;

$messages = [
    new PublishedMessageV2(
        publishingId: 1,
        messageBody: $encodedMessage
    ),
];

$stream->sendMessage(new PublishRequestV2(
    publisherId: 1,
    messages: $messages
));
```

### 4. PublishConfirm (0x0003) - Server Push

Server sends this frame to confirm successful message persistence. **No correlation ID** - this is a server-push frame.

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
| `publishingIds` | uint64[] | Array of confirmed publishing IDs |

**Behavior:**
- Server confirms messages in batches
- Multiple messages may be confirmed in a single frame
- Confirmations may arrive out of order relative to publishing
- All messages with publishingId ≤ confirmed ID are confirmed

**PHP Implementation:**
```php
use CrazyGoat\RabbitStream\Response\PublishConfirmResponseV1;

// Register callback before publishing
$connection->registerPublisher(
    publisherId: 1,
    onConfirm: function (array $publishingIds) {
        echo "Confirmed messages: " . implode(', ', $publishingIds) . "\n";
    },
    onError: function (array $errors) {
        // Handle errors
    }
);

// Or handle in readLoop
$response = $connection->readLoop(maxFrames: 1);
if ($response instanceof PublishConfirmResponseV1) {
    $confirmedIds = $response->getPublishingIds();
}
```

### 5. PublishError (0x0004) - Server Push

Server sends this frame when messages fail to publish. **No correlation ID** - this is a server-push frame.

**Frame Structure:**
```
Key:        0x0004 (uint16)
Version:    1 (uint16)
publisherId:   uint8
errors[]:      Array of PublishingError
```

**PublishingError Structure:**
```
publishingId:  uint64 (the failed message)
errorCode:     uint16 (reason for failure)
```

**Common Error Codes:**
| Code | Description |
|------|-------------|
| 0x0002 | Stream does not exist |
| 0x0003 | Publisher not found |
| 0x0004 | Publisher reference already in use |
| 0x0005 | Publisher closed |

**PHP Implementation:**
```php
use CrazyGoat\RabbitStream\Response\PublishErrorResponseV1;
use CrazyGoat\RabbitStream\VO\PublishingError;

// Handle in readLoop
$response = $connection->readLoop(maxFrames: 1);
if ($response instanceof PublishErrorResponseV1) {
    foreach ($response->getErrors() as $error) {
        echo "Message {$error->getPublishingId()} failed with code {$error->getErrorCode()}\n";
    }
}
```

### 6. QueryPublisherSequence (0x0005)

Retrieves the last sequence number stored for a publisher reference. Used for deduplication on reconnect.

**Request Frame Structure:**
```
Key:        0x0005 (uint16)
Version:    1 (uint16)
CorrelationId: (uint32)
publisherReference: string
stream:        string
```

**Request Fields:**
| Field | Type | Description |
|-------|------|-------------|
| `publisherReference` | string | The publisher reference name |
| `stream` | string | Stream name |

**Response Frame Structure:**
```
Key:        0x8005 (uint16)
Version:    1 (uint16)
CorrelationId: (uint32) - matches request
ResponseCode:  uint16
sequence:      uint64 (last confirmed publishing ID)
```

**Response Fields:**
| Field | Type | Description |
|-------|------|-------------|
| `sequence` | uint64 | Last confirmed publishing ID for this reference |

**PHP Implementation:**
```php
use CrazyGoat\RabbitStream\Request\QueryPublisherSequenceRequestV1;
use CrazyGoat\RabbitStream\Response\QueryPublisherSequenceResponseV1;

// Send
$stream->sendMessage(new QueryPublisherSequenceRequestV1(
    publisherReference: 'my-producer',
    stream: 'my-stream'
));

// Receive
$response = $stream->readMessage();
assert($response instanceof QueryPublisherSequenceResponseV1);
$lastSequence = $response->getSequence();
echo "Last confirmed sequence: $lastSequence\n";

// Resume publishing from next sequence
$nextPublishingId = $lastSequence + 1;
```

### 7. DeletePublisher (0x0006)

Unregisters a publisher and frees its resources.

**Request Frame Structure:**
```
Key:        0x0006 (uint16)
Version:    1 (uint16)
CorrelationId: (uint32)
publisherId:   uint8
```

**Request Fields:**
| Field | Type | Description |
|-------|------|-------------|
| `publisherId` | uint8 | Publisher ID to delete |

**Response Frame Structure:**
```
Key:        0x8006 (uint16)
Version:    1 (uint16)
CorrelationId: (uint32) - matches request
ResponseCode:  uint16 (0x0001 = OK)
```

**PHP Implementation:**
```php
use CrazyGoat\RabbitStream\Request\DeletePublisherRequestV1;
use CrazyGoat\RabbitStream\Response\DeletePublisherResponseV1;

// Send
$stream->sendMessage(new DeletePublisherRequestV1(publisherId: 1));

// Receive
$response = $stream->readMessage();
assert($response instanceof DeletePublisherResponseV1);
assert($response->getResponseCode()->value === 0x0001);  // OK
```

## Publishing Flow

```
┌─────────┐     DeclarePublisher      ┌─────────┐
│ Client  │ ────────────────────────► │ Server  │
│         │ ◄──────────────────────── │         │
│         │     DeclarePublisherResponse (OK)     │
│         │                           │         │
│         │     Publish (batch)       │         │
│         │ ────────────────────────► │         │
│         │     (fire-and-forget)     │         │
│         │                           │         │
│         │ ◄──────────────────────── │         │
│         │     PublishConfirm        │         │
│         │     (async, server-push)  │         │
│         │                           │         │
│         │     DeletePublisher       │         │
│         │ ────────────────────────► │         │
│         │ ◄──────────────────────── │         │
│         │     DeletePublisherResponse (OK)    │
└─────────┘                           └─────────┘
```

## Complete Publishing Example

```php
use CrazyGoat\RabbitStream\StreamConnection;
use CrazyGoat\RabbitStream\Request\DeclarePublisherRequestV1;
use CrazyGoat\RabbitStream\Request\PublishRequestV1;
use CrazyGoat\RabbitStream\Request\DeletePublisherRequestV1;
use CrazyGoat\RabbitStream\VO\PublishedMessage;

$connection = new StreamConnection('localhost', 5552);
$connection->connect();

$publisherId = 1;
$stream = 'my-stream';

// 1. Declare publisher
$connection->sendMessage(new DeclarePublisherRequestV1(
    publisherId: $publisherId,
    publisherReference: 'my-producer',
    stream: $stream
));
$response = $connection->readMessage();
assert($response->getResponseCode()->value === 0x0001);

// 2. Publish messages
$messages = [];
for ($i = 1; $i <= 10; $i++) {
    $messages[] = new PublishedMessage(
        publishingId: $i,
        messageBody: encodeMessage("Message $i")
    );
}

$connection->sendMessage(new PublishRequestV1(
    publisherId: $publisherId,
    messages: $messages
));

// 3. Wait for confirmations
$connection->readLoop(maxFrames: 1);  // Handles PublishConfirm

// 4. Delete publisher
$connection->sendMessage(new DeletePublisherRequestV1($publisherId));
$response = $connection->readMessage();
assert($response->getResponseCode()->value === 0x0001);
```

## Next Steps

- Learn about [Consuming Commands](./consuming-commands.md) - message consumption
- Explore [Server Push Frames](./server-push-frames.md) - async confirmation handling
- See [Connection & Authentication](./connection-auth.md) - handshake details
