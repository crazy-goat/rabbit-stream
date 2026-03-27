# Named Producer Deduplication Example

This example demonstrates message deduplication using named producers in RabbitMQ Streams. Named producers track publishing IDs server-side, enabling exactly-once semantics across reconnections.

## Overview

When a connection drops and reconnects, messages may be published twice. Named producers solve this by:

1. Assigning a unique name to each producer
2. Tracking the highest confirmed publishing ID server-side
3. Automatically deduplicating messages with IDs ≤ last confirmed ID

## Complete Working Example

```php
<?php

declare(strict_types=1);

use CrazyGoat\RabbitStream\Client\Connection;
use CrazyGoat\RabbitStream\Client\ConfirmationStatus;

require_once __DIR__ . '/../../vendor/autoload.php';

/**
 * Named Producer Deduplication Example
 * 
 * Demonstrates:
 * - Creating a named producer
 * - Publishing with sequence numbers
 * - Simulating a disconnect/reconnect
 * - Querying the last confirmed sequence
 * - Deduplication in action
 */
class NamedProducerDeduplicationExample
{
    private string $producerName = 'order-producer';
    private string $streamName = 'orders-stream';
    private array $confirmedMessages = [];
    private array $failedMessages = [];
    
    public function run(): void
    {
        echo "=== Named Producer Deduplication Example ===\n\n";
        
        // Phase 1: Initial connection and publishing
        echo "PHASE 1: Initial Connection\n";
        echo str_repeat('-', 40) . "\n";
        $connection1 = $this->createConnection();
        $this->createStream($connection1);
        $producer1 = $this->createNamedProducer($connection1);
        
        // Publish messages 1-5
        echo "Publishing messages 1-5...\n";
        for ($i = 1; $i <= 5; $i++) {
            $producer1->send("Order #{$i}");
            echo "  → Sent Order #{$i} (ID: {$producer1->getLastPublishingId()})\n";
        }
        
        $this->waitForConfirms($producer1);
        $this->showStatus("After Phase 1");
        
        // Phase 2: Simulate disconnect
        echo "\nPHASE 2: Simulating Disconnect\n";
        echo str_repeat('-', 40) . "\n";
        echo "Closing connection (simulating network failure)...\n";
        $producer1->close();
        $connection1->close();
        echo "  ✓ Connection closed\n";
        
        // Phase 3: Reconnect with same producer name
        echo "\nPHASE 3: Reconnecting\n";
        echo str_repeat('-', 40) . "\n";
        $connection2 = $this->createConnection();
        $producer2 = $this->createNamedProducer($connection2);
        
        // Query the sequence - should be 5
        $lastSequence = $producer2->querySequence();
        echo "Last confirmed sequence from server: {$lastSequence}\n";
        echo "Next publishing ID will be: " . ($lastSequence + 1) . "\n";
        
        // Phase 4: Demonstrate deduplication
        echo "\nPHASE 4: Deduplication in Action\n";
        echo str_repeat('-', 40) . "\n";
        
        // Try to "retry" messages 3, 4, 5 (these should be deduplicated)
        echo "Attempting to retry messages 3, 4, 5 (should be deduplicated)...\n";
        $producer2->send("Order #3 (retry)");
        $producer2->send("Order #4 (retry)");
        $producer2->send("Order #5 (retry)");
        
        // Send a new message (should succeed)
        echo "Sending new message 6...\n";
        $producer2->send("Order #6 (new)");
        
        $this->waitForConfirms($producer2);
        $this->showStatus("After Phase 4");
        
        // Phase 5: Cleanup
        echo "\nPHASE 5: Cleanup\n";
        echo str_repeat('-', 40) . "\n";
        $producer2->close();
        $connection2->close();
        echo "  ✓ Cleanup complete\n";
        
        // Summary
        echo "\n=== Summary ===\n";
        echo "Total confirmed (unique): " . count($this->confirmedMessages) . "\n";
        echo "Total failed/duplicates: " . count($this->failedMessages) . "\n";
        echo "\nConfirmed messages:\n";
        foreach ($this->confirmedMessages as $id => $msg) {
            echo "  #{$id}: {$msg}\n";
        }
    }
    
    private function createConnection(): Connection
    {
        $host = getenv('RABBITMQ_HOST') ?: '127.0.0.1';
        $port = (int)(getenv('RABBITMQ_PORT') ?: 5552);
        
        return Connection::create(
            host: $host,
            port: $port,
            user: 'guest',
            password: 'guest',
        );
    }
    
    private function createStream(Connection $connection): void
    {
        try {
            $connection->createStream($this->streamName, [
                'max-length-bytes' => '1000000000',
            ]);
            echo "  ✓ Stream '{$this->streamName}' created\n";
        } catch (\Exception $e) {
            echo "  ℹ Stream may already exist\n";
        }
    }
    
    private function createNamedProducer(Connection $connection): \CrazyGoat\RabbitStream\Client\Producer
    {
        echo "Creating named producer '{$this->producerName}'...\n";
        
        $producer = $connection->createProducer(
            $this->streamName,
            name: $this->producerName,
            onConfirm: function (ConfirmationStatus $status) {
                if ($status->isConfirmed()) {
                    $id = $status->getPublishingId();
                    $this->confirmedMessages[$id] = true;
                    echo "    ✓ Confirmed: #{$id}\n";
                } else {
                    $id = $status->getPublishingId();
                    $this->failedMessages[$id] = $status->getErrorCode();
                    echo "    ✗ Failed/duplicate: #{$id} (code: {$status->getErrorCode()})\n";
                }
            }
        );
        
        echo "  ✓ Producer created\n";
        
        return $producer;
    }
    
    private function waitForConfirms(\CrazyGoat\RabbitStream\Client\Producer $producer): void
    {
        try {
            $producer->waitForConfirms(timeout: 5.0);
            echo "  ✓ All confirms received\n";
        } catch (\CrazyGoat\RabbitStream\Exception\TimeoutException $e) {
            echo "  ⚠ Timeout waiting for confirms\n";
        }
    }
    
    private function showStatus(string $phase): void
    {
        echo "\n  Status [{$phase}]:\n";
        echo "    - Confirmed: " . count($this->confirmedMessages) . "\n";
        echo "    - Failed/Duplicates: " . count($this->failedMessages) . "\n";
    }
}

// Run the example
$example = new NamedProducerDeduplicationExample();
$example->run();
```

