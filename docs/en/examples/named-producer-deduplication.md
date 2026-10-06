# Named Producer Deduplication Example

This example demonstrates how named producers avoid duplicate messages across a
process restart in RabbitMQ Streams. The broker stores the highest confirmed
publishing ID per producer name, and the client resumes from it — so a restart
continues the sequence instead of replaying it.

## Overview

When a process restarts, it must not publish messages the broker has already
stored. A named producer solves this by:

1. Assigning a unique name to each producer
2. Querying the broker's last confirmed publishing ID for that name on creation
3. Continuing from that ID + 1, so the broker never sees an ID it already stored

The broker also ignores any publish whose ID is `≤` the stored sequence for the
name. The current high-level `Producer` API always advances IDs monotonically
and starts above the stored sequence, so it cannot deliberately re-send an old
ID. See [How Deduplication Works](#how-deduplication-works) below.

## Complete Working Example

```php
<?php

declare(strict_types=1);

use CrazyGoat\RabbitStream\Client\ConfirmationStatus;
use CrazyGoat\RabbitStream\Client\Connection;

require_once __DIR__ . '/../vendor/autoload.php';

/**
 * Named Producer Deduplication Example
 *
 * Demonstrates:
 * - Creating a named producer
 * - Publishing with auto-assigned publishing IDs
 * - Simulating a process restart (disconnect/reconnect)
 * - Resuming from the broker's stored sequence instead of replaying
 * - Why the broker never sees a duplicate publishing ID from this client
 */
class NamedProducerDeduplicationExample
{
    private string $producerName = 'order-producer';
    private string $streamName = 'orders-stream';

    /** @var array<int, string> Message body keyed by the publishing ID it was sent with */
    private array $sentMessages = [];

    /** @var array<int, string> Confirmed message body keyed by publishing ID */
    private array $confirmedMessages = [];

    /** @var array<int, int> Failure code keyed by publishing ID */
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

        // Publish messages 1-5. A named producer starts at querySequence() + 1,
        // so on a fresh stream its first message gets publishing ID 1.
        echo "Publishing messages 1-5...\n";
        for ($i = 1; $i <= 5; $i++) {
            $message = "Order #{$i}";
            $producer1->send($message);
            $this->sentMessages[$producer1->getLastPublishingId()] = $message;
            echo "  → Sent {$message} (ID: {$producer1->getLastPublishingId()})\n";
        }

        $this->waitForConfirms($producer1);
        $this->showStatus("After Phase 1");

        // Phase 2: Simulate a process restart
        echo "\nPHASE 2: Simulating a Restart\n";
        echo str_repeat('-', 40) . "\n";
        echo "Closing connection (simulating process exit)...\n";
        $producer1->close();
        $connection1->close();
        echo "  ✓ Connection closed\n";

        // Phase 3: Reconnect with the same producer name
        echo "\nPHASE 3: Reconnecting\n";
        echo str_repeat('-', 40) . "\n";
        $connection2 = $this->createConnection();
        $producer2 = $this->createNamedProducer($connection2);

        // Phase 4: Resume after the restart
        echo "\nPHASE 4: Resuming After Restart\n";
        echo str_repeat('-', 40) . "\n";

        // The broker has durably stored everything up to ID 5. The producer
        // already resumed there in its constructor; querySequence() returns the
        // same value, so the application knows where to continue.
        $resumeFrom = $producer2->querySequence();
        echo "Broker stored publishing IDs up to: {$resumeFrom}\n";
        echo "Next publishing ID will be: " . ($resumeFrom + 1) . "\n";
        echo "getLastPublishingId() reports: " . $producer2->getLastPublishingId() . "\n";

        // Continue from ID 6. The client does not replay IDs 1-5, so the broker
        // never receives a publishing ID it has already stored.
        echo "Publishing messages 6-7...\n";
        for ($i = 6; $i <= 7; $i++) {
            $message = "Order #{$i}";
            $producer2->send($message);
            $this->sentMessages[$producer2->getLastPublishingId()] = $message;
            echo "  → Sent {$message} (ID: {$producer2->getLastPublishingId()})\n";
        }

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
        echo "Total confirmed: " . count($this->confirmedMessages) . "\n";
        echo "Total failed: " . count($this->failedMessages) . "\n";
        echo "\nConfirmed messages:\n";
        ksort($this->confirmedMessages);
        foreach ($this->confirmedMessages as $id => $message) {
            echo "  #{$id}: {$message}\n";
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
                $id = $status->getPublishingId();
                if ($status->isConfirmed()) {
                    $this->confirmedMessages[$id] = $this->sentMessages[$id] ?? '(unknown)';
                    echo "    ✓ Confirmed: #{$id}\n";
                } else {
                    $this->failedMessages[$id] = $status->getErrorCode();
                    echo "    ✗ Failed: #{$id} (code: {$status->getErrorCode()})\n";
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
        echo "    - Failed: " . count($this->failedMessages) . "\n";
    }
}

// Run the example
$example = new NamedProducerDeduplicationExample();
$example->run();
```

## How Deduplication Works

### Publishing ID Assignment

Every message published by a producer carries a publishing ID. The high-level
`Producer` assigns these IDs itself, monotonically, starting at `0` for an
anonymous producer and at `querySequence() + 1` for a named one:

```php
// Fresh stream, named producer
$producer1 = $connection->createProducer('orders', name: 'order-producer');
$producer1->send("Order #1"); // ID: 1
$producer1->send("Order #2"); // ID: 2
$producer1->send("Order #3"); // ID: 3
```

After the messages are confirmed, the broker stores: `order-producer` → last
confirmed ID = 3.

### Reconnect and Resume

```php
// The process restarts and reconnects with the same producer name
$producer2 = $connection->createProducer('orders', name: 'order-producer');

// The constructor already queried the broker and resumed the sequence
$lastId = $producer2->querySequence();       // Returns 3
$nextId = $producer2->getLastPublishingId() + 1; // Returns 4
```

`querySequence()` is a manual round-trip to the broker; the constructor already
performed one and set the next publishing ID, so calling it again is only for
logging or for deciding which application messages still need to be sent.

### Why the Client Never Sends a Duplicate ID

```php
// These do NOT re-use IDs 1-3. They continue at 4, 5, 6...
$producer2->send("Order #4 (new)"); // ID: 4
$producer2->send("Order #5 (new)"); // ID: 5
$producer2->send("Order #6 (new)"); // ID: 6
```

The API has no way to choose a publishing ID or reset the counter, so an
application cannot deliberately re-send an ID that the broker has already
stored. The broker's deduplication rule — *ignore a publish whose ID is `≤` the
stored sequence* — is what makes the automatic resume safe: if a client reconnects
and continues the same numbering, the broker drops anything it already has. With
the current high-level API that rule is not reachable by hand; the guarantee you
get is that a restart **resumes** rather than **replays**.

## Key Methods

### querySequence()

Query the last confirmed publishing ID from the server:

```php
$lastConfirmedId = $producer->querySequence();
echo "Server has confirmed up to ID: {$lastConfirmedId}";
```

This is automatically called when creating a named producer, but you can call it
manually to log or inspect the broker's state.

### getLastPublishingId()

Get the last publishing ID used locally:

```php
$lastId = $producer->getLastPublishingId();
echo "Last published ID: {$lastId}";
```

Returns `null` only for an anonymous producer that has not published yet. A
**named** producer is initialised from the broker's last confirmed sequence, so
`getLastPublishingId()` already returns a non-null id (`0` when the broker
stored nothing) before the first `send()`.

## Resume Flow Diagram

```
┌─────────────────────────────────────────────────────────────────────────────┐
│                        Resume After Restart                                  │
└─────────────────────────────────────────────────────────────────────────────┘

Process 1:
  ┌──────────────────┐
  │ Producer         │──► Publish [ID=1] ──► Server (stored, last=1)
  │  (name=X)        │──► Publish [ID=2] ──► Server (stored, last=2)
  │                  │──► Publish [ID=3] ──► Server (stored, last=3)
  └──────────────────┘
         │
         ▼ (process exits)

Process 2:
  ┌──────────────────┐
  │ Producer         │──► querySequence() ──► Server returns 3
  │  (name=X)        │    next ID = 4 (set by the constructor)
  │                  │──► Publish [ID=4] ──► Server (stored, last=4)
  │                  │──► Publish [ID=5] ──► Server (stored, last=5)
  └──────────────────┘

Key Points:
• Deduplication state is per-producer-name, not per-connection
• The server tracks the last confirmed ID for each named producer
• The client resumes at stored ID + 1; it never replays old IDs
• Different producer names have independent state
```

## Best Practices

### 1. Use Meaningful Producer Names

```php
// Good: Descriptive and unique per stream
$producer = $connection->createProducer('orders', name: 'payment-service-producer');

// Bad: Generic or non-unique names
$producer = $connection->createProducer('orders', name: 'producer');
```

### 2. Resume, Do Not Replay

After a restart, create the producer with the same name and let the constructor
resume the sequence. Do not try to "retry" earlier messages with earlier IDs —
the API will assign them fresh IDs and the broker will store them as new
messages.

```php
// After a restart, the producer continues at stored ID + 1
$producer = $connection->createProducer($stream, name: $producerName);
$producer->send($nextMessage);
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

### Pitfall 2: Not Waiting for Confirms Before Restart

```php
// Wrong: May lose track of which messages were confirmed
$producer->send("Message 1");
$connection->close(); // Don't close before confirms!

// Right: Wait for confirms
$producer->send("Message 1");
$producer->waitForConfirms(timeout: 5.0);
$connection->close();
```

### Pitfall 3: Expecting the Broker to Deduplicate a Re-Sent Message

```php
// Wrong: this gets a NEW publishing ID and is stored as a second message.
// The API has no way to re-use an old ID, so the broker cannot recognise it
// as a duplicate.
$producer2->send("Order #3 (retry)");
```

To avoid duplicates, resume from `querySequence()` and only send messages that
have not been confirmed yet; do not resend already-stored messages.

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
php examples/named_producer_deduplication.php
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
    - Failed: 0

PHASE 2: Simulating a Restart
----------------------------------------
Closing connection (simulating process exit)...
  ✓ Connection closed

PHASE 3: Reconnecting
----------------------------------------
Creating named producer 'order-producer'...
  ✓ Producer created

PHASE 4: Resuming After Restart
----------------------------------------
Broker stored publishing IDs up to: 5
Next publishing ID will be: 6
getLastPublishingId() reports: 5
Publishing messages 6-7...
  → Sent Order #6 (ID: 6)
  → Sent Order #7 (ID: 7)
    ✓ Confirmed: #6
    ✓ Confirmed: #7
  ✓ All confirms received

  Status [After Phase 4]:
    - Confirmed: 7
    - Failed: 0

PHASE 5: Cleanup
----------------------------------------
  ✓ Cleanup complete

=== Summary ===
Total confirmed: 7
Total failed: 0

Confirmed messages:
  #1: Order #1
  #2: Order #2
  #3: Order #3
  #4: Order #4
  #5: Order #5
  #6: Order #6
  #7: Order #7
```

The IDs above assume a **fresh** `orders-stream` (the first run against the
broker). If the stream already has a stored sequence for `order-producer`, the
constructor resumes above it and the printed IDs will be higher — that is the
deduplication behaviour this example demonstrates.

## See Also

- [Publishing Guide](../guide/publishing.md)
- [Basic Producer Example](basic-producer.md)
- [Producer API Reference](../api-reference/producer.md)
- [Publish Flow Diagram](../../assets/diagrams/publish-flow.md)
