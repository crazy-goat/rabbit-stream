# Performance Tuning

> Optimizing RabbitStream for high throughput and low latency

## Overview

This guide covers performance optimization techniques for the RabbitStream PHP client. Learn how to tune batch publishing, credit management, buffer sizes, and timeouts for your specific workload.

## Batch Publishing vs Single Send

### Single Message Publishing

Sending messages one at a time:

```php
$producer = $connection->createProducer('my-stream');

foreach ($messages as $message) {
    $producer->send($message);  // Network round-trip per message
}
```

**Characteristics:**
- Simple to implement
- Higher latency (network round-trip per message)
- Lower throughput
- Use case: Low volume, latency-sensitive applications

### Batch Publishing

Sending multiple messages in one request:

```php
$producer = $connection->createProducer('my-stream');

// Collect messages
$batch = [];
foreach ($data as $item) {
    $batch[] = json_encode($item);
    
    if (count($batch) >= 100) {
        $producer->sendBatch($batch);  // One network request for 100 messages
        $batch = [];
    }
}

// Send remaining
if (!empty($batch)) {
    $producer->sendBatch($batch);
}
```

**Characteristics:**
- Amortizes network overhead across many messages
- Higher throughput (10-100x improvement typical)
- Slightly higher latency per batch
- Use case: High volume, throughput-sensitive applications

### Performance Comparison

| Approach | Messages/sec | Latency | CPU Usage |
|----------|--------------|---------|-----------|
| Single send | ~1,000 | ~1ms each | High |
| Batch (100) | ~50,000 | ~5ms batch | Medium |
| Batch (1000) | ~100,000 | ~10ms batch | Low |

*Benchmarks on localhost, actual results vary by network and message size.*

### Optimal Batch Size

```php
<?php

class AdaptiveBatchProducer
{
    private array $buffer = [];
    private int $maxBatchSize;
    private float $maxWaitMs;
    private float $lastSend;
    
    public function __construct(
        private $producer,
        int $maxBatchSize = 100,
        float $maxWaitMs = 10.0
    ) {
        $this->maxBatchSize = $maxBatchSize;
        $this->maxWaitMs = $maxWaitMs;
        $this->lastSend = microtime(true);
    }
    
    public function send(string $message): void
    {
        $this->buffer[] = $message;
        
        $elapsedMs = (microtime(true) - $this->lastSend) * 1000;
        
        if (count($this->buffer) >= $this->maxBatchSize ||
            $elapsedMs >= $this->maxWaitMs) {
            $this->flush();
        }
    }
    
    public function flush(): void
    {
        if (!empty($this->buffer)) {
            $this->producer->sendBatch($this->buffer);
            $this->buffer = [];
            $this->lastSend = microtime(true);
        }
    }
}
```

## Credit Tuning

### Understanding Credits

Credits control flow between server and consumer:

```
Consumer ──► Subscribe (initialCredit=10) ──► Server
Consumer ◄── Deliver (10 messages) ◄─────── Server
Consumer ──► Credit (5) ─────────────────► Server
Consumer ◄── Deliver (5 messages) ◄────── Server
```

### Initial Credit Parameter

Set when creating a Consumer:

```php
use CrazyGoat\RabbitStream\Client\Consumer;
use CrazyGoat\RabbitStream\VO\OffsetSpec;

$consumer = new Consumer(
    connection: $connection,
    stream: 'my-stream',
    subscriptionId: 1,
    offset: OffsetSpec::next(),
    initialCredit: 100,  // Request 100 messages upfront
);
```

**Guidelines:**
- **Low (1-10):** Memory-constrained consumers, slow processing
- **Medium (50-100):** Balanced throughput and memory
- **High (500+):** High-throughput consumers with fast processing

### Credit Management Internals

The Consumer automatically manages credits:

```php
// When buffer is below maxBufferSize, send 1 credit
if (count($this->buffer) < $this->maxBufferSize) {
    $this->connection->sendMessage(
        new CreditRequestV1($this->subscriptionId, 1)
    );
}
```

### Manual Credit Control

For advanced scenarios, use low-level API:

```php
use CrazyGoat\RabbitStream\Request\CreditRequestV1;

// Send credits manually
$connection->sendMessage(
    new CreditRequestV1($subscriptionId, 50)
);
```

## Consumer Buffer Size

### maxBufferSize Parameter

Controls back-pressure on the consumer:

