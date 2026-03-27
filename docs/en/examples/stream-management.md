# Stream Management Examples

This document provides complete, working examples for managing RabbitMQ Streams.

## Basic Stream Operations

### Creating and Deleting a Stream

```php
<?php

use CrazyGoat\RabbitStream\Client\Connection;

require_once __DIR__ . '/../../vendor/autoload.php';

// Create connection
$connection = Connection::create(
    host: '127.0.0.1',
    port: 5552,
    user: 'guest',
    password: 'guest',
    vhost: '/'
);

try {
    $streamName = 'example-stream';
    
    // Create a simple stream
    echo "Creating stream '$streamName'...\n";
    $connection->createStream($streamName);
    echo "Stream created successfully\n";
    
    // Clean up
    echo "Deleting stream...\n";
    $connection->deleteStream($streamName);
    echo "Stream deleted successfully\n";
    
} finally {
    $connection->close();
}
```

### Stream with Retention Policy

```php
<?php

use CrazyGoat\RabbitStream\Client\Connection;

require_once __DIR__ . '/../../vendor/autoload.php';

$connection = Connection::create();

try {
    $streamName = 'retention-stream';
    
    // Create stream with retention policies
    $connection->createStream($streamName, [
        'max-length-bytes' => '1073741824',  // 1 GB max size
        'max-age' => '24h',                   // 24 hour retention
        'stream-max-segment-size-bytes' => '500000000',  // 500 MB segments
    ]);
    
    echo "Stream created with retention:\n";
    echo "  - Max size: 1 GB\n";
    echo "  - Max age: 24 hours\n";
    echo "  - Segment size: 500 MB\n";
    
    // Clean up
    $connection->deleteStream($streamName);
    
} finally {
    $connection->close();
}
```

## Error Handling Patterns

### Idempotent Stream Creation

```php
<?php

use CrazyGoat\RabbitStream\Client\Connection;
use CrazyGoat\RabbitStream\Enum\ResponseCodeEnum;
use CrazyGoat\RabbitStream\Request\CreateRequestV1;
use CrazyGoat\RabbitStream\Response\CreateResponseV1;

require_once __DIR__ . '/../../vendor/autoload.php';

function createStreamIdempotent(Connection $connection, string $name, array $args = []): bool
{
    try {
        // Try high-level API first
        $connection->createStream($name, $args);
        return true;
    } catch (\Exception $e) {
        // If it failed, check if stream already exists using low-level API
        $stream = $connection->getStreamConnection();
        
        $stream->sendMessage(new CreateRequestV1($name, $args));
        $response = $stream->readMessage();
        
        if ($response instanceof CreateResponseV1) {
            $code = $response->getResponseCode();
            
            if ($code === ResponseCodeEnum::OK) {
                return true; // Created successfully
            } elseif ($code === ResponseCodeEnum::STREAM_ALREADY_EXISTS) {
                return false; // Already exists, not an error
            }
        }
        
        throw $e;
    }
}

// Usage
$connection = Connection::create();

try {
    $streamName = 'idempotent-stream';
    
    // First call creates the stream
    $created = createStreamIdempotent($connection, $streamName);
    echo "First call: " . ($created ? "created" : "already exists") . "\n";
    
    // Second call returns false (already exists)
    $created = createStreamIdempotent($connection, $streamName);
    echo "Second call: " . ($created ? "created" : "already exists") . "\n";
    
    // Clean up
    $connection->deleteStream($streamName);
    
} finally {
    $connection->close();
}
```

### Safe Stream Deletion

```php
<?php

use CrazyGoat\RabbitStream\Client\Connection;
use CrazyGoat\RabbitStream\Enum\ResponseCodeEnum;
use CrazyGoat\RabbitStream\Request\DeleteStreamRequestV1;
use CrazyGoat\RabbitStream\Response\DeleteStreamResponseV1;

require_once __DIR__ . '/../../vendor/autoload.php';

function deleteStreamSafe(Connection $connection, string $name): bool
{
    $stream = $connection->getStreamConnection();
    
    $stream->sendMessage(new DeleteStreamRequestV1($name));
    $response = $stream->readMessage();
    
    if ($response instanceof DeleteStreamResponseV1) {
        $code = $response->getResponseCode();
        
        if ($code === ResponseCodeEnum::OK) {
            return true; // Deleted successfully
        } elseif ($code === ResponseCodeEnum::STREAM_NOT_EXIST) {
            return false; // Didn't exist, nothing to delete
        }
    }
    
    throw new \Exception("Delete failed: " . ($response?->getResponseCode()->getMessage() ?? 'Unknown error'));
}

// Usage
$connection = Connection::create();

try {
    $streamName = 'safe-delete-stream';
    
    // Create the stream
    $connection->createStream($streamName);
    
    // First delete succeeds
    $deleted = deleteStreamSafe($connection, $streamName);
    echo "First delete: " . ($deleted ? "deleted" : "did not exist") . "\n";
    
    // Second delete returns false (already gone)
    $deleted = deleteStreamSafe($connection, $streamName);
    echo "Second delete: " . ($deleted ? "deleted" : "did not exist") . "\n";
    
} finally {
    $connection->close();
}
```

