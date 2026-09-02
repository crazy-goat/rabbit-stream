# SuperStreamProducer API Reference

Complete API reference for the `CrazyGoat\RabbitStream\Client\SuperStreamProducer` class (implementing `SuperStreamProducerInterface`).

## Class Overview

```php
namespace CrazyGoat\RabbitStream\Client;

class SuperStreamProducer implements SuperStreamProducerInterface
{
    // Constructor and internal methods...

    public function send(string $message, string $routingKey, ?float $timeout = null): void;
    public function sendBatch(array $messages, ?float $timeout = null): void;
    public function waitForConfirms(float $timeout = 5.0): void;
    public function getPendingConfirms(): int;
    public function getPartitions(): array;
    public function close(): void;
}
```

Publishes to a super stream's partitions, routing each message to the
correct partition(s) via a `RoutingStrategy` — see
[`RoutingStrategy`](#routing-strategies) below. Opens one `Producer` per
partition **lazily**, on the first publish to that partition.

## Constructor

The `SuperStreamProducer` class is instantiated via
`Connection::createSuperStreamProducer()`. Direct instantiation is not
recommended.

```php
use CrazyGoat\RabbitStream\Client\Routing\RoutingStrategy;

$producer = $connection->createSuperStreamProducer(
    string $superStream,                // Required: super stream name
    ?RoutingStrategy $strategy = null,  // Optional: default HashRoutingStrategy
    ?string $name = null,               // Optional: base producer name
    ?callable $onConfirm = null,        // Optional: confirmation callback
    int $maxPendingConfirms = Producer::DEFAULT_MAX_PENDING_CONFIRMS,
): SuperStreamProducerInterface
```

### Parameters

| Parameter | Type | Required | Description |
|-----------|------|----------|-------------|
| `$superStream` | `string` | Yes | Name of the super stream to publish to |
| `$strategy` | `?RoutingStrategy` | No | Routing strategy; defaults to `HashRoutingStrategy` |
| `$name` | `?string` | No | Base name. Each partition's underlying `Producer` gets `"{$name}-{$partition}"` so per-partition sequence dedup (`Producer::querySequence()`) still works. |
| `$onConfirm` | `?callable` | No | Confirmation callback, passed through to every partition's `Producer` |
| `$maxPendingConfirms` | `int` | No | Back-pressure cap, passed through to every partition's `Producer` |

### Examples

**Default hash routing:**
```php
$producer = $connection->createSuperStreamProducer('orders');
```

**Key routing, named producer, confirm callback:**
```php
use CrazyGoat\RabbitStream\Client\ConfirmationStatus;
use CrazyGoat\RabbitStream\Client\Routing\KeyRoutingStrategy;

$producer = $connection->createSuperStreamProducer(
    'orders',
    new KeyRoutingStrategy($connection, 'orders'),
    name: 'order-service',
    onConfirm: function (ConfirmationStatus $status) {
        if (!$status->isConfirmed()) {
            error_log("Publish failed: #{$status->getPublishingId()}");
        }
    },
);
```

## Methods

### send()

Publish a single message, routed to a partition (or several — see
`RoutingStrategy::route()`) by the configured routing strategy.

```php
public function send(string $message, string $routingKey, ?float $timeout = null): void
```

#### Parameters

| Parameter | Type | Required | Description |
|-----------|------|----------|-------------|
| `$message` | `string` | Yes | Message body as string, same encoding as `Producer::send()` (wrapped in an AMQP 1.0 Data section on the wire) |
| `$routingKey` | `string` | Yes | The key used to determine the destination partition(s) |
| `$timeout` | `?float` | No | Socket write timeout in seconds; null uses connection default |

#### Return Value

`void`

#### Exceptions

- `InvalidArgumentException` - `HashRoutingStrategy` with an empty partition list (should not happen — partitions are resolved at construction)
- `NoRouteForKeyException` - `KeyRoutingStrategy` when the broker has no binding for `$routingKey`
- `ConnectionException` - If the connection is lost

#### Example

```php
$producer->send('Order #1 payload', routingKey: 'customer-123');
```

#### Notes

- Opens the destination partition's `Producer` on first use (lazy)
- With `HashRoutingStrategy` this is entirely client-side — no broker round trip

---

### sendBatch()

Publish multiple messages, each routed independently, grouped into one batch
send per destination partition.

```php
/**
 * @param list<array{0: string, 1: string}> $messages list of [message, routingKey] pairs
 */
public function sendBatch(array $messages, ?float $timeout = null): void
```

#### Parameters

| Parameter | Type | Required | Description |
|-----------|------|----------|-------------|
| `$messages` | `list<array{0: string, 1: string}>` | Yes | `[message, routingKey]` pairs |
| `$timeout` | `?float` | No | Socket write timeout in seconds |

#### Return Value

`void`

#### Example

```php
$producer->sendBatch([
    ['Order #1 payload', 'customer-123'],
    ['Order #2 payload', 'customer-456'],
    ['Order #3 payload', 'customer-123'], // same partition as #1
]);
```

#### Notes

- Messages are grouped by destination partition first, then sent with one `Producer::sendBatch()` call per partition — so two messages routed to the same partition share a network frame even if their routing keys differ
- Empty input array is a no-op
- If a routing key resolves to more than one partition (possible with `KeyRoutingStrategy`), the message is queued into every one of them

---

### waitForConfirms()

Block until all pending publish confirmations are received, across every
partition producer opened so far.

```php
public function waitForConfirms(float $timeout = 5.0): void
```

#### Parameters

| Parameter | Type | Required | Description |
|-----------|------|----------|-------------|
| `$timeout` | `float` | No | Maximum time to wait in seconds, applied per partition producer. Default: `5.0` |

#### Return Value

`void`

#### Exceptions

- `TimeoutException` - If any partition's confirms don't complete within the timeout

#### Notes

- Only iterates partitions whose `Producer` has actually been opened (i.e. at least one message was routed there)

---

### getPendingConfirms()

Get the total number of publishes sent but not yet confirmed or errored, summed across every partition producer opened so far.

```php
public function getPendingConfirms(): int
```

#### Return Value

`int`

---

### getPartitions()

The super stream's partition names, as resolved when this producer was created.

```php
/** @return list<string> */
public function getPartitions(): array
```

#### Return Value

`list<string>`

#### Example

```php
foreach ($producer->getPartitions() as $partition) {
    echo "Partition: {$partition}\n";
}
```

#### Notes

- This does **not** reflect a topology change after construction — see the caveat under [Constructor](#constructor)

---

### close()

Close every partition producer opened so far.

```php
public function close(): void
```

#### Return Value

`void`

#### Notes

- Only partitions that actually had a `Producer` opened (i.e. received at least one message) are closed
- Safe to call multiple times

---

## Routing Strategies

`SuperStreamProducer` delegates "which partition(s) does this routing key map
to" entirely to a `RoutingStrategy`:

```php
namespace CrazyGoat\RabbitStream\Client\Routing;

interface RoutingStrategy
{
    /** @return list<string> */
    public function route(string $routingKey, array $partitions): array;
}
```

### HashRoutingStrategy (default)

```php
final class HashRoutingStrategy implements RoutingStrategy
{
    public const SEED = 104729;

    public function route(string $routingKey, array $partitions): array; // exactly one partition
}
```

Hashes `$routingKey` with **MurmurHash3 x86_32** using seed `104729`
(`HashRoutingStrategy::SEED`) and takes the **unsigned** result modulo
`count($partitions)`. This is entirely client-side (no broker round trip)
and, critically, is the exact same hash function, seed, and modulo scheme
used by the **Java and .NET RabbitMQ Stream clients** — a routing key lands
on the same partition regardless of which client language published it.

Throws `InvalidArgumentException` if `$partitions` is empty.

```php
use CrazyGoat\RabbitStream\Client\Routing\HashRoutingStrategy;

$producer = $connection->createSuperStreamProducer('orders', new HashRoutingStrategy());
// equivalent to the default: $strategy = null
```

### KeyRoutingStrategy

```php
final class KeyRoutingStrategy implements RoutingStrategy
{
    public function __construct(ConnectionInterface $connection, string $superStream);

    public function route(string $routingKey, array $partitions): array;
}
```

Routes via the broker's exchange-binding-based routing
(`Connection::route()`), one `Route` request per **distinct** routing key —
the result is cached in memory on the strategy instance, so repeated keys
never trigger a second round trip. Like the Java client, a single key can
legitimately resolve to more than one partition.

Throws `CrazyGoat\RabbitStream\Exception\NoRouteForKeyException` (extends
`RabbitStreamException`, exposes `getRoutingKey()`/`getSuperStream()`) if the
broker's `Route` response contains no partitions for the key.

```php
use CrazyGoat\RabbitStream\Client\Routing\KeyRoutingStrategy;

$strategy = new KeyRoutingStrategy($connection, 'orders');
$producer = $connection->createSuperStreamProducer('orders', $strategy);
```

### Custom strategies

Implement `RoutingStrategy` directly for anything else (e.g. sticky
round-robin, or a custom mapping table):

```php
use CrazyGoat\RabbitStream\Client\Routing\RoutingStrategy;

final class RoundRobinRoutingStrategy implements RoutingStrategy
{
    private int $index = 0;

    public function route(string $routingKey, array $partitions): array
    {
        $partition = $partitions[$this->index % count($partitions)];
        $this->index++;
        return [$partition];
    }
}
```

---

## See Also

- [Super Streams Guide](../guide/super-streams.md)
- [Super Stream Routing Example](../examples/super-stream-routing.md)
- [Connection API Reference](connection.md)
- [Producer API Reference](producer.md)
- [SuperStreamConsumer API Reference](super-stream-consumer.md)
