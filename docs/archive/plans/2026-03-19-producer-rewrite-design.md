# Design: Producer Rewrite (Issue #53)

**Date:** 2026-03-19  
**Issue:** #53  
**Goal:** Rewrite `Producer` with `sendBatch()`, `waitForConfirms()`, and `querySequence()` — zero BC.

---

## Overview

Rewrite the existing `Producer` class in-place to add batch sending, confirm waiting, and sequence querying capabilities. The existing public API (`send(string)` and `close()`) remains unchanged.

---

## Architecture

### Changes to `Producer` (`src/Client/Producer.php`)

```php
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

    // Existing methods (unchanged signatures):
    public function send(string $message): void;
    public function close(): void;

    // New methods:
    public function sendBatch(array $messages): void;
    public function waitForConfirms(int $timeout = 5): void;
    public function getLastPublishingId(): ?int;
    public function querySequence(): int;
}
```

### Changes to `ProducerConfig`

Add `@deprecated` annotation directing users to use `Connection::createProducer()` parameters instead.

### Changes to `Connection::createProducer()`

Internal update to use new `Producer` constructor while maintaining backward-compatible public signature.

---

## Data Flow

### `sendBatch()`

1. Iterate through `$messages` array
2. Create `PublishedMessage` for each with auto-incremented `publishingId`
3. Increment `$pendingConfirms` counter for each message
4. Send single `PublishRequestV1` with all messages

### `waitForConfirms()`

1. Calculate deadline: `time() + $timeout`
2. Loop while `$pendingConfirms > 0` and `time() < deadline`:
   - Call `$connection->readLoop(timeout: $remaining)`
   - `readLoop()` dispatches server-push frames (PublishConfirm/PublishError) via registered callbacks
   - Registered callbacks decrement `$pendingConfirms`
3. If `$pendingConfirms > 0` after timeout: throw `\RuntimeException`

### `querySequence()`

1. Validate `$name !== null` (required for named producers)
2. Send `QueryPublisherSequenceRequestV1`
3. Read `QueryPublisherSequenceResponseV1`
4. Return `$response->getSequence()`

---

## Error Handling

- `waitForConfirms()` throws `\RuntimeException` on timeout
- `querySequence()` throws `\RuntimeException` for unnamed producers
- All other errors propagate from underlying `StreamConnection`

---

## Backward Compatibility

| Change | Impact |
|--------|--------|
| `Producer` constructor signature | None — constructor is internal (called by `StreamClient`/`Connection`) |
| `send(string): void` | None — same signature |
| `close(): void` | None — same signature |
| New methods | None — additive only |
| `ProducerConfig` deprecated | None — informational only |
| `StreamClient::createProducer()` | None — same public signature |

---

## Testing Strategy

### Unit Tests

1. `send()` creates correct `PublishRequestV1` with auto-incremented `publishingId`
2. `sendBatch()` creates single `PublishRequestV1` with multiple messages
3. `waitForConfirms()` blocks and resolves when confirms arrive
4. `waitForConfirms()` throws on timeout
5. `querySequence()` throws for unnamed producer
6. `close()` sends `DeletePublisherRequestV1`

### E2E Tests

1. `Connection::create()` → `createProducer()` → `send()` → `waitForConfirms()` → `close()`
2. `sendBatch()` with multiple messages and confirm waiting
3. `querySequence()` for named producer

---

## Dependencies

- Issue #52 (Connection class) — **CLOSED** ✓
- Existing `StreamConnection::registerPublisher()` API
- Existing `PublishRequestV1` with variadic `PublishedMessage` support
