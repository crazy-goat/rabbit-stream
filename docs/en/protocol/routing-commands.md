# Routing Commands

This document provides detailed protocol reference for Super Stream routing in RabbitMQ Streams.

## Overview

Super Streams provide partitioned message streams for horizontal scalability. Routing commands enable:

- **Route resolution** - Determine which partition handles a routing key
- **Partition discovery** - List all partitions of a super stream
- **Client-side routing** - Publishers can route messages to appropriate partitions

## Super Streams

A Super Stream is a logical stream composed of multiple partition streams:

```
┌─────────────────────────────────────────────────────────────────────────────┐
│                        Super Stream: "orders"                               │
├─────────────────────────────────────────────────────────────────────────────┤
│  Partition 1        Partition 2        Partition 3                          │
│  "orders-1"         "orders-2"         "orders-3"                           │
│  (binding key: 1)   (binding key: 2)   (binding key: 3)                     │
└─────────────────────────────────────────────────────────────────────────────┘
```

## Protocol Commands

### 1. Route (0x0018)

Resolves a routing key to the appropriate partition stream(s).

**Request Frame Structure:**
```
Key:        0x0018 (uint16)
Version:    1 (uint16)
CorrelationId: (uint32)
routingKey:    string (key to route)
superStream:   string (super stream name)
```

**Request Fields:**
| Field | Type | Description |
|-------|------|-------------|
| `routingKey` | string | The routing key to resolve (e.g., "user-123") |
| `superStream` | string | Name of the super stream |

**Response Frame Structure:**
```
Key:        0x8018 (uint16)
Version:    1 (uint16)
CorrelationId: (uint32) - matches request
ResponseCode:  uint16 (0x0001 = OK)
streams[]:     Array<string> (matching partition streams)
```

**Response Fields:**
| Field | Type | Description |
|-------|------|-------------|
| `streams` | string[] | Names of partition streams that match the routing key |

**Routing Behavior:**
- Returns one or more partition streams based on the super stream's routing strategy
- Hash-based routing typically returns exactly one partition
- Fan-out routing may return multiple partitions

**PHP Implementation:**
```php
use CrazyGoat\RabbitStream\Request\RouteRequestV1;
use CrazyGoat\RabbitStream\Response\RouteResponseV1;

// Route a message to the appropriate partition
$routingKey = 'user-' . ($userId % 3 + 1);  // Simple hash

$stream->sendMessage(new RouteRequestV1(
    routingKey: $routingKey,
    superStream: 'orders-super-stream'
));

$response = $stream->readMessage();
assert($response instanceof RouteResponseV1);
assert($response->getResponseCode()->value === 0x0001);

// Get target partition(s)
$partitionStreams = $response->getStreams();
foreach ($partitionStreams as $partitionStream) {
    echo "Route to partition: $partitionStream\n";
}

// Publish to the resolved partition
$targetPartition = $partitionStreams[0];
```

### 2. Partitions (0x0019)

Retrieves all partition streams for a super stream.

**Request Frame Structure:**
```
Key:        0x0019 (uint16)
Version:    1 (uint16)
CorrelationId: (uint32)
superStream:   string
```

**Request Fields:**
| Field | Type | Description |
|-------|------|-------------|
| `superStream` | string | Name of the super stream |

**Response Frame Structure:**
```
Key:        0x8019 (uint16)
Version:    1 (uint16)
CorrelationId: (uint32) - matches request
ResponseCode:  uint16 (0x0001 = OK)
streams[]:     Array<string> (all partition streams)
```

**Response Fields:**
| Field | Type | Description |
|-------|------|-------------|
| `streams` | string[] | Names of all partition streams in order |

**PHP Implementation:**
```php
use CrazyGoat\RabbitStream\Request\PartitionsRequestV1;
use CrazyGoat\RabbitStream\Response\PartitionsResponseV1;

// Get all partitions
$stream->sendMessage(new PartitionsRequestV1(
    superStream: 'orders-super-stream'
));

$response = $stream->readMessage();
assert($response instanceof PartitionsResponseV1);
assert($response->getResponseCode()->value === 0x0001);

// List all partitions
$partitions = $response->getStreams();
echo "Super stream has " . count($partitions) . " partitions:\n";
foreach ($partitions as $index => $partition) {
    echo "  [$index] $partition\n";
}
```

## Routing Strategies

### Hash-Based Routing

Messages are routed based on a hash of the routing key:

```php
// Consistent hashing example
function getPartitionForKey(string $key, int $partitionCount): int {
    return (crc32($key) % $partitionCount) + 1;
}

$userId = 12345;
$routingKey = 'user-' . $userId;
$partitionNumber = getPartitionForKey($routingKey, 3);
$targetStream = "orders-super-stream-{$partitionNumber}";
```

### Direct Routing

Routing key directly maps to partition:

```php
// Direct mapping
$routingKey = 'orders.2';  // Routes to partition 2
```

### Fan-Out Routing

Message goes to all partitions (broadcast):

```php
// Special routing key triggers fan-out
$routingKey = '*';  // All partitions
```

## Complete Routing Example

