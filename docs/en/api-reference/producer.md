# Producer API Reference

Complete API reference for the `CrazyGoat\RabbitStream\Client\Producer` class.

## Class Overview

```php
namespace CrazyGoat\RabbitStream\Client;

class Producer
{
    // Constructor and internal methods...
    
    public function send(string $message, ?float $timeout = null): void;
    public function sendWithFilter(string $message, ?string $filterValue, ?float $timeout = null): void;
    public function sendBatch(array $messages, ?float $timeout = null): void;
    public function close(): void;
    public function isClosed(): bool;
    public function waitForConfirms(float $timeout = 5.0): void;
    public function getLastPublishingId(): ?int;
    public function querySequence(): int;
    public function getPendingConfirms(): int;
    public function getLostConfirmCount(): int;
    public function isStale(): bool;
    public function getRedeclareCount(): int;
}
```

## Constructor

The `Producer` class is instantiated via `Connection::createProducer()`. Direct instantiation is not recommended.

```php
$producer = $connection->createProducer(
    string $stream,                    // Required: Stream name
    ?string $name = null,              // Optional: Producer name for deduplication
    ?callable $onConfirm = null,       // Optional: Confirmation callback
    int $maxPendingConfirms = Producer::DEFAULT_MAX_PENDING_CONFIRMS, // Optional
    float $redeclareTimeout = Producer::DEFAULT_REDECLARE_TIMEOUT,    // Optional
): ProducerInterface
```

### Parameters

