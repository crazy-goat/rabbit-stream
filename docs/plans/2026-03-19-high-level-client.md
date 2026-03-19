# High-Level Client API — Master Plan

**Goal:** Build a high-level layer on top of existing protocol classes (Request/Response/StreamConnection), providing a simple API: `Connection`, `Producer`, `Consumer`, `Message`.

**Model:** Pull-based consumer (inspired by the Rust client), user controls the loop.

**Architecture:** Single abstraction layer at the `array ↔ binary` conversion boundary:

- **Request/Response** always communicate via PHP arrays (`toArray()` / `fromArray()`)
- **`BinarySerializerInterface`** converts `array ↔ binary` — swappable backend
- **`StreamConnection`** manages TCP transport and protocol logic

C++ backends handle **only** fast `array ↔ binary` conversion. They don't touch sockets or create PHP objects.

---

## Flow

```
SERIALIZE (send):

  Request::toArray() → array
    → BinarySerializerInterface::serialize(int $key, int $version, array $data) → binary
        ├─ PhpBinarySerializer (default, WriteBuffer)
        ├─ FfiBinarySerializer (future, C++ FFI)
        └─ ExtBinarySerializer (future, PHP ext)
    → socket_write(binary)

DESERIALIZE (read):

  socket_read() → binary
    → BinarySerializerInterface::deserialize(string $frame) → array
        ├─ PhpBinarySerializer (default, ReadBuffer)
        ├─ FfiBinarySerializer (future, C++ FFI)
        └─ ExtBinarySerializer (future, PHP ext)
    → Response::fromArray(array) → typed object
```

Single code path, no conditionals. Request/Response classes are unaware of the underlying backend.

---

## Serialization Abstraction

### `BinarySerializerInterface` (`src/Serializer/BinarySerializerInterface.php`)

Interface for `array ↔ binary` conversion.

**Methods:**
- `serialize(int $key, int $version, array $data): string` — PHP array → binary frame
- `deserialize(string $frame): array` — binary frame → PHP array

### Implementations:
- `PhpBinarySerializer implements BinarySerializerInterface` — current logic (WriteBuffer/ReadBuffer)
- `FfiBinarySerializer implements BinarySerializerInterface` — C++ FFI (future)
- `ExtBinarySerializer implements BinarySerializerInterface` — PHP extension (future)

---

## Request/Response Changes

### Request — add `toArray(): array`
Every Request class implements `toArray()` returning its data as a PHP array. `toStreamBuffer()` remains for backward compatibility; `PhpBinarySerializer` may use it internally.

### Response — add `static fromArray(array $data): self`
Every Response class implements `fromArray()` constructing an object from a PHP array. `fromStreamBuffer()` remains for backward compatibility; `PhpBinarySerializer` may use it internally.

---

## New Classes

### `Connection` (`src/Client/Connection.php`)

Entry point. Wraps `StreamConnection` + handshake + stream management.

**Methods:**
- `static create(host, port, user, password, vhost, ?BinarySerializerInterface): self` — connect + full handshake (PeerProperties, SaslHandshake, SaslAuthenticate, Tune, Open). Defaults to `PhpBinarySerializer`, but FFI/ext can be injected.
- `createProducer(stream, ?name, ?onConfirm): Producer`
- `createConsumer(stream, offset, ?name, ?autoCommit): Consumer`
- `createStream(name, arguments): void`
- `deleteStream(name): void`
- `streamExists(name): bool`
- `getStreamStats(name): array`
- `getMetadata(streams): MetadataResponseV1`
- `queryOffset(name, stream): int`
- `close(): void` — sends `CloseRequestV1`, then closes socket

### `Producer` (`src/Client/Producer.php`)

New producer, created via `Connection::createProducer()`.

**Methods:**
- `send(string $message): void` — publishes a single message, auto-increments publishingId
- `sendBatch(array $messages): void` — publishes multiple messages in a single frame
- `waitForConfirms(timeout): void` — blocks until all confirms are received
- `getLastPublishingId(): int`
- `querySequence(): int` — queries the server for the last publishingId for this producer
- `close(): void` — DeletePublisher + cleanup

**Constructor internally:**
- DeclarePublisher
- Registers confirm/error callbacks on StreamConnection

### `Consumer` (`src/Client/Consumer.php`)

Pull-based consumer, created via `Connection::createConsumer()`.

**Methods:**
- `read(timeout): Message[]` — reads one chunk, parses it, returns array of messages (empty on timeout)
- `readOne(timeout): ?Message` — returns a single message (buffers remaining chunk internally)
- `storeOffset(int $offset): void` — stores offset on the server
- `queryOffset(): int` — queries the server for the stored offset
- `close(): void` — Unsubscribe + cleanup

**Constructor internally:**
- Subscribe with given OffsetSpec and initial credit
- Registers deliver callback on StreamConnection

