# Producer API Reference

Complete API reference for the `CrazyGoat\RabbitStream\Client\Producer` class.

## Class Overview

```php
namespace CrazyGoat\RabbitStream\Client;

class Producer
{
    // Constructor and internal methods...
    
    public function send(string $message, ?float $timeout = null): void;
    public function sendBatch(array $messages, ?float $timeout = null): void;
    public function close(): void;
    public function waitForConfirms(float $timeout = 5.0): void;
    public function getLastPublishingId(): ?int;
    public function querySequence(): int;
}
```

## Constructor

The `Producer` class is instantiated via `Connection::createProducer()`. Direct instantiation is not recommended.

```php
$producer = $connection->createProducer(
    string $stream,                    // Required: Stream name
    ?string $name = null,              // Optional: Producer name for deduplication
    ?callable $onConfirm = null,        // Optional: Confirmation callback
): Producer
```

### Parameters

| Parameter | Type | Required | Description |
|-----------|------|----------|-------------|
| `$stream` | `string` | Yes | Name of the stream to publish to |
| `$name` | `?string` | No | Unique producer name for deduplication. If provided, enables exactly-once semantics across reconnects. |
| `$onConfirm` | `?callable` | No | Callback invoked for each publish confirmation. Receives `ConfirmationStatus` object. |

### Examples

**Anonymous producer (no deduplication):**
```php
$producer = $connection->createProducer('my-stream');
```

**Named producer with confirm callback:**
```php
use CrazyGoat\RabbitStream\Client\ConfirmationStatus;

$producer = $connection->createProducer(
    'my-stream',
    name: 'my-producer',
    onConfirm: function (ConfirmationStatus $status) {
        if ($status->isConfirmed()) {
            echo "Confirmed: #{$status->getPublishingId()}\n";
        } else {
            echo "Failed: #{$status->getPublishingId()}\n";
        }
    }
);
```

## Methods

### send()

Publish a single message to the stream.

```php
public function send(string $message, ?float $timeout = null): void
```

#### Parameters

| Parameter | Type | Required | Description |
|-----------|------|----------|-------------|
| `$message` | `string` | Yes | Message body as string |
| `$timeout` | `?float` | No | Socket write timeout in seconds. Null uses connection default. |

#### Return Value

`void`

#### Exceptions

- `ConnectionException` - If the connection is lost
- `\Exception` - For protocol errors

#### Example

```php
$producer->send('Hello, World!');
$producer->send('Urgent message', timeout: 1.0);
```

#### Notes

- Messages are assigned an auto-incrementing publishing ID internally
- The message is not immediately confirmed; use `waitForConfirms()` or the `onConfirm` callback
- For high throughput, consider `sendBatch()` instead

---

### sendBatch()

Publish multiple messages in a single batch.

```php
/**
 * @param string[] $messages
 */
public function sendBatch(array $messages, ?float $timeout = null): void
```

#### Parameters

| Parameter | Type | Required | Description |
|-----------|------|----------|-------------|
| `$messages` | `array<string>` | Yes | Array of message strings |
| `$timeout` | `?float` | No | Socket write timeout in seconds |

#### Return Value

`void`

#### Exceptions

- `ConnectionException` - If the connection is lost
- `\Exception` - For protocol errors

#### Example

```php
$messages = [
    'Message 1',
    'Message 2',
    'Message 3',
];

$producer->sendBatch($messages);
```

#### Notes

- More efficient than multiple `send()` calls for high throughput
- All messages in the batch share the same network frame
- Each message still gets its own publishing ID and confirmation
- Empty arrays are silently ignored (no-op)

---

### close()

Close the producer and release server resources.

```php
public function close(): void
```

#### Parameters

None

#### Return Value

`void`

#### Exceptions

- `ConnectionException` - If the connection is already closed

#### Example

```php
try {
    $producer->send('Final message');
    $producer->waitForConfirms(timeout: 5.0);
} finally {
    $producer->close();
}
```

#### Notes

- Sends `DeletePublisher` command to server
- Unregisters the publisher from the connection
- Frees the publisher ID for reuse
- Does not close the underlying connection
- Safe to call multiple times (idempotent)

---

### waitForConfirms()

Block until all pending publish confirmations are received.

```php
public function waitForConfirms(float $timeout = 5.0): void
```

#### Parameters

| Parameter | Type | Required | Description |
|-----------|------|----------|-------------|
| `$timeout` | `float` | No | Maximum time to wait in seconds. Default: 5.0 |

