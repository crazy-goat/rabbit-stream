# Iteration 8: `Consumer` Class

> **For Claude:** REQUIRED SUB-SKILL: Use superpowers:executing-plans to implement this plan task-by-task.

**Goal:** Create a pull-based `Consumer` class with `read()`, `readOne()`, `storeOffset()`, `queryOffset()`, auto-commit, and automatic credit flow. Zero BC — entirely new class.

**Why:** There is no consumer abstraction in the library. Users must manually subscribe, register callbacks, manage credits, parse chunks, and decode AMQP messages. The `Consumer` class wraps all of this into a simple pull API.

**Depends on:** Iteration 4 (OsirisChunkParser), Iteration 5 (AmqpMessageDecoder), Iteration 6 (Connection).

---

## `Consumer` (`src/Client/Consumer.php`)

```php
namespace CrazyGoat\RabbitStream\Client;

use CrazyGoat\RabbitStream\StreamConnection;
use CrazyGoat\RabbitStream\VO\OffsetSpec;

class Consumer
{
    /** @var Message[] */
    private array $buffer = [];
    private int $messagesProcessed = 0;
    private int $lastOffset = 0;

    public function __construct(
        private readonly StreamConnection $connection,
        private readonly string $stream,
        private readonly int $subscriptionId,
        private readonly OffsetSpec $offset,
        private readonly ?string $name = null,
        private readonly int $autoCommit = 0,
        private readonly int $initialCredit = 10,
    ) {
        $this->subscribe();
    }

    /** @return Message[] */
    public function read(int $timeout = 5): array;

    public function readOne(int $timeout = 5): ?Message;

    public function storeOffset(int $offset): void;

    public function queryOffset(): int;

    public function close(): void;
}
```

---

## Implementation Tasks

### Task 8.1: Implement `subscribe()` (private, called from constructor)

```php
private function subscribe(): void
{
    // Register deliver callback that stores chunks for pull retrieval
    $this->connection->registerSubscriber(
        $this->subscriptionId,
        function ($deliverResponse) {
            $entries = OsirisChunkParser::parse($deliverResponse->getChunkBytes());
            $messages = AmqpMessageDecoder::decodeAll($entries);
            $this->buffer = array_merge($this->buffer, $messages);

            // Send credit for next chunk
            $this->connection->sendMessage(
                new CreditRequestV1($this->subscriptionId, 1)
            );
        },
    );

    // Subscribe to the stream
    $this->connection->sendMessage(
        new SubscribeRequestV1(
            $this->subscriptionId,
            $this->stream,
            $this->offset,
            $this->initialCredit,
        )
    );
    $this->connection->readMessage(); // SubscribeResponseV1
}
```

### Task 8.2: Implement `read()`

Returns all buffered messages, or waits for new ones up to timeout. Returns empty array on timeout.

```php
/**
 * @return Message[]
 */
public function read(int $timeout = 5): array
{
    if (empty($this->buffer)) {
        // readMessage() will dispatch server-push frames (including Deliver),
        // which populates $this->buffer via the registered callback
        try {
            $this->connection->readMessage($timeout);
        } catch (\Exception $e) {
            // Timeout — return empty
            return [];
        }
    }

    $messages = $this->buffer;
    $this->buffer = [];

    if (!empty($messages)) {
        $lastMsg = end($messages);
        $this->lastOffset = $lastMsg->getOffset();
        $this->messagesProcessed += count($messages);
        $this->maybeAutoCommit();
    }

    return $messages;
}
```

### Task 8.3: Implement `readOne()`

Returns a single message from the buffer, or waits for new chunk if buffer is empty.

```php
public function readOne(int $timeout = 5): ?Message
{
    if (empty($this->buffer)) {
        try {
            $this->connection->readMessage($timeout);
        } catch (\Exception $e) {
            return null;
        }
    }

    if (empty($this->buffer)) {
        return null;
    }

    $message = array_shift($this->buffer);
    $this->lastOffset = $message->getOffset();
    $this->messagesProcessed++;
    $this->maybeAutoCommit();

    return $message;
}
```