**Auto-commit:**
- `autoCommit: 0` (default) — disabled, user manually calls `storeOffset()`
- `autoCommit: N` — automatic `storeOffset()` every N messages

**Credit flow:**
- Automatic — sends new credit after processing a chunk

### `Message` (`src/Client/Message.php`)

Message read from a chunk.

**Methods:**
- `getOffset(): int`
- `getData(): string` — raw body (bytes)
- `getProperties(): array` — AMQP 1.0 properties (after decoder implementation)
- `getApplicationProperties(): array` — AMQP 1.0 application-properties
- `getTimestamp(): ?int` — timestamp from chunk entry

### `OsirisChunkParser` (`src/Client/OsirisChunkParser.php`)

Parses raw bytes from `DeliverResponseV1::getChunkBytes()` into an array of `Message`.

**Methods:**
- `static parse(string $chunkBytes): Message[]`

### `AmqpMessageDecoder` (`src/Client/AmqpMessageDecoder.php`)

Decodes an AMQP 1.0 encoded message into a `Message` object.

**Methods:**
- `static decode(string $data): Message`

---

## Implementation Iterations

Each iteration is a self-contained step with zero or minimal BC. Detailed plans in `docs/plans/iterations/`.

| # | Iteration | BC | Plan |
|---|-----------|-----|------|
| 1 | `toArray()` / `fromArray()` on all Request/Response | Zero | [01-toarray-fromarray.md](iterations/01-toarray-fromarray.md) |
| 2 | `BinarySerializerInterface` + `PhpBinarySerializer` | Zero | [02-binary-serializer.md](iterations/02-binary-serializer.md) |
| 3 | `StreamConnection` uses `BinarySerializerInterface` | Zero | [03-streamconnection-uses-serializer.md](iterations/03-streamconnection-uses-serializer.md) |
| 4 | `OsirisChunkParser` | Zero | [04-osiris-chunk-parser.md](iterations/04-osiris-chunk-parser.md) |
| 5 | `AmqpMessageDecoder` + `Message` | Zero | [05-amqp-message-decoder.md](iterations/05-amqp-message-decoder.md) |
| 6 | `Connection` class | Zero | [06-connection.md](iterations/06-connection.md) |
| 7 | New `Producer` (replace in-place) | Zero | [07-producer.md](iterations/07-producer.md) |
| 8 | `Consumer` class | Zero | [08-consumer.md](iterations/08-consumer.md) |
| 9 | Examples, docs, deprecation | Zero | [09-examples-and-deprecation.md](iterations/09-examples-and-deprecation.md) |
| 10 | Cleanup (major version) | **Breaking** | [10-cleanup.md](iterations/10-cleanup.md) |

### Dependency Graph

```
1. toArray/fromArray ─────────┐
2. BinarySerializerInterface ─┤
3. StreamConnection refactor ─┤
4. OsirisChunkParser ─────────┤
5. AmqpMessageDecoder ────────┤
                              ├─► 6. Connection
                              ├─► 7. Producer
                              ├─► 8. Consumer (requires 4, 5)
                              └─► 9. Examples (requires 6, 7, 8)
                                  └─► 10. Cleanup (optional, major version)
```

Iterations 1-3 are sequential (each builds on the previous). Iterations 4-5 can run in parallel with 1-3. Iteration 6 (Connection) can start after 3. Producer (7) needs 6. Consumer (8) needs 4, 5, 6.

### Future (not in this plan)

| # | Iteration | Plan |
|---|-----------|------|
| 11 | `FfiBinarySerializer` — C++ FFI backend | TBD |
| 12 | `ExtBinarySerializer` — PHP extension backend | TBD |

---

## Target Usage

```php
// Pure PHP (default)
$connection = Connection::create(host: 'localhost', port: 5552, user: 'guest', password: 'guest');

// C++ FFI backend (future)
// $connection = Connection::create(host: 'localhost', port: 5552, user: 'guest', password: 'guest',
//     serializer: new FfiBinarySerializer(),
// );

// Stream management
$connection->createStream('my-stream', ['max-length-bytes' => 1_000_000_000]);

// Producer
$producer = $connection->createProducer('my-stream', name: 'my-producer',
    onConfirm: fn(ConfirmationStatus $s) => $s->isConfirmed() ? null : throw new \Exception('fail'),
);
$producer->send('hello world');
$producer->waitForConfirms(timeout: 5);
$producer->close();

// Consumer (pull-based)
$consumer = $connection->createConsumer('my-stream', offset: OffsetSpec::first(), name: 'my-consumer');
while ($messages = $consumer->read(timeout: 5)) {
    foreach ($messages as $msg) {
        echo "offset={$msg->getOffset()} data={$msg->getData()}\n";
    }
}
$consumer->storeOffset($msg->getOffset());
$consumer->close();

$connection->close();
```
