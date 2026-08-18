# Super Streams

This guide covers working with RabbitMQ Super Streams — partitioned streams that enable horizontal scaling of message processing.

## Overview

Super Streams are a RabbitMQ Streams feature that allows you to partition a logical stream into multiple physical streams (partitions). This enables:

- **Horizontal scaling**: Distribute messages across multiple partitions for parallel processing
- **Increased throughput**: Multiple consumers can read from different partitions simultaneously
- **Ordered processing within partitions**: Messages within a single partition maintain their order
- **Flexible routing**: Route messages to specific partitions based on routing keys

### When to Use Super Streams

Use Super Streams when:
- You need to process more messages than a single stream can handle
- You want to parallelize consumption across multiple consumers
- You need to maintain ordering within logical groups (e.g., per-customer ordering)
- Your workload exceeds the throughput limits of a single stream

### Super Stream Architecture

```
┌─────────────────────────────────────────────────────────────┐
│                    Super Stream: orders                     │
│                    (Logical Stream)                         │
└─────────────────────────────────────────────────────────────┘
                              │
          ┌───────────────────┼───────────────────┐
          │                   │                   │
          ▼                   ▼                   ▼
┌─────────────────┐  ┌─────────────────┐  ┌─────────────────┐
│  Partition 0    │  │  Partition 1    │  │  Partition 2    │
│  orders-0       │  │  orders-1       │  │  orders-2       │
│                 │  │                 │  │                 │
│  Binding Key: 0 │  │  Binding Key: 1 │  │  Binding Key: 2 │
└─────────────────┘  └─────────────────┘  └─────────────────┘
          │                   │                   │
          └───────────────────┼───────────────────┘
                              │
                    ┌─────────┴─────────┐
                    │   Exchange        │
                    │   (routes based   │
                    │   on binding key) │
                    └─────────────────┘
```

**Key Concepts:**

- **Super Stream**: The logical stream that clients interact with (e.g., `orders`)
- **Partition**: A physical stream that stores a subset of messages (e.g., `orders-0`, `orders-1`)
- **Binding Key**: A string that determines which partition receives a message
- **Routing Key**: The key used when publishing to determine the target partition

## Creating Super Streams

### Basic Super Stream Creation

Create a super stream with multiple partitions:

```php
use CrazyGoat\RabbitStream\Client\Connection;

$connection = Connection::create();

$superStreamName = 'orders';
$partition1 = 'orders-0';
$partition2 = 'orders-1';
$partition3 = 'orders-2';

$connection->createSuperStream(
    $superStreamName,
    [$partition1, $partition2, $partition3],
    ['0', '1', '2']
);
echo "Super stream created successfully\n";
```

### Super Stream with Arguments

Configure partitions with retention policies:

```php
$connection->createSuperStream(
    'events',
    ['events-0', 'events-1', 'events-2', 'events-3'],
    ['0', '1', '2', '3'],
    [
        'max-length-bytes' => '1073741824',     // 1 GB per partition
        'max-age' => '24h',                      // 24 hour retention
        'stream-max-segment-size-bytes' => '500000000',
    ]
);
```

### Available Arguments

| Argument | Type | Description | Example |
|----------|------|-------------|---------|
| `max-length-bytes` | string | Maximum total size per partition | `'1000000000'` (1 GB) |
| `max-age` | string | Maximum age of messages | `'24h'`, `'7d'` |
| `stream-max-segment-size-bytes` | string | Maximum size of segment files | `'500000000'` (500 MB) |
| `initial-cluster-size` | string | Initial number of replicas | `'3'` |

### Error Handling: SUPER_STREAM_ALREADY_EXISTS

Handle the case when a super stream already exists — the high-level API throws `ProtocolException` when the server answers with an error code:

