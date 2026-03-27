# Quick Start

Get up and running with RabbitStream in minutes. This guide walks you through creating a stream, publishing messages, and consuming them.

## Prerequisites

Before starting, ensure:

1. **RabbitStream is installed** — See [Installation](./installation.md)
2. **RabbitMQ is running** with the stream plugin enabled on port 5552

Quick start with Docker:

```bash
docker compose up -d
sleep 30  # Wait for RabbitMQ to be ready
```

## Complete Example

Here's a complete end-to-end example that creates a stream, publishes messages, and consumes them:

```php
<?php

declare(strict_types=1);

require_once 'vendor/autoload.php';

use CrazyGoat\RabbitStream\Client\Connection;
use CrazyGoat\RabbitStream\VO\OffsetSpec;

// Configuration
$host = getenv('RABBITMQ_HOST') ?: '127.0.0.1';
$port = (int)(getenv('RABBITMQ_PORT') ?: 5552);
$streamName = 'hello-world-stream';

// Step 1: Connect to RabbitMQ
echo "Connecting to RabbitMQ at {$host}:{$port}...\n";
$connection = Connection::create(
    host: $host,
    port: $port,
    user: 'guest',
    password: 'guest',
);
echo "Connected!\n\n";

// Step 2: Create a stream (if it doesn't exist)
echo "Creating stream '{$streamName}'...\n";
try {
    $connection->createStream($streamName, [
        'max-length-bytes' => '100000000',  // 100MB max size
    ]);
    echo "Stream created!\n\n";
} catch (\Exception $e) {
    if (strpos($e->getMessage(), 'already exists') !== false) {
        echo "Stream already exists, continuing...\n\n";
    } else {
        throw $e;
    }
}

// Step 3: Publish messages
echo "Publishing messages...\n";
$producer = $connection->createProducer(
    stream: $streamName,
    name: 'quick-start-producer',
);

$messages = ['Hello', 'World', 'from', 'RabbitStream!'];
foreach ($messages as $msg) {
    $producer->send($msg);
    echo "Sent: {$msg}\n";
}

// Wait for server confirmations
$producer->waitForConfirms(timeout: 5);
echo "All messages confirmed!\n\n";

$producer->close();

// Step 4: Consume messages
echo "Consuming messages...\n";
$consumer = $connection->createConsumer(
    stream: $streamName,
    offset: OffsetSpec::first(),  // Start from the beginning
    name: 'quick-start-consumer',
);

$received = [];
$messages = $consumer->read(timeout: 5);
foreach ($messages as $msg) {
    $body = $msg->getBody();
    $received[] = $body;
    echo "Received [offset={$msg->getOffset()}]: {$body}\n";
}

// Store offset for potential resume
if (isset($msg)) {
    $consumer->storeOffset($msg->getOffset());
    echo "\nOffset stored: {$msg->getOffset()}\n";
}

$consumer->close();

// Step 5: Cleanup (optional)
echo "\nCleaning up...\n";
$connection->deleteStream($streamName);
echo "Stream deleted.\n";

$connection->close();
echo "\nDone! Received " . count($received) . " messages.\n";
```

Save this as `quick-start.php` and run:

```bash
php quick-start.php
```

## Expected Output

```
Connecting to RabbitMQ at 127.0.0.1:5552...
Connected!

Creating stream 'hello-world-stream'...
Stream created!

Publishing messages...
Sent: Hello
Sent: World
Sent: from
Sent: RabbitStream!
All messages confirmed!

Consuming messages...
Received [offset=0]: Hello
Received [offset=1]: World
Received [offset=2]: from
Received [offset=3]: RabbitStream!

Offset stored: 3

Cleaning up...
Stream deleted.

Done! Received 4 messages.
```

## Step-by-Step Walkthrough

### 1. Connect to RabbitMQ

```php
$connection = Connection::create(
    host: '127.0.0.1',
    port: 5552,
    user: 'guest',
    password: 'guest',
);
```

The `Connection::create()` method handles:
- TCP socket connection
- Protocol handshake (PeerProperties)
- Authentication (SASL)
- Connection tuning
- Virtual host selection

### 2. Create a Stream

```php
$connection->createStream('my-stream', [
    'max-length-bytes' => '100000000',  // 100MB
]);
```

Streams are append-only logs. Common options:

| Option | Description | Example |
|--------|-------------|---------|
| `max-length-bytes` | Maximum stream size in bytes | `'1000000000'` (1GB) |
| `max-age` | Maximum message age | `'24h'`, `'7d'` |
| `max-segment-size-bytes` | Segment file size | `'500000000'` (500MB) |

### 3. Publish Messages

```php
$producer = $connection->createProducer(
    stream: 'my-stream',
    name: 'my-producer',
);

$producer->send('Hello World');
$producer->waitForConfirms(timeout: 5);
```

The producer:
- Registers with the server
- Sends messages asynchronously
- Receives confirmations from the server
- Must wait for confirms before closing

### 4. Consume Messages

```php
$consumer = $connection->createConsumer(
    stream: 'my-stream',
    offset: OffsetSpec::first(),
    name: 'my-consumer',
);

$messages = $consumer->read(timeout: 5);
foreach ($messages as $msg) {
    echo $msg->getBody();
}
```

Offset specifications:

| Offset | Description |
|--------|-------------|
| `OffsetSpec::first()` | Start from the first message |
| `OffsetSpec::last()` | Start from the last message |
| `OffsetSpec::next()` | Start after the last message (new messages only) |
| `OffsetSpec::offset(123)` | Start from a specific offset |

### 5. Cleanup

Always close resources when done:

```php
$producer->close();
$consumer->close();
$connection->close();
```

## Common First-Time Errors

### Error: Connection refused

```
Warning: socket_connect(): unable to connect [111]: Connection refused
```

**Cause:** RabbitMQ is not running or the stream plugin is not enabled.

**Solution:**
```bash
# Start RabbitMQ with Docker
docker compose up -d

# Or check if RabbitMQ is running
docker ps | grep rabbitmq

# Verify plugin is enabled
rabbitmq-plugins list | grep rabbitmq_stream
```

### Error: Authentication failed

```
Exception: Authentication failed
```

**Cause:** Wrong username or password.

**Solution:**
- Default credentials are `guest`/`guest`
- Check RabbitMQ logs: `docker logs rabbitmq`
- Verify user exists in management UI: http://localhost:15672

### Error: Stream does not exist

```
Exception: Stream 'my-stream' does not exist
```

**Cause:** Trying to consume from a stream that was never created.

**Solution:**
- Create the stream first: `$connection->createStream('my-stream')`
- Or check if it exists: `$connection->streamExists('my-stream')`

### Error: Extension not loaded

```
Error: Call to undefined function socket_create()
```

**Cause:** The `sockets` extension is not enabled.

**Solution:**
```bash
# Install sockets extension
sudo apt-get install php-sockets

# Or check if loaded
php -m | grep sockets
```

## Next Steps

Now that you've completed the quick start:

- Learn about [Configuration](./configuration.md) options
- Explore the [Publishing Guide](../guide/publishing.md) for advanced producer features
- Read the [Consuming Guide](../guide/consuming.md) for consumer patterns
- Check the [API Reference](../api/index.md) for complete class documentation
- See [Examples](../../examples/) for more working code samples