#### Return Value

`void`

#### Exceptions

- `TimeoutException` - If timeout is reached before all confirms received

#### Example

```php
// Publish messages
for ($i = 0; $i < 100; $i++) {
    $producer->send("Message {$i}");
}

// Wait up to 10 seconds for all confirms
try {
    $producer->waitForConfirms(timeout: 10.0);
    echo "All messages confirmed!\n";
} catch (TimeoutException $e) {
    echo "Timeout: {$e->getMessage()}\n";
}
```

#### Notes

- Returns immediately if there are no pending confirms
- Internally calls `connection->readLoop()` to process incoming frames
- The `onConfirm` callback is invoked during this call
- Does not guarantee message delivery if connection drops during wait

---

### getLastPublishingId()

Get the last publishing ID that was used.

```php
public function getLastPublishingId(): ?int
```

#### Parameters

None

#### Return Value

`?int` - The last publishing ID, or `null` if no messages have been sent

#### Example

```php
$producer->send('Message 1');
$id1 = $producer->getLastPublishingId(); // 1

$producer->send('Message 2');
$id2 = $producer->getLastPublishingId(); // 2

// Batch publishing
$producer->sendBatch(['A', 'B', 'C']);
$id3 = $producer->getLastPublishingId(); // 5 (2 + 3 messages)
```

#### Notes

- Returns the ID of the last message that was **sent**, not necessarily confirmed
- For named producers, this is automatically managed based on `querySequence()`
- Publishing IDs start at 1 for unnamed producers
- Publishing IDs start at `querySequence() + 1` for named producers

---

### querySequence()

Query the last confirmed publishing ID from the server.

```php
public function querySequence(): int
```

#### Parameters

None

#### Return Value

`int` - The highest publishing ID confirmed by the server for this named producer

#### Exceptions

- `InvalidArgumentException` - If called on an unnamed producer
- `UnexpectedResponseException` - If the server returns an unexpected response

#### Example

```php
// Only works with named producers
$producer = $connection->createProducer('my-stream', name: 'my-producer');

// After some publishing and reconnecting...
$lastConfirmed = $producer->querySequence();
echo "Server has confirmed up to ID: {$lastConfirmed}";
```

#### Notes

- Only available for named producers (throws exception for anonymous producers)
- Automatically called during producer construction for named producers
- Used for deduplication: messages with ID ≤ returned value are duplicates
- Makes a round-trip to the server

## ConfirmationStatus Class

The `ConfirmationStatus` object is passed to the `onConfirm` callback.

```php
namespace CrazyGoat\RabbitStream\Client;

class ConfirmationStatus
{
    public function isConfirmed(): bool;
    public function getPublishingId(): ?int;
    public function getErrorCode(): ?int;
}
```

### Methods

#### isConfirmed()

Returns `true` if the message was successfully stored by the server.

```php
public function isConfirmed(): bool
```

#### getPublishingId()

Returns the publishing ID of the message.

```php
public function getPublishingId(): ?int
```

Returns `null` if the publishing ID is not available (rare).

#### getErrorCode()

Returns the error code if the message failed.

```php
public function getErrorCode(): ?int
```

Returns `null` if the message was confirmed successfully.

Common error codes:
- `0x02` - `STREAM_NOT_EXIST` - Stream does not exist
- `0x12` - `PUBLISHER_NOT_EXIST` - Publisher ID is invalid
- `0x10` - `ACCESS_REFUSED` - No write permission

See `ResponseCodeEnum` for all error codes.

## Usage Patterns

### Pattern 1: Fire and Forget

For scenarios where you don't need to wait for confirms:

```php
$producer = $connection->createProducer('logs');

// Publish without waiting
$producer->send('Log message 1');
$producer->send('Log message 2');
$producer->send('Log message 3');

// Close (may lose unconfirmed messages)
$producer->close();
```

**Pros:** Maximum throughput  
**Cons:** No guarantee of durability

### Pattern 2: Wait for All Confirms

For scenarios requiring durability guarantees:

```php
$producer = $connection->createProducer('orders');

// Publish batch
$producer->sendBatch($orderMessages);

// Wait for confirms before proceeding
$producer->waitForConfirms(timeout: 10.0);

// Safe to close - all messages confirmed
$producer->close();
```

**Pros:** Guaranteed durability  
**Cons:** Lower throughput due to blocking

### Pattern 3: Async with Callback