```php
use CrazyGoat\RabbitStream\Exception\ProtocolException;
use CrazyGoat\RabbitStream\Enum\ResponseCodeEnum;

try {
    $connection->createSuperStream(
        $superStreamName,
        [$partition1, $partition2],
        ['key1', 'key2']
    );
    echo "Super stream created\n";
} catch (ProtocolException $e) {
    if ($e->getResponseCode() === ResponseCodeEnum::STREAM_ALREADY_EXISTS) {
        echo "Super stream already exists - continuing\n";
    } else {
        throw $e;
    }
}
```

## Deleting Super Streams

### Basic Deletion

Delete a super stream and all its partitions:

```php
$connection->deleteSuperStream('orders');
echo "Super stream deleted\n";
```

### Error Handling: SUPER_STREAM_NOT_EXIST

Handle the case when the super stream doesn't exist:

```php
use CrazyGoat\RabbitStream\Exception\ProtocolException;
use CrazyGoat\RabbitStream\Enum\ResponseCodeEnum;

try {
    $connection->deleteSuperStream($superStreamName);
    echo "Super stream deleted\n";
} catch (ProtocolException $e) {
    if ($e->getResponseCode() === ResponseCodeEnum::STREAM_NOT_EXIST) {
        echo "Super stream does not exist - nothing to delete\n";
    } else {
        throw $e;
    }
}
```

## Listing Partitions

### Querying Partitions

There is no high-level wrapper for listing partitions yet — use a raw `StreamConnection` (`$stream`), i.e. the low-level API. Create it and complete the handshake on it first:

```php
use CrazyGoat\RabbitStream\StreamConnection;
use CrazyGoat\RabbitStream\Client\Connection;
use CrazyGoat\RabbitStream\Request\PartitionsRequestV1;
use CrazyGoat\RabbitStream\Response\PartitionsResponseV1;

// Low-level connection, handshaken by the high-level factory
$stream = new StreamConnection($host, $port);
$stream->connect();
$connection = Connection::create(host: $host, port: $port, streamConnection: $stream);

$stream->sendMessage(new PartitionsRequestV1('orders'));
$response = $stream->readMessage();

if ($response instanceof PartitionsResponseV1) {
    $partitions = $response->getStreams();
    echo "Partitions: " . implode(', ', $partitions) . "\n";
    // Output: Partitions: orders-0, orders-1, orders-2
}
```

### Use Cases for Partition Queries

**Discovering partitions at startup** (low-level, `$stream` is the handshaken `StreamConnection`):

```php
function discoverPartitions(StreamConnection $stream, string $superStream): array
{
    $stream->sendMessage(new PartitionsRequestV1($superStream));
    $response = $stream->readMessage();
    
    if ($response instanceof PartitionsResponseV1) {
        return $response->getStreams();
    }
    
    throw new \Exception("Failed to get partitions");
}

// Use discovered partitions to create consumers (high-level)
$partitions = discoverPartitions($stream, 'orders');
foreach ($partitions as $partition) {
    $consumer = $connection->createConsumer($partition, OffsetSpec::first());
    echo "Creating consumer for: $partition\n";
}
```

**Verifying super stream existence:**

```php
function superStreamExists(StreamConnection $stream, string $superStream): bool
{
    try {
        $stream->sendMessage(new PartitionsRequestV1($superStream));
        $response = $stream->readMessage();
        return $response instanceof PartitionsResponseV1;
    } catch (\Exception $e) {
        return false;
    }
}
```

## Routing

### Understanding Routing

Routing determines which partition receives a message based on the routing key. The routing key is matched against binding keys using exchange routing logic.

### Basic Routing Query

Query which partition(s) a routing key maps to:

```php
$routingKey = 'customer-123';
$superStream = 'orders';

$streams = $connection->route($routingKey, $superStream);
echo "Routing key '$routingKey' maps to: " . implode(', ', $streams) . "\n";
```

### Routing Strategies

**Hash-based routing** (most common):

