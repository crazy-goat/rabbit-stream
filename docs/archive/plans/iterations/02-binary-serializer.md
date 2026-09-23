# Iteration 2: `BinarySerializerInterface` + `PhpBinarySerializer`

> **For Claude:** REQUIRED SUB-SKILL: Use superpowers:executing-plans to implement this plan task-by-task.

**Goal:** Create the `BinarySerializerInterface` and a `PhpBinarySerializer` implementation that wraps the existing `WriteBuffer`/`ReadBuffer`/`ResponseBuilder` logic. Zero BC — this is a new layer that exists alongside the current code.

**Why:** This is the abstraction point where C++ FFI or PHP extension backends will plug in. The PHP implementation proves the interface works before we touch `StreamConnection`.

---

## New Interface

### `BinarySerializerInterface` (`src/Serializer/BinarySerializerInterface.php`)

```php
namespace CrazyGoat\RabbitStream\Serializer;

interface BinarySerializerInterface
{
    /**
     * Converts a Request object to a binary frame (without the 4-byte length prefix).
     * Uses Request::toArray() to get the data, then serializes to binary.
     */
    public function serialize(object $request): string;

    /**
     * Converts a binary frame to a Response object.
     * Deserializes binary to array, then uses Response::fromArray() to build the object.
     */
    public function deserialize(string $frame): object;
}
```

---

## `PhpBinarySerializer` (`src/Serializer/PhpBinarySerializer.php`)

The PHP implementation bridges the new `toArray()`/`fromArray()` interface with the existing `WriteBuffer`/`ReadBuffer` infrastructure.

**Two possible internal strategies:**

### Strategy A: Delegate to existing methods (recommended for iteration 2)
- `serialize()`: calls `$request->toStreamBuffer()->getContents()` (uses existing serialization)
- `deserialize()`: calls `ResponseBuilder::fromResponseBuffer(new ReadBuffer($frame))` (uses existing deserialization)

This means the `toArray()`/`fromArray()` path is NOT used yet by `PhpBinarySerializer`. The existing binary logic is reused as-is. This is the safest approach — zero risk of introducing bugs.

### Strategy B: Full array-based serialization (future, optional)
- `serialize()`: calls `$request->toArray()`, then manually builds binary from the array
- `deserialize()`: parses binary into array, then calls `Response::fromArray()`

This would be a full rewrite of the serialization logic and is NOT recommended for this iteration. It can be done later if needed, or skipped entirely (since C++ backends will implement their own binary logic anyway).

**Decision: Use Strategy A.** `PhpBinarySerializer` is a thin wrapper around existing code.

---

## Implementation

### Task 2.1: Create `BinarySerializerInterface`

```php
namespace CrazyGoat\RabbitStream\Serializer;

interface BinarySerializerInterface
{
    public function serialize(object $request): string;
    public function deserialize(string $frame): object;
}
```

### Task 2.2: Create `PhpBinarySerializer`

```php
namespace CrazyGoat\RabbitStream\Serializer;

use CrazyGoat\RabbitStream\Buffer\ReadBuffer;
use CrazyGoat\RabbitStream\Buffer\ToStreamBufferInterface;
use CrazyGoat\RabbitStream\ResponseBuilder;

class PhpBinarySerializer implements BinarySerializerInterface
{
    public function serialize(object $request): string
    {
        if (!$request instanceof ToStreamBufferInterface) {
            throw new \InvalidArgumentException(
                'Request must implement ToStreamBufferInterface'
            );
        }

        return $request->toStreamBuffer()->getContents();
    }

    public function deserialize(string $frame): object
    {
        return ResponseBuilder::fromResponseBuffer(new ReadBuffer($frame));
    }
}
```

### Task 2.3: Tests for `PhpBinarySerializer`

Test that `PhpBinarySerializer` produces identical output to calling `toStreamBuffer()` / `ResponseBuilder` directly:

1. **Serialize test:** For each Request class, verify that `$serializer->serialize($request)` returns the same bytes as `$request->toStreamBuffer()->getContents()`.

2. **Deserialize test:** For known binary frames (from existing Response tests), verify that `$serializer->deserialize($frame)` returns the same typed object as `ResponseBuilder::fromResponseBuffer()`.

3. **Roundtrip test:** Serialize a Request, then deserialize the response frame, verify the full cycle works through the serializer.

---

## What This Does NOT Do

- Does NOT modify `StreamConnection` — that happens in iteration 3
- Does NOT use `toArray()`/`fromArray()` internally — `PhpBinarySerializer` delegates to existing methods
- Does NOT break any existing tests or behavior
- Does NOT remove or modify `ResponseBuilder` or `WriteBuffer`/`ReadBuffer`

---

## File Structure After This Iteration

```
src/
├── Serializer/
│   ├── BinarySerializerInterface.php
│   └── PhpBinarySerializer.php
```