## How Deduplication Works

### Publishing ID Tracking

Each message published by a named producer has a unique publishing ID:

```php
// First connection
$producer1 = $connection->createProducer('orders', name: 'order-producer');
$producer1->send("Order #1"); // ID: 1
$producer1->send("Order #2"); // ID: 2
$producer1->send("Order #3"); // ID: 3
```

The server tracks: `order-producer` → last confirmed ID = 3

### Reconnect and Resume

```php
// Connection drops, reconnect with same name
$producer2 = $connection->createProducer('orders', name: 'order-producer');

// Automatically queries sequence from server
$lastId = $producer2->querySequence(); // Returns 3
$nextId = $producer2->getLastPublishingId() + 1; // Returns 4
```

### Deduplication Logic

```php
// These will be deduplicated (IDs 1-3 ≤ last confirmed ID 3)
$producer2->send("Order #1 (retry)"); // Deduplicated
$producer2->send("Order #2 (retry)"); // Deduplicated
$producer2->send("Order #3 (retry)"); // Deduplicated

// This will be stored (ID 4 > last confirmed ID 3)
$producer2->send("Order #4 (new)");   // Stored
```

## Key Methods

### querySequence()

Query the last confirmed publishing ID from the server:

```php
$lastConfirmedId = $producer->querySequence();
echo "Server has confirmed up to ID: {$lastConfirmedId}";
```

This is automatically called when creating a named producer, but you can call it manually after reconnecting.

### getLastPublishingId()

Get the last publishing ID used locally:

```php
$lastId = $producer->getLastPublishingId();
echo "Last published ID: {$lastId}";
```

Returns `null` if no messages have been published yet.

## Deduplication Flow Diagram

```
┌─────────────────────────────────────────────────────────────────────────────┐
│                        Deduplication Flow                                    │
└─────────────────────────────────────────────────────────────────────────────┘

Connection 1:
  ┌─────────────┐
  │ Producer A  │──► Publish [seq=1] ──► Server (stored, last=1)
  │  (name=X)   │──► Publish [seq=2] ──► Server (stored, last=2)
  │             │──► Publish [seq=3] ──► Server (stored, last=3)
  └─────────────┘
         │
         ▼ (connection drops)

Connection 2:
  ┌─────────────┐
  │ Producer B  │──► querySequence() ──► Server returns 3
  │  (name=X)   │──► Publish [seq=1] ──► Server (duplicate, ignored)
  │             │──► Publish [seq=2] ──► Server (duplicate, ignored)
  │             │──► Publish [seq=3] ──► Server (duplicate, ignored)
  │             │──► Publish [seq=4] ──► Server (stored, last=4)
  └─────────────┘

Key Points:
• Deduplication is per-producer-name, not per-connection
• Server tracks last confirmed ID for each named producer
• Messages with ID ≤ last confirmed are silently ignored
• Different producer names have independent deduplication state
```

## Best Practices

### 1. Use Meaningful Producer Names

```php
// Good: Descriptive and unique per stream
$producer = $connection->createProducer('orders', name: 'payment-service-producer');

// Bad: Generic or non-unique names
$producer = $connection->createProducer('orders', name: 'producer');
```

### 2. Handle Reconnects Gracefully