```php
// Create super stream with numeric binding keys
$partitions = ['orders-0', 'orders-1', 'orders-2'];
$bindingKeys = ['0', '1', '2'];

// Route based on hash of routing key
function getPartitionForKey(string $routingKey, int $partitionCount): string
{
    $hash = crc32($routingKey);
    $partition = $hash % $partitionCount;
    return "orders-{$partition}";
}

// Example usage
$customerId = 'customer-123';
$partition = getPartitionForKey($customerId, 3);
echo "Customer $customerId -> $partition\n";
```

**Direct routing** (explicit mapping):

```php
// Create super stream with specific binding keys
$partitions = ['orders-us', 'orders-eu', 'orders-asia'];
$bindingKeys = ['us', 'eu', 'asia'];

// Route based on region
$region = 'eu'; // From message context
$streams = $connection->route($region, 'orders');
echo "Region '$region' maps to: " . implode(', ', $streams) . "\n";
```

**Range-based routing**:

```php
// Create super stream with range binding keys
$partitions = ['orders-low', 'orders-mid', 'orders-high'];
$bindingKeys = ['0-1000', '1001-5000', '5001-*'];

// Route based on order value
function getPartitionForOrderValue(float $value): string
{
    if ($value <= 1000) {
        return 'orders-low';
    } elseif ($value <= 5000) {
        return 'orders-mid';
    } else {
        return 'orders-high';
    }
}
```

### Complete Routing Workflow

```php
function routeAndPublish(Connection $connection, string $superStream, string $routingKey, string $message): void
{
    // 1. Query which partition to use
    $streams = $connection->route($routingKey, $superStream);
    if (empty($streams)) {
        throw new \Exception("No partition found for routing key");
    }
    
    $targetPartition = $streams[0];
    
    // 2. Publish to the specific partition
    $producer = $connection->createProducer($targetPartition);
    $producer->send($message);
    $producer->waitForConfirms(timeout: 5.0);
    $producer->close();
}

// Usage
routeAndPublish($connection, 'orders', 'customer-123', 'Order #12345');
```

## Publishing to Super Streams

### High-Level Publishing Pattern

The recommended approach for publishing to super streams:

```php
use CrazyGoat\RabbitStream\Client\Connection;

$connection = Connection::create(
    host: '127.0.0.1',
    port: 5552,
    user: 'guest',
    password: 'guest',
);

// Create producers for each partition
$partitions = ['orders-0', 'orders-1', 'orders-2'];
$producers = [];

foreach ($partitions as $partition) {
    $producers[$partition] = $connection->createProducer($partition);
}

// Route and publish
function publishToSuperStream($producers, string $superStream, string $routingKey, string $message): void
{
    // Determine partition using hash
    $partitionCount = count($producers);
    $hash = crc32($routingKey);
    $partitionIndex = $hash % $partitionCount;
    $partitionName = "orders-{$partitionIndex}";
    
    // Publish to the selected partition
    $producers[$partitionName]->send($message);
}

// Publish messages
for ($i = 0; $i < 100; $i++) {
    $customerId = "customer-" . ($i % 10); // 10 different customers
    $message = json_encode([
        'order_id' => $i,
        'customer_id' => $customerId,
        'amount' => rand(10, 1000),
    ]);
    
    publishToSuperStream($producers, 'orders', $customerId, $message);
}

// Wait for all confirms
foreach ($producers as $producer) {
    $producer->waitForConfirms(timeout: 5.0);
    $producer->close();
}
```

### Consistent Hashing for Even Distribution

For better distribution across partitions:

```php
function consistentHash(string $key, int $partitions): int
{
    $hash = crc32($key);
    return $hash % $partitions;
}

// This ensures the same key always maps to the same partition
$customerId = 'customer-123';
$partitionIndex = consistentHash($customerId, 3);
echo "Customer $customerId always goes to partition $partitionIndex\n";
```

### Batch Publishing to Partitions

For high-throughput scenarios:

