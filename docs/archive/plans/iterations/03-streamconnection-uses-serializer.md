# Iteration 3: `StreamConnection` Uses `BinarySerializerInterface`

> **For Claude:** REQUIRED SUB-SKILL: Use superpowers:executing-plans to implement this plan task-by-task.

**Goal:** Refactor `StreamConnection` to use `BinarySerializerInterface` for encode/decode instead of directly calling `toStreamBuffer()` and `ResponseBuilder`. Minimal BC — constructor gets a new optional parameter with a default value.

**Why:** After this, swapping the serialization backend is a one-line change: pass a different `BinarySerializerInterface` to `StreamConnection`.

---

## Changes to `StreamConnection`

### Task 3.1: Add `BinarySerializerInterface` to constructor

**Before:**
```php
public function __construct(
    private string $host = '172.17.0.2',
    private int $port = 5552,
    private LoggerInterface $logger = new NullLogger(),
) {}
```

**After:**
```php
public function __construct(
    private string $host = '172.17.0.2',
    private int $port = 5552,
    private LoggerInterface $logger = new NullLogger(),
    private BinarySerializerInterface $serializer = new PhpBinarySerializer(),
) {}
```

**BC impact:** None. Existing code that creates `StreamConnection` without the 4th argument gets `PhpBinarySerializer` by default.

### Task 3.2: Refactor `sendMessage()` to use serializer

**Before:**
```php
public function sendMessage(object $request): void
{
    $this->correlationId++;
    if ($request instanceof CorrelationInterface) {
        $request->withCorrelationId($this->correlationId);
    }
    if (!$request instanceof ToStreamBufferInterface) {
        throw new \Exception("Request must implement ToStreamBufferInterface");
    }
    $content = $request->toStreamBuffer()->getContents();
    $frame = (new WriteBuffer())
        ->addUInt32(strlen($content))
        ->addRaw($content)
        ->getContents();
    $this->sendFrame($frame);
}
```

**After:**
```php
public function sendMessage(object $request): void
{
    $this->correlationId++;
    if ($request instanceof CorrelationInterface) {
        $request->withCorrelationId($this->correlationId);
    }
    $content = $this->serializer->serialize($request);
    $frame = (new WriteBuffer())
        ->addUInt32(strlen($content))
        ->addRaw($content)
        ->getContents();
    $this->sendFrame($frame);
}
```

**BC impact:** None. `PhpBinarySerializer::serialize()` internally calls `toStreamBuffer()->getContents()` — same behavior.

### Task 3.3: Refactor `readMessage()` to use serializer

**Before:**
```php
public function readMessage(int $timeout = 30): object
{
    while (true) {
        $frame = $this->readFrame($timeout);
        if ($frame === null) {
            throw new \Exception("Read timeout");
        }
        $key = $frame->peekUint16();
        if (in_array($key, self::SERVER_PUSH_KEYS, true)) {
            $this->dispatchServerPush($frame);
            continue;
        }
        return ResponseBuilder::fromResponseBuffer($frame);
    }
}
```

**After:**
```php
public function readMessage(int $timeout = 30): object
{
    while (true) {
        $frame = $this->readFrame($timeout);
        if ($frame === null) {
            throw new \Exception("Read timeout");
        }
        $key = $frame->peekUint16();
        if (in_array($key, self::SERVER_PUSH_KEYS, true)) {
            $this->dispatchServerPush($frame);
            continue;
        }
        return $this->serializer->deserialize($frame->getRemainingBytes());
    }
}
```

**Note:** `readFrame()` returns a `ReadBuffer`. We need to pass raw bytes to `deserialize()`. Check if `readFrame()` returns the full frame or just the payload. If it returns a `ReadBuffer` with position already at 0, we use `$frame->getRemainingBytes()` or similar. Alternatively, `PhpBinarySerializer::deserialize()` could accept `ReadBuffer` — but the interface should stay `string` for FFI/ext compatibility.

**Alternative:** Keep `readMessage()` using `ReadBuffer` internally and only go through the serializer for the final object construction. The `dispatchServerPush()` method also uses `ResponseBuilder` internally — it needs the same treatment.

**Revised approach:** Since `dispatchServerPush()` also needs deserialization, and `readFrame()` returns `ReadBuffer`, the cleanest approach is:

```php
public function readMessage(int $timeout = 30): object
{
    while (true) {
        $rawFrame = $this->readRawFrame($timeout);
        if ($rawFrame === null) {
            throw new \Exception("Read timeout");
        }
        $key = unpack('n', $rawFrame)[1];
        if (in_array($key, self::SERVER_PUSH_KEYS, true)) {
            $this->dispatchServerPush($this->serializer->deserialize($rawFrame));
            continue;
        }
        return $this->serializer->deserialize($rawFrame);
    }
}
```

But this changes `dispatchServerPush()` to accept objects instead of `ReadBuffer`. This is a bigger refactor. 

**Simplest approach for minimal BC:** Only change the non-push path. `dispatchServerPush()` continues using `ResponseBuilder` directly for now. We can unify later.

```php
public function readMessage(int $timeout = 30): object
{
    while (true) {
        $frame = $this->readFrame($timeout);
        if ($frame === null) {
            throw new \Exception("Read timeout");
        }
        $key = $frame->peekUint16();
        if (in_array($key, self::SERVER_PUSH_KEYS, true)) {
            $this->dispatchServerPush($frame);
            continue;
        }
        $frame->rewind();
        return $this->serializer->deserialize($frame->getRemainingBytes());
    }
}
```

### Task 3.4: Verify `ReadBuffer` state

Check if `peekUint16()` advances the position or not. If it does NOT advance (peek), then we don't need `rewind()`. If it does, we need to rewind before passing to the serializer.

Looking at the existing code: `peekUint16()` does NOT advance the position (it's a peek). And `ResponseBuilder::fromResponseBuffer()` expects the buffer at position 0 (it reads key and version first). So the current code works because `peekUint16()` doesn't move the cursor.

For the serializer path: `$frame->getRemainingBytes()` should return the full frame from position 0 if peek didn't advance. Verify this.

### Task 3.5: Run existing tests

All existing tests must pass without modification. The refactor is purely internal — `PhpBinarySerializer` delegates to the same code that was called directly before.

---

## What This Does NOT Do

- Does NOT change `dispatchServerPush()` — it still uses `ResponseBuilder` directly (can be unified later)
- Does NOT change the public API of `StreamConnection`
- Does NOT break existing `StreamClient` or `Producer` code
- Does NOT require any changes to Request/Response classes

---

## BC Assessment

| Change | BC Impact |
|--------|-----------|
| New constructor parameter with default | None — existing callers unaffected |
| `sendMessage()` uses serializer | None — same behavior via `PhpBinarySerializer` |
| `readMessage()` uses serializer | None — same behavior via `PhpBinarySerializer` |
| `dispatchServerPush()` unchanged | None |
