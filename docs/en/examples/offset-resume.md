# Offset Resume Example

This example demonstrates the complete pattern for resuming message consumption from a stored offset. This is essential for building fault-tolerant consumers that can recover from restarts without losing or reprocessing messages.

## Overview

Offset resume allows consumers to:
- Persist their position in a stream
- Resume from the exact point after a restart
- Avoid reprocessing already-handled messages
- Handle failures gracefully

## Complete Working Example

```php
<?php

declare(strict_types=1);

use CrazyGoat\RabbitStream\Client\Connection;
use CrazyGoat\RabbitStream\VO\OffsetSpec;

require_once __DIR__ . '/../../vendor/autoload.php';

/**
 * Offset Resume Example
 * 
 * Demonstrates:
 * - Querying stored offsets
 * - Resuming from last offset + 1
 * - Complete resume pattern with error handling
 * - Named consumer best practices
 */
class OffsetResumeExample
{
    private Connection $connection;
    private string $consumerName = 'offset-resume-demo';
    private int $messagesToProcess = 30;
    
    public function run(): void
    {
        echo "=== Offset Resume Example ===\n\n";
        
        // Step 1: Create connection
        $this->createConnection();
        
        // Step 2: Create the stream (if it doesn't exist)
        $this->createStream();
        
        // Step 3: Create consumer with resume capability
        $consumer = $this->createResumingConsumer();
        
        // Step 4: Process messages with offset tracking
        $processed = $this->processMessages($consumer);
        
        // Step 5: Cleanup
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
    
    /**
     * Create a consumer that resumes from the last stored offset
     */
    private function createResumingConsumer(): \CrazyGoat\RabbitStream\Client\Consumer
    {
        echo "Step 3: Creating resuming consumer...\n";
        
        // First, try to query the last stored offset
        $tempConsumer = $this->connection->createConsumer(
            stream: 'example-stream',
            offset: OffsetSpec::first(),
            name: $this->consumerName
        );
        
        $startOffset = OffsetSpec::first();
        $resumeInfo = "Starting from beginning (no stored offset)";
        
        try {
            $lastOffset = $tempConsumer->queryOffset();
            $tempConsumer->close();
            
            // Resume from the next offset after the last stored
            $startOffset = OffsetSpec::offset($lastOffset + 1);
            $resumeInfo = "Resuming from offset " . ($lastOffset + 1) . " (last stored: {$lastOffset})";
            
            echo "  ✓ Found stored offset: {$lastOffset}\n";
            echo "  ✓ {$resumeInfo}\n";
        } catch (\Exception $e) {
            $tempConsumer->close();
            echo "  ℹ No stored offset found (first run or offset expired)\n";
            echo "  ℹ {$resumeInfo}\n";
        }
        
        // Create the actual consumer with the determined offset
        $consumer = $this->connection->createConsumer(
            stream: 'example-stream',
            offset: $startOffset,
            name: $this->consumerName,
            initialCredit: 20
        );
        
        echo "  ✓ Consumer created\n";
        echo "  ℹ Consumer name: {$this->consumerName}\n\n";
        
        return $consumer;
    }
    
    /**
     * Process messages and store offset after each one
     */
    private function processMessages(\CrazyGoat\RabbitStream\Client\Consumer $consumer): int
    {
        echo "Step 4: Processing messages (max {$this->messagesToProcess})...\n";
        
        $processed = 0;
        $lastStoredOffset = -1;
        
        try {
            while ($processed < $this->messagesToProcess) {
                // Read messages with 5-second timeout
                $messages = $consumer->read(timeout: 5.0);
                
                if (empty($messages)) {
                    echo "  ℹ No new messages, waiting...\n";
                    continue;
                }
                
                foreach ($messages as $message) {
                    $currentOffset = $message->getOffset();
                    
                    // Process the message
                    $success = $this->processMessage($message);
                    
                    if ($success) {
                        // Store offset ONLY after successful processing
                        $consumer->storeOffset($currentOffset);
                        $lastStoredOffset = $currentOffset;
                        
                        $processed++;
                        
                        echo "  ✓ [{$currentOffset}] Processed message {$processed} ";
                        echo "(offset stored)\n";
                        
                        if ($processed >= $this->messagesToProcess) {
                            echo "  ℹ Reached message limit ({$this->messagesToProcess})\n";
                            break 2;
                        }
                    } else {
                        echo "  ✗ [{$currentOffset}] Failed to process message\n";
                        echo "  ℹ Stopping to prevent data loss\n";
                        break 2;
                    }
                }
            }
        } catch (\Exception $e) {
            echo "  ✗ Error: {$e->getMessage()}\n";
        }
        
        echo "\n  ℹ Last stored offset: {$lastStoredOffset}\n";
        echo "  ℹ On next run, will resume from offset " . ($lastStoredOffset + 1) . "\n\n";
        
        return $processed;
    }
    
    /**
     * Simulate message processing
     * Returns true on success, false on failure
     */
    private function processMessage(\CrazyGoat\RabbitStream\Client\Message $message): bool
    {
        try {
            $body = $message->getBody();
            
            // Simulate processing work
            // In a real application, you might:
            // - Parse JSON/XML
            // - Validate data
            // - Update database
            // - Call external APIs
            // - etc.
            
            // Small delay to simulate work
            usleep(1000); // 1ms
            
            // Simulate occasional failures (5% chance)
            // In production, this would be real error handling
            if (rand(1, 100) <= 5) {
                echo "\n  ⚠ Simulated processing failure\n";
                return false;
            }
            
            return true;
        } catch (\Exception $e) {
            echo "\n  ✗ Processing error: {$e->getMessage()}\n";
            return false;
        }
    }
    
    private function cleanup(\CrazyGoat\RabbitStream\Client\Consumer $consumer): void
    {
        echo "Step 5: Cleaning up...\n";
        
        $consumer->close();
        echo "  ✓ Consumer closed\n";
        
        $this->connection->close();
        echo "  ✓ Connection closed\n";
    }
}

// Run the example
$example = new OffsetResumeExample();
$example->run();
```