```php
// Buffer messages per partition
$buffers = array_fill_keys($partitions, []);

// Accumulate messages
foreach ($messages as $message) {
    $partitionIndex = consistentHash($message['customer_id'], 3);
    $partitionName = "orders-{$partitionIndex}";
    $buffers[$partitionName][] = $message;
}

// Batch publish to each partition
foreach ($buffers as $partition => $batch) {
    if (!empty($batch)) {
        $producers[$partition]->sendBatch($batch);
    }
}
```

## Consuming from Super Streams

### Basic Partition Consumption

Consume from individual partitions — the high-level `Consumer` handles subscribe and credit management internally:

```php
use CrazyGoat\RabbitStream\VO\OffsetSpec;

$partitions = ['orders-0', 'orders-1', 'orders-2'];
$consumers = [];

foreach ($partitions as $partition) {
    $consumers[$partition] = $connection->createConsumer($partition, OffsetSpec::first());
    echo "Created consumer for $partition\n";
}

// Read from every partition
while (true) {
    foreach ($consumers as $partition => $consumer) {
        foreach ($consumer->read(timeout: 5.0) as $message) {
            echo "[$partition] {$message->getBody()}\n";
        }
    }
}
```

### Consumer Groups with Single Active Consumer

Single Active Consumer is a RabbitMQ Streams feature for coordinated consumption: all consumers in a group share a reference, the server activates exactly one of them and reassigns the stream when the active consumer disconnects.

> Note: the current client cannot subscribe with a consumer/group reference
> yet (the protocol field is not exposed by `SubscribeRequestV1`), so
> group-based coordination is not available. Without a group reference each
> consumer reads the partition independently, which is fine for parallel
> processing:

```php
use CrazyGoat\RabbitStream\VO\OffsetSpec;

// Each consumer in the group uses the same consumer name
$consumerName = 'order-processor';

foreach ($partitions as $partition) {
    $consumer = $connection->createConsumer($partition, OffsetSpec::first(), name: $consumerName);
}
```

### Handling ConsumerUpdate for Partition Assignment

When the server sends `ConsumerUpdate` frames (e.g. to reassign a single-active-consumer subscription), the client auto-replies and lets you hook the decision via `onConsumerUpdate()` on a raw `StreamConnection` — low-level API:

```php
use CrazyGoat\RabbitStream\Response\ConsumerUpdateResponseV1;

// Register callback for ConsumerUpdate (returns [offsetType, offset])
$stream->onConsumerUpdate(function (ConsumerUpdateResponseV1 $query): array {
    if ($query->getSubscriptionId() === 1) {
        echo "Consumer became active for subscription 1\n";
        // Start processing messages from the beginning
        return [0, 0];
    }
    return [1, 0]; // OFFSET, start from 0
});

// Process messages in a loop (low-level API)
while (true) {
    $stream->readLoop(maxFrames: 10, timeout: 1.0);
}
```

### Parallel Consumer Example

```php
use CrazyGoat\RabbitStream\Client\Connection;
use CrazyGoat\RabbitStream\VO\OffsetSpec;

class PartitionConsumer
{
    private \CrazyGoat\RabbitStream\Client\Consumer $consumer;
    private string $partition;
    
    public function __construct(Connection $connection, string $partition)
    {
        $this->partition = $partition;
        $this->consumer = $connection->createConsumer($partition, OffsetSpec::first());
    }
    
    public function read(): void
    {
        foreach ($this->consumer->read(timeout: 1.0) as $message) {
            $data = json_decode($message->getBody(), true);
            echo "Processing from {$this->partition}: Order #{$data['order_id']}\n";
            
            // Process the message
            $this->processOrder($data);
        }
    }
    
    private function processOrder(array $order): void
    {
        // Business logic here
    }
}

// Create consumers for each partition
$consumers = [];

foreach ($partitions as $partition) {
    $consumers[] = new PartitionConsumer($connection, $partition);
}

// Run event loop
while (true) {
    foreach ($consumers as $consumer) {
        $consumer->read();
    }
}
```

