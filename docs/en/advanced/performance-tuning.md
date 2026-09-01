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

## Serialization & Frame I/O Overhead

Below the batching-level tuning above, the client also minimizes CPU cost per
message on the hot publish path and per frame on the hot receive path:

- `PublishRequestV1`/`V2::toStreamBuffer()` build the entire request payload
  with direct `pack()` calls and string concatenation in one pass, instead of
  routing the header and each message through its own `WriteBuffer` object.
- `StreamConnection::wrapFrame()` and `CommandTrait::getKeyVersion()` build
  their fixed-width headers with a single `pack()` call instead of several
  chained `WriteBuffer::addUIntX()` calls.
- `PublishConfirmResponseV1::fromStreamBuffer()` reads all publishing ids
  with one `unpack('J*', ...)` call instead of one `ReadBuffer::getUint64()`
  call per id.
- `StreamConnection::readBytes()` reads with `socket_recv(..., MSG_WAITALL)`
  in a loop instead of looping `socket_read()` with `.=`, cutting the number
  of syscalls and string reallocations needed to read a large frame (e.g. an
  8MB Deliver frame).

Micro-benchmark results (Apple Silicon, PHP 8.5, localhost broker):

| Operation | Before | After |
|-----------|--------|-------|
| Serialize 1x 1KB `PublishRequestV1` | ~0.72 µs | ~0.42 µs |
| Serialize batch of 500x 1KB messages | ~0.26 µs/msg | ~0.17 µs/msg |
| Serialize 1x 512KB message | ~78 µs | ~15 µs |
| Parse `PublishConfirm` with 5000 ids | ~365 µs total | ~29 µs total |