## Key Concepts

### The Resume Pattern

The complete resume pattern involves three steps:

```php
// 1. Query the last stored offset
$tempConsumer = $connection->createConsumer($stream, OffsetSpec::first(), name: $consumerName);

$startOffset = OffsetSpec::first();
try {
    $lastOffset = $tempConsumer->queryOffset();
    $tempConsumer->close();
    
    // 2. Resume from next offset (last + 1)
    $startOffset = OffsetSpec::offset($lastOffset + 1);
} catch (\Exception $e) {
    $tempConsumer->close();
    // 3. No stored offset - start from beginning
    $startOffset = OffsetSpec::first();
}

// Create consumer with determined offset
$consumer = $connection->createConsumer($stream, $startOffset, name: $consumerName);
```

### Why Resume from offset + 1?

```
Stream offsets:  [0]  [1]  [2]  [3]  [4]  [5]  [6]
                 │    │    │    │    │    │    │
Processed:       ✓    ✓    ✓    ✓    ✓    │    │
                 │    │    │    │    │    │    │
Stored offset:   4 ───────────────────────┘    │
                                               │
Resume from:     5 (offset + 1) ───────────────┘
```

The stored offset represents the **last successfully processed** message. To avoid reprocessing it, we resume from the **next** offset (stored + 1).

### Named Consumers

Offset tracking requires a named consumer:

```php
// Good: named consumer can store and query offsets
$consumer = $connection->createConsumer(
    'events',
    OffsetSpec::first(),
    name: 'payment-processor-v1'  // Unique name
);

// Bad: unnamed consumer cannot track offsets
$consumer = $connection->createConsumer(
    'events',
    OffsetSpec::first()
    // No name = no offset tracking!
);
```

**Naming Best Practices:**
- Use descriptive names: `{service}-{version}`
- Include version numbers: `order-processor-v2`
- Keep names consistent across restarts
- Avoid dynamic or random names

### Store Offset After Processing

Always store the offset **after** successful processing:

```php
foreach ($messages as $message) {
    // 1. Process first
    $success = processMessage($message);
    
    if ($success) {
        // 2. Store offset only after success
        $consumer->storeOffset($message->getOffset());
    } else {
        // 3. Don't store on failure - message will be reprocessed
        handleFailure($message);
        break;
    }
}
```

This ensures exactly-once processing semantics.

