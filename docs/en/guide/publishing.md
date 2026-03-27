# Publishing Guide

This guide covers everything you need to know about publishing messages to RabbitMQ Streams using the RabbitStream PHP client.

## Overview

Publishing in RabbitMQ Streams is asynchronous by design. When you publish a message, the server does not immediately respond. Instead, confirmations arrive asynchronously via the `PublishConfirm` (0x0003) command. This design enables high throughput while ensuring message durability.

## 1. Basic Publishing

### Creating a Producer

The simplest way to publish messages is using the high-level `Producer` API:

```php
<?php

declare(strict_types=1);

use CrazyGoat\RabbitStream\Client\Connection;

require_once __DIR__ . '/vendor/autoload.php';

$connection = Connection::create(
    host: '127.0.0.1',
    port: 5552,
    user: 'guest',
    password: 'guest',
);

// Create a producer for a stream
$producer = $connection->createProducer('my-stream');
```

### Sending a Single Message

Once you have a producer, sending a message is straightforward:

```php
$producer->send('Hello, World!');
```

### Sending a Batch

For better performance, you can send multiple messages in a single batch:

```php
$messages = [
    'Message 1',
    'Message 2',
    'Message 3',
];

$producer->sendBatch($messages);
```

Batch publishing reduces network overhead and improves throughput significantly.

### Closing the Producer

Always close producers when you're done to free up resources:

```php
$producer->close();
```

## 2. Publish Confirms

### Understanding Confirms

RabbitMQ Streams uses asynchronous publish confirmations. When you publish a message:

1. The client sends the `Publish` (0x0002) command
2. The server stores the message durably
3. Later, the server sends `PublishConfirm` (0x0003) with the publishing IDs that were confirmed

This flow is illustrated in the [Publish Flow Diagram](../assets/diagrams/publish-flow.md).

### Using onConfirm Callback

Register a callback to receive confirmation status for each message:

```php
<?php

use CrazyGoat\RabbitStream\Client\ConfirmationStatus;

$producer = $connection->createProducer(
    'my-stream',
    onConfirm: function (ConfirmationStatus $status) {
        if ($status->isConfirmed()) {
            echo "Confirmed: #{$status->getPublishingId()}\n";
        } else {
            echo "Failed: #{$status->getPublishingId()} code={$status->getErrorCode()}\n";
        }
    }
);
```

