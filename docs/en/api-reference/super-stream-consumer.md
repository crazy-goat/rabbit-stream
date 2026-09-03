# SuperStreamConsumer API Reference

Complete API reference for the `CrazyGoat\RabbitStream\Client\SuperStreamConsumer` class (implementing `SuperStreamConsumerInterface`).

## Class Overview

```php
namespace CrazyGoat\RabbitStream\Client;

class SuperStreamConsumer implements SuperStreamConsumerInterface
{
    // Constructor and internal methods...

    /** @return Message[] */
    public function read(float $timeout = 5.0): array;
    public function readOne(float $timeout = 5.0): ?Message;

    public function storeOffset(string $partition, int $offset): void;
    public function queryOffset(string $partition): int;

    /** @return list<string> */
    public function getPartitions(): array;
    /** @return array<string, ConsumerInterface> */
    public function getConsumers(): array;
    public function isActive(string $partition): bool;

    public function close(): void;
}
```

Consumes from every partition of a super stream through one object. Each
partition is a plain `Consumer` subscribed the same way
`Connection::createConsumer()` subscribes any consumer — auto-commit and
single-active-consumer activation/deactivation are handled per-partition by
that existing `Consumer` machinery. This class only aggregates reads and
delegates offset/activation queries to the right partition.

**Offset tracking is entirely per-partition** — each partition is a distinct
stream with its own offset sequence; there is no aggregate,
super-stream-wide offset. `storeOffset()`/`queryOffset()` always operate on
one named partition.

## Constructor

The `SuperStreamConsumer` class is instantiated via
`Connection::createSuperStreamConsumer()`. Direct instantiation is not
recommended.

```php
use CrazyGoat\RabbitStream\VO\OffsetSpec;

$consumer = $connection->createSuperStreamConsumer(
    string $superStream,                 // Required: super stream name
    OffsetSpec $offset,                  // Required: starting offset, applied to every partition
    ?string $name = null,                // Optional: consumer name shared by every partition
    int $autoCommit = 0,                 // Optional: auto-commit interval (messages)
    int $initialCredit = 10,             // Optional: initial flow control credits
    bool $singleActiveConsumer = false,  // Optional: single active consumer per partition
): SuperStreamConsumerInterface
```

### Parameters

| Parameter | Type | Required | Description |
|-----------|------|----------|-------------|
| `$superStream` | `string` | Yes | Name of the super stream to consume from |
| `$offset` | `OffsetSpec` | Yes | Starting offset, applied identically to every partition |
| `$name` | `?string` | No | Consumer name, shared by every partition's subscription. Required for `storeOffset()`/`queryOffset()` and for `singleActiveConsumer` — the shared name is what lets the broker group each partition's subscribers into a single-active-consumer group. |
| `$autoCommit` | `int` | No | Auto-commit interval (messages), passed through to every partition's `Consumer` |
| `$initialCredit` | `int` | No | Initial flow control credits, passed through to every partition's `Consumer` |
| `$singleActiveConsumer` | `bool` | No | Enables single active consumer per partition. Requires `$name`. |

### Examples

**Basic consumer from the beginning:**
```php
$consumer = $connection->createSuperStreamConsumer('orders', OffsetSpec::first());
```

**Named consumer with auto-commit:**
```php
$consumer = $connection->createSuperStreamConsumer(
    'orders',
    OffsetSpec::last(),
    name: 'order-processor',
    autoCommit: 100,
    initialCredit: 50,
);
```

**Single active consumer per partition:**
```php
$consumer = $connection->createSuperStreamConsumer(
    'orders',
    OffsetSpec::first(),
    name: 'order-processor', // Same name on every worker process's SuperStreamConsumer
    singleActiveConsumer: true,
);
```

---

## Reading Methods

### read()

Return whatever messages are already buffered across all partitions without
blocking; only if nothing is buffered anywhere does this run a single
bounded read against the connection and then collect whatever became
buffered.

```php
/** @return Message[] */
public function read(float $timeout = 5.0): array
```

#### Parameters

| Parameter | Type | Required | Description |
|-----------|------|----------|-------------|
| `$timeout` | `float` | No | Maximum time for the one bounded read pass, in seconds. Default: `5.0` |

#### Return Value

`Message[]` - messages from every partition that had data, in no particular cross-partition order. Use `Message::getStream()` to tell which partition each one came from.

#### Example

```php
foreach ($consumer->read(timeout: 5.0) as $message) {
    echo "[{$message->getStream()}] offset={$message->getOffset()} {$message->getBody()}\n";
    $consumer->storeOffset($message->getStream(), $message->getOffset());
}
```

#### Notes

- If any partition already has buffered messages, this call does **no connection I/O** — it just drains every partition's buffer
- Otherwise it performs exactly one bounded read-loop pass, then drains whatever arrived
- May return an empty array if the timeout expires with no messages anywhere

---

### readOne()

Like `read()`, but returns at most one message, round-robining fairly across
partitions that currently have buffered data.

```php
public function readOne(float $timeout = 5.0): ?Message
```

#### Parameters

| Parameter | Type | Required | Description |
|-----------|------|----------|-------------|
| `$timeout` | `float` | No | Maximum time for the one bounded read pass, in seconds. Default: `5.0` |

#### Return Value

`?Message` - a single message, or `null` if nothing arrived within the timeout

#### Example

```php
while (($message = $consumer->readOne(timeout: 1.0)) !== null) {
    process($message);
    $consumer->storeOffset($message->getStream(), $message->getOffset());
}
```

#### Notes