```php
use CrazyGoat\RabbitStream\StreamConnection;
use CrazyGoat\RabbitStream\Request\PartitionsRequestV1;
use CrazyGoat\RabbitStream\Request\RouteRequestV1;
use CrazyGoat\RabbitStream\Request\CreateSuperStreamRequestV1;
use CrazyGoat\RabbitStream\Request\DeclarePublisherRequestV1;
use CrazyGoat\RabbitStream\Request\PublishRequestV1;
use CrazyGoat\RabbitStream\VO\PublishedMessage;

$connection = new StreamConnection('localhost', 5552);
$connection->connect();

$superStream = 'orders-super-stream';

// 1. Create super stream with 3 partitions
$connection->sendMessage(new CreateSuperStreamRequestV1(
    superStream: $superStream,
    partitions: 3,
    bindingKeys: ['orders.1', 'orders.2', 'orders.3']
));
$response = $connection->readMessage();
assert($response->getResponseCode()->value === 0x0001);

// 2. Get all partitions
$connection->sendMessage(new PartitionsRequestV1(superStream: $superStream));
$partitionsResponse = $connection->readMessage();
$partitions = $partitionsResponse->getStreams();
echo "Available partitions: " . implode(', ', $partitions) . "\n";

// 3. Declare publishers for each partition
$publisherId = 1;
foreach ($partitions as $partition) {
    $connection->sendMessage(new DeclarePublisherRequestV1(
        publisherId: $publisherId,
        publisherReference: "producer-{$partition}",
        stream: $partition
    ));
    $response = $connection->readMessage();
    assert($response->getResponseCode()->value === 0x0001);
    $publisherId++;
}

// 4. Route and publish messages
function publishToSuperStream(
    $connection,
    string $superStream,
    string $routingKey,
    array $partitions,
    string $messageBody
): void {
    // Route to determine partition
    $connection->sendMessage(new RouteRequestV1(
        routingKey: $routingKey,
        superStream: $superStream
    ));
    $routeResponse = $connection->readMessage();
    $targetPartitions = $routeResponse->getStreams();
    
    // Publish to each resolved partition
    foreach ($targetPartitions as $partitionStream) {
        // Find publisher ID for this partition
        $publisherId = array_search($partitionStream, $partitions) + 1;
        
        $connection->sendMessage(new PublishRequestV1(
            publisherId: $publisherId,
            messages: [
                new PublishedMessage(
                    publishingId: time(),
                    messageBody: $messageBody
                )
            ]
        ));
    }
}

// Publish order for user 123 (routes based on user ID)
$userId = 123;
$routingKey = "user-{$userId}";
publishToSuperStream(
    $connection,
    $superStream,
    $routingKey,
    $partitions,
    encodeOrderMessage(['user_id' => $userId, 'items' => [...]])
);
```

## Consumer Routing

Consumers can subscribe to specific partitions or all partitions:

```php
use CrazyGoat\RabbitStream\Request\SubscribeRequestV1;
use CrazyGoat\RabbitStream\VO\OffsetSpec;

// Subscribe to all partitions (parallel consumers)
$subscriptionId = 1;
foreach ($partitions as $partition) {
    $connection->sendMessage(new SubscribeRequestV1(
        subscriptionId: $subscriptionId,
        stream: $partition,
        offsetSpec: OffsetSpec::last(),
        credit: 10
    ));
    $response = $connection->readMessage();
    assert($response->getResponseCode()->value === 0x0001);
    $subscriptionId++;
}

// Or subscribe to specific partition only
$connection->sendMessage(new SubscribeRequestV1(
    subscriptionId: 1,
    stream: 'orders-super-stream-1',
    offsetSpec: OffsetSpec::first(),
    credit: 10
));
```

## Routing Flow Diagram

```
┌─────────────────────────────────────────────────────────────────────────────┐
│                     Super Stream Publishing Flow                            │
├─────────────────────────────────────────────────────────────────────────────┤
│                                                                             │
│  Publisher                                                                  │
│     │                                                                       │
│     │ 1. PartitionsRequest                                                  │
│     │ ───────────────────────► RabbitMQ                                     │
│     │ ◄───────────────────────                                              │
│     │    PartitionsResponse                                                 │
│     │    [orders-1, orders-2, orders-3]                                     │
│     │                                                                       │
│     │ 2. DeclarePublisher (for each partition)                              │
│     │ ───────────────────────►                                              │
│     │                                                                       │
│     │ 3. RouteRequest (routingKey="user-123")                             │
│     │ ───────────────────────►                                              │
│     │ ◄───────────────────────                                              │
│     │    RouteResponse [orders-2]                                           │
│     │                                                                       │
│     │ 4. PublishRequest (to orders-2)                                       │
│     │ ───────────────────────►                                              │
│     │                                                                       │
│     ▼                                                                       │
│                                                                             │
└─────────────────────────────────────────────────────────────────────────────┘
```

## Next Steps

- Learn about [Stream Management](./stream-management-commands.md) - creating super streams
- Explore [Publishing Commands](./publishing-commands.md) - sending messages
- Explore [Consuming Commands](./consuming-commands.md) - receiving messages
- See [Super Streams Guide](../guide/super-streams.md) - practical guide
