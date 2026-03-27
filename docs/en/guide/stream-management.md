# Stream Management

This guide covers creating, managing, and monitoring RabbitMQ Streams using the high-level Connection API and low-level protocol commands.

## Overview

RabbitMQ Streams are persistent, replicated message logs. Unlike traditional queues, streams:
- Store messages durably until retention policies delete them
- Support multiple consumers reading from different positions
- Scale horizontally with partitioning (super streams)
- Provide at-least-once delivery guarantees

## Creating Streams

### Basic Stream Creation

Create a simple stream with just a name:

```php
use CrazyGoat\RabbitStream\Client\Connection;

$connection = Connection::create();

// Create a basic stream
$connection->createStream('my-stream');
```

### Stream with Arguments

Configure streams with retention policies and other options:

```php
$connection->createStream('my-stream', [
    'max-length-bytes' => '1073741824',     // 1 GB max size
    'max-age' => '24h',                      // 24 hour retention
    'stream-max-segment-size-bytes' => '500000000',  // 500 MB segments
    'initial-cluster-size' => '3',           // 3 replicas
    'queue-leader-locator' => 'balanced',    // Balanced leader placement
]);
```

### Available Stream Arguments

| Argument | Type | Description | Example |
|----------|------|-------------|---------|
| `max-length-bytes` | string | Maximum total size of stream in bytes | `'1000000000'` (1 GB) |
| `max-age` | string | Maximum age of messages | `'24h'`, `'7d'` |
| `stream-max-segment-size-bytes` | string | Maximum size of segment files | `'500000000'` (500 MB) |
| `initial-cluster-size` | string | Initial number of replicas | `'3'` |
| `queue-leader-locator` | string | Policy for leader placement | `'client-local'`, `'balanced'` |

### Error Handling: STREAM_ALREADY_EXISTS

Handle the case when a stream already exists:

```php
use CrazyGoat\RabbitStream\Enum\ResponseCodeEnum;
use CrazyGoat\RabbitStream\Request\CreateRequestV1;
use CrazyGoat\RabbitStream\Response\CreateResponseV1;

// Using low-level API for fine-grained error handling
$connection->getStreamConnection()->sendMessage(
    new CreateRequestV1('my-stream', ['max-age' => '1h'])
);
$response = $connection->getStreamConnection()->readMessage();

if ($response instanceof CreateResponseV1) {
    $code = $response->getResponseCode();
    
    if ($code === ResponseCodeEnum::OK) {
        echo "Stream created successfully\n";
    } elseif ($code === ResponseCodeEnum::STREAM_ALREADY_EXISTS) {
        echo "Stream already exists - continuing\n";
    } else {
        throw new \Exception("Create failed: " . $code->getMessage());
    }
}
```

## Deleting Streams

### Basic Deletion

Delete a stream and all its data:

```php
$connection->deleteStream('my-stream');
```

### Error Handling: STREAM_NOT_EXIST

Handle the case when the stream doesn't exist:

```php
use CrazyGoat\RabbitStream\Enum\ResponseCodeEnum;
use CrazyGoat\RabbitStream\Request\DeleteStreamRequestV1;
use CrazyGoat\RabbitStream\Response\DeleteStreamResponseV1;

$connection->getStreamConnection()->sendMessage(
    new DeleteStreamRequestV1('my-stream')
);
$response = $connection->getStreamConnection()->readMessage();

if ($response instanceof DeleteStreamResponseV1) {
    $code = $response->getResponseCode();
    
    if ($code === ResponseCodeEnum::OK) {
        echo "Stream deleted successfully\n";
    } elseif ($code === ResponseCodeEnum::STREAM_NOT_EXIST) {
        echo "Stream does not exist - nothing to delete\n";
    } else {
        throw new \Exception("Delete failed: " . $code->getMessage());
    }
}
```

## Checking Stream Existence

### Using streamExists()

The high-level API provides a simple boolean check:

```php
if ($connection->streamExists('my-stream')) {
    echo "Stream exists\n";
} else {
    echo "Stream does not exist\n";
}
```

### How It Works Internally

The `streamExists()` method queries stream metadata and checks the response code:

```php
// Equivalent low-level implementation
$connection->getStreamConnection()->sendMessage(
    new MetadataRequestV1(['my-stream'])
);
$response = $connection->getStreamConnection()->readMessage();

foreach ($response->getStreamMetadata() as $meta) {
    if ($meta->getStreamName() === 'my-stream') {
        $exists = $meta->getResponseCode() === ResponseCodeEnum::OK->value;
    }
}
```