## Stream Metadata Inspection

### Inspecting Stream Topology

```php
<?php

use CrazyGoat\RabbitStream\Client\Connection;
use CrazyGoat\RabbitStream\Enum\ResponseCodeEnum;

require_once __DIR__ . '/../../vendor/autoload.php';

$connection = Connection::create();

try {
    $streamName = 'topology-stream';
    
    // Create stream
    $connection->createStream($streamName);
    
    // Get metadata
    $metadata = $connection->getMetadata([$streamName]);
    
    echo "=== Broker Information ===\n";
    foreach ($metadata->getBrokers() as $broker) {
        echo "Broker {$broker->getReference()}: ";
        echo "{$broker->getHost()}:{$broker->getPort()}\n";
    }
    
    echo "\n=== Stream Topology ===\n";
    foreach ($metadata->getStreamMetadata() as $streamMeta) {
        echo "Stream: {$streamMeta->getStreamName()}\n";
        
        $code = $streamMeta->getResponseCode();
        if ($code === ResponseCodeEnum::OK->value) {
            echo "  Status: OK\n";
            echo "  Leader: Broker {$streamMeta->getLeaderReference()}\n";
            
            $replicas = $streamMeta->getReplicaReferences();
            if (count($replicas) > 0) {
                echo "  Replicas: " . implode(', ', $replicas) . "\n";
            } else {
                echo "  Replicas: none (single node)\n";
            }
        } else {
            $error = ResponseCodeEnum::from($code);
            echo "  Status: Error ({$error->getMessage()})\n";
        }
    }
    
    // Clean up
    $connection->deleteStream($streamName);
    
} finally {
    $connection->close();
}
```

### Multi-Stream Metadata Query

```php
<?php

use CrazyGoat\RabbitStream\Client\Connection;
use CrazyGoat\RabbitStream\Enum\ResponseCodeEnum;

require_once __DIR__ . '/../../vendor/autoload.php';

$connection = Connection::create();

try {
    // Create multiple streams
    $streams = ['stream-alpha', 'stream-beta', 'stream-gamma'];
    foreach ($streams as $stream) {
        $connection->createStream($stream);
    }
    
    // Query all at once (more efficient)
    $metadata = $connection->getMetadata($streams);
    
    echo "=== Multi-Stream Metadata ===\n";
    foreach ($metadata->getStreamMetadata() as $streamMeta) {
        $name = $streamMeta->getStreamName();
        $code = $streamMeta->getResponseCode();
        
        if ($code === ResponseCodeEnum::OK->value) {
            $leader = $streamMeta->getLeaderReference();
            $replicaCount = count($streamMeta->getReplicaReferences());
            echo "$name: leader=$leader, replicas=$replicaCount\n";
        } else {
            $error = ResponseCodeEnum::from($code);
            echo "$name: ERROR - {$error->getMessage()}\n";
        }
    }
    
    // Clean up
    foreach ($streams as $stream) {
        $connection->deleteStream($stream);
    }
    
} finally {
    $connection->close();
}
```

## Stream Statistics

### Monitoring Stream Health

```php
<?php

use CrazyGoat\RabbitStream\Client\Connection;

require_once __DIR__ . '/../../vendor/autoload.php';

$connection = Connection::create();

try {
    $streamName = 'stats-stream';
    
    // Create stream
    $connection->createStream($streamName);
    
    // Get statistics
    $stats = $connection->getStreamStats($streamName);
    
    echo "=== Stream Statistics ===\n";
    echo "Stream: $streamName\n\n";
    
    foreach ($stats as $key => $value) {
        echo sprintf("  %-20s: %d\n", $key, $value);
    }
    
    // Calculate derived metrics
    $firstOffset = $stats['first_offset'] ?? 0;
    $lastOffset = $stats['last_offset'] ?? 0;
    $messageCount = $lastOffset - $firstOffset + 1;
    $chunkCount = $stats['chunk_count'] ?? 0;
    
    echo "\n=== Derived Metrics ===\n";
    echo "  Total messages: $messageCount\n";
    echo "  Average chunk size: " . ($chunkCount > 0 ? round($messageCount / $chunkCount, 2) : 0) . " messages\n";
    
    // Clean up
    $connection->deleteStream($streamName);
    
} finally {
    $connection->close();
}
```