### Task 8.4: Implement `storeOffset()`

```php
public function storeOffset(int $offset): void
{
    if ($this->name === null) {
        throw new \RuntimeException('Cannot store offset for unnamed consumer');
    }
    $this->connection->sendMessage(
        new StoreOffsetRequestV1($this->name, $this->stream, $offset)
    );
    // StoreOffset is fire-and-forget (no response)
}
```

### Task 8.5: Implement `queryOffset()`

```php
public function queryOffset(): int
{
    if ($this->name === null) {
        throw new \RuntimeException('Cannot query offset for unnamed consumer');
    }
    $this->connection->sendMessage(
        new QueryOffsetRequestV1($this->name, $this->stream)
    );
    $response = $this->connection->readMessage(); // QueryOffsetResponseV1
    return $response->getOffset();
}
```

### Task 8.6: Implement auto-commit

```php
private function maybeAutoCommit(): void
{
    if ($this->autoCommit <= 0 || $this->name === null) {
        return;
    }
    if ($this->messagesProcessed >= $this->autoCommit) {
        $this->storeOffset($this->lastOffset);
        $this->messagesProcessed = 0;
    }
}
```

### Task 8.7: Implement `close()`

```php
public function close(): void
{
    // Store final offset if auto-commit is enabled
    if ($this->autoCommit > 0 && $this->name !== null && $this->messagesProcessed > 0) {
        $this->storeOffset($this->lastOffset);
    }

    $this->connection->sendMessage(
        new UnsubscribeRequestV1($this->subscriptionId)
    );
    $this->connection->readMessage(); // UnsubscribeResponseV1
}
```

### Task 8.8: Tests

1. **Unit test:** `read()` returns empty array on timeout
2. **Unit test:** `read()` returns messages when buffer is populated
3. **Unit test:** `readOne()` returns single message and keeps rest in buffer
4. **Unit test:** `readOne()` returns null on timeout
5. **Unit test:** `storeOffset()` sends correct `StoreOffsetRequestV1`
6. **Unit test:** `storeOffset()` throws for unnamed consumer
7. **Unit test:** `queryOffset()` sends request and returns offset
8. **Unit test:** Auto-commit triggers `storeOffset()` after N messages
9. **Unit test:** `close()` stores final offset if auto-commit enabled
10. **Unit test:** `close()` sends `UnsubscribeRequestV1`
11. **E2E test:** Full cycle — produce messages, then consume with `read()`, verify data matches

---

## Credit Flow Design

The consumer uses automatic credit management:

1. **Initial credit:** `$initialCredit` (default 10) is sent with the `SubscribeRequestV1`. This tells the server "you can send me up to 10 chunks".
2. **Replenish:** After each chunk is received and parsed (in the deliver callback), 1 credit is sent back. This maintains a steady flow.
3. **Backpressure:** If the user doesn't call `read()` or `readOne()`, messages accumulate in `$this->buffer`. The deliver callback still sends credits, so the buffer can grow. This is acceptable for the initial implementation.

Future improvement: Only send credit when the user actually reads from the buffer (true backpressure). This would require moving the credit send from the callback to `read()`/`readOne()`.

---

## Edge Cases

- **Multiple chunks before read:** If multiple Deliver frames arrive before the user calls `read()`, they all get buffered. `read()` returns all of them at once.
- **readMessage() returns non-push frame:** If `readMessage()` returns a response to a different request (e.g., a concurrent `queryOffset()`), it should be handled. For now, this is not expected — the consumer should be the only thing reading from the connection.
- **Empty chunk:** A chunk with 0 entries should return an empty array from `read()`.

---

## File Structure After This Iteration

```
src/
├── Client/
│   ├── Consumer.php
tests/
├── Client/
│   └── ConsumerTest.php
```