## Stream Statistics

### Getting Statistics

Retrieve metrics about a stream:

```php
$stats = $connection->getStreamStats('my-stream');

foreach ($stats as $key => $value) {
    echo "$key: $value\n";
}
```

### Available Statistics

| Statistic | Description |
|-----------|-------------|
| `first_offset` | Offset of the first message in the stream |
| `last_offset` | Offset of the last message in the stream |
| `committed_chunk_id` | ID of the last committed chunk |
| `chunk_count` | Total number of chunks in the stream |

### Calculating Message Count

```php
$stats = $connection->getStreamStats('my-stream');

$firstOffset = $stats['first_offset'] ?? 0;
$lastOffset = $stats['last_offset'] ?? 0;
$messageCount = $lastOffset - $firstOffset + 1;

echo "Stream contains $messageCount messages\n";
echo "Number of chunks: " . ($stats['chunk_count'] ?? 0) . "\n";
```

## Stream Metadata

### Querying Metadata

Get detailed topology information about streams:

```php
use CrazyGoat\RabbitStream\Response\MetadataResponseV1;

// Query single stream
$metadata = $connection->getMetadata(['my-stream']);

// Query multiple streams at once
$metadata = $connection->getMetadata(['stream1', 'stream2', 'stream3']);
```

### Response Structure

The `MetadataResponseV1` contains two main sections:

```php
// Broker information
foreach ($metadata->getBrokers() as $broker) {
    echo "Broker {$broker->getReference()}: ";
    echo "{$broker->getHost()}:{$broker->getPort()}\n";
}

// Stream metadata
foreach ($metadata->getStreamMetadata() as $streamMeta) {
    echo "Stream: {$streamMeta->getStreamName()}\n";
    echo "  Response Code: {$streamMeta->getResponseCode()}\n";
    echo "  Leader: Broker {$streamMeta->getLeaderReference()}\n";
    echo "  Replicas: " . implode(', ', $streamMeta->getReplicaReferences()) . "\n";
}
```

### Multi-Stream Queries

Querying multiple streams in a single request is more efficient:

```php
$streamNames = ['orders', 'events', 'logs'];
$metadata = $connection->getMetadata($streamNames);

foreach ($metadata->getStreamMetadata() as $streamMeta) {
    $name = $streamMeta->getStreamName();
    $code = $streamMeta->getResponseCode();
    
    if ($code === ResponseCodeEnum::OK->value) {
        $leader = $streamMeta->getLeaderReference();
        $replicas = $streamMeta->getReplicaReferences();
        echo "$name: leader=$leader, replicas=" . count($replicas) . "\n";
    } else {
        echo "$name: error - " . ResponseCodeEnum::from($code)->getMessage() . "\n";
    }
}
```

## Low-Level Commands

### When to Use Low-Level API

Use the low-level protocol classes when:
- Debugging protocol issues
- Learning the RabbitMQ Stream protocol
- Implementing custom connection logic
- Need fine-grained error handling

### Available Classes

**Request Classes:**
- `CreateRequestV1` - Create a stream
- `DeleteStreamRequestV1` - Delete a stream
- `MetadataRequestV1` - Query stream metadata
- `StreamStatsRequestV1` - Get stream statistics

**Response Classes:**
- `CreateResponseV1` - Create operation result
- `DeleteStreamResponseV1` - Delete operation result
- `MetadataResponseV1` - Metadata query result
- `StreamStatsResponseV1` - Statistics query result

### Low-Level Example

```php
use CrazyGoat\RabbitStream\Request\CreateRequestV1;
use CrazyGoat\RabbitStream\Request\MetadataRequestV1;
use CrazyGoat\RabbitStream\Request\StreamStatsRequestV1;
use CrazyGoat\RabbitStream\Request\DeleteStreamRequestV1;

$stream = $connection->getStreamConnection();

// Create
$stream->sendMessage(new CreateRequestV1('my-stream'));
$createResponse = $stream->readMessage();

// Get metadata
$stream->sendMessage(new MetadataRequestV1(['my-stream']));
$metadata = $stream->readMessage();

// Get stats
$stream->sendMessage(new StreamStatsRequestV1('my-stream'));
$stats = $stream->readMessage();

// Delete
$stream->sendMessage(new DeleteStreamRequestV1('my-stream'));
$deleteResponse = $stream->readMessage();
```

## Complete Working Example

