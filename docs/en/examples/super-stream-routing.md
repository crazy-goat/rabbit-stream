# Super Stream Routing Example

This example demonstrates a complete workflow for working with RabbitMQ Super Streams using the high-level `SuperStreamProducer`/`SuperStreamConsumer` API: creation, routing, publishing, and consuming.

## Overview

This example shows how to:
1. Create a super stream with multiple partitions
2. Publish messages routed to partitions with the default hash routing strategy (MurmurHash3, Java/.NET-compatible)
3. Publish messages routed via broker-resolved key routing
4. Consume messages from all partitions through one `SuperStreamConsumer`
5. Clean up resources

## Prerequisites

- RabbitMQ 3.11+ with the stream plugin enabled
- PHP 8.1+ with the `pcntl` extension (used by the consumer script for graceful shutdown)
- The RabbitStream library installed

## Runnable scripts

Two ready-to-run scripts ship in `examples/`:

- [`examples/super_stream_producer.php`](../../../examples/super_stream_producer.php) — creates the super stream (if needed) and publishes 100 messages using the default hash routing strategy
- [`examples/super_stream_consumer.php`](../../../examples/super_stream_consumer.php) — subscribes to every partition and prints each message's partition, offset and body until interrupted (Ctrl+C)

## Producer: default hash routing

```php
use CrazyGoat\RabbitStream\Client\Connection;

$connection = Connection::create(host: '127.0.0.1', port: 5552, user: 'guest', password: 'guest');

$superStream = 'my-super-stream';
$connection->createSuperStream(
    $superStream,
    ['my-super-stream-0', 'my-super-stream-1', 'my-super-stream-2'],
    ['0', '1', '2']
);

// No $strategy argument: defaults to HashRoutingStrategy — MurmurHash3 x86_32
// (seed 104729), the exact scheme the Java and .NET RabbitMQ Stream clients
// use, so producers in other languages land the same routing key on the
// same partition.
$producer = $connection->createSuperStreamProducer($superStream, name: 'super-stream-producer');

for ($i = 0; $i < 100; $i++) {
    $customerId = 'customer-' . ($i % 5);
    $message = json_encode(['order_id' => $i, 'customer_id' => $customerId]);
    $producer->send($message, routingKey: $customerId);
}

$producer->waitForConfirms(timeout: 5.0);
$producer->close();
$connection->close();
```

## Producer: key routing (broker-resolved)

Use `KeyRoutingStrategy` when partition placement must follow the super
stream's exchange bindings (`$bindingKeys` passed to `createSuperStream()`)
instead of a hash — for example, explicit region routing:

```php
use CrazyGoat\RabbitStream\Client\Routing\KeyRoutingStrategy;

$superStream = 'orders-by-region';
$connection->createSuperStream(
    $superStream,
    ['orders-us', 'orders-eu', 'orders-asia'],
    ['us', 'eu', 'asia']
);

$strategy = new KeyRoutingStrategy($connection, $superStream);
$producer = $connection->createSuperStreamProducer($superStream, $strategy);

// One Route request per distinct key (cached in memory afterwards).
$producer->send('Order payload', routingKey: 'eu');
$producer->send('Another order', routingKey: 'us');

$producer->waitForConfirms(timeout: 5.0);
$producer->close();
```

If no binding matches a routing key, `KeyRoutingStrategy` throws
`CrazyGoat\RabbitStream\Exception\NoRouteForKeyException`.

## Consumer: reading from every partition

```php
use CrazyGoat\RabbitStream\VO\OffsetSpec;

$consumer = $connection->createSuperStreamConsumer(
    'my-super-stream',
    offset: OffsetSpec::first(),
    name: 'super-stream-consumer',
);

while (true) {
    foreach ($consumer->read(timeout: 5.0) as $message) {
        // getStream() names the partition this message was delivered from —
        // offset tracking is per-partition, so store against that name.
        echo "[{$message->getStream()}] offset={$message->getOffset()} {$message->getBody()}\n";
        $consumer->storeOffset($message->getStream(), $message->getOffset());
    }
}
```

## Running the Example

1. **Start RabbitMQ with the stream plugin:**

