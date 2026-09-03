# Super Streams

This guide covers working with RabbitMQ Super Streams — partitioned streams that enable horizontal scaling of message processing.

## Overview

Super Streams are a RabbitMQ Streams feature that allows you to partition a logical stream into multiple physical streams (partitions). This enables:

- **Horizontal scaling**: Distribute messages across multiple partitions for parallel processing
- **Increased throughput**: Multiple consumers can read from different partitions simultaneously
- **Ordered processing within partitions**: Messages within a single partition maintain their order
- **Flexible routing**: Route messages to specific partitions based on routing keys

### When to Use Super Streams

Use Super Streams when:
- You need to process more messages than a single stream can handle
- You want to parallelize consumption across multiple consumers
- You need to maintain ordering within logical groups (e.g., per-customer ordering)
- Your workload exceeds the throughput limits of a single stream

### Super Stream Architecture

```
┌─────────────────────────────────────────────────────────────┐
│                    Super Stream: orders                     │
│                    (Logical Stream)                         │
└─────────────────────────────────────────────────────────────┘
                              │
          ┌───────────────────┼───────────────────┐
          │                   │                   │
          ▼                   ▼                   ▼
┌─────────────────┐  ┌─────────────────┐  ┌─────────────────┐
│  Partition 0    │  │  Partition 1    │  │  Partition 2    │
│  orders-0       │  │  orders-1       │  │  orders-2       │
│                 │  │                 │  │                 │
│  Binding Key: 0 │  │  Binding Key: 1 │  │  Binding Key: 2 │
└─────────────────┘  └─────────────────┘  └─────────────────┘
          │                   │                   │
          └───────────────────┼───────────────────┘
                              │
                    ┌─────────┴─────────┐
                    │   Exchange        │
                    │   (routes based   │
                    │   on binding key) │
                    └───────────────────┘
```

**Key Concepts:**

- **Super Stream**: The logical stream that clients interact with (e.g., `orders`)
- **Partition**: A physical stream that stores a subset of messages (e.g., `orders-0`, `orders-1`)
- **Binding Key**: A string that determines which partition receives a message (broker-side exchange binding)
- **Routing Key**: The key used when publishing to determine the target partition

This library exposes two layers on top of this: the raw `Connection` methods
(`createSuperStream()`, `deleteSuperStream()`, `partitions()`, `route()`), and
the high-level `SuperStreamProducer`/`SuperStreamConsumer` built on top of
them, which is what most applications should use.

## Creating Super Streams

### Basic Super Stream Creation

Create a super stream with multiple partitions:

```php
use CrazyGoat\RabbitStream\Client\Connection;

$connection = Connection::create();

$superStreamName = 'orders';
$partition1 = 'orders-0';
$partition2 = 'orders-1';
$partition3 = 'orders-2';

$connection->createSuperStream(
    $superStreamName,
    [$partition1, $partition2, $partition3],
    ['0', '1', '2']
);
echo "Super stream created successfully\n";
```

### Super Stream with Arguments

Configure partitions with retention policies:

```php
$connection->createSuperStream(
    'events',
    ['events-0', 'events-1', 'events-2', 'events-3'],
    ['0', '1', '2', '3'],
    [
        'max-length-bytes' => '1073741824',     // 1 GB per partition
        'max-age' => '24h',                      // 24 hour retention
        'stream-max-segment-size-bytes' => '500000000',
    ]
);
```

### Available Arguments

| Argument | Type | Description | Example |
|----------|------|-------------|---------|
| `max-length-bytes` | string | Maximum total size per partition | `'1000000000'` (1 GB) |
| `max-age` | string | Maximum age of messages | `'24h'`, `'7d'` |
| `stream-max-segment-size-bytes` | string | Maximum size of segment files | `'500000000'` (500 MB) |
| `initial-cluster-size` | string | Initial number of replicas | `'3'` |

### Error Handling: SUPER_STREAM_ALREADY_EXISTS

Handle the case when a super stream already exists — the high-level API throws `ProtocolException` when the server answers with an error code:

```php
use CrazyGoat\RabbitStream\Exception\ProtocolException;
use CrazyGoat\RabbitStream\Enum\ResponseCodeEnum;

try {
    $connection->createSuperStream(
        $superStreamName,
        [$partition1, $partition2],
        ['key1', 'key2']
    );
    echo "Super stream created\n";
} catch (ProtocolException $e) {
    if ($e->getResponseCode() === ResponseCodeEnum::STREAM_ALREADY_EXISTS) {
        echo "Super stream already exists - continuing\n";
    } else {
        throw $e;
    }
}
```