```php
$consumer = new Consumer(
    connection: $connection,
    stream: 'my-stream',
    subscriptionId: 1,
    offset: OffsetSpec::next(),
    initialCredit: 100,
    maxBufferSize: 1000,  // Buffer up to 1000 messages
);
```

**Behavior:**
- When buffer reaches `maxBufferSize`, no new credits are sent
- Server stops delivering until consumer catches up
- Prevents memory exhaustion on slow consumers

### Buffer Size Guidelines

| Consumer Type | maxBufferSize | Initial Credit |
|--------------|---------------|----------------|
| Fast processor | 100-500 | 50-100 |
| Slow processor | 10-50 | 5-10 |
| Memory-constrained | 5-20 | 1-5 |
| Batch processor | 1000+ | 100-500 |

### Back-Pressure Example

```php
<?php

// Slow consumer with small buffer
$consumer = new Consumer(
    connection: $connection,
    stream: 'my-stream',
    subscriptionId: 1,
    offset: OffsetSpec::next(),
    initialCredit: 5,
    maxBufferSize: 20,  // Small buffer
);

while (true) {
    $messages = $consumer->read(timeout: 5.0);
    
    foreach ($messages as $message) {
        // Slow processing (e.g., database write)
        processSlowly($message);
    }
    
    // Buffer stays small, back-pressure prevents overload
}
```

## Timeout Tuning

### readMessage() Timeout

Controls how long to wait for responses:

```php
// Default: 30 seconds
$response = $connection->readMessage(timeout: 30.0);

// Fast response expected
$response = $connection->readMessage(timeout: 1.0);

// Long-running operation
$response = $connection->readMessage(timeout: 300.0);
```

**Guidelines:**
- **Low (1-5s):** Interactive applications, fast responses
- **Medium (30s):** Default, good for most cases
- **High (300s+):** Long-running queries, slow networks

### waitForConfirms() Timeout

Controls publish confirmation timeout:

```php
$producer = $connection->createProducer('my-stream');

// Send messages
foreach ($messages as $msg) {
    $producer->send($msg);
}

// Wait for confirms (default: 5 seconds)
try {
    $producer->waitForConfirms(timeout: 5.0);
} catch (TimeoutException $e) {
    echo "Some messages not confirmed\n";
}
```

**Tuning considerations:**
- **Low (1-2s):** Local development, fast network
- **Medium (5-10s):** Production with good network
- **High (30s+):** High latency networks, large batches

### Consumer read() Timeout

Controls message polling timeout:

```php
$consumer = new Consumer(/* ... */);

// Non-blocking check
$messages = $consumer->read(timeout: 0.0);

// Wait up to 5 seconds for messages
$messages = $consumer->read(timeout: 5.0);

// Long poll
$messages = $consumer->read(timeout: 60.0);
```

## Frame Size Limits

### setMaxFrameSize()

Protects against memory exhaustion from huge frames:

```php
// Default: 8MB
$connection->setMaxFrameSize(8 * 1024 * 1024);

// For small messages only
$connection->setMaxFrameSize(1024 * 1024);  // 1MB

// For large messages
$connection->setMaxFrameSize(64 * 1024 * 1024);  // 64MB

// No limit (not recommended)
$connection->setMaxFrameSize(0);
```

**When to adjust:**
- **Decrease:** If you only send small messages, prevents DoS
- **Increase:** If you send large messages (>8MB)

### Frame Size Error

If a frame exceeds the limit:

```php
if ($this->maxFrameSize > 0 && $size > $this->maxFrameSize) {
    $this->close();
    throw new ConnectionException(
        "Frame size {$size} exceeds maximum allowed {$this->maxFrameSize}"
    );
}
```

## Known Limitations

### Single-Threaded

PHP is single-threaded, which affects:

1. **Publishing:** One publish at a time per connection
2. **Consuming:** One consumer per connection processes sequentially
3. **No parallel frame processing**

**Workarounds:**
- Use multiple connections for parallel streams
- Process messages asynchronously after receiving
- Use ReactPHP or Swoole for async I/O

### No Connection Pooling

Each `StreamConnection` creates one TCP connection:

```php
// Creates separate connections
$conn1 = new StreamConnection(host: '127.0.0.1', port: 5552);
$conn2 = new StreamConnection(host: '127.0.0.1', port: 5552);
```

**Impact:**
- More connections = more server resources
- No built-in connection reuse

**Workarounds:**
- Share one connection for multiple producers/consumers
- Implement connection pooling in application layer