```php
function publishWithReconnect($connection, $stream, $producerName, $messages) {
    $attempts = 0;
    $maxAttempts = 3;
    
    while ($attempts < $maxAttempts) {
        try {
            $producer = $connection->createProducer($stream, name: $producerName);
            
            foreach ($messages as $msg) {
                $producer->send($msg);
            }
            
            $producer->waitForConfirms(timeout: 5.0);
            $producer->close();
            
            return true; // Success
        } catch (ConnectionException $e) {
            $attempts++;
            echo "Connection lost, attempt {$attempts}/{$maxAttempts}\n";
            sleep(1);
            $connection = Connection::create(/* ... */);
        }
    }
    
    return false; // Failed after retries
}
```

### 3. Track Publishing IDs for Debugging

```php
$sentMessages = [];

$producer = $connection->createProducer(
    'orders',
    name: 'order-producer',
    onConfirm: function (ConfirmationStatus $status) use (&$sentMessages) {
        $id = $status->getPublishingId();
        
        if ($status->isConfirmed()) {
            echo "Confirmed: #{$id} - {$sentMessages[$id]}\n";
        } else {
            echo "Failed: #{$id} - code={$status->getErrorCode()}\n";
        }
    }
);

// Track what we send
$msg = "Order #123";
$producer->send($msg);
$sentMessages[$producer->getLastPublishingId()] = $msg;
```

### 4. Don't Share Producer Names Across Different Applications

Each application or service should use a unique producer name:

```php
// Payment service
$producer = $connection->createProducer('orders', name: 'payment-service');

// Inventory service (different name!)
$producer = $connection->createProducer('orders', name: 'inventory-service');
```

## Common Pitfalls

### Pitfall 1: Using the Same Name for Different Streams

```php
// Wrong: Same name on different streams
$producer1 = $connection->createProducer('orders', name: 'producer');
$producer2 = $connection->createProducer('payments', name: 'producer');
// These share deduplication state! Don't do this.
```

### Pitfall 2: Not Waiting for Confirms Before Reconnect

```php
// Wrong: May lose track of which messages were confirmed
$producer->send("Message 1");
$connection->close(); // Don't close before confirms!

// Right: Wait for confirms
$producer->send("Message 1");
$producer->waitForConfirms(timeout: 5.0);
$connection->close();
```

### Pitfall 3: Manual Publishing ID Management

```php
// Don't do this - the Producer class handles it automatically
$publishingId = 1; // Manual tracking
$producer->send($message); // Producer uses its own internal counter
```

## Running the Example

1. Start RabbitMQ with streams enabled:
```bash
docker run -d --name rabbitmq-stream \
  -p 5552:5552 \
  -p 15672:15672 \
  rabbitmq:3.13-management-alpine

docker exec rabbitmq-stream rabbitmq-plugins enable rabbitmq_stream
```

2. Run the example:
```bash
php docs/en/examples/named-producer-deduplication.php
```

## Expected Output

```
=== Named Producer Deduplication Example ===

PHASE 1: Initial Connection
----------------------------------------
  ✓ Stream 'orders-stream' created
Creating named producer 'order-producer'...
  ✓ Producer created
Publishing messages 1-5...
  → Sent Order #1 (ID: 1)
  → Sent Order #2 (ID: 2)
  → Sent Order #3 (ID: 3)
  → Sent Order #4 (ID: 4)
  → Sent Order #5 (ID: 5)
    ✓ Confirmed: #1
    ✓ Confirmed: #2
    ✓ Confirmed: #3
    ✓ Confirmed: #4
    ✓ Confirmed: #5
  ✓ All confirms received

  Status [After Phase 1]:
    - Confirmed: 5
    - Failed/Duplicates: 0

PHASE 2: Simulating Disconnect
----------------------------------------
Closing connection (simulating network failure)...
  ✓ Connection closed

PHASE 3: Reconnecting
----------------------------------------
Creating named producer 'order-producer'...
  ✓ Producer created
Last confirmed sequence from server: 5
Next publishing ID will be: 6

PHASE 4: Deduplication in Action
----------------------------------------
Attempting to retry messages 3, 4, 5 (should be deduplicated)...
Sending new message 6...
    ✗ Failed/duplicate: #3 (code: 0)
    ✗ Failed/duplicate: #4 (code: 0)
    ✗ Failed/duplicate: #5 (code: 0)
    ✓ Confirmed: #6
  ✓ All confirms received

  Status [After Phase 4]:
    - Confirmed: 6
    - Failed/Duplicates: 3

PHASE 5: Cleanup
----------------------------------------
  ✓ Cleanup complete

=== Summary ===
Total confirmed (unique): 6
Total failed/duplicates: 3

Confirmed messages:
  #1: ✓
  #2: ✓
  #3: ✓
  #4: ✓
  #5: ✓
  #6: ✓
```

## See Also

- [Publishing Guide](../guide/publishing.md)
- [Basic Producer Example](basic-producer.md)
- [Producer API Reference](../api-reference/producer.md)
- [Publish Flow Diagram](../assets/diagrams/publish-flow.md)