### Statistics Comparison

```php
<?php

use CrazyGoat\RabbitStream\Client\Connection;

require_once __DIR__ . '/../../vendor/autoload.php';

function getStreamMetrics(Connection $connection, string $streamName): array
{
    $stats = $connection->getStreamStats($streamName);
    
    $firstOffset = $stats['first_offset'] ?? 0;
    $lastOffset = $stats['last_offset'] ?? 0;
    
    return [
        'name' => $streamName,
        'message_count' => $lastOffset - $firstOffset + 1,
        'first_offset' => $firstOffset,
        'last_offset' => $lastOffset,
        'chunk_count' => $stats['chunk_count'] ?? 0,
        'committed_chunk' => $stats['committed_chunk_id'] ?? 0,
    ];
}

function printMetricsComparison(array $metrics): void
{
    echo "=== Stream Metrics Comparison ===\n";
    echo sprintf("%-20s %12s %12s %12s\n", "Stream", "Messages", "Chunks", "Committed");
    echo str_repeat("-", 60) . "\n";
    
    foreach ($metrics as $m) {
        echo sprintf(
            "%-20s %12d %12d %12d\n",
            $m['name'],
            $m['message_count'],
            $m['chunk_count'],
            $m['committed_chunk']
        );
    }
}

$connection = Connection::create();

try {
    $streams = ['metrics-a', 'metrics-b', 'metrics-c'];
    
    // Create streams
    foreach ($streams as $stream) {
        $connection->createStream($stream);
    }
    
    // Collect metrics
    $allMetrics = [];
    foreach ($streams as $stream) {
        $allMetrics[] = getStreamMetrics($connection, $stream);
    }
    
    // Display comparison
    printMetricsComparison($allMetrics);
    
    // Clean up
    foreach ($streams as $stream) {
        $connection->deleteStream($stream);
    }
    
} finally {
    $connection->close();
}
```

## Complete Working Example

### Stream Lifecycle Management

```php
<?php

use CrazyGoat\RabbitStream\Client\Connection;
use CrazyGoat\RabbitStream\Enum\ResponseCodeEnum;

require_once __DIR__ . '/../../vendor/autoload.php';

/**
 * Complete example showing stream lifecycle:
 * 1. Connect to RabbitMQ
 * 2. Create stream with retention policy
 * 3. Verify stream exists
 * 4. Inspect metadata and topology
 * 5. Monitor statistics
 * 6. Clean up (delete stream)
 */

$connection = Connection::create(
    host: '127.0.0.1',
    port: 5552,
    user: 'guest',
    password: 'guest',
    vhost: '/'
);

try {
    $streamName = 'lifecycle-example-stream';
    
    echo "=== RabbitMQ Stream Lifecycle Example ===\n\n";
    
    // Step 1: Create stream with retention policy
    echo "Step 1: Creating stream with retention policy...\n";
    $connection->createStream($streamName, [
        'max-length-bytes' => '1073741824',  // 1 GB
        'max-age' => '24h',                   // 24 hours
        'queue-leader-locator' => 'balanced', // Balanced placement
    ]);
    echo "✓ Stream created successfully\n\n";
    
    // Step 2: Verify stream exists
    echo "Step 2: Checking stream existence...\n";
    if ($connection->streamExists($streamName)) {
        echo "✓ Stream exists: confirmed\n\n";
    } else {
        throw new \RuntimeException("Stream should exist but doesn't");
    }
    
    // Step 3: Inspect metadata
    echo "Step 3: Inspecting stream metadata...\n";
    $metadata = $connection->getMetadata([$streamName]);
    
    echo "Brokers in cluster:\n";
    foreach ($metadata->getBrokers() as $broker) {
        echo "  - Broker {$broker->getReference()}: {$broker->getHost()}:{$broker->getPort()}\n";
    }
    
    foreach ($metadata->getStreamMetadata() as $streamMeta) {
        if ($streamMeta->getResponseCode() === ResponseCodeEnum::OK->value) {
            echo "\nStream '{$streamMeta->getStreamName()}':\n";
            echo "  - Leader: Broker {$streamMeta->getLeaderReference()}\n";
            
            $replicas = $streamMeta->getReplicaReferences();
            if (count($replicas) > 0) {
                echo "  - Replicas: " . implode(', ', $replicas) . "\n";
            }
        }
    }
    echo "\n";
    
    // Step 4: Get statistics
    echo "Step 4: Getting stream statistics...\n";
    $stats = $connection->getStreamStats($streamName);
    
    echo "Raw statistics:\n";
    foreach ($stats as $key => $value) {
        echo "  - $key: $value\n";
    }
    
    $firstOffset = $stats['first_offset'] ?? 0;
    $lastOffset = $stats['last_offset'] ?? 0;
    $messageCount = $lastOffset - $firstOffset + 1;
    
    echo "\nDerived metrics:\n";
    echo "  - Total messages: $messageCount\n";
    echo "  - Chunk count: " . ($stats['chunk_count'] ?? 0) . "\n";
    echo "\n";
    
    // Step 5: Delete stream
    echo "Step 5: Deleting stream...\n";
    $connection->deleteStream($streamName);
    echo "✓ Stream deleted successfully\n\n";
    
    // Step 6: Verify deletion
    echo "Step 6: Verifying deletion...\n";
    if (!$connection->streamExists($streamName)) {
        echo "✓ Stream no longer exists: confirmed\n\n";
    } else {
        throw new \RuntimeException("Stream should be deleted but still exists");
    }
    
    echo "=== Example Complete ===\n";
    
} catch (\Exception $e) {
    echo "Error: " . $e->getMessage() . "\n";
    exit(1);
} finally {
    // Always close connection
    $connection->close();
    echo "\nConnection closed.\n";
}
```