The `ConfirmationStatus` object provides:
- `isConfirmed()`: Returns `true` if the message was confirmed
- `getPublishingId()`: The publishing ID of the message
- `getErrorCode()`: Error code if the message failed (see [Error Handling](#6-error-handling))

### Blocking Wait for Confirms

To wait for all pending confirmations before continuing:

```php
// Publish some messages
for ($i = 0; $i < 100; $i++) {
    $producer->send("Message {$i}");
}

// Block until all messages are confirmed (or timeout)
$producer->waitForConfirms(timeout: 5.0);
```

The `waitForConfirms()` method:
- Blocks until all pending confirmations are received
- Throws `TimeoutException` if the timeout is reached
- Returns immediately if there are no pending confirms

### Non-blocking Dispatch

For applications that need to process other work while waiting for confirms:

```php
// Process up to 10 server frames without blocking indefinitely
$connection->readLoop(maxFrames: 10, timeout: 1.0);
```

This is useful when you want to:
- Process incoming messages while publishing
- Handle heartbeats
- Dispatch confirms without blocking the main thread

### Batch Confirm Behavior

RabbitMQ batches confirmations for efficiency. A single `PublishConfirm` frame may contain multiple publishing IDs:

```
Publish #1 ──►  Publish #2 ──►  Publish #3 ──►  Publish #4
     │              │              │              │
     └──────────────┴──────────────┴──────────────┘
                        │
                        ▼
            PublishConfirm [1,2,3,4] (batch confirmation)
```

This is normal behavior and improves performance. Your `onConfirm` callback will be called once for each publishing ID in the batch.

## 3. Named Producers & Deduplication

### Creating a Named Producer

Named producers enable message deduplication across reconnections:

```php
$producer = $connection->createProducer(
    'my-stream',
    name: 'my-producer'
);
```

The name must be unique per stream. Multiple connections using the same name will share the same deduplication state.

### How Deduplication Works

Each message published by a named producer has a sequence number (publishing ID). The server tracks the highest confirmed publishing ID for each named producer. If a message with a publishing ID less than or equal to the last confirmed ID is received, it is ignored as a duplicate.

```
Publisher A ──► Publish [seq=1] ──► Server (stored)
Publisher A ──► Publish [seq=2] ──► Server (stored)
Publisher B ──► Publish [seq=1] ──► Server (duplicate - ignored)
Publisher A ──► Publish [seq=3] ──► Server (stored)
```

Deduplication is per-producer-name, not per-publisher-id.

### Querying Sequence After Reconnect

When reconnecting with a named producer, query the last confirmed sequence:

```php
// After reconnect
$lastId = $producer->querySequence();
echo "Last confirmed ID: {$lastId}\n";

// The producer automatically resumes from lastId + 1
```

The `Producer` class automatically handles this on creation - it queries the sequence and sets the next publishing ID accordingly.

### Local Tracking

To track the last publishing ID locally:

```php
$lastId = $producer->getLastPublishingId();
echo "Last published ID: {$lastId}\n";
```

This returns the highest publishing ID that was sent (not necessarily confirmed).

### Deduplication Example

Complete reconnect scenario with deduplication:

```php
<?php

// First connection - publish messages 1-5
$connection1 = Connection::create(host: '127.0.0.1', port: 5552, user: 'guest', password: 'guest');
$producer1 = $connection1->createProducer('my-stream', name: 'order-producer');

for ($i = 1; $i <= 5; $i++) {
    $producer1->send("Order #{$i}");
}
$producer1->waitForConfirms(timeout: 5.0);

// Connection drops, reconnect
$connection1->close();

// Second connection - same producer name
$connection2 = Connection::create(host: '127.0.0.1', port: 5552, user: 'guest', password: 'guest');
$producer2 = $connection2->createProducer('my-stream', name: 'order-producer');

// Automatically resumes from ID 6
// If we retry messages 3-5, they will be deduplicated
$producer2->send("Order #3 (retry)"); // Will be deduplicated (ID 3 <= 5)
$producer2->send("Order #4 (retry)"); // Will be deduplicated (ID 4 <= 5)
$producer2->send("Order #6 (new)");  // Will be stored (ID 6 > 5)

$producer2->waitForConfirms(timeout: 5.0);
$producer2->close();
```

## 4. Publish V2 with Filter Values

### When to Use V2

Use `PublishRequestV2` when you need to include filter values with your messages. Filter values enable consumers to filter messages based on application-defined criteria.

### Using PublishRequestV2

```php
<?php

use CrazyGoat\RabbitStream\Request\PublishRequestV2;
use CrazyGoat\RabbitStream\VO\PublishedMessageV2;

// Create a message with a filter value
$message = new PublishedMessageV2(
    publishingId: 1,
    filterValue: 'customer-123',
    message: 'Order confirmed'
);

// Create the publish request
$request = new PublishRequestV2($publisherId, $message);

// Send via low-level connection
$connection->sendMessage($request);
```

### V1 vs V2 Comparison

| Feature | V1 | V2 |
|---------|-----|-----|
| Basic publishing | ✅ | ✅ |
| Filter values | ❌ | ✅ |
| Publishing ID | ✅ | ✅ |
| Message body | ✅ | ✅ |

For high-level API usage, the `Producer` class handles the protocol version automatically. Use the low-level API directly only when you need filter values.

## 5. Low-Level Publishing

For advanced use cases, you can use the protocol-level API directly.

### Declare Publisher

```php
<?php

use CrazyGoat\RabbitStream\Request\DeclarePublisherRequestV1;

$request = new DeclarePublisherRequestV1(
    publisherId: 1,
    publisherReference: 'my-publisher',  // null for unnamed
    streamName: 'my-stream'
);

$connection->sendMessage($request);
$response = $connection->readMessage();
```

### Publish Messages

```php
<?php

use CrazyGoat\RabbitStream\Request\PublishRequestV1;
use CrazyGoat\RabbitStream\VO\PublishedMessage;

$message = new PublishedMessage(
    publishingId: 1,
    message: 'Hello'
);

$request = new PublishRequestV1($publisherId, $message);
$connection->sendMessage($request);
```

### Handle Confirms

Register callbacks to handle asynchronous confirms:

```php
$connection->registerPublisher(
    $publisherId,
    onConfirm: function (array $publishingIds): void {
        echo "Confirmed: " . implode(', ', $publishingIds) . "\n";
    },
    onError: function (array $errors): void {
        foreach ($errors as $error) {
            echo "Error: #{$error->getPublishingId()} code={$error->getCode()}\n";
        }
    }
);
```

### Delete Publisher

Clean up when done:

```php
<?php

use CrazyGoat\RabbitStream\Request\DeletePublisherRequestV1;

$request = new DeletePublisherRequestV1($publisherId);
$connection->sendMessage($request);
$response = $connection->readMessage();
```

## 6. Error Handling

### Publish Error Response Codes

When publishing fails, you may receive these error codes:

| Code | Name | Description |
|------|------|-------------|
| 0x02 | STREAM_NOT_EXIST | Stream does not exist |
| 0x12 | PUBLISHER_NOT_EXIST | Invalid publisher ID |
| 0x10 | ACCESS_REFUSED | No write permission |
| 0x11 | PRECONDITION_FAILED | Stream precondition failed |

### Timeout Handling

Handle timeouts when waiting for confirms:

```php
<?php

use CrazyGoat\RabbitStream\Exception\TimeoutException;

try {
    $producer->waitForConfirms(timeout: 5.0);
} catch (TimeoutException $e) {
    echo "Some messages were not confirmed: " . $e->getMessage();
    // Decide: retry, log, or fail
}
```

### Publishing to Non-existent Stream

Attempting to publish to a non-existent stream results in a `PublishError`:

```php
<?php

use CrazyGoat\RabbitStream\Enum\ResponseCodeEnum;

$producer = $connection->createProducer('non-existent-stream');
$producer->send('message');

// In the onConfirm callback:
onConfirm: function (ConfirmationStatus $status) {
    if (!$status->isConfirmed()) {
        if ($status->getErrorCode() === ResponseCodeEnum::STREAM_NOT_EXIST->value) {
            echo "Stream does not exist!\n";
        }
    }
}
```

### Error Recovery Strategies

1. **Transient errors** (network issues): Retry with exponential backoff
2. **Permanent errors** (stream not found): Create the stream or fail
3. **Timeout**: Check if messages were actually stored before retrying

## Best Practices

1. **Always use named producers for deduplication in production** - Prevents duplicate messages on reconnect
2. **Set appropriate timeouts for waitForConfirms()** - Balance between reliability and responsiveness
3. **Handle publish errors gracefully** - Log errors and implement retry logic
4. **Close producers when done** - Frees server resources
5. **Use batch publishing for high throughput** - Reduces network overhead
6. **Monitor pending confirms** - Track `waitForConfirms()` timeouts as a health metric
7. **Use filter values (V2) for selective consumers** - Reduces network traffic for consumers

## See Also

- [Basic Producer Example](../examples/basic-producer.md)
- [Named Producer Deduplication](../examples/named-producer-deduplication.md)
- [Low-Level Protocol](../examples/low-level-protocol.md)
- [Publish Flow Diagram](../assets/diagrams/publish-flow.md)
- [Producer API Reference](../api-reference/producer.md)