### No TLS Support

Current implementation does not support TLS/SSL:

```php
// This will fail if server requires TLS
$connection = new StreamConnection(
    host: 'rabbitmq.example.com',
    port: 5552,  // Standard port, no TLS
);
```

**Workarounds:**
- Use VPN or private network
- Terminate TLS at load balancer
- Use stunnel for TLS tunneling

## Performance Benchmarks

### Test Setup

```php
<?php

// Benchmark configuration
$config = [
    'host' => '127.0.0.1',
    'port' => 5552,
    'stream' => 'benchmark-stream',
    'message_size' => 1024,  // 1KB messages
    'duration' => 10,        // 10 seconds
];

// Results storage
$results = [
    'messages_sent' => 0,
    'bytes_sent' => 0,
    'start_time' => microtime(true),
];
```

### Publish Benchmark

```php
<?php

function benchmarkPublishing($connection, $config): array
{
    $producer = $connection->createProducer($config['stream']);
    
    $message = str_repeat('x', $config['message_size']);
    $endTime = microtime(true) + $config['duration'];
    
    $count = 0;
    while (microtime(true) < $endTime) {
        $producer->sendBatch(array_fill(0, 100, $message));
        $count += 100;
    }
    
    $producer->waitForConfirms(timeout: 30.0);
    
    $elapsed = $config['duration'];
    return [
        'messages_per_second' => $count / $elapsed,
        'mb_per_second' => ($count * $config['message_size']) / $elapsed / 1024 / 1024,
    ];
}
```

### Consume Benchmark

```php
<?php

function benchmarkConsuming($connection, $config): array
{
    $consumer = $connection->createConsumer(
        stream: $config['stream'],
        subscriptionId: 1,
        offset: OffsetSpec::first(),
        initialCredit: 100,
        maxBufferSize: 1000,
    );
    
    $count = 0;
    $startTime = microtime(true);
    $endTime = $startTime + $config['duration'];
    
    while (microtime(true) < $endTime) {
        $messages = $consumer->read(timeout: 1.0);
        $count += count($messages);
    }
    
    $elapsed = $config['duration'];
    return [
        'messages_per_second' => $count / $elapsed,
        'mb_per_second' => ($count * $config['message_size']) / $elapsed / 1024 / 1024,
    ];
}
```

### Sample Results

Typical performance on modern hardware:

| Operation | Messages/sec | MB/sec | Notes |
|-----------|--------------|--------|-------|
| Batch publish (100) | 50,000-100,000 | 50-100 | Local RabbitMQ |
| Single publish | 1,000-2,000 | 1-2 | Network round-trip |
| Consume | 30,000-50,000 | 30-50 | With AMQP decoding |

## Best Practices

### 1. Use Batch Publishing

```php
// Good: Batch publishing
$batch = [];
foreach ($messages as $msg) {
    $batch[] = $msg;
    if (count($batch) >= 100) {
        $producer->sendBatch($batch);
        $batch = [];
    }
}

// Bad: Single sends
foreach ($messages as $msg) {
    $producer->send($msg);  // Slow!
}
```

### 2. Tune Credits for Your Workload

```php
// Fast processor: High credits
new Consumer(
    initialCredit: 500,
    maxBufferSize: 2000,
);

// Slow processor: Low credits
new Consumer(
    initialCredit: 10,
    maxBufferSize: 50,
);
```

### 3. Set Appropriate Timeouts

```php
// Fast network
$producer->waitForConfirms(timeout: 2.0);

// Slow network
$producer->waitForConfirms(timeout: 30.0);
```

### 4. Monitor Pending Confirms

```php
// Track confirm timeouts as health metric
try {
    $producer->waitForConfirms(timeout: 5.0);
} catch (TimeoutException $e) {
    // Alert: Server may be overloaded
    $metrics->increment('rabbitstream.confirm_timeouts');
}
```

### 5. Use Named Producers for Deduplication

```php
// Good: Named producer survives reconnects
$producer = $connection->createProducer(
    'my-stream',
    name: 'order-producer'
);

// Bad: Unnamed producer may duplicate on reconnect
$producer = $connection->createProducer('my-stream');
```

## See Also

- [Publishing Guide](../guide/publishing.md)
- [Consuming Guide](../guide/consuming.md)
- [Flow Control](../guide/flow-control.md)
- [Binary Serialization](./binary-serialization.md)