## Error Handling

### Common Super Stream Errors

| Code | Name | Description | Handling Strategy |
|------|------|-------------|-------------------|
| 0x02 | STREAM_NOT_EXIST | Super stream or partition doesn't exist | Create stream or fail |
| 0x03 | STREAM_ALREADY_EXISTS | Super stream already exists | Continue or delete/recreate |
| 0x10 | ACCESS_REFUSED | No permission | Check credentials |
| 0x11 | PRECONDITION_FAILED | Invalid arguments | Validate parameters |

### Error Handling Pattern

```php
use CrazyGoat\RabbitStream\Enum\ResponseCodeEnum;

function handleSuperStreamError(ResponseCodeEnum $code, string $operation): void
{
    match ($code) {
        ResponseCodeEnum::OK => null,
        ResponseCodeEnum::STREAM_NOT_EXIST => 
            throw new \RuntimeException("Super stream not found during $operation"),
        ResponseCodeEnum::STREAM_ALREADY_EXISTS => 
            throw new \RuntimeException("Super stream already exists during $operation"),
        ResponseCodeEnum::ACCESS_REFUSED => 
            throw new \RuntimeException("Access denied during $operation"),
        ResponseCodeEnum::PRECONDITION_FAILED => 
            throw new \RuntimeException("Invalid arguments during $operation"),
        default => throw new \RuntimeException("Super stream $operation failed: " . $code->getMessage()),
    };
}
```

### Retry Logic for Transient Failures

```php
use CrazyGoat\RabbitStream\Exception\ProtocolException;
use CrazyGoat\RabbitStream\Enum\ResponseCodeEnum;

function createSuperStreamWithRetry($connection, string $name, array $partitions, array $bindingKeys, int $maxRetries = 3): void
{
    $attempt = 0;
    
    while ($attempt < $maxRetries) {
        try {
            $connection->createSuperStream($name, $partitions, $bindingKeys);
            return;
        } catch (ProtocolException $e) {
            if ($e->getResponseCode() === ResponseCodeEnum::STREAM_ALREADY_EXISTS) {
                return; // Already exists, that's fine
            }
            throw $e; // Not transient - fail fast
        } catch (\Exception $e) {
            $attempt++;
            if ($attempt >= $maxRetries) {
                throw $e;
            }
            usleep(100000 * $attempt); // Exponential backoff
        }
    }
}
```

## Best Practices

### 1. Partition Count Selection

Choose the right number of partitions:
- **Too few**: Limits throughput, can't scale
- **Too many**: Increases overhead, harder to manage
- **Rule of thumb**: Start with 2-4x your expected consumer count

```php
// Calculate partitions based on expected throughput
$expectedMessagesPerSecond = 10000;
$messagesPerPartition = 1000;
$partitionCount = ceil($expectedMessagesPerSecond / $messagesPerPartition);
```

### 2. Consistent Routing

Always use the same routing logic:

```php
class Router
{
    private int $partitionCount;
    
    public function __construct(int $partitionCount)
    {
        $this->partitionCount = $partitionCount;
    }
    
    public function getPartition(string $routingKey): int
    {
        return crc32($routingKey) % $this->partitionCount;
    }
    
    public function getPartitionName(string $superStream, string $routingKey): string
    {
        return "{$superStream}-" . $this->getPartition($routingKey);
    }
}

// Use the same router everywhere
$router = new Router(3);
$partition = $router->getPartitionName('orders', 'customer-123');
```

### 3. Idempotent Creation

Make super stream creation idempotent:

