# Consumer Auto-Commit Example

This example demonstrates automatic offset management using the auto-commit feature. Auto-commit stores offsets at regular intervals, reducing the need for manual `storeOffset()` calls.

## Overview

Auto-commit is ideal for:
- High-throughput scenarios where manual offset storage would be too slow
- Applications that can tolerate reprocessing up to N messages after a crash
- Simplifying code by removing explicit `storeOffset()` calls

## Complete Working Example

```php
<?php

declare(strict_types=1);

use CrazyGoat\RabbitStream\Client\Connection;
use CrazyGoat\RabbitStream\VO\OffsetSpec;

require_once __DIR__ . '/../../vendor/autoload.php';

/**
 * Consumer Auto-Commit Example
 * 
 * Demonstrates:
 * - Creating a named consumer with auto-commit enabled
 * - Automatic offset storage every N messages
 * - Final offset storage on close()
 * - Recovery after restart
 */
class ConsumerAutoCommitExample
{
    private Connection $connection;
    private string $consumerName = 'auto-commit-demo';
    private int $messagesToProcess = 50;
    
    public function run(): void
    {
        echo "=== Consumer Auto-Commit Example ===\n\n";
        
        // Step 1: Create connection
        $this->createConnection();
        
        // Step 2: Create the stream (if it doesn't exist)
        $this->createStream();
        
        // Step 3: Check for existing offset (resume scenario)
        $this->checkExistingOffset();
        
        // Step 4: Create consumer with auto-commit
        $consumer = $this->createConsumer();
        
        // Step 5: Process messages
        $processed = $this->processMessages($consumer);
        
        // Step 6: Cleanup (triggers final offset storage)
        $this->cleanup($consumer);
        
        echo "\n=== Example Complete ===\n";
        echo "Messages processed: {$processed}\n";
        echo "Consumer name: {$this->consumerName}\n";
        echo "\nRun this example again to see resume behavior!\n";
    }
    
    private function createConnection(): void
    {
        echo "Step 1: Creating connection...\n";
        
        $host = getenv('RABBITMQ_HOST') ?: '127.0.0.1';
        $port = (int)(getenv('RABBITMQ_PORT') ?: 5552);
        
        $this->connection = Connection::create(
            host: $host,
            port: $port,
            user: 'guest',
            password: 'guest',
        );
        
        echo "  ✓ Connected to {$host}:{$port}\n\n";
    }
    
    private function createStream(): void
    {
        echo "Step 2: Creating stream 'example-stream'...\n";
        
        try {
            $this->connection->createStream('example-stream', [
                'max-length-bytes' => '1000000000',
            ]);
            echo "  ✓ Stream created\n\n";
        } catch (\Exception $e) {
            echo "  ℹ Stream may already exist: {$e->getMessage()}\n\n";
        }
    }
    
    private function checkExistingOffset(): void
    {
        echo "Step 3: Checking for existing offset...\n";
        
        // Create temporary consumer to query offset
        $tempConsumer = $this->connection->createConsumer(
            stream: 'example-stream',
            offset: OffsetSpec::first(),
            name: $this->consumerName
        );
        
        try {
            $lastOffset = $tempConsumer->queryOffset();
            echo "  ✓ Found stored offset: {$lastOffset}\n";
            echo "  ℹ Will resume from offset " . ($lastOffset + 1) . "\n\n";
        } catch (\Exception $e) {
            echo "  ℹ No stored offset found (first run)\n\n";
        } finally {
            $tempConsumer->close();
        }
    }
    
    private function createConsumer(): \CrazyGoat\RabbitStream\Client\Consumer
    {
        echo "Step 4: Creating consumer with auto-commit...\n";
        
        // Create consumer with auto-commit every 10 messages
        $consumer = $this->connection->createConsumer(
            stream: 'example-stream',
            offset: OffsetSpec::first(),
            name: $this->consumerName,
            autoCommit: 10,  // Store offset every 10 messages
            initialCredit: 20
        );
        
        echo "  ✓ Consumer created\n";
        echo "  ℹ Auto-commit interval: 10 messages\n";
        echo "  ℹ Consumer name: {$this->consumerName}\n\n";
        
        return $consumer;
    }
    
    private function processMessages(\CrazyGoat\RabbitStream\Client\Consumer $consumer): int
    {
        echo "Step 5: Processing messages (max {$this->messagesToProcess})...\n";
        
        $processed = 0;
        $lastStoredOffset = 0;
        
        try {
            while ($processed < $this->messagesToProcess) {
                // Read messages with 5-second timeout
                $messages = $consumer->read(timeout: 5.0);
                
                if (empty($messages)) {
                    echo "  ℹ No new messages, waiting...\n";
                    continue;
                }
                
                foreach ($messages as $message) {
                    $processed++;
                    $currentOffset = $message->getOffset();
                    
                    // Simulate message processing
                    $this->simulateProcessing($message);
                    
                    // Auto-commit happens automatically every 10 messages
                    // We can track when it happens by checking the offset
                    if ($processed % 10 === 0) {
                        echo "  ✓ [{$currentOffset}] Processed {$processed} messages ";
                        echo "(auto-commit triggered)\n";
                        $lastStoredOffset = $currentOffset;
                    } else {
                        echo "  ✓ [{$currentOffset}] Processed message {$processed}\n";
                    }
                    
                    if ($processed >= $this->messagesToProcess) {
                        echo "  ℹ Reached message limit ({$this->messagesToProcess})\n";
                        break 2;
                    }
                }
            }
        } catch (\Exception $e) {
            echo "  ✗ Error: {$e->getMessage()}\n";
        }
        
        echo "\n  ℹ Last auto-commit at offset: {$lastStoredOffset}\n";
        echo "  ℹ Final offset will be stored on close()\n\n";
        
        return $processed;
    }
    
    private function simulateProcessing(\CrazyGoat\RabbitStream\Client\Message $message): void
    {
        // Simulate some processing work
        $body = $message->getBody();
        
        // In a real application, you would:
        // - Parse the message
        // - Validate the data
        // - Update a database
        // - Send notifications
        // - etc.
        
        // Small delay to simulate work
        usleep(1000); // 1ms
    }
    
    private function cleanup(\CrazyGoat\RabbitStream\Client\Consumer $consumer): void
    {
        echo "Step 6: Cleaning up...\n";
        
        // close() automatically stores the final offset
        $consumer->close();
        echo "  ✓ Consumer closed (final offset stored)\n";
        
        $this->connection->close();
        echo "  ✓ Connection closed\n";
    }
}

// Run the example
$example = new ConsumerAutoCommitExample();
$example->run();
```

