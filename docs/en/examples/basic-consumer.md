# Basic Consumer Example

This example demonstrates the essential patterns for consuming messages from RabbitMQ Streams using the high-level Consumer API.

## Complete Working Example

```php
<?php

declare(strict_types=1);

use CrazyGoat\RabbitStream\Client\Connection;
use CrazyGoat\RabbitStream\VO\OffsetSpec;

require_once __DIR__ . '/../../vendor/autoload.php';

/**
 * Basic Consumer Example
 * 
 * Demonstrates:
 * - Connection creation
 * - Consumer creation with offset specification
 * - Reading messages in a loop
 * - Processing messages
 * - Proper cleanup with try/finally
 */
class BasicConsumerExample
{
    private Connection $connection;
    private int $messageCount = 0;
    
    public function run(): void
    {
        echo "=== Basic Consumer Example ===\n\n";
        
        // Step 1: Create connection
        $this->createConnection();
        
        // Step 2: Create the stream (if it doesn't exist)
        $this->createStream();
        
        // Step 3: Create consumer
        $consumer = $this->createConsumer();
        
        // Step 4: Consume messages
        $this->consumeMessages($consumer);
        
        // Step 5: Cleanup
        $this->cleanup($consumer);
        
        echo "\n=== Example Complete ===\n";
        echo "Messages consumed: {$this->messageCount}\n";
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
    
    private function createConsumer(): \CrazyGoat\RabbitStream\Client\Consumer
    {
        echo "Step 3: Creating consumer...\n";
        
        // Create consumer starting from the first message
        $consumer = $this->connection->createConsumer(
            stream: 'example-stream',
            offset: OffsetSpec::first(),
            initialCredit: 10
        );
        
        echo "  ✓ Consumer created\n";
        echo "  ℹ Starting from offset: first\n\n";
        
        return $consumer;
    }
    
    private function consumeMessages(\CrazyGoat\RabbitStream\Client\Consumer $consumer): void
    {
        echo "Step 4: Consuming messages (max 10)...\n";
        
        $maxMessages = 10;
        
        try {
            while ($this->messageCount < $maxMessages) {
                // Read messages with 5-second timeout
                $messages = $consumer->read(timeout: 5.0);
                
                if (empty($messages)) {
                    echo "  ℹ No new messages, waiting...\n";
                    continue;
                }
                
                foreach ($messages as $message) {
                    $this->messageCount++;
                    
                    echo "  ✓ [{$message->getOffset()}] ";
                    
                    // Display message body (truncated if too long)
                    $body = $message->getBody();
                    $bodyStr = is_string($body) ? $body : json_encode($body);
                    if (strlen($bodyStr) > 50) {
                        $bodyStr = substr($bodyStr, 0, 50) . '...';
                    }
                    echo "{$bodyStr}\n";
                    
                    if ($this->messageCount >= $maxMessages) {
                        echo "  ℹ Reached message limit ({$maxMessages})\n";
                        break 2;
                    }
                }
            }
        } catch (\Exception $e) {
            echo "  ✗ Error: {$e->getMessage()}\n";
        }
        
        echo "\n";
    }
    
    private function cleanup(\CrazyGoat\RabbitStream\Client\Consumer $consumer): void
    {
        echo "Step 5: Cleaning up...\n";
        
        $consumer->close();
        $this->connection->close();
        
        echo "  ✓ Consumer and connection closed\n";
    }
}

// Run the example
$example = new BasicConsumerExample();
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

### Consumer Creation

```php
$consumer = $connection->createConsumer(
    stream: 'example-stream',
    offset: OffsetSpec::first(),
    initialCredit: 10
);
```

**Parameters:**
- `stream` - Name of the stream to consume from
- `offset` - Starting position (first, last, next, offset, timestamp)
- `initialCredit` - Number of messages to request initially

### Reading Messages

**Read multiple messages:**
```php
$messages = $consumer->read(timeout: 5.0);

foreach ($messages as $message) {
    echo "Offset: {$message->getOffset()}\n";
    echo "Body: {$message->getBody()}\n";
}
```

**Read a single message:**
```php
$message = $consumer->readOne(timeout: 5.0);

if ($message !== null) {
    echo "Received: {$message->getBody()}\n";
}
```

### Proper Cleanup

Always use try/finally to ensure resources are released:

```php
try {
    while ($message = $consumer->readOne()) {
        processMessage($message);
    }
} finally {
    $consumer->close();
    $connection->close();
}
```

The `close()` method:
- Sends `Unsubscribe` command to the server
- Clears the internal message buffer
- Frees the subscription ID for reuse

## Offset Specifications

Choose where to start consuming:

```php
// From the beginning
OffsetSpec::first()

// From the last message (new messages only)
OffsetSpec::last()

// From a specific offset
OffsetSpec::offset(1000)

// From a specific timestamp
OffsetSpec::timestamp(time() - 3600)
```

## Error Handling

```php
use CrazyGoat\RabbitStream\Exception\ConnectionException;
use CrazyGoat\RabbitStream\Exception\TimeoutException;

try {
    $consumer = $connection->createConsumer('non-existent', OffsetSpec::first());
} catch (\Exception $e) {
    echo "Failed to create consumer: {$e->getMessage()}\n";
}

try {
    while (true) {
        $messages = $consumer->read(timeout: 5.0);
        
        foreach ($messages as $message) {
            try {
                processMessage($message);
            } catch (\Exception $e) {
                echo "Failed to process message: {$e->getMessage()}\n";
                // Decide whether to continue or stop
            }
        }
    }
} catch (ConnectionException $e) {
    echo "Connection lost: {$e->getMessage()}\n";
} catch (TimeoutException $e) {
    echo "Read timeout: {$e->getMessage()}\n";
} finally {
    $consumer->close();
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

4. Run the consumer example:
```bash
cd /path/to/rabbit-stream
php docs/en/examples/basic-consumer.php
```

## Expected Output

```
=== Basic Consumer Example ===

Step 1: Creating connection...
  ✓ Connected to 127.0.0.1:5552

Step 2: Creating stream 'example-stream'...
  ℹ Stream may already exist: Stream already exists

Step 3: Creating consumer...
  ✓ Consumer created
  ℹ Starting from offset: first

Step 4: Consuming messages (max 10)...
  ✓ [0] Hello, RabbitMQ Streams!
  ✓ [1] Batch message #1
  ✓ [2] Batch message #2
  ✓ [3] Batch message #3
  ✓ [4] Batch message #4
  ✓ [5] Batch message #5
  ℹ Reached message limit (10)

Step 5: Cleaning up...
  ✓ Consumer and connection closed

=== Example Complete ===
Messages consumed: 6
```

## Next Steps

- Learn about [Offset Tracking and Resume](offset-resume.md)
- Explore [Auto-Commit for Automatic Offset Management](consumer-auto-commit.md)
- See the [Consuming Guide](../guide/consuming.md) for comprehensive documentation
- Review the [Consumer API Reference](../api-reference/consumer.md)