```php
use CrazyGoat\RabbitStream\Exception\ProtocolException;
use CrazyGoat\RabbitStream\Enum\ResponseCodeEnum;

function ensureSuperStreamExists($connection, string $name, int $partitionCount): void
{
    $partitions = [];
    $bindingKeys = [];
    
    for ($i = 0; $i < $partitionCount; $i++) {
        $partitions[] = "{$name}-{$i}";
        $bindingKeys[] = (string)$i;
    }
    
    try {
        $connection->createSuperStream($name, $partitions, $bindingKeys);
    } catch (ProtocolException $e) {
        if ($e->getResponseCode() !== ResponseCodeEnum::STREAM_ALREADY_EXISTS) {
            throw $e;
        }
        // Already exists - that's fine
    }
}
```

### 4. Cleanup in Finally Blocks

Always clean up super streams in tests or temporary usage:

```php
$superStreamName = 'temp-orders-' . uniqid();

// Create partitions array
$partitions = [];
$bindingKeys = [];
for ($i = 0; $i < 3; $i++) {
    $partitions[] = "{$superStreamName}-{$i}";
    $bindingKeys[] = (string)$i;
}

try {
    $connection->createSuperStream($superStreamName, $partitions, $bindingKeys);
    
    // ... use the super stream ...
    
} finally {
    // Always clean up
    try {
        $connection->deleteSuperStream($superStreamName);
    } catch (\Exception $e) {
        error_log("Failed to delete super stream: " . $e->getMessage());
    }
}
```

### 5. Monitor Partition Balance

Check that messages are evenly distributed:

```php
function checkPartitionBalance(Connection $connection, array $partitions): array
{
    $stats = [];
    
    foreach ($partitions as $partition) {
        $raw = $connection->getStreamStats($partition);
        
        $stats[$partition] = [
            'first_offset' => $raw['first_offset'] ?? 0,
            'last_offset' => $raw['last_offset'] ?? 0,
            'message_count' => ($raw['last_offset'] ?? 0) - ($raw['first_offset'] ?? 0) + 1,
        ];
    }
    
    return $stats;
}

// Usage
$stats = checkPartitionBalance($connection, ['orders-0', 'orders-1', 'orders-2']);
foreach ($stats as $partition => $info) {
    echo "$partition: {$info['message_count']} messages\n";
}
```

## Complete Working Example

