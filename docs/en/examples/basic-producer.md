# Basic Producer Example

This example demonstrates the essential patterns for publishing messages to RabbitMQ Streams using the high-level Producer API.

## Complete Working Example

```php
<?php

declare(strict_types=1);

use CrazyGoat\RabbitStream\Client\Connection;
use CrazyGoat\RabbitStream\Client\ConfirmationStatus;

require_once __DIR__ . '/../../vendor/autoload.php';

/**
 * Basic Producer Example
 * 
 * Demonstrates:
 * - Connection creation
 * - Producer creation
 * - Single message publishing
 * - Batch publishing
 * - Confirm handling
 * - Cleanup
 */
class BasicProducerExample
{
    private Connection $connection;
    private int $confirmedCount = 0;
    private int $failedCount = 0;
    
    public function run(): void
    {
        echo "=== Basic Producer Example ===\n\n";
        
        // Step 1: Create connection
        $this->createConnection();
        
        // Step 2: Create the stream (if it doesn't exist)
        $this->createStream();
        
        // Step 3: Create producer with confirm callback
        $producer = $this->createProducer();
        
        // Step 4: Publish single message
        $this->publishSingleMessage($producer);
        
        // Step 5: Publish batch of messages
        $this->publishBatch($producer);
        
        // Step 6: Wait for all confirms
        $this->waitForConfirms($producer);
        
        // Step 7: Cleanup
        $this->cleanup($producer);
        
        echo "\n=== Example Complete ===\n";
        echo "Confirmed: {$this->confirmedCount}\n";
        echo "Failed: {$this->failedCount}\n";
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
            // Stream may already exist, which is fine
            echo "  ℹ Stream may already exist: {$e->getMessage()}\n\n";
        }
    }
    
    private function createProducer(): \CrazyGoat\RabbitStream\Client\Producer
    {
        echo "Step 3: Creating producer...\n";
        
        $producer = $this->connection->createProducer(
            'example-stream',
            onConfirm: function (ConfirmationStatus $status) {
                if ($status->isConfirmed()) {
                    $this->confirmedCount++;
                    echo "  ✓ Confirmed: #{$status->getPublishingId()}\n";
                } else {
                    $this->failedCount++;
                    echo "  ✗ Failed: #{$status->getPublishingId()} ";
                    echo "code={$status->getErrorCode()}\n";
                }
            }
        );
        
        echo "  ✓ Producer created\n\n";
        
        return $producer;
    }
    
    private function publishSingleMessage(\CrazyGoat\RabbitStream\Client\Producer $producer): void
    {
        echo "Step 4: Publishing single message...\n";
        
        $producer->send('Hello, RabbitMQ Streams!');
        
        echo "  ✓ Message sent\n\n";
    }
    
    private function publishBatch(\CrazyGoat\RabbitStream\Client\Producer $producer): void
    {
        echo "Step 5: Publishing batch of 5 messages...\n";
        
        $messages = [];
        for ($i = 1; $i <= 5; $i++) {
            $messages[] = "Batch message #{$i}";
        }
        
        $producer->sendBatch($messages);
        
        echo "  ✓ Batch sent (5 messages)\n\n";
    }
    
    private function waitForConfirms(\CrazyGoat\RabbitStream\Client\Producer $producer): void
    {
        echo "Step 6: Waiting for confirms...\n";
        
        try {
            $producer->waitForConfirms(timeout: 5.0);
            echo "  ✓ All messages confirmed\n\n";
        } catch (\CrazyGoat\RabbitStream\Exception\TimeoutException $e) {
            echo "  ⚠ Timeout: {$e->getMessage()}\n\n";
        }
    }
    
    private function cleanup(\CrazyGoat\RabbitStream\Client\Producer $producer): void
    {
        echo "Step 7: Cleaning up...\n";
        
        $producer->close();
        $this->connection->close();
        
        echo "  ✓ Producer and connection closed\n";
    }
}

// Run the example
$example = new BasicProducerExample();
$example->run();
```

## Key Concepts

### Connection Creation

```php
$connection = Connection::create(
    host: '127.0.0.1',
    port: 5552,
    user: 'guest',
    password: 'guest',
);
```

The `Connection::create()` method handles the complete handshake:
1. TCP connection
2. Peer properties exchange
3. SASL authentication
4. Tune parameters
5. Virtual host open

### Producer with Confirm Callback

```php
$producer = $connection->createProducer(
    'stream-name',
    onConfirm: function (ConfirmationStatus $status) {
        if ($status->isConfirmed()) {
            echo "Confirmed: #{$status->getPublishingId()}\n";
        } else {
            echo "Failed: #{$status->getPublishingId()}\n";
        }
    }
);
```

The `onConfirm` callback is called for each message when the server confirms or rejects it.

### Single vs Batch Publishing

**Single message:**
```php
$producer->send('Hello, World!');
```

**Batch messages:**
```php
$producer->sendBatch(['msg1', 'msg2', 'msg3']);
```

Batch publishing is more efficient for high throughput scenarios.

### Waiting for Confirms

```php
try {
    $producer->waitForConfirms(timeout: 5.0);
} catch (TimeoutException $e) {
    // Handle timeout
}
```

Always wait for confirms before closing the producer to ensure messages are durably stored.

### Cleanup

```php
$producer->close();
$connection->close();
```

Properly close resources to free server-side publisher slots and TCP connections.

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

3. Run the example:
```bash
cd /path/to/rabbit-stream
php docs/en/examples/basic-producer.php
```

## Expected Output

```
=== Basic Producer Example ===

Step 1: Creating connection...
  ✓ Connected to 127.0.0.1:5552

Step 2: Creating stream 'example-stream'...
  ✓ Stream created

Step 3: Creating producer...
  ✓ Producer created

Step 4: Publishing single message...
  ✓ Message sent

Step 5: Publishing batch of 5 messages...
  ✓ Batch sent (5 messages)

Step 6: Waiting for confirms...
  ✓ Confirmed: #1
  ✓ Confirmed: #2
  ✓ Confirmed: #3
  ✓ Confirmed: #4
  ✓ Confirmed: #5
  ✓ Confirmed: #6
  ✓ All messages confirmed

Step 7: Cleaning up...
  ✓ Producer and connection closed

=== Example Complete ===
Confirmed: 6
Failed: 0
```

## Next Steps

- Learn about [Named Producer Deduplication](named-producer-deduplication.md)
- Explore the [Publishing Guide](../guide/publishing.md)
- See the [Producer API Reference](../api-reference/producer.md)