### Handling Missing Offsets

On first run (or if offsets expire), `queryOffset()` will throw an exception:

```php
try {
    $lastOffset = $consumer->queryOffset();
    $startOffset = OffsetSpec::offset($lastOffset + 1);
    echo "Resuming from offset: {$lastOffset}\n";
} catch (\Exception $e) {
    // No stored offset - this is normal for first run
    $startOffset = OffsetSpec::first();
    echo "Starting from beginning\n";
}
```

## Resume Patterns

### Pattern 1: Simple Resume (shown in example)

Query offset, create new consumer from offset+1:

```php
function createResumingConsumer(
    Connection $connection,
    string $stream,
    string $consumerName
): Consumer {
    $tempConsumer = $connection->createConsumer($stream, OffsetSpec::first(), name: $consumerName);
    
    $startOffset = OffsetSpec::first();
    try {
        $lastOffset = $tempConsumer->queryOffset();
        $tempConsumer->close();
        $startOffset = OffsetSpec::offset($lastOffset + 1);
    } catch (\Exception $e) {
        $tempConsumer->close();
    }
    
    return $connection->createConsumer($stream, $startOffset, name: $consumerName);
}
```

### Pattern 2: Using OffsetSpec::next()

Use the built-in `next()` type for automatic resume:

```php
// Automatically resumes from stored offset
$consumer = $connection->createConsumer(
    'events',
    OffsetSpec::next(),  // Uses stored offset
    name: 'my-consumer'
);
```

**Note**: `OffsetSpec::next()` requires a previously stored offset. If none exists, it starts from the beginning.

### Pattern 3: Resume with Consumer Recovery

Handle connection failures and retry:

```php
function consumeWithResume(
    Connection $connection,
    string $stream,
    string $consumerName,
    callable $processor,
    int $maxRetries = 3
): void {
    $retries = 0;
    
    while ($retries < $maxRetries) {
        try {
            // Resume from last offset
            $consumer = createResumingConsumer($connection, $stream, $consumerName);
            
            while ($message = $consumer->readOne()) {
                $processor($message);
                $consumer->storeOffset($message->getOffset());
            }
            
            $consumer->close();
            return;
            
        } catch (\Exception $e) {
            $retries++;
            echo "Error: {$e->getMessage()}. Retry {$retries}/{$maxRetries}\n";
            sleep(1);
        }
    }
    
    throw new \Exception("Failed after {$maxRetries} retries");
}
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

4. Run the offset resume example:
```bash
cd /path/to/rabbit-stream
php docs/en/examples/offset-resume.php
```

5. Run it again to see the resume behavior:
```bash
php docs/en/examples/offset-resume.php
```

## Expected Output

**First Run:**
```
=== Offset Resume Example ===

Step 1: Creating connection...
  ✓ Connected to 127.0.0.1:5552

Step 2: Creating stream 'example-stream'...
  ℹ Stream may already exist: Stream already exists

Step 3: Creating resuming consumer...
  ℹ No stored offset found (first run or offset expired)
  ℹ Starting from beginning (no stored offset)
  ✓ Consumer created
  ℹ Consumer name: offset-resume-demo

Step 4: Processing messages (max 30)...
  ✓ [0] Processed message 1 (offset stored)
  ✓ [1] Processed message 2 (offset stored)
  ✓ [2] Processed message 3 (offset stored)
  ...
  ✓ [29] Processed message 30 (offset stored)
  ℹ Reached message limit (30)

  ℹ Last stored offset: 29
  ℹ On next run, will resume from offset 30

Step 5: Cleaning up...
  ✓ Consumer closed
  ✓ Connection closed

=== Example Complete ===
Messages processed: 30
Consumer name: offset-resume-demo

Run this example again to see resume behavior!
```

**Second Run (Resume):**
```
=== Offset Resume Example ===

Step 1: Creating connection...
  ✓ Connected to 127.0.0.1:5552

Step 2: Creating stream 'example-stream'...
  ℹ Stream may already exist: Stream already exists

Step 3: Creating resuming consumer...
  ✓ Found stored offset: 29
  ✓ Resuming from offset 30 (last stored: 29)
  ✓ Consumer created
  ℹ Consumer name: offset-resume-demo