## Deleting Super Streams

### Basic Deletion

Delete a super stream and all its partitions:

```php
$connection->deleteSuperStream('orders');
echo "Super stream deleted\n";
```

### Error Handling: SUPER_STREAM_NOT_EXIST

Handle the case when the super stream doesn't exist:

```php
use CrazyGoat\RabbitStream\Exception\ProtocolException;
use CrazyGoat\RabbitStream\Enum\ResponseCodeEnum;

try {
    $connection->deleteSuperStream($superStreamName);
    echo "Super stream deleted\n";
} catch (ProtocolException $e) {
    if ($e->getResponseCode() === ResponseCodeEnum::STREAM_NOT_EXIST) {
        echo "Super stream does not exist - nothing to delete\n";
    } else {
        throw $e;
    }
}
```

## Listing Partitions

`Connection::partitions(string $superStream): array` resolves a super
stream's partition (physical stream) names:

```php
$partitions = $connection->partitions('orders');
echo "Partitions: " . implode(', ', $partitions) . "\n";
// Output: Partitions: orders-0, orders-1, orders-2
```

It throws `ProtocolException` if the super stream doesn't exist, and also if
it exists but currently has zero partitions. `createSuperStreamProducer()`
and `createSuperStreamConsumer()` (below) call this internally, so you
rarely need to call it yourself — it's mainly useful for discovery/monitoring
scripts.

## Routing

`Connection::route(string $routingKey, string $superStream): array` asks the
broker which partition(s) a routing key maps to, via the exchange bindings
created with `createSuperStream()`'s `$bindingKeys`:

```php
$routingKey = 'customer-123';
$superStream = 'orders';

$streams = $connection->route($routingKey, $superStream);
echo "Routing key '$routingKey' maps to: " . implode(', ', $streams) . "\n";
```

Like the Java client, this can legitimately return more than one partition
for a single key (e.g. overlapping binding keys). This method is a broker
round trip per call — see `KeyRoutingStrategy` below for a producer-side
strategy that caches the result per key.

## Publishing: SuperStreamProducer

`Connection::createSuperStreamProducer()` returns a `SuperStreamProducerInterface`
that resolves the super stream's partitions once, then routes and publishes
each message via a pluggable `RoutingStrategy`:

```php
use CrazyGoat\RabbitStream\Client\Connection;

$connection = Connection::create();

$producer = $connection->createSuperStreamProducer('orders');

$producer->send('Order #1 payload', routingKey: 'customer-123');
$producer->sendBatch([
    ['Order #2 payload', 'customer-123'],
    ['Order #3 payload', 'customer-456'],
]);

$producer->waitForConfirms(timeout: 5.0);
$producer->close();
```

A `Producer` for each partition is opened **lazily**, on the first publish
routed to that partition — creating a `SuperStreamProducer` for a
100-partition super stream does not open 100 sockets/publishers up front.