```php
use CrazyGoat\RabbitStream\Client\Connection;
use CrazyGoat\RabbitStream\Enum\ResponseCodeEnum;

$connection = Connection::create(
    host: '127.0.0.1',
    port: 5552,
    user: 'guest',
    password: 'guest',
    vhost: '/'
);

try {
    $streamName = 'events-stream';
    
    // 1. Create stream with retention policy
    echo "Creating stream...\n";
    $connection->createStream($streamName, [
        'max-length-bytes' => '1073741824',  // 1 GB
        'max-age' => '24h',                   // 24 hours
    ]);
    echo "Stream created\n";
    
    // 2. Check existence
    if ($connection->streamExists($streamName)) {
        echo "Stream exists: yes\n";
    }
    
    // 3. Get metadata
    $metadata = $connection->getMetadata([$streamName]);
    foreach ($metadata->getStreamMetadata() as $meta) {
        echo "Leader: Broker {$meta->getLeaderReference()}\n";
        echo "Replicas: " . implode(', ', $meta->getReplicaReferences()) . "\n";
    }
    
    // 4. Get statistics
    $stats = $connection->getStreamStats($streamName);
    echo "First offset: {$stats['first_offset']}\n";
    echo "Last offset: {$stats['last_offset']}\n";
    echo "Chunk count: {$stats['chunk_count']}\n";
    
    // 5. Delete stream
    echo "Deleting stream...\n";
    $connection->deleteStream($streamName);
    echo "Stream deleted\n";
    
} finally {
    $connection->close();
}
```

## Best Practices

### Idempotent Creation Pattern

Make your code resilient to stream already existing:

```php
function ensureStreamExists(Connection $connection, string $name, array $args = []): void
{
    try {
        $connection->createStream($name, $args);
    } catch (\Exception $e) {
        // Check if it's already exists error
        if (strpos($e->getMessage(), 'already exists') !== false) {
            return; // Stream exists, that's fine
        }
        throw $e;
    }
}

// Usage
ensureStreamExists($connection, 'my-stream', ['max-age' => '7d']);
```

### Proper Error Handling

Always handle specific error codes:

```php
use CrazyGoat\RabbitStream\Enum\ResponseCodeEnum;

function handleStreamError(ResponseCodeEnum $code, string $operation): void
{
    match ($code) {
        ResponseCodeEnum::OK => null, // Success, no action needed
        ResponseCodeEnum::STREAM_ALREADY_EXISTS => 
            throw new \RuntimeException("Stream already exists during $operation"),
        ResponseCodeEnum::STREAM_NOT_EXIST => 
            throw new \RuntimeException("Stream not found during $operation"),
        ResponseCodeEnum::ACCESS_REFUSED => 
            throw new \RuntimeException("Access denied during $operation"),
        ResponseCodeEnum::PRECONDITION_FAILED => 
            throw new \RuntimeException("Invalid arguments during $operation"),
        default => throw new \RuntimeException("Stream $operation failed: " . $code->getMessage()),
    };
}
```

### Metadata Caching

Cache metadata to reduce server load:

```php
class StreamMetadataCache
{
    private array $cache = [];
    private int $ttl;
    
    public function __construct(private Connection $connection, int $ttlSeconds = 60)
    {
        $this->ttl = $ttlSeconds;
    }
    
    public function getMetadata(string $stream): ?StreamMetadata
    {
        $key = $stream;
        $now = time();
        
        if (isset($this->cache[$key]) && $this->cache[$key]['expires'] > $now) {
            return $this->cache[$key]['data'];
        }
        
        $response = $this->connection->getMetadata([$stream]);
        foreach ($response->getStreamMetadata() as $meta) {
            if ($meta->getStreamName() === $stream) {
                $this->cache[$key] = [
                    'data' => $meta,
                    'expires' => $now + $this->ttl,
                ];
                return $meta;
            }
        }
        
        return null;
    }
}
```

### Cleanup in Finally Blocks

Always ensure streams are cleaned up:

```php
$streamName = 'temp-stream-' . uniqid();

try {
    $connection->createStream($streamName);
    // ... use the stream ...
} finally {
    // Always clean up, even on error
    try {
        $connection->deleteStream($streamName);
    } catch (\Exception $e) {
        // Log but don't throw from cleanup
        error_log("Failed to delete stream: " . $e->getMessage());
    }
}
```

## See Also

- [Stream Management Commands](../protocol/stream-management-commands.md) - Protocol reference
- [Stream Management Examples](../examples/stream-management.md) - Working code examples
- [Super Streams Guide](./super-streams.md) - Partitioned streams
- [Connection Lifecycle](./connection-lifecycle.md) - Connection management