Step 4: Processing messages (max 30)...
  ✓ [30] Processed message 1 (offset stored)
  ✓ [31] Processed message 2 (offset stored)
  ...
```

Notice how the second run starts from offset 30, not offset 0!

## Best Practices

### 1. Use Descriptive Consumer Names

```php
// Good: descriptive and versioned
name: 'order-processor-v2'
name: 'payment-service-prod'
name: 'analytics-consumer-2024'

// Bad: non-descriptive or dynamic
name: 'consumer-' . uniqid()  // Changes every restart!
name: 'my-consumer'           // Too generic
```

### 2. Handle Processing Failures

```php
foreach ($messages as $message) {
    try {
        // Process first
        processMessage($message);
        
        // Store offset only after success
        $consumer->storeOffset($message->getOffset());
    } catch (\Exception $e) {
        // Don't store offset on failure
        logError($e, $message);
        
        // Decide whether to:
        // - Stop processing (conservative)
        // - Skip message (with dead letter queue)
        // - Retry immediately
        break;
    }
}
```

### 3. Make Processing Idempotent

Since failures can cause reprocessing, ensure your operations are idempotent:

```php
function processMessage(Message $message): void
{
    $data = json_decode($message->getBody(), true);
    
    // Use UPSERT instead of INSERT
    $db->upsert('orders', 
        ['order_id' => $data['order_id']],
        $data
    );
    
    // Or check if already processed
    if (!isProcessed($data['order_id'])) {
        processOrder($data);
        markAsProcessed($data['order_id']);
    }
}
```

### 4. Monitor Offset Lag

```php
function checkOffsetLag(
    Connection $connection,
    string $stream,
    string $consumerName
): int {
    $tempConsumer = $connection->createConsumer(
        $stream,
        OffsetSpec::first(),
        name: $consumerName
    );
    
    try {
        $storedOffset = $tempConsumer->queryOffset();
        $latestOffset = getLatestStreamOffset($connection, $stream);
        
        return $latestOffset - $storedOffset;
    } catch (\Exception $e) {
        return 0;
    } finally {
        $tempConsumer->close();
    }
}
```

### 5. Clean Up Old Offsets

When deploying new consumer versions, clean up old offset storage:

```php
// On deployment of v2, clean up v1
if (getenv('CONSUMER_VERSION') === 'v2') {
    deleteConsumerOffset('order-processor-v1');
}

$consumer = $connection->createConsumer(
    'orders',
    OffsetSpec::first(),
    name: 'order-processor-v2'
);
```

## Common Pitfalls

### 1. Storing Offset Before Processing

```php
// Wrong: may lose messages on crash
foreach ($messages as $message) {
    $consumer->storeOffset($message->getOffset());  // Stored before processing!
    processMessage($message);  // If crash here, message is lost
}

// Right: store after successful processing
foreach ($messages as $message) {
    processMessage($message);
    $consumer->storeOffset($message->getOffset());  // Stored after success
}
```

### 2. Using Dynamic Consumer Names

```php
// Wrong: new name every restart = no resume possible
$consumer = $connection->createConsumer(
    'events',
    OffsetSpec::first(),
    name: 'consumer-' . gethostname()  // Changes on every host!
);

// Right: consistent name across restarts
$consumer = $connection->createConsumer(
    'events',
    OffsetSpec::first(),
    name: 'payment-processor-v1'  // Consistent name
);
```

### 3. Not Handling Missing Offset Exception

```php
// Wrong: exception on first run
$lastOffset = $consumer->queryOffset();  // Throws if no offset stored!

// Right: handle missing offset gracefully
try {
    $lastOffset = $consumer->queryOffset();
} catch (\Exception $e) {
    $lastOffset = -1;  // No offset stored yet
}
```

## See Also

- [Basic Consumer Example](basic-consumer.md) - Simple consumer without offset tracking
- [Consumer Auto-Commit Example](consumer-auto-commit.md) - Automatic offset management
- [Consuming Guide](../guide/consuming.md) - Comprehensive consuming documentation
- [Offset Tracking Guide](../guide/offset-tracking.md) - Detailed offset management
- [Consumer API Reference](../api-reference/consumer.md) - Complete API documentation