## Key Concepts

### Enabling Auto-Commit

Set the `autoCommit` parameter when creating the consumer:

```php
$consumer = $connection->createConsumer(
    stream: 'my-stream',
    offset: OffsetSpec::first(),
    name: 'my-consumer',        // Required for offset tracking
    autoCommit: 100              // Store offset every 100 messages
);
```

**Requirements:**
- Must be a named consumer (provide `name` parameter)
- `autoCommit` is the number of messages between offset stores
- Set to `0` (default) to disable auto-commit

### How Auto-Commit Works

1. **Counter-based**: The consumer counts messages internally
2. **Automatic storage**: Every N messages, the offset is stored on the server
3. **Final commit**: When `close()` is called, the final offset is stored
4. **Fire-and-forget**: Storage happens asynchronously in the background

```
Message Flow with Auto-Commit (interval=10):

Offset:  0   1   2   3   4   5   6   7   8   9   10  11  12
         │   │   │   │   │   │   │   │   │   │   │   │
Process: ✓   ✓   ✓   ✓   ✓   ✓   ✓   ✓   ✓   ✓   ✓   ✓   ✓
         │                           │               │
         └───────────┬───────────────┘               │
                     │                               │
              Store offset 9                    Store offset 12
              (every 10th msg)                  (on close())
```

### Trade-offs

| Aspect | Manual Commit | Auto-Commit |
|--------|--------------|-------------|
| **Durability** | High (store after each message) | Medium (store every N messages) |
| **Performance** | Lower (more server round-trips) | Higher (fewer round-trips) |
| **Code complexity** | Higher (explicit storeOffset calls) | Lower (automatic) |
| **Reprocessing** | None (exactly-once) | Up to N messages (at-least-once) |

### Choosing the Auto-Commit Interval

```php
// High reliability: small interval
// May reprocess up to 10 messages after crash
$consumer = $connection->createConsumer(
    'events',
    OffsetSpec::first(),
    name: 'reliable-consumer',
    autoCommit: 10
);

// Balanced: medium interval
// May reprocess up to 100 messages after crash
$consumer = $connection->createConsumer(
    'events',
    OffsetSpec::first(),
    name: 'balanced-consumer',
    autoCommit: 100
);

// High throughput: large interval
// May reprocess up to 1000 messages after crash
$consumer = $connection->createConsumer(
    'events',
    OffsetSpec::first(),
    name: 'fast-consumer',
    autoCommit: 1000
);
```

### Recovery After Restart

When the consumer restarts, it can resume from the last stored offset:

```php
// Check for existing offset
$tempConsumer = $connection->createConsumer(
    'events',
    OffsetSpec::first(),
    name: 'my-consumer'
);

$startOffset = OffsetSpec::first();
try {
    $lastOffset = $tempConsumer->queryOffset();
    $startOffset = OffsetSpec::offset($lastOffset + 1);
    echo "Resuming from offset: {$lastOffset}\n";
} catch (\Exception $e) {
    echo "Starting from beginning\n";
} finally {
    $tempConsumer->close();
}

// Create consumer with auto-commit
$consumer = $connection->createConsumer(
    'events',
    $startOffset,
    name: 'my-consumer',
    autoCommit: 100
);
```