```bash
docker run -d --name rabbitmq \
  -p 5672:5672 \
  -p 15672:15672 \
  -p 5552:5552 \
  rabbitmq:3.11-management-alpine

# Enable the stream plugin
docker exec rabbitmq rabbitmq-plugins enable rabbitmq_stream
```

2. **Install dependencies:**

```bash
composer install
```

3. **Run the producer, then the consumer (in another terminal):**

```bash
php examples/super_stream_producer.php
php examples/super_stream_consumer.php
```

## Expected Output

Producer:

```
Created super stream 'my-super-stream' with 3 partitions.
Done. Published 100 messages across partitions:
  - my-super-stream-0
  - my-super-stream-1
  - my-super-stream-2
```

Consumer (Ctrl+C to stop):

```
partition=my-super-stream-1 offset=0 body={"order_id":0,"customer_id":"customer-1","amount":742}
partition=my-super-stream-2 offset=0 body={"order_id":1,"customer_id":"customer-2","amount":318}
...
```

## Key Concepts Demonstrated

### 1. Cross-Language-Compatible Hash Routing

`HashRoutingStrategy` (the default) hashes the routing key with MurmurHash3
x86_32, seed `104729` (`HashRoutingStrategy::SEED`), and takes the unsigned
result modulo the partition count — the exact scheme the Java and .NET
clients use, so mixed-language producers agree on partition placement for the
same key. Routing is entirely client-side; no broker round trip per publish.

### 2. Lazy Per-Partition Producers

`SuperStreamProducer` opens a `Producer` for a partition only on the first
message routed to it, not eagerly for every partition at construction.

### 3. Aggregated, Fair Consumption

`SuperStreamConsumer` subscribes a plain `Consumer` to every partition (all
sharing the consumer name), and `read()`/`readOne()` aggregate across them —
`readOne()` round-robins fairly so one high-traffic partition can't starve
the others.

### 4. Per-Partition Offset Tracking

There is no aggregate, super-stream-wide offset: `storeOffset()`/
`queryOffset()` on `SuperStreamConsumer` always take the partition name
(`Message::getStream()`) explicitly.

### 5. Resource Cleanup

```php
$producer->waitForConfirms(timeout: 5.0);
$producer->close();

$consumer->close(); // closes every partition's Consumer

$connection->deleteSuperStream($superStreamName);
$connection->close();
```

## Known limitations

Closing several single-active-consumer partitions in a row on one connection
can occasionally race with the broker pushing a `ConsumerUpdate` activation
query for another partition while a partition's own `close()` is waiting on
its `UnsubscribeResponse`. This is a pre-existing limitation of `Consumer`'s
non-correlated response dispatch, not something `SuperStreamConsumer`
introduces — its `close()` tolerates the race per-partition (it keeps closing
the remaining partitions rather than aborting). See the
[Super Streams Guide](../guide/super-streams.md#known-limitations) for details.

## Troubleshooting

### Issue: "Stream does not exist"

**Cause**: Trying to publish/consume a super stream that was never created (or was deleted).

**Solution**: Verify with `Connection::partitions($superStream)` first — it throws `ProtocolException` if the super stream doesn't exist.

### Issue: "Access refused"

**Cause**: Insufficient permissions.

**Solution**: Check user has stream management permissions:

```bash
rabbitmqctl set_permissions -p / guest ".*" ".*" ".*"
```

### Issue: `NoRouteForKeyException` with `KeyRoutingStrategy`

**Cause**: The routing key doesn't match any binding key configured on `createSuperStream()`.

**Solution**: Verify with a raw `route()` call:

```php
$streams = $connection->route($routingKey, $superStream);
var_dump($streams); // empty array means no binding matched
```

## See Also

- [Super Streams Guide](../guide/super-streams.md) - Comprehensive guide
- [SuperStreamProducer API Reference](../api-reference/super-stream-producer.md)
- [SuperStreamConsumer API Reference](../api-reference/super-stream-consumer.md)
- [Stream Management Guide](../guide/stream-management.md) - Managing streams
- [Publishing Guide](../guide/publishing.md) - Publishing messages
- [Consuming Guide](../guide/consuming.md) - Consuming messages