> **Caveat**: partition membership is resolved once, when the
> `SuperStreamProducer` is created. A `MetadataUpdate` for a partition (e.g.
> its leader changed, or the super stream's partition set itself changed) is
> **not** automatically detected or handled — if your topology can change at
> runtime, recreate the `SuperStreamProducer` to pick it up.

> **Throughput tip:** prefer `sendBatch()` over per-message `send()` when
> publishing to a super stream. Routing spreads the writes over partitions, so
> single-message publishes end up as very small chunks (measured: ~5 messages
> per chunk vs ~106 with batches of 500), which slows down every consumer of
> that partition. See [Performance Tuning](../advanced/performance-tuning.md#super-streams-over-a-network).

### Hash routing (default)

By default, `createSuperStreamProducer()` uses `HashRoutingStrategy`: it
hashes the routing key with **MurmurHash3 x86_32** (seed `104729`, exposed as
`HashRoutingStrategy::SEED`) and takes the unsigned result modulo the
partition count, exactly the scheme the **Java and .NET RabbitMQ Stream
clients** use. This means:

- Routing is entirely client-side — no broker round trip per publish.
- A producer written in PHP and a producer written in Java/.NET, publishing
  the same routing key to the same super stream, land on the **same
  partition** — this is essential for any cross-language deployment.

```php
use CrazyGoat\RabbitStream\Client\Routing\HashRoutingStrategy;

// Explicit (this is also the default when $strategy is omitted):
$producer = $connection->createSuperStreamProducer('orders', new HashRoutingStrategy());
```

### Key routing (broker-resolved)

`KeyRoutingStrategy` instead calls `Connection::route()` — the broker-side,
exchange-binding-based routing described above — once per distinct routing
key, caching the result in memory for the lifetime of the strategy instance
(repeated keys never trigger a second round trip). Use it when partition
placement must follow the super stream's binding keys rather than a hash
(e.g. explicit region/range routing):

```php
use CrazyGoat\RabbitStream\Client\Routing\KeyRoutingStrategy;

$strategy = new KeyRoutingStrategy($connection, 'orders');
$producer = $connection->createSuperStreamProducer('orders', $strategy);

$producer->send('Order payload', routingKey: 'eu');
```

If the broker returns no partition for a key (no binding matches),
`KeyRoutingStrategy::route()` throws
`CrazyGoat\RabbitStream\Exception\NoRouteForKeyException` (extends the
library's `RabbitStreamException`), exposing `getRoutingKey()` and
`getSuperStream()`.

### Named producers

Passing `name:` gives each **partition's** underlying `Producer` a derived
name `"{$name}-{$partition}"`, so per-partition sequence dedup
(`Producer::querySequence()`) still works correctly per partition:

```php
$producer = $connection->createSuperStreamProducer('orders', name: 'order-service');
// Partition 'orders-0' gets producer name 'order-service-orders-0', etc.
```

### SuperStreamProducer API

| Method | Description |
|--------|-------------|
| `send(string $message, string $routingKey, ?float $timeout = null): void` | Route and publish one message |
| `sendBatch(array $messages, ?float $timeout = null): void` | `array<array{0: string, 1: string}>` of `[message, routingKey]` pairs — grouped per destination partition, one batch send per partition |
| `waitForConfirms(float $timeout = 5.0): void` | Waits across every partition producer opened so far |
| `getPendingConfirms(): int` | Sum of pending confirms across all opened partition producers |
| `getPartitions(): array` | The super stream's partition names, as resolved at creation time |
| `close(): void` | Closes every partition producer opened so far |

## Consuming: SuperStreamConsumer

`Connection::createSuperStreamConsumer()` resolves the super stream's
partitions and subscribes a plain `Consumer` to every one of them, all
sharing the same `$name` (see [Single Active Consumer](#single-active-consumer-on-super-streams)
below for why that matters):

```php
use CrazyGoat\RabbitStream\VO\OffsetSpec;

$consumer = $connection->createSuperStreamConsumer('orders', OffsetSpec::first(), name: 'order-processor');

while (true) {
    foreach ($consumer->read(timeout: 5.0) as $message) {
        echo "[{$message->getStream()}] offset={$message->getOffset()} {$message->getBody()}\n";
    }
}
```

`Message::getStream(): ?string` returns the name of the partition (physical
stream) the message was actually delivered from, set once per message from
the subscribing `Consumer`'s stream name — this is how you tell which
partition a message in the aggregated `read()`/`readOne()` result came from.

> **Credit window:** partition chunks are usually much smaller than plain-stream
> chunks. The consumer adapts its credit window in bytes (`creditWindowBytes`,
> default 8 MiB), so small chunks over a network no longer throttle it to a few
> messages per round trip; raise it for high-latency links. See
> [Flow Control](flow-control.md#credit-is-counted-in-chunks-not-bytes).

### read() vs. readOne()

- `read(float $timeout = 5.0): array` — if any partition already has buffered
  messages, drains all of them across every partition **without blocking**;
  otherwise it performs exactly one bounded read pass against the connection
  and then collects whatever became buffered.
- `readOne(float $timeout = 5.0): ?Message` — like `read()`, but returns at
  most one message, round-robining fairly across partitions that currently
  have buffered data (so one noisy partition can't starve the others).

### SuperStreamConsumer API

| Method | Description |
|--------|-------------|
| `read(float $timeout = 5.0): array` | See above |
| `readOne(float $timeout = 5.0): ?Message` | See above |
| `storeOffset(string $partition, int $offset): void` | Store an offset for one partition |
| `queryOffset(string $partition): int` | Query the stored offset for one partition |
| `getPartitions(): array` | The super stream's partition names |
| `getConsumers(): array` | `array<string, ConsumerInterface>`, partition name => the underlying `Consumer` |
| `isActive(string $partition): bool` | Single-active-consumer activation state for one partition |
| `close(): void` | Closes every partition's `Consumer` (see the known limitation below) |

### Per-partition offset tracking

Offset tracking is entirely **per-partition** — each partition is a distinct
physical stream with its own offset sequence. There is no aggregate,
super-stream-wide offset; `storeOffset()`/`queryOffset()` always take the
partition name explicitly:

```php
foreach ($consumer->read(timeout: 5.0) as $message) {
    process($message);
    $consumer->storeOffset($message->getStream(), $message->getOffset());
}
```

### Single Active Consumer on super streams

Passing `singleActiveConsumer: true` to `createSuperStreamConsumer()`
subscribes every partition with `singleActiveConsumer` set and the shared
`$name` — this is exactly what the broker needs to group the per-partition
subscriptions into one single-active-consumer group *per partition*, so that
running several `SuperStreamConsumer` instances (e.g. one per worker
process) with the same `$name` gives you coordinated, exclusive consumption
of each partition, with automatic reassignment when a worker disconnects.
Activation state is tracked per partition — `isActive($partition)` reflects
the broker's most recent `ConsumerUpdate` for that one partition, not the
super stream as a whole.

## Monitoring Partition Balance

Check that messages are evenly distributed across partitions using
`Connection::getStreamStats()` per partition:

```php
function checkPartitionBalance(Connection $connection, array $partitions): array
{
    $stats = [];

    foreach ($partitions as $partition) {
        $raw = $connection->getStreamStats($partition);

        $stats[$partition] = [
            'first_offset' => $raw['first_offset'] ?? 0,
            'last_offset' => $raw['last_offset'] ?? 0,
            'message_count' => ($raw['last_offset'] ?? 0) - ($raw['first_offset'] ?? 0) + 1,
        ];
    }

    return $stats;
}

// Usage
$stats = checkPartitionBalance($connection, $connection->partitions('orders'));
foreach ($stats as $partition => $info) {
    echo "$partition: {$info['message_count']} messages\n";
}
```

## Best Practices

### 1. Partition Count Selection

Choose the right number of partitions:
- **Too few**: Limits throughput, can't scale
- **Too many**: Increases overhead, harder to manage
- **Rule of thumb**: Start with 2-4x your expected consumer count

### 2. Idempotent Creation

Make super stream creation idempotent:

```php
use CrazyGoat\RabbitStream\Exception\ProtocolException;
use CrazyGoat\RabbitStream\Enum\ResponseCodeEnum;

function ensureSuperStreamExists(Connection $connection, string $name, int $partitionCount): void
{
    $partitions = [];
    $bindingKeys = [];

    for ($i = 0; $i < $partitionCount; $i++) {
        $partitions[] = "{$name}-{$i}";
        $bindingKeys[] = (string)$i;
    }

    try {
        $connection->createSuperStream($name, $partitions, $bindingKeys);
    } catch (ProtocolException $e) {
        if ($e->getResponseCode() !== ResponseCodeEnum::STREAM_ALREADY_EXISTS) {
            throw $e;
        }
        // Already exists - that's fine
    }
}
```

### 3. Cleanup in Finally Blocks

Always clean up super streams in tests or temporary usage:

```php
$superStreamName = 'temp-orders-' . uniqid();

$partitions = [];
$bindingKeys = [];
for ($i = 0; $i < 3; $i++) {
    $partitions[] = "{$superStreamName}-{$i}";
    $bindingKeys[] = (string)$i;
}

try {
    $connection->createSuperStream($superStreamName, $partitions, $bindingKeys);

    // ... use the super stream ...
} finally {
    try {
        $connection->deleteSuperStream($superStreamName);
    } catch (\Exception $e) {
        error_log("Failed to delete super stream: " . $e->getMessage());
    }
}
```

## Error Handling

### Common Super Stream Errors

| Code | Name | Description | Handling Strategy |
|------|------|-------------|-------------------|
| 0x02 | STREAM_NOT_EXIST | Super stream or partition doesn't exist | Create stream or fail |
| 0x03 | STREAM_ALREADY_EXISTS | Super stream already exists | Continue or delete/recreate |
| 0x10 | ACCESS_REFUSED | No permission | Check credentials |
| 0x11 | PRECONDITION_FAILED | Invalid arguments | Validate parameters |

## See Also

- [Super Stream Routing Example](../examples/super-stream-routing.md) - Complete working example
- [Stream Management Guide](./stream-management.md) - Managing individual streams
- [Publishing Guide](./publishing.md) - Publishing messages
- [Consuming Guide](./consuming.md) - Consuming messages
- [SuperStreamProducer API Reference](../api-reference/super-stream-producer.md)
- [SuperStreamConsumer API Reference](../api-reference/super-stream-consumer.md)
- [Protocol Reference](../protocol/routing-commands.md) - Low-level protocol details