## Low-Level Protocol Examples

### Manual Protocol Commands

```php
<?php

use CrazyGoat\RabbitStream\Client\Connection;
use CrazyGoat\RabbitStream\Request\CreateRequestV1;
use CrazyGoat\RabbitStream\Request\DeleteStreamRequestV1;
use CrazyGoat\RabbitStream\Request\MetadataRequestV1;
use CrazyGoat\RabbitStream\Request\StreamStatsRequestV1;
use CrazyGoat\RabbitStream\Response\CreateResponseV1;
use CrazyGoat\RabbitStream\Response\DeleteStreamResponseV1;
use CrazyGoat\RabbitStream\Response\MetadataResponseV1;
use CrazyGoat\RabbitStream\Response\StreamStatsResponseV1;

require_once __DIR__ . '/../../vendor/autoload.php';

$connection = Connection::create();
$stream = $connection->getStreamConnection();

try {
    $streamName = 'low-level-stream';
    
    // Create using low-level API
    echo "Creating stream via low-level API...\n";
    $stream->sendMessage(new CreateRequestV1($streamName, [
        'max-age' => '1h'
    ]));
    $response = $stream->readMessage();
    
    if ($response instanceof CreateResponseV1) {
        echo "Response code: " . $response->getResponseCode()->getMessage() . "\n";
    }
    
    // Query metadata
    echo "\nQuerying metadata...\n";
    $stream->sendMessage(new MetadataRequestV1([$streamName]));
    $metadata = $stream->readMessage();
    
    if ($metadata instanceof MetadataResponseV1) {
        foreach ($metadata->getStreamMetadata() as $meta) {
            echo "Stream: {$meta->getStreamName()}\n";
            echo "Leader: {$meta->getLeaderReference()}\n";
        }
    }
    
    // Get statistics
    echo "\nGetting statistics...\n";
    $stream->sendMessage(new StreamStatsRequestV1($streamName));
    $stats = $stream->readMessage();
    
    if ($stats instanceof StreamStatsResponseV1) {
        foreach ($stats->getStats() as $stat) {
            echo "{$stat->getKey()}: {$stat->getValue()}\n";
        }
    }
    
    // Delete using low-level API
    echo "\nDeleting stream via low-level API...\n";
    $stream->sendMessage(new DeleteStreamRequestV1($streamName));
    $response = $stream->readMessage();
    
    if ($response instanceof DeleteStreamResponseV1) {
        echo "Response code: " . $response->getResponseCode()->getMessage() . "\n";
    }
    
} finally {
    $connection->close();
}
```

## Best Practices Examples

### Temporary Stream Pattern