For scenarios requiring per-message handling:

```php
$confirmed = [];
$failed = [];

$producer = $connection->createProducer(
    'events',
    onConfirm: function (ConfirmationStatus $status) use (&$confirmed, &$failed) {
        $id = $status->getPublishingId();
        
        if ($status->isConfirmed()) {
            $confirmed[] = $id;
        } else {
            $failed[$id] = $status->getErrorCode();
        }
    }
);

// Publish
for ($i = 0; $i < 100; $i++) {
    $producer->send("Event {$i}");
}

// Process other work while waiting...
// ...

// Eventually wait for remaining confirms
$producer->waitForConfirms(timeout: 5.0);

// Handle failures
foreach ($failed as $id => $code) {
    echo "Message #{$id} failed with code {$code}\n";
}

$producer->close();
```

**Pros:** Non-blocking, per-message tracking  
**Cons:** More complex code

### Pattern 4: Named Producer with Deduplication

For exactly-once semantics:

```php
$producer = $connection->createProducer(
    'payments',
    name: 'payment-service-producer',
    onConfirm: function (ConfirmationStatus $status) {
        if (!$status->isConfirmed()) {
            error_log("Payment failed: #{$status->getPublishingId()}");
        }
    }
);

// Publish with automatic deduplication on reconnect
$producer->send(json_encode(['order_id' => 123, 'amount' => 99.99]));
$producer->waitForConfirms(timeout: 5.0);

// If connection drops and reconnects with same name,
// duplicate messages will be automatically deduplicated
```

**Pros:** Exactly-once semantics, automatic deduplication  
**Cons:** Slightly higher overhead for sequence tracking

## Performance Considerations

### Throughput Optimization

1. **Use batch publishing** for high throughput:
   ```php
   // Good: Single batch
   $producer->sendBatch($messages);
   
   // Bad: Individual sends
   foreach ($messages as $msg) {
       $producer->send($msg);
   }
   ```

2. **Adjust batch size** based on message size:
   - Small messages (< 1KB): 100-1000 messages per batch
   - Large messages (> 10KB): 10-100 messages per batch

3. **Use appropriate timeouts**:
   ```php
   // Short timeout for fast failure detection
   $producer->send($msg, timeout: 0.5);
   ```

### Latency Optimization

1. **Don't wait for every message**:
   ```php
   // Bad: High latency
   foreach ($messages as $msg) {
       $producer->send($msg);
       $producer->waitForConfirms(timeout: 1.0);
   }
   
   // Good: Lower latency
   foreach ($messages as $msg) {
       $producer->send($msg);
   }
   $producer->waitForConfirms(timeout: 5.0);
   ```

2. **Use async confirms** for non-critical messages:
   ```php
   $producer = $connection->createProducer('logs');
   // No onConfirm callback, no waitForConfirms
   ```

## Error Handling

### Common Errors

```php
use CrazyGoat\RabbitStream\Exception\TimeoutException;
use CrazyGoat\RabbitStream\Exception\ConnectionException;

try {
    $producer = $connection->createProducer('my-stream');
    $producer->send('Message');
    $producer->waitForConfirms(timeout: 5.0);
} catch (TimeoutException $e) {
    // Some messages not confirmed within timeout
    // May or may not be stored - check application logic
    echo "Timeout: {$e->getMessage()}\n";
} catch (ConnectionException $e) {
    // Connection lost - may need to reconnect
    echo "Connection lost: {$e->getMessage()}\n";
} finally {
    $producer?->close();
}
```

### Retry Logic

```php
function publishWithRetry($connection, $stream, $message, $maxRetries = 3) {
    $attempts = 0;
    
    while ($attempts < $maxRetries) {
        try {
            $producer = $connection->createProducer($stream);
            $producer->send($message);
            $producer->waitForConfirms(timeout: 5.0);
            $producer->close();
            return true;
        } catch (\Exception $e) {
            $attempts++;
            if ($attempts >= $maxRetries) {
                throw $e;
            }
            usleep(100000 * $attempts); // Exponential backoff
        }
    }
    
    return false;
}
```

## See Also

- [Publishing Guide](../guide/publishing.md)
- [Basic Producer Example](../examples/basic-producer.md)
- [Named Producer Deduplication](../examples/named-producer-deduplication.md)
- [Connection API Reference](connection.md) (if available)
- [ResponseCodeEnum](../../src/Enum/ResponseCodeEnum.php)