| Parameter | Type | Required | Description |
|-----------|------|----------|-------------|
| `$stream` | `string` | Yes | Name of the stream to publish to |
| `$name` | `?string` | No | Unique producer name for deduplication. If provided, enables exactly-once semantics across reconnects and makes the producer read back its publishing sequence on creation. |
| `$onConfirm` | `?callable` | No | Callback invoked for each publish confirmation. Receives `ConfirmationStatus` object. |
| `$maxPendingConfirms` | `int` | No | Back-pressure cap on outstanding (unconfirmed) publishes; default `10000`. Once reached, `send()`/`sendBatch()`/`sendWithFilter()` block, draining confirms until the count drops back below the limit. `0` disables the cap (old unlimited behavior). See [Performance Tuning](../advanced/performance-tuning.md#producer-flow-control-maxpendingconfirms). |
| `$redeclareTimeout` | `float` | No | How long (seconds) a publish keeps retrying `DeclarePublisher` after a `MetadataUpdate` dropped the publisher; default `5.0`. `0` fails on the first attempt. Must be `>= 0`. See [isStale()](#isstale). |

The underlying `Producer` constructor also takes an optional `$onClose` callback (called with the publisher id once `close()` has run, so the owning `Connection` can reclaim it) and an optional `$logger` (`LoggerInterface`, defaults to `NullLogger`); `Connection` supplies both, so they are not part of `createProducer()`.

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

- `ConnectionException` - If the socket is not connected or the write/read fails
- `DeserializationException` - If a response or server-push frame read while draining back-pressure cannot be deserialized
- `InvalidArgumentException` - If the serialized `Publish` request exceeds the negotiated outgoing frame size
- `ProtocolException` - If the broker rejects a re-declare or sends an unexpected frame
- `TimeoutException` - If the write, a re-declare, or the `maxPendingConfirms` back-pressure drain times out

#### Example

```php
$producer->send('Hello, World!');
$producer->send('Urgent message', timeout: 1.0);
```

#### Notes

- `$message` is a plain payload string; it is automatically wrapped in a single AMQP 1.0 Data section on the wire, so a consumer's `Message::getBody()` returns the same string unchanged
- To publish pre-encoded AMQP 1.0 bytes, use the low-level API with `AmqpMessageEncoder::encodeDataSection()` — `send()` would wrap them again
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

- `ConnectionException` - If the socket is not connected or the write/read fails
- `DeserializationException` - If a response or server-push frame read while draining back-pressure cannot be deserialized
- `InvalidArgumentException` - If the serialized `Publish` request exceeds the negotiated outgoing frame size
- `ProtocolException` - If the broker rejects a re-declare or sends an unexpected frame
- `TimeoutException` - If the write, a re-declare, or the `maxPendingConfirms` back-pressure drain times out

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
- Each message is a plain payload string, automatically wrapped in an AMQP 1.0 Data section (same encoding as `send()`)
- Each message still gets its own publishing ID and confirmation
- Empty arrays are silently ignored (no-op)

---

### sendWithFilter()

Publish a single message tagged with a stream filtering value.

```php
public function sendWithFilter(string $message, ?string $filterValue, ?float $timeout = null): void
```

#### Parameters

| Parameter | Type | Required | Description |
|-----------|------|----------|-------------|
| `$message` | `string` | Yes | Message body as string, same encoding as `send()` (wrapped in an AMQP 1.0 Data section on the wire) |
| `$filterValue` | `?string` | Yes | Stream filtering value attached to the message. `null` publishes an unfiltered message (still contributes to the chunk's bloom filter as "no value") |
| `$timeout` | `?float` | No | Socket write timeout in seconds; null uses connection default |

#### Return Value

`void`

#### Exceptions

- `ConnectionException` - If the socket is not connected or the write/read fails
- `DeserializationException` - If a response or server-push frame read while draining back-pressure cannot be deserialized
- `InvalidArgumentException` - If the serialized request exceeds the negotiated outgoing frame size
- `ProtocolException` - If `$filterValue` is not null but the broker did not negotiate `Publish` v2 (stream filtering requires RabbitMQ 3.13+), or a re-declare is rejected
- `TimeoutException` - If the write, a re-declare, or the `maxPendingConfirms` back-pressure drain times out

#### Example

```php
$producer->sendWithFilter(json_encode(['region' => 'eu', 'order_id' => 1]), filterValue: 'eu');
$producer->sendWithFilter(json_encode(['region' => 'us', 'order_id' => 2]), filterValue: 'us');
```

#### Notes

- With a non-null `$filterValue` the message is sent as a `PublishRequestV2`/`PublishedMessageV2` frame carrying the per-message filter value. With a null value the plain v1 frame is sent instead (v1 has no filter field); a non-null value on a broker that never negotiated Publish v2 is a hard `ProtocolException` rather than a silent unfiltered publish
- Filtering is **broker-side and chunk-granular**: the broker maintains a bloom filter per chunk and delivers the whole chunk once its filter *may* contain a matching value — every message in a delivered chunk arrives, matching or not. A consumer must still filter application-side for exact matching; see [`$filterValues`/`$matchUnfiltered` on `Consumer`](consumer.md) and the [Stream Filtering guide](../guide/consuming.md#7-stream-filtering)
- Each message still gets its own publishing ID and confirmation, exactly like `send()`

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

- `ConnectionException` - If the socket is not connected or the `DeletePublisher` write/read fails
- `DeserializationException` - If a response or server-push frame read while draining confirms cannot be deserialized
- `InvalidArgumentException` - If the serialized `DeletePublisher` request exceeds the negotiated outgoing frame size
- `ProtocolException` - If the `DeletePublisher` response has an unexpected version or command
- `TimeoutException` - If the `DeletePublisher` response does not arrive in time

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
- Keeps the confirm callback registered through the `DeletePublisher` exchange and drains any still-in-flight confirms (bounded); anything not drained in time is counted by [`getLostConfirmCount()`](#getlostconfirmcount)

---

### isClosed()

Whether `close()` has already run.

```php
public function isClosed(): bool
```

Returns `true` once `close()` has been called, even when the `DeletePublisher` exchange failed (the publisher id is released either way). Always `false` before the first `close()`, including after a `waitForConfirms()` timeout.

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
- `ConnectionException` - If the socket is not connected or a read fails
- `DeserializationException` - If a `PublishConfirm`/`PublishError` or other frame read while waiting cannot be deserialized

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

`?int` - The last publishing ID used. For an anonymous producer this is `null`
until the first `send()`; a **named** producer can return a non-null value
before any `send()` (see Notes).

#### Example

```php
// Anonymous producer
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
- **Counter-intuitive:** for a named producer this can be non-null *before any
  `send()`*. The constructor runs `querySequence()` and resumes from
  `sequence + 1`, so `getLastPublishingId()` immediately returns the broker's
  last confirmed sequence (`0` when the broker stored nothing) rather than
  `null`. It is `null` only for an anonymous producer that has not sent yet.
- For named producers, this is automatically managed based on `querySequence()`
- Publishing IDs start at 1 for unnamed producers
- Publishing IDs start at `querySequence() + 1` for named producers, which is
  why a named producer's first `send()` uses `querySequence() + 1` and dedup
  resumes correctly after a reconnect

---

### getPendingConfirms()

Get the number of publishes sent but not yet confirmed or errored.

```php
public function getPendingConfirms(): int
```

#### Parameters

None

#### Return Value

`int` - Current count of outstanding (unconfirmed) publishes

#### Example

```php
$producer->send('Message 1');
$producer->send('Message 2');
echo $producer->getPendingConfirms(); // 2

$producer->waitForConfirms();
echo $producer->getPendingConfirms(); // 0
```

#### Notes

- Decremented as `onConfirm`/publish-error frames arrive, whether observed via `waitForConfirms()`, the `onConfirm` callback, or the `maxPendingConfirms` back-pressure drain in `send()`/`sendBatch()`
- Incremented only once the frame has actually been written: a `send()` that throws leaves the count (and the publishing ID) untouched, so a later `waitForConfirms()` is never blocked by a message the broker never received
- Tracked per publishing id, not as a bare counter (since #521): a duplicate or late `PublishConfirm`/`PublishError` for an id that has already been retired is ignored, so `waitForConfirms()` cannot return while messages are still unconfirmed and a `MetadataUpdate` that makes the producer stale (`isStale()` returns `true`) reports exactly the still-outstanding ids as failed
- Tracked as a set, so bookkeeping is O(outstanding) rather than O(1): with `maxPendingConfirms: 0` it grows until confirms are drained (see [Performance Tuning](../advanced/performance-tuning.md#producer-flow-control-maxpendingconfirms))
- Useful for custom throttling or metrics alongside `maxPendingConfirms`

---

### getLostConfirmCount()

Cumulative number of publishes whose confirms were abandoned by the bounded
`close()` drain timeout (since #522).

```php
public function getLostConfirmCount(): int
```

#### Parameters

None

#### Return Value

`int` - Total number of publishes lost to drain timeouts over this producer's
lifetime (0 in normal operation)

#### Example

```php
$producer->send('Message 1');
$producer->close();

if ($producer->getLostConfirmCount() > 0) {
    // The broker stopped confirming before the 2 s drain expired: these
    // publishes may or may not have reached the broker.
}
```

#### Notes

- `close()` waits up to 2 s (`CLOSE_CONFIRM_DRAIN_TIMEOUT`) for in-flight
  `PublishConfirm`/`PublishError` frames. Anything still outstanding when that
  expires can never be confirmed, because the publisher id is released — the
  confirms are lost. Previously this happened silently (#522).
- Each timeout also emits a `warning` log line naming the producer and stream,
  the number of affected publishing ids, and a bounded prefix of them (at most
  10 ids in the log context, regardless of how many were outstanding), so
  operators can tell "the broker stopped confirming" from "everything drained".
- The counter is not reset by `close()`; `close()` is idempotent, so it cannot
  double-count.
- The stranded ids are still visible through `getPendingConfirms()` after
  `close()`.
- `waitForConfirms()` timing out does **not** increment this counter: those
  confirms belong to a still-open producer and can still arrive.

---

### isStale()

```php
public function isStale(): bool
```

Whether the broker has dropped this publisher — it pushed a `MetadataUpdate`
for the stream, or answered a publish with `PUBLISHER_NOT_EXIST` /
`STREAM_NOT_AVAILABLE`. The next `send()`, `sendBatch()` or `sendWithFilter()`
re-runs `DeclarePublisher` before publishing, retrying with exponential
back-off for up to `$redeclareTimeout` seconds and throwing a
`ProtocolException` if the stream is still gone.

Unconfirmed messages are lost when this happens: they are reported to the
`onConfirm` callback as a failed `ConfirmationStatus` carrying the broker's
response code, and they stop counting toward `maxPendingConfirms`.

```php
$producer->send('a');
// ... the stream is deleted; any readLoop()/waitForConfirms() dispatches the MetadataUpdate ...
$producer->isStale();      // true
$producer->send('b');      // re-declares first, then publishes
$producer->isStale();      // false
```

See [Publishing → Stream Deleted or Leader Moved](../guide/publishing.md#stream-deleted-or-leader-moved-metadataupdate).

---

### getRedeclareCount()

```php
public function getRedeclareCount(): int
```

How many times this publisher has been successfully re-declared after a
`MetadataUpdate`. Useful as a health metric: a number that keeps growing means
the stream is flapping.

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
- `ConnectionException` - If the socket is not connected or the exchange fails
- `DeserializationException` - If the response frame cannot be deserialized
- `ProtocolException` - If the broker answers with a non-OK response code
- `TimeoutException` - If the response does not arrive in time
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
- [ResponseCodeEnum](../../../src/Enum/ResponseCodeEnum.php)