- Fair rotation: each call that finds buffered data starts scanning just past the partition it returned from last time, not always from the first partition — so one high-traffic partition can't starve the others
- Performs connection I/O only when no partition has anything buffered, same as `read()`

---

## Offset Management Methods

### storeOffset()

Store the current offset for one partition.

```php
public function storeOffset(string $partition, int $offset): void
```

#### Parameters

| Parameter | Type | Required | Description |
|-----------|------|----------|-------------|
| `$partition` | `string` | Yes | Partition (physical stream) name — from `Message::getStream()` or `getPartitions()` |
| `$offset` | `int` | Yes | The offset value to store |

#### Exceptions

- `InvalidArgumentException` - If `$partition` is not one of this consumer's partitions
- `ProtocolException` - If this consumer was created without a `$name`

#### Example

```php
$consumer->storeOffset($message->getStream(), $message->getOffset());
```

---

### queryOffset()

Query the last stored offset for one partition.

```php
public function queryOffset(string $partition): int
```

#### Parameters

| Parameter | Type | Required | Description |
|-----------|------|----------|-------------|
| `$partition` | `string` | Yes | Partition (physical stream) name |

#### Return Value

`int` - the last stored offset for this consumer's `$name` on that partition

#### Exceptions

- `InvalidArgumentException` - If `$partition` is not one of this consumer's partitions
- `ProtocolException` - If this consumer was created without a `$name`
- `UnexpectedResponseException` - If the server returns an unexpected response

#### Example

```php
foreach ($consumer->getPartitions() as $partition) {
    try {
        echo "{$partition}: {$consumer->queryOffset($partition)}\n";
    } catch (\Exception $e) {
        echo "{$partition}: no stored offset\n";
    }
}
```

---

## Introspection Methods

### getPartitions()

The super stream's partition names, as resolved when this consumer was created.

```php
/** @return list<string> */
public function getPartitions(): array
```

### getConsumers()

The underlying per-partition consumers.

```php
/** @return array<string, ConsumerInterface> partition stream name => Consumer */
public function getConsumers(): array
```

Useful for anything not exposed on `SuperStreamConsumerInterface` directly —
e.g. registering a custom `onConsumerUpdate()` callback on one specific
partition's `Consumer`.

```php
foreach ($consumer->getConsumers() as $partition => $partitionConsumer) {
    $partitionConsumer->onConsumerUpdate(function (bool $active, $c) use ($partition) {
        echo "{$partition}: " . ($active ? "activated" : "deactivated") . "\n";
        return null;
    });
}
```

### isActive()

Single-active-consumer activation state for one partition.

```php
public function isActive(string $partition): bool
```

Always `true` for a `SuperStreamConsumer` created without
`singleActiveConsumer`. Otherwise tracks that partition's most recent
`ConsumerUpdate` from the broker — activation state is per partition, not
per super stream.

#### Exceptions

- `InvalidArgumentException` - If `$partition` is not one of this consumer's partitions

---

## Lifecycle Methods

### close()

Close every partition's `Consumer`.

```php
public function close(): void
```

#### Return Value

`void`

#### Notes

- Every partition still gets its own `close()` attempt regardless of what happens to the others — see [Known limitation](#known-limitation) below
- Stores final offsets per-partition if auto-commit is enabled (same as a plain `Consumer::close()`)
- Safe to call multiple times

#### Known limitation

Closing several single-active-consumer partitions in a row on one connection
can occasionally race with the broker pushing a `ConsumerUpdate` activation
query for another partition while a partition's own `close()` is waiting on
its `UnsubscribeResponse`. This is a pre-existing limitation of `Consumer`'s
non-correlated response dispatch (see `Consumer::defaultConsumerUpdateHandler()`'s
docblock), not something `SuperStreamConsumer` introduces — `close()`
tolerates it per-partition, continuing to close the rest of the partitions
rather than aborting the whole call if one partition's unsubscribe races
like this.

---

## Usage Patterns

### Pattern 1: Basic Loop with Per-Partition Offset Tracking

```php
use CrazyGoat\RabbitStream\VO\OffsetSpec;

$consumer = $connection->createSuperStreamConsumer('orders', OffsetSpec::first(), name: 'order-processor');

try {
    while (true) {
        foreach ($consumer->read(timeout: 5.0) as $message) {
            processOrder($message->getBody());
            $consumer->storeOffset($message->getStream(), $message->getOffset());
        }
    }
} finally {
    $consumer->close();
}
```

### Pattern 2: Single Active Consumer Across Worker Processes

Run this same script in several worker processes; the broker activates
exactly one worker per partition and reassigns on disconnect:

```php
use CrazyGoat\RabbitStream\VO\OffsetSpec;

$consumer = $connection->createSuperStreamConsumer(
    'orders',
    OffsetSpec::first(),
    name: 'order-processor', // same name in every worker process
    autoCommit: 100,
    singleActiveConsumer: true,
);

while (true) {
    foreach ($consumer->getPartitions() as $partition) {
        if (!$consumer->isActive($partition)) {
            continue; // this worker isn't the active consumer for this partition right now
        }
    }
    foreach ($consumer->read(timeout: 1.0) as $message) {
        processOrder($message->getBody());
        $consumer->storeOffset($message->getStream(), $message->getOffset());
    }
}
```

---

## See Also

- [Super Streams Guide](../guide/super-streams.md)
- [Super Stream Routing Example](../examples/super-stream-routing.md)
- [Connection API Reference](connection.md)
- [Consumer API Reference](consumer.md)
- [Message API Reference](message.md)
- [SuperStreamProducer API Reference](super-stream-producer.md)
