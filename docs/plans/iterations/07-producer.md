# Iteration 7: New `Producer` Class

> **For Claude:** REQUIRED SUB-SKILL: Use superpowers:executing-plans to implement this plan task-by-task.

**Goal:** Create a new `Producer` class with `send()`, `sendBatch()`, `waitForConfirms()`, and `querySequence()`. The old `Producer` and `ProducerConfig` are deprecated. Minimal BC.

**Why:** The existing `Producer` works but lacks batch sending, confirm waiting, and sequence querying. The new `Producer` has a cleaner API aligned with the `Connection` class.

---

## Namespace Conflict Resolution

The existing `Producer` is at `CrazyGoat\RabbitStream\Client\Producer`. The new one needs the same name. Options:

1. **Rename old to `LegacyProducer`** — BC break for anyone importing the class
2. **Move old to `Client\Legacy\Producer`** — BC break for anyone importing the class
3. **Replace in-place** — keep the same class name, rewrite internals, keep `send(string)` signature compatible

**Decision: Option 3 — replace in-place.** The existing `Producer` has a very small public API (`send(string)`, `close()`). We keep those signatures and add new methods. The constructor changes (it's called from `StreamClient`/`Connection`, not by users directly), so that's not a BC concern.

Changes to existing `Producer`:
- Constructor signature changes (internal, not user-facing)
- `send(string $message): void` — same signature, same behavior
- `close(): void` — same signature, same behavior
- New: `sendBatch(array $messages): void`
- New: `waitForConfirms(int $timeout = 5): void`
- New: `getLastPublishingId(): int`
- New: `querySequence(): int`

`ProducerConfig` becomes deprecated — its fields move to `Connection::createProducer()` parameters.

---

## `Producer` (`src/Client/Producer.php`) — Rewritten

```php
namespace CrazyGoat\RabbitStream\Client;

use CrazyGoat\RabbitStream\StreamConnection;
use CrazyGoat\RabbitStream\Request\DeclarePublisherRequestV1;
use CrazyGoat\RabbitStream\Request\DeletePublisherRequestV1;
use CrazyGoat\RabbitStream\Request\PublishRequestV1;
use CrazyGoat\RabbitStream\Request\QueryPublisherSequenceRequestV1;
use CrazyGoat\RabbitStream\VO\PublishedMessage;

class Producer
{
    private int $publishingId = 0;
    private int $pendingConfirms = 0;

    public function __construct(
        private readonly StreamConnection $connection,
        private readonly string $stream,
        private readonly int $publisherId,
        private readonly ?string $name = null,
        private readonly ?callable $onConfirm = null,
    ) {
        $this->declare();
    }

    public function send(string $message): void;
    public function sendBatch(array $messages): void;
    public function waitForConfirms(int $timeout = 5): void;
    public function getLastPublishingId(): int;
    public function querySequence(): int;
    public function close(): void;
}
```

---

## Implementation Tasks

### Task 7.1: Rewrite `Producer` constructor and `declare()`

```php
private function declare(): void
{
    $this->connection->registerPublisher(
        $this->publisherId,
        onConfirm: function (array $publishingIds) {
            $this->pendingConfirms -= count($publishingIds);
            if ($this->onConfirm !== null) {
                foreach ($publishingIds as $id) {
                    ($this->onConfirm)(new ConfirmationStatus(true, publishingId: $id));
                }
            }
        },
        onError: function (array $errors) {
            $this->pendingConfirms -= count($errors);
            if ($this->onConfirm !== null) {
                foreach ($errors as $error) {
                    ($this->onConfirm)(new ConfirmationStatus(
                        false,
                        errorCode: $error->getCode(),
                        publishingId: $error->getPublishingId(),
                    ));
                }
            }
        },
    );

    $this->connection->sendMessage(
        new DeclarePublisherRequestV1($this->publisherId, $this->name, $this->stream)
    );
    $this->connection->readMessage(); // DeclarePublisherResponseV1
}
```

### Task 7.2: Implement `send()`

```php
public function send(string $message): void
{
    $this->pendingConfirms++;
    $this->connection->sendMessage(new PublishRequestV1(
        $this->publisherId,
        new PublishedMessage($this->publishingId++, $message),
    ));
}
```

Same signature as existing `Producer::send()` — zero BC.

### Task 7.3: Implement `sendBatch()`

```php
/**
 * @param string[] $messages
 */
public function sendBatch(array $messages): void
{
    $published = [];
    foreach ($messages as $message) {
        $published[] = new PublishedMessage($this->publishingId++, $message);
        $this->pendingConfirms++;
    }
    $this->connection->sendMessage(new PublishRequestV1($this->publisherId, ...$published));
}
```

### Task 7.4: Implement `waitForConfirms()`

Blocks until all pending confirms are received or timeout expires.

```php
public function waitForConfirms(int $timeout = 5): void
{
    $deadline = time() + $timeout;
    while ($this->pendingConfirms > 0 && time() < $deadline) {
        $remaining = $deadline - time();
        if ($remaining <= 0) {
            break;
        }
        $this->connection->readMessage((int) $remaining);
    }
    if ($this->pendingConfirms > 0) {
        throw new \RuntimeException(
            "Timed out waiting for {$this->pendingConfirms} publish confirms"
        );
    }
}
```

Note: `readMessage()` will dispatch server-push frames (PublishConfirm/PublishError) internally, which decrements `$pendingConfirms` via the registered callbacks.

### Task 7.5: Implement `getLastPublishingId()`

```php
public function getLastPublishingId(): int
{
    return $this->publishingId - 1;
}
```

### Task 7.6: Implement `querySequence()`

```php
public function querySequence(): int
{
    if ($this->name === null) {
        throw new \RuntimeException('Cannot query sequence for unnamed producer');
    }
    $this->connection->sendMessage(
        new QueryPublisherSequenceRequestV1($this->name, $this->stream)
    );
    $response = $this->connection->readMessage(); // QueryPublisherSequenceResponseV1
    return $response->getSequence();
}
```

### Task 7.7: Implement `close()`

```php
public function close(): void
{
    $this->connection->sendMessage(new DeletePublisherRequestV1($this->publisherId));
    $this->connection->readMessage(); // DeletePublisherResponseV1
}
```

Same as existing — zero BC.

### Task 7.8: Deprecate `ProducerConfig`

Add `@deprecated Use Connection::createProducer() parameters instead` to `ProducerConfig`.

### Task 7.9: Update `StreamClient::createProducer()` for backward compatibility

`StreamClient::createProducer()` currently creates `Producer` with the old constructor. Update it to use the new constructor signature:

```php
public function createProducer(string $stream, ?ProducerConfig $config = null): Producer
{
    $config = $config ?? new ProducerConfig();
    return new Producer(
        $this->connection,
        $stream,
        $this->publisherIdCounter++,
        $config->name,
        $config->onConfirmation,
    );
}
```

### Task 7.10: Tests

1. **Unit test:** `send()` creates correct `PublishRequestV1` with auto-incremented publishingId
2. **Unit test:** `sendBatch()` creates single `PublishRequestV1` with multiple messages
3. **Unit test:** `waitForConfirms()` blocks and resolves when confirms arrive
4. **Unit test:** `waitForConfirms()` throws on timeout
5. **Unit test:** `querySequence()` throws for unnamed producer
6. **Unit test:** `close()` sends `DeletePublisherRequestV1`
7. **E2E test:** `Connection::create()` → `createProducer()` → `send()` → `waitForConfirms()` → `close()`

---

## BC Assessment

| Change | BC Impact |
|--------|-----------|
| `Producer` constructor signature | None — constructor is internal (called by `StreamClient`/`Connection`) |
| `send(string): void` | None — same signature |
| `close(): void` | None — same signature |
| New methods (`sendBatch`, `waitForConfirms`, etc.) | None — additive |
| `ProducerConfig` deprecated | None — informational |
| `StreamClient::createProducer()` updated | None — same public signature, same behavior |