```php
<?php

declare(strict_types=1);

use CrazyGoat\RabbitStream\Client\Connection;
use CrazyGoat\RabbitStream\Enum\ResponseCodeEnum;
use CrazyGoat\RabbitStream\Exception\ProtocolException;
use CrazyGoat\RabbitStream\Request\PartitionsRequestV1;
use CrazyGoat\RabbitStream\Response\PartitionsResponseV1;
use CrazyGoat\RabbitStream\StreamConnection;
use CrazyGoat\RabbitStream\VO\OffsetSpec;

require_once __DIR__ . '/vendor/autoload.php';

// Configuration
$superStreamName = 'orders';
$partitionCount = 3;
$host = '127.0.0.1';
$port = 5552;

try {
    // 1. Connect: wrap the raw StreamConnection so it is also available
    // for the low-level partitions query below
    echo "Connecting to RabbitMQ Streams...\n";
    $stream = new StreamConnection($host, $port);
    $stream->connect();
    $connection = Connection::create(
        host: $host,
        port: $port,
        user: 'guest',
        password: 'guest',
        vhost: '/',
        streamConnection: $stream
    );
    
    // 2. Create super stream
    echo "Creating super stream '$superStreamName' with $partitionCount partitions...\n";
    $partitions = [];
    $bindingKeys = [];
    for ($i = 0; $i < $partitionCount; $i++) {
        $partitions[] = "{$superStreamName}-{$i}";
        $bindingKeys[] = (string)$i;
    }
    
    try {
        $connection->createSuperStream(
            $superStreamName,
            $partitions,
            $bindingKeys,
            ['max-age' => '1h']
        );
        echo "Super stream created successfully\n";
    } catch (ProtocolException $e) {
        if ($e->getResponseCode() === ResponseCodeEnum::STREAM_ALREADY_EXISTS) {
            echo "Super stream already exists\n";
        } else {
            throw $e;
        }
    }
    
    // 3. Query partitions (low-level: no high-level wrapper exists)
    echo "\nQuerying partitions...\n";
    $stream->sendMessage(new PartitionsRequestV1($superStreamName));
    $partitionsResponse = $stream->readMessage();
    
    $discoveredPartitions = [];
    if ($partitionsResponse instanceof PartitionsResponseV1) {
        $discoveredPartitions = $partitionsResponse->getStreams();
        echo "Discovered " . count($discoveredPartitions) . " partitions:\n";
        foreach ($discoveredPartitions as $partition) {
            echo "  - $partition\n";
        }
    }
    
    // 4. Test routing
    echo "\nTesting routing...\n";
    $testKeys = ['customer-1', 'customer-2', 'customer-3', 'customer-4'];
    foreach ($testKeys as $key) {
        $targetPartitions = $connection->route($key, $superStreamName);
        echo "  Routing key '$key' -> " . implode(', ', $targetPartitions) . "\n";
    }
    
    // 5. Create producers for each partition
    echo "\nCreating producers...\n";
    $producers = [];
    foreach ($discoveredPartitions as $partition) {
        $producers[$partition] = $connection->createProducer($partition);
        echo "  Created producer for $partition\n";
    }
    
    // 6. Publish messages
    echo "\nPublishing 100 messages...\n";
    for ($i = 0; $i < 100; $i++) {
        $customerId = "customer-" . ($i % 10);
        $partitionIndex = crc32($customerId) % $partitionCount;
        $partitionName = "{$superStreamName}-{$partitionIndex}";
        
        $message = json_encode([
            'order_id' => $i,
            'customer_id' => $customerId,
            'amount' => rand(10, 1000),
        ]);
        
        $producers[$partitionName]->send($message);
    }
    
    // Wait for confirms
    foreach ($producers as $producer) {
        $producer->waitForConfirms(timeout: 5.0);
    }
    echo "Messages published successfully\n";
    
    // 7. Create consumers for each partition (subscribe + credit are
    // handled internally by the high-level Consumer)
    echo "\nCreating consumers...\n";
    $consumers = [];
    $receivedMessages = 0;
    foreach ($discoveredPartitions as $partition) {
        $consumers[$partition] = $connection->createConsumer($partition, OffsetSpec::first());
        echo "  Created consumer for $partition\n";
    }
    
    // 8. Consume some messages
    echo "\nConsuming messages (max 10)...\n";
    $startTime = time();
    while ($receivedMessages < 10 && (time() - $startTime) < 5) {
        foreach ($consumers as $partition => $consumer) {
            foreach ($consumer->read(timeout: 0.1) as $message) {
                $data = json_decode($message->getBody(), true);
                echo "  Received: Order #{$data['order_id']} from {$data['customer_id']}\n";
                $receivedMessages++;
                if ($receivedMessages >= 10) {
                    break 3;
                }
            }
        }
    }
    
    echo "\nReceived $receivedMessages messages\n";
    
    // 9. Cleanup
    echo "\nCleaning up...\n";
    foreach ($producers as $producer) {
        $producer->close();
    }
    foreach ($consumers as $consumer) {
        $consumer->close();
    }
    
    $connection->deleteSuperStream($superStreamName);
    echo "Super stream deleted successfully\n";
    
    $connection->close();
    echo "\nDone!\n";
    
} catch (\Exception $e) {
    echo "Error: " . $e->getMessage() . "\n";
    exit(1);
}
```

## See Also

- [Super Stream Routing Example](../examples/super-stream-routing.md) - Complete working example
- [Stream Management Guide](./stream-management.md) - Managing individual streams
- [Publishing Guide](./publishing.md) - Publishing messages
- [Consuming Guide](./consuming.md) - Consuming messages
- [Protocol Reference](../protocol/super-stream-commands.md) - Low-level protocol details