## Running the Example

1. Start RabbitMQ with the stream plugin enabled:
```bash
docker run -d --name rabbitmq-stream \
  -p 5552:5552 \
  -p 15672:15672 \
  -e RABBITMQ_SERVER_ADDITIONAL_ERL_ARGS='-rabbitmq_stream advertised_host localhost' \
  rabbitmq:3.13-management-alpine
```

2. Enable the stream plugin:
```bash
docker exec rabbitmq-stream rabbitmq-plugins enable rabbitmq_stream
```

3. Publish some test messages (see [Basic Producer Example](basic-producer.md)):
```bash
cd /path/to/rabbit-stream
php docs/en/examples/basic-producer.php
```

4. Run the auto-commit example:
```bash
cd /path/to/rabbit-stream
php docs/en/examples/consumer-auto-commit.php
```

5. Run it again to see the resume behavior:
```bash
php docs/en/examples/consumer-auto-commit.php
```

## Expected Output

**First Run:**
```
=== Consumer Auto-Commit Example ===

Step 1: Creating connection...
  ✓ Connected to 127.0.0.1:5552

Step 2: Creating stream 'example-stream'...
  ℹ Stream may already exist: Stream already exists

Step 3: Checking for existing offset...
  ℹ No stored offset found (first run)

Step 4: Creating consumer with auto-commit...
  ✓ Consumer created
  ℹ Auto-commit interval: 10 messages
  ℹ Consumer name: auto-commit-demo

Step 5: Processing messages (max 50)...
  ✓ [0] Processed message 1
  ✓ [1] Processed message 2
  ...
  ✓ [9] Processed 10 messages (auto-commit triggered)
  ...
  ✓ [19] Processed 20 messages (auto-commit triggered)
  ...
  ℹ Reached message limit (50)

  ℹ Last auto-commit at offset: 49
  ℹ Final offset will be stored on close()

Step 6: Cleaning up...
  ✓ Consumer closed (final offset stored)
  ✓ Connection closed

=== Example Complete ===
Messages processed: 50
Consumer name: auto-commit-demo

Run this example again to see resume behavior!
```

**Second Run (Resume):**
```
=== Consumer Auto-Commit Example ===

Step 1: Creating connection...
  ✓ Connected to 127.0.0.1:5552

Step 2: Creating stream 'example-stream'...
  ℹ Stream may already exist: Stream already exists

Step 3: Checking for existing offset...
  ✓ Found stored offset: 49
  ℹ Will resume from offset 50

Step 4: Creating consumer with auto-commit...
  ✓ Consumer created
  ℹ Auto-commit interval: 10 messages
  ℹ Consumer name: auto-commit-demo

Step 5: Processing messages (max 50)...
  ✓ [50] Processed message 1
  ...
```

## Best Practices

### 1. Choose the Right Interval

```php
// For critical data (payments, orders): small interval
$consumer = $connection->createConsumer(
    'payments',
    OffsetSpec::first(),
    name: 'payment-processor',
    autoCommit: 10  // Max 10 messages reprocessed
);

// For analytics: larger interval
$consumer = $connection->createConsumer(
    'analytics',
    OffsetSpec::first(),
    name: 'analytics-consumer',
    autoCommit: 1000  // Max 1000 messages reprocessed
);
```

### 2. Handle Duplicate Messages

Since auto-commit provides at-least-once semantics, your processing should be idempotent:

```php
function processMessage(Message $message): void
{
    $data = json_decode($message->getBody(), true);
    
    // Use idempotent operations
    // Example: UPSERT instead of INSERT
    $db->upsert('events', 
        ['id' => $data['id']],  // Unique key
        $data  // Data to insert/update
    );
}
```

### 3. Monitor Offset Lag

```php
// Periodically check how far behind the consumer is
function checkOffsetLag(Connection $connection, string $stream, string $consumerName): void
{
    $tempConsumer = $connection->createConsumer(
        $stream,
        OffsetSpec::first(),
        name: $consumerName
    );
    
    try {
        $storedOffset = $tempConsumer->queryOffset();
        // Get latest offset from stream stats
        $latestOffset = getLatestStreamOffset($connection, $stream);
        
        $lag = $latestOffset - $storedOffset;
        
        if ($lag > 1000) {
            alert("Consumer {$consumerName} is {$lag} messages behind!");
        }
    } catch (\Exception $e) {
        // No offset stored yet
    } finally {
        $tempConsumer->close();
    }
}
```

## See Also

- [Basic Consumer Example](basic-consumer.md) - Simple consumer without offset tracking
- [Offset Resume Example](offset-resume.md) - Manual offset management
- [Consuming Guide](../guide/consuming.md) - Comprehensive consuming documentation
- [Offset Tracking Guide](../guide/offset-tracking.md) - Detailed offset management
- [Consumer API Reference](../api-reference/consumer.md) - Complete API documentation
