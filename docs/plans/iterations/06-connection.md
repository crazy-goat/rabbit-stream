# Iteration 6: `Connection` Class

> **For Claude:** REQUIRED SUB-SKILL: Use superpowers:executing-plans to implement this plan task-by-task.

**Goal:** Create a new `Connection` class that serves as the high-level entry point. It wraps `StreamConnection`, performs the full handshake, and provides stream management methods. Zero BC — new class alongside existing `StreamClient`.

**Why:** `StreamClient` is limited (no stream management, no `sendMessage()` exposure, no graceful close). `Connection` replaces it as the recommended API.

---

## `Connection` (`src/Client/Connection.php`)

```php
namespace CrazyGoat\RabbitStream\Client;

use CrazyGoat\RabbitStream\Serializer\BinarySerializerInterface;
use CrazyGoat\RabbitStream\Serializer\PhpBinarySerializer;
use CrazyGoat\RabbitStream\StreamConnection;
use CrazyGoat\RabbitStream\VO\OffsetSpec;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

class Connection
{
    private int $publisherIdCounter = 0;
    private int $subscriptionIdCounter = 0;

    private function __construct(
        private readonly StreamConnection $streamConnection,
    ) {}

    static public function create(
        string $host = '127.0.0.1',
        int $port = 5552,
        string $user = 'guest',
        string $password = 'guest',
        string $vhost = '/',
        ?BinarySerializerInterface $serializer = null,
        ?LoggerInterface $logger = null,
    ): self;

    public function createProducer(
        string $stream,
        ?string $name = null,
        ?callable $onConfirm = null,
    ): Producer;

    public function createConsumer(
        string $stream,
        OffsetSpec $offset,
        ?string $name = null,
        int $autoCommit = 0,
        int $initialCredit = 10,
    ): Consumer;

    public function createStream(string $name, array $arguments = []): void;
    public function deleteStream(string $name): void;
    public function streamExists(string $name): bool;
    public function getStreamStats(string $name): array;
    public function getMetadata(array $streams): MetadataResponseV1;
    public function queryOffset(string $reference, string $stream): int;
    public function close(): void;
}
```

---

## Implementation Tasks

### Task 6.1: Create `Connection` class with `create()` factory

`create()` performs the full handshake sequence:

1. Create `StreamConnection($host, $port, $logger, $serializer)`
2. `$connection->connect()`
3. Send `PeerPropertiesRequestV1` with client properties
4. Read `PeerPropertiesResponseV1`
5. Send `SaslHandshakeRequestV1`
6. Read `SaslHandshakeResponseV1` — verify PLAIN mechanism is available
7. Send `SaslAuthenticateRequestV1('PLAIN', $user, $password)`
8. Read `SaslAuthenticateResponseV1`
9. Read `TuneRequestV1` (server's tune)
10. Send `TuneResponseV1` (echo back server's values)
11. Send `OpenRequest($vhost)`
12. Read `OpenResponseV1`
13. Return `new self($streamConnection)`

This is the same sequence as `StreamClient::connect()` — extract and reuse.

### Task 6.2: Implement `createStream()`

```php
public function createStream(string $name, array $arguments = []): void
{
    $this->streamConnection->sendMessage(new CreateRequestV1($name, $arguments));
    $this->streamConnection->readMessage(); // CreateResponseV1 — throws on error
}
```

### Task 6.3: Implement `deleteStream()`

```php
public function deleteStream(string $name): void
{
    $this->streamConnection->sendMessage(new DeleteStreamRequestV1($name));
    $this->streamConnection->readMessage(); // DeleteStreamResponseV1
}
```

### Task 6.4: Implement `streamExists()`

Uses metadata query — if the stream's response code is OK, it exists.

```php
public function streamExists(string $name): bool
{
    $this->streamConnection->sendMessage(new MetadataRequestV1([$name]));
    $response = $this->streamConnection->readMessage(); // MetadataResponseV1
    foreach ($response->getStreamMetadata() as $meta) {
        if ($meta->getStream() === $name) {
            return $meta->getResponseCode() === ResponseCodeEnum::OK->value;
        }
    }
    return false;
}
```

### Task 6.5: Implement `getStreamStats()`

```php
public function getStreamStats(string $name): array
{
    $this->streamConnection->sendMessage(new StreamStatsRequestV1($name));
    $response = $this->streamConnection->readMessage(); // StreamStatsResponseV1
    $result = [];
    foreach ($response->getStats() as $stat) {
        $result[$stat->getKey()] = $stat->getValue();
    }
    return $result;
}
```

### Task 6.6: Implement `getMetadata()`

```php
public function getMetadata(array $streams): MetadataResponseV1
{
    $this->streamConnection->sendMessage(new MetadataRequestV1($streams));
    return $this->streamConnection->readMessage();
}
```

### Task 6.7: Implement `queryOffset()`

```php
public function queryOffset(string $reference, string $stream): int
{
    $this->streamConnection->sendMessage(new QueryOffsetRequestV1($reference, $stream));
    $response = $this->streamConnection->readMessage(); // QueryOffsetResponseV1
    return $response->getOffset();
}
```

### Task 6.8: Implement `close()` with graceful shutdown

```php
public function close(): void
{
    try {
        $this->streamConnection->sendMessage(new CloseRequestV1(0, 'OK'));
        $this->streamConnection->readMessage(); // CloseResponseV1
    } finally {
        $this->streamConnection->close();
    }
}
```

### Task 6.9: Implement `createProducer()` (stub)

Returns a new `Producer` instance. Full `Producer` implementation is in iteration 7.

```php
public function createProducer(
    string $stream,
    ?string $name = null,
    ?callable $onConfirm = null,
): Producer {
    $publisherId = $this->publisherIdCounter++;
    return new Producer($this->streamConnection, $stream, $publisherId, $name, $onConfirm);
}
```

### Task 6.10: Implement `createConsumer()` (stub)

Returns a new `Consumer` instance. Full `Consumer` implementation is in iteration 8.

```php
public function createConsumer(
    string $stream,
    OffsetSpec $offset,
    ?string $name = null,
    int $autoCommit = 0,
    int $initialCredit = 10,
): Consumer {
    $subscriptionId = $this->subscriptionIdCounter++;
    return new Consumer($this->streamConnection, $stream, $subscriptionId, $offset, $name, $autoCommit, $initialCredit);
}
```

### Task 6.11: Deprecate `StreamClient`

Add `@deprecated Use Connection::create() instead` to `StreamClient` class docblock. Do NOT remove it.

### Task 6.12: Tests

1. **Unit test:** Mock `StreamConnection`, verify `createStream()` sends correct request and reads response.
2. **Unit test:** Mock `StreamConnection`, verify `close()` sends `CloseRequestV1` before closing socket.
3. **Unit test:** Verify `streamExists()` returns true/false based on metadata response code.
4. **E2E test:** `Connection::create()` → `createStream()` → `streamExists()` → `deleteStream()` → `close()`.

---

## BC Assessment

| Change | BC Impact |
|--------|-----------|
| New `Connection` class | None — additive |
| `@deprecated` on `StreamClient` | None — informational only |
| `StreamClient` unchanged | None |

---

## File Structure After This Iteration

```
src/
├── Client/
│   ├── Connection.php
│   ├── StreamClient.php  (deprecated, unchanged)
```