These are CPU-only serialization/deserialization costs, not full round-trip
latency — see [Performance Comparison](#performance-comparison) above for
end-to-end throughput. The wire format is unchanged; every existing
exact-byte serialization test still passes.

## Credit Tuning

### Understanding Credits

Credits control flow between server and consumer, and they are **chunk-granular**:
1 credit grants exactly 1 future chunk delivery, regardless of how many
messages that chunk contains (the server always delivers whole chunks — one
Deliver frame per chunk, atomic on the wire, from 1 to thousands of messages
each). `maxBufferSize`, covered below, is a separate, **message**-granular
bound — see [Consumer Buffer Size](#consumer-buffer-size) for how the two
interact.

```
Consumer ──► Subscribe (initialCredit=10) ──► Server
Consumer ◄── Deliver (1 chunk)     ◄─────── Server   [consumes 1 credit, any number of messages]
Consumer ──► Credit (5)            ─────────────────► Server
Consumer ◄── Deliver (5 chunks)    ◄────── Server    [consumes 5 credits]
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

The Consumer automatically manages credits, with units kept consistent
throughout: `pendingCredits` and `creditsInFlight` are chunk-granular counters,
and the buffer-full check they're gated on compares against `unreadCount`
(unread messages), never the raw backing-array size:

```php
// One credit unit is owed per chunk delivered...
$this->pendingCredits++;

// ...and sent back only while there's message-level headroom in the buffer,
// bounded by how much chunk-level credit initialCredit still allows outstanding.
private function sendPendingCredits(): void
{
    if ($this->pendingCredits <= 0 || $this->unreadCount >= $this->maxBufferSize) {
        return;
    }
    $creditsToSend = min($this->pendingCredits, $this->initialCredit - $this->creditsInFlight, self::MAX_UINT16);
    // ... send $creditsToSend, decrement pendingCredits, increment creditsInFlight
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

Controls back-pressure on the consumer. `maxBufferSize` is a **message** bound
— a target, not a hard cap — while credit remains chunk-granular (see
[Understanding Credits](#understanding-credits)):

```php
$consumer = new Consumer(
    connection: $connection,
    stream: 'my-stream',
    subscriptionId: 1,
    offset: OffsetSpec::next(),
    initialCredit: 100,
    maxBufferSize: 1000,  // Target: keep unread messages around/under 1000
);
```

**Behavior:**
- A delivered chunk is always accepted into the buffer in full — messages are
  never dropped (at-least-once delivery) — even if that overshoots
  `maxBufferSize`; the buffer can transiently hold up to one chunk's worth more
  than the configured bound right after a chunk lands
- Once the unread count reaches or exceeds `maxBufferSize`, no *new* credit is
  granted; withheld credit is remembered and granted back — one credit per
  chunk's worth of headroom that reopens — as the buffer drains via
  `read()`/`readOne()`
- Outstanding (in-flight) credit is additionally capped at `initialCredit`, so
  the server can never have more than `initialCredit` chunks in flight at once
- Server stops delivering new chunks once it runs out of un-replenished credit
- Prevents unbounded memory growth on slow consumers, bounded by chunk size
  rather than message size alone

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

## Message Decoding

Chunk parsing and AMQP 1.0 section decoding are both **lazy**:

- `OsirisChunkParser::parseEntries()` (used internally by `Consumer`) yields
  `ChunkEntry` instances one at a time from a chunk instead of materialising
  them all up front. `OsirisChunkParser::parse()` still returns an eager
  `ChunkEntry[]` array — its signature and behavior are unchanged — but it is
  now implemented in terms of `parseEntries()`.
- Each `Message` decodes its body, properties, application properties and
  message annotations only on the **first** call to a getter that needs them
  (`getBody()`, `getProperties()`, etc.) — not at construction time — and
  caches the result for subsequent calls.

This means a consumer that only reads `getOffset()`/`getTimestamp()`, or that
reads a handful of messages out of a large chunk and moves on, never pays the
AMQP decode cost (properties, application properties, message annotations, and
the body itself) for messages it never inspects. The eager `Message`
constructor and all its accessors keep working exactly as before —
`Message::fromRawEntry()` is purely an additional, opt-in construction path
used internally by `AmqpMessageDecoder::decode()`.

### Consumer Hot Path: No Intermediate ChunkEntry, and a Data-Section Fast Path

For the common case — 1 KB payloads published by this library's own
`Producer`, which always wraps the body in a single AMQP 1.0 Data section —
two further optimizations cut per-message CPU on `Consumer`'s deliver path:

- `OsirisChunkParser::parseMessages()` yields `Message` objects directly from
  a chunk, sharing the same validated parsing core as `parseEntries()`/
  `parse()` but skipping the intermediate `ChunkEntry` allocation entirely.
  `Consumer` uses `parseMessages()` internally; `parseEntries()` and `parse()`
  are unchanged and still return `ChunkEntry`.
- `Message::getBody()` recognizes a raw entry that is *exactly* one AMQP 1.0
  Data section (`00 53 75 b0` + big-endian uint32 length + body, or the
  `00 53 75 a0` vbin8 variant) and extracts the body directly, without
  invoking the generic `AmqpDecoder`. Anything else (a Properties section
  present, a different body encoding, etc.) transparently falls back to the
  full `AmqpDecoder::decodeMessage()` path — behavior is identical either way,
  only the cost differs.

Measured against a real `bench-batch` stream of 1 KB messages (12 captured
chunks, ~21,500 entries, averaged over repeated passes):

| Path | Before | After |
|------|--------|-------|
| Chunk → `Message` construction | ~0.62–0.66 µs/msg | ~0.47–0.53 µs/msg |
| `getBody()` | ~0.68–0.74 µs/msg | ~0.16–0.18 µs/msg |

`AmqpDecoder`'s primitive readers (used by the generic-decode fallback) were
also tightened: offset-form `unpack($format, $data, $position)` instead of
`substr()` + `unpack()`, and big-endian format codes (`n`/`N`/`J`, `G`/`E` for
float/double) with explicit two's-complement sign conversion instead of
`strrev()` + machine-endian formats (`s`/`l`/`q`/`f`/`d`) — the latter were
only correct on little-endian hosts by accident of `strrev()` undoing what a
machine-endian read would otherwise get wrong.

### Zero-Copy Chunk Parsing (No Full-Chunk Copies)

A Deliver frame used to be copied twice before a single message could be read
out of it:

1. `DeliverResponseV1::fromStreamBuffer()` copied the whole chunk out of the
   frame via `ReadBuffer::getRemainingBytes()`.
2. `OsirisChunkParser` then `substr()`-copied the declared data section out of
   that chunk again.

For a large chunk (chunks are received whole — see
[Frame Size Limits](#frame-size-limits) — and can run into several MB under a
fast producer), that is pure memory-bandwidth churn that produces nothing.

`ReadBuffer` now supports a zero-copy window: `new ReadBuffer($buffer, $offset,
$length)` shares the backing string instead of copying it (`getRemainingWindow()`
is the zero-copy counterpart of `getRemainingBytes()`, and `slice()` derives a
nested window). `DeliverResponseV1` stores the whole frame buffer plus a
chunk offset/length window instead of a copied chunk string —
`getChunkBytes()` still works exactly as before (it just materialises the
chunk lazily, only if something actually calls it), and two zero-copy
accessors were added: `getChunkView(): array{0: string, 1: int, 2: int}` and
`getChunkBuffer(): ReadBuffer`. `OsirisChunkParser::parse()`/`parseEntries()`/
`parseMessages()` gained optional `$offset`/`$length` parameters so they can
parse a chunk living inside a larger buffer without copying it out first.
`Consumer`'s deliver callback uses this end to end via `getChunkView()`, so a
chunk is parsed straight out of the socket-read frame buffer.

Measured on the `bench-batch` stream (200k × 1 KB messages, chunks up to
~6356 messages):

| Metric | Before | After |
|--------|--------|-------|
| `Consumer::read()` peak memory (whole 200k-message stream) | 42.30 MB | 33.10 MB |
| `Consumer::readOne()` peak memory (whole 200k-message stream) | 42.30 MB | 33.10 MB |
| `OsirisChunkParser::parse()` on a ~6.58 MB chunk | ~2.95 ms | ~2.16 ms |
| `OsirisChunkParser::parse()` on a ~2.12 MB chunk | ~0.95 ms | ~0.67 ms |

Per-message payload `substr()` inside `OsirisChunkParser` (extracting each
entry's own bytes, and a sub-batch's inner-record bytes) is unchanged — those
copies are proportional to real message data and are the `Message`'s own
payload, not chunk-wide churn.

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

### Producer Flow Control (`maxPendingConfirms`)

`Producer::send()`/`sendBatch()` do not read the socket by default beyond what
is needed for back-pressure, so a long run of `send()` calls without
`waitForConfirms()` can put an unbounded number of frames in flight. The
broker then coalesces them into large chunks, which can slow down consumers
and spike broker-side memory.

The `maxPendingConfirms` constructor parameter (default `10000`) caps how many
unconfirmed publishes are allowed before `send()`/`sendBatch()` transparently
drain confirms off the socket (blocking, like `waitForConfirms()`) until the
count drops back below the limit:

```php
$producer = new Producer(
    $streamConnection,
    'my-stream',
    publisherId: 1,
    maxPendingConfirms: 1000, // block send() once 1000 confirms are outstanding
);

for ($i = 0; $i < 200_000; $i++) {
    $producer->send("message-{$i}"); // self-throttles instead of unbounded fan-out
}
```

Pass `0` to restore the old unlimited/fire-and-forget behavior. Use
`getPendingConfirms(): int` to inspect the current outstanding count, e.g. for
metrics or custom throttling logic.

**Guidelines:**
- **Low (100-1,000):** Memory-constrained environments, slow consumers
- **Medium (10,000, default):** Good default for most workloads
- **`0` (unlimited):** Only when you fully control pacing yourself (e.g. calling `waitForConfirms()` periodically)

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

Protects against memory exhaustion from huge **incoming, non-Deliver** frames
(protocol responses, PublishConfirm/PublishError, MetadataUpdate, etc.):

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

### setMaxDeliverFrameSize() — Deliver frames need a separate, larger cap

The broker does **not** enforce the negotiated `frame_max` on Deliver frames
(key `0x0008`): a stream chunk is written to the wire whole, however big it
is. A fast producer publishing several batches back-to-back without calling
`waitForConfirms()` between them lets the broker's chunk writer coalesce them
into a single on-disk chunk — measured well over 1 MiB from a burst of 1 KB
messages, even though every individual `PublishRequest` frame stayed under the
negotiated `frame_max`. Because that chunk lives on the broker's disk, a
consumer that caps Deliver frames by the same (smaller) control-frame limit
dies with `ConnectionException` ("Frame size ... exceeds maximum allowed
...") on *every* restart, forever, at that same offset — a "poison chunk".

`StreamConnection` therefore tracks the Deliver frame cap separately from
`maxFrameSize`, defaulting to a much larger, but still bounded, ceiling:

```php
// Default: 64MB — comfortably above realistic coalesced chunk sizes, while
// still guarding against a hostile/broken broker sending an unbounded frame.
$connection->setMaxDeliverFrameSize(64 * 1024 * 1024);

// No limit (not recommended — only if you fully trust the broker)
$connection->setMaxDeliverFrameSize(0);
```

`Connection::create()` exposes this as a `maxDeliverFrameSize` parameter
(`null` = default):

```php
$connection = Connection::create(
    host: '127.0.0.1',
    maxDeliverFrameSize: 128 * 1024 * 1024, // raise further if you expect huge chunks
);
```

### Negotiated frame_max only ever lowers the default control-frame cap

`Connection::create()` negotiates `frame_max` with the broker during the Tune
step. If you don't pass `requestedFrameMax` yourself, a broker advertising an
unexpectedly huge value (including `0xFFFFFFFF`) cannot blow the incoming
control-frame cap open — the effective cap is
`min(negotiatedFrameMax, StreamConnection::DEFAULT_MAX_FRAME_SIZE)` in that
case. Passing `requestedFrameMax` explicitly is treated as a deliberate
choice and is honored as-is (even above the default).

### Outgoing frames fail fast instead of silently killing the connection

Writing a frame bigger than the negotiated `frame_max` used to be written to
the socket anyway, the broker would close the connection, and the failure
only surfaced later as a confusing "Cannot read: socket is not connected"\.
`sendFrame()` now validates the frame size against the negotiated outgoing
limit **before writing anything**, raising a clear
`CrazyGoat\RabbitStream\Exception\InvalidArgumentException` naming both the
frame size and the limit, with the connection left connected and usable:

```php
try {
    $producer->send($hugeMessage);
} catch (\CrazyGoat\RabbitStream\Exception\InvalidArgumentException $e) {
    // e.g. "Frame size 3132009 exceeds negotiated maximum frame size of 1048576"
    // the connection is still usable — no need to reconnect
}
```

`setOutgoingMaxFrameSize()` / `getOutgoingMaxFrameSize()` on `StreamConnection`
manage this limit directly (`0` = unlimited); `Connection::create()` sets it
from the negotiated `frame_max` automatically.

### Frame Size Error

If an incoming frame exceeds its cap (`maxFrameSize` for everything except
Deliver frames, `maxDeliverFrameSize` for Deliver frames):

```php
if ($cap > 0 && $size > $cap) {
    $this->close();
    throw new ConnectionException(
        "Frame size {$size} exceeds maximum allowed {$cap}"
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