```php
<?php

use CrazyGoat\RabbitStream\Client\Connection;

require_once __DIR__ . '/../../vendor/autoload.php';

/**
 * Creates a temporary stream, executes a callback, then cleans up.
 * Ensures stream is always deleted even if callback throws.
 */
function withTemporaryStream(
    Connection $connection,
    string $prefix,
    array $args,
    callable $callback
): void {
    $streamName = $prefix . '-' . uniqid();
    
    try {
        // Create stream
        $connection->createStream($streamName, $args);
        
        // Execute callback with stream name
        $callback($streamName);
        
    } finally {
        // Always clean up
        try {
            if ($connection->streamExists($streamName)) {
                $connection->deleteStream($streamName);
            }
        } catch (\Exception $e) {
            error_log("Failed to delete temporary stream: " . $e->getMessage());
        }
    }
}

// Usage
$connection = Connection::create();

try {
    withTemporaryStream(
        $connection,
        'temp',
        ['max-age' => '1h'],
        function (string $streamName) use ($connection) {
            echo "Working with temporary stream: $streamName\n";
            
            // Do work with the stream...
            $stats = $connection->getStreamStats($streamName);
            echo "Stream has {$stats['chunk_count']} chunks\n";
        }
    );
    
    echo "Temporary stream automatically cleaned up\n";
    
} finally {
    $connection->close();
}
```

### Stream Manager Class

```php
<?php

use CrazyGoat\RabbitStream\Client\Connection;
use CrazyGoat\RabbitStream\Enum\ResponseCodeEnum;

require_once __DIR__ . '/../../vendor/autoload.php';

/**
 * Higher-level stream management abstraction.
 */
class StreamManager
{
    public function __construct(private Connection $connection) {}
    
    /**
     * Ensure a stream exists, creating it if necessary.
     */
    public function ensureStream(string $name, array $args = []): bool
    {
        if ($this->connection->streamExists($name)) {
            return false; // Already existed
        }
        
        $this->connection->createStream($name, $args);
        return true; // Was created
    }
    
    /**
     * Delete a stream if it exists.
     */
    public function deleteIfExists(string $name): bool
    {
        if (!$this->connection->streamExists($name)) {
            return false; // Didn't exist
        }
        
        $this->connection->deleteStream($name);
        return true; // Was deleted
    }
    
    /**
     * Get stream info including metadata and stats.
     */
    public function getStreamInfo(string $name): ?array
    {
        if (!$this->connection->streamExists($name)) {
            return null;
        }
        
        $metadata = $this->connection->getMetadata([$name]);
        $stats = $this->connection->getStreamStats($name);
        
        $meta = null;
        foreach ($metadata->getStreamMetadata() as $m) {
            if ($m->getStreamName() === $name) {
                $meta = $m;
                break;
            }
        }
        
        return [
            'name' => $name,
            'leader' => $meta?->getLeaderReference(),
            'replicas' => $meta?->getReplicaReferences(),
            'stats' => $stats,
        ];
    }
    
    /**
     * List all streams matching a pattern (requires RabbitMQ management API).
     * Note: This is a placeholder - actual implementation would use HTTP API.
     */
    public function listStreams(): array
    {
        // This would typically use the RabbitMQ Management HTTP API
        // For now, return empty array as streams are known by name
        return [];
    }
}

// Usage
$connection = Connection::create();

try {
    $manager = new StreamManager($connection);
    
    // Ensure stream exists
    $wasCreated = $manager->ensureStream('managed-stream', [
        'max-age' => '7d'
    ]);
    echo "Stream " . ($wasCreated ? "created" : "already existed") . "\n";
    
    // Get stream info
    $info = $manager->getStreamInfo('managed-stream');
    if ($info) {
        echo "Leader: {$info['leader']}\n";
        echo "Messages: " . (($info['stats']['last_offset'] ?? 0) - ($info['stats']['first_offset'] ?? 0) + 1) . "\n";
    }
    
    // Clean up
    $wasDeleted = $manager->deleteIfExists('managed-stream');
    echo "Stream " . ($wasDeleted ? "deleted" : "did not exist") . "\n";
    
} finally {
    $connection->close();
}
```

## Running the Examples

To run these examples:

1. Ensure RabbitMQ is running with the stream plugin enabled:
   ```bash
   docker run -d --name rabbitmq-stream \
     -p 5552:5552 -p 15672:15672 \
     -e RABBITMQ_SERVER_ADDITIONAL_ERL_ARGS="-rabbitmq_stream tcp_listeners [5552]" \
     rabbitmq:3.13-management
   ```

2. Install dependencies:
   ```bash
   composer install
   ```

3. Run an example:
   ```bash
   php docs/en/examples/stream-management/basic-operations.php
   ```

## See Also

- [Stream Management Guide](../guide/stream-management.md) - Detailed guide
- [Stream Management Protocol](../protocol/stream-management-commands.md) - Protocol reference
- [Connection Lifecycle](../guide/connection-lifecycle.md) - Connection management
