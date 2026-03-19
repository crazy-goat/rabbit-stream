# Iteration 1: Add `toArray()` / `fromArray()` to Request/Response Classes

> **For Claude:** REQUIRED SUB-SKILL: Use superpowers:executing-plans to implement this plan task-by-task.

**Goal:** Every Request class gets a `toArray(): array` method. Every Response class gets a `static fromArray(array $data): static` method. Zero BC — existing `toStreamBuffer()` and `fromStreamBuffer()` remain untouched.

**Why:** This is the foundation for swappable serialization backends. Once Request/Response speak in arrays, we can plug in C++ FFI or PHP extension backends that convert `array ↔ binary` without touching protocol logic.

---

## New Interfaces

### `ToArrayInterface` (`src/Buffer/ToArrayInterface.php`)

```php
namespace CrazyGoat\RabbitStream\Buffer;

interface ToArrayInterface
{
    public function toArray(): array;
}
```

### `FromArrayInterface` (`src/Buffer/FromArrayInterface.php`)

```php
namespace CrazyGoat\RabbitStream\Buffer;

interface FromArrayInterface
{
    public static function fromArray(array $data): static;
}
```

---

## Request Classes — `toArray()` Specification

Each `toArray()` returns an associative array with the same fields that `toStreamBuffer()` serializes, but as PHP values instead of binary. The array does NOT include `key`, `version`, or `correlationId` — those are handled by the serializer layer.

### Task 1.1: Simple single-field Requests

| Class | `toArray()` returns |
|-------|-------------------|
| `SaslHandshakeRequestV1` | `[]` (no fields) |
| `OpenRequest` | `['vhost' => string]` |
| `DeleteStreamRequestV1` | `['stream' => string]` |
| `DeletePublisherRequestV1` | `['publisherId' => int]` |
| `UnsubscribeRequestV1` | `['subscriptionId' => int]` |
| `StreamStatsRequestV1` | `['stream' => string]` |
| `PartitionsRequestV1` | `['superStream' => string]` |
| `DeleteSuperStreamRequestV1` | `['name' => string]` |
| `HeartbeatRequestV1` | `[]` (no fields) |

### Task 1.2: Multi-field Requests

| Class | `toArray()` returns |
|-------|-------------------|
| `SaslAuthenticateRequestV1` | `['mechanism' => string, 'username' => string, 'password' => string]` |
| `TuneRequestV1` | `['frameMax' => int, 'heartbeat' => int]` |
| `CloseRequestV1` | `['closingCode' => int, 'closingReason' => string]` |
| `DeclarePublisherRequestV1` | `['publisherId' => int, 'publisherReference' => ?string, 'stream' => string]` |
| `CreditRequestV1` | `['subscriptionId' => int, 'credit' => int]` |
| `StoreOffsetRequestV1` | `['reference' => string, 'stream' => string, 'offset' => int]` |
| `QueryOffsetRequestV1` | `['reference' => string, 'stream' => string]` |
| `QueryPublisherSequenceRequestV1` | `['reference' => string, 'stream' => string]` |
| `RouteRequestV1` | `['routingKey' => string, 'superStream' => string]` |
| `ResolveOffsetSpecRequestV1` | `['stream' => string, 'offsetSpec' => array]` (offsetSpec via `OffsetSpec::toArray()`) |

### Task 1.3: Requests with arrays/complex fields

| Class | `toArray()` returns |
|-------|-------------------|
| `PeerPropertiesToStreamBufferV1` | `['properties' => [['key' => string, 'value' => ?string], ...]]` |
| `CreateRequestV1` | `['stream' => string, 'arguments' => ['key' => 'value', ...]]` |
| `MetadataRequestV1` | `['streams' => string[]]` |
| `PublishRequestV1` | `['publisherId' => int, 'messages' => [['publishingId' => int, 'data' => string], ...]]` |
| `PublishRequestV2` | `['publisherId' => int, 'messages' => [['publishingId' => int, 'filterValue' => string, 'data' => string], ...]]` |
| `SubscribeRequestV1` | `['subscriptionId' => int, 'stream' => string, 'offsetSpec' => array, 'credit' => int]` |
| `ExchangeCommandVersionsRequestV1` | `['commands' => [['key' => int, 'minVersion' => int, 'maxVersion' => int], ...]]` |
| `CreateSuperStreamRequestV1` | `['name' => string, 'partitions' => string[], 'bindingKeys' => string[], 'arguments' => ['key' => 'value', ...]]` |
| `ConsumerUpdateReplyV1` | `['correlationId' => int, 'responseCode' => int, 'offsetType' => int, 'offset' => int]` |

### Task 1.4: VO classes — add `toArray()` where needed

| Class | `toArray()` returns |
|-------|-------------------|
| `KeyValue` | `['key' => string, 'value' => ?string]` |
| `PublishedMessage` | `['publishingId' => int, 'data' => string]` |
| `PublishedMessageV2` | `['publishingId' => int, 'filterValue' => string, 'data' => string]` |
| `OffsetSpec` | `['type' => int, 'value' => ?int]` |
| `CommandVersion` | `['key' => int, 'minVersion' => int, 'maxVersion' => int]` |
| `Statistic` | `['key' => string, 'value' => int]` |
| `Broker` | `['reference' => int, 'host' => string, 'port' => int]` |
| `StreamMetadata` | `['stream' => string, 'responseCode' => int, 'leaderReference' => int, 'replicasReferences' => int[]]` |
| `PublishingError` | `['publishingId' => int, 'code' => int]` |

---

## Response Classes — `fromArray()` Specification

Each `fromArray(array $data)` accepts the same structure that `fromStreamBuffer()` would produce after parsing. The array does NOT include `key` or `version` — those are already resolved by the deserializer.

### Task 1.5: Simple ack-only Responses (correlationId + responseCode only)

These all follow the same pattern: `fromArray(['correlationId' => int])`.

| Class |
|-------|
| `SaslAuthenticateResponseV1` |
| `CloseResponseV1` |
| `CreateResponseV1` |
| `DeleteStreamResponseV1` |
| `DeclarePublisherResponseV1` |
| `DeletePublisherResponseV1` |
| `SubscribeResponseV1` |
| `UnsubscribeResponseV1` |
| `CreateSuperStreamResponseV1` |
| `DeleteSuperStreamResponseV1` |

### Task 1.6: Responses with data fields

| Class | `fromArray()` input |
|-------|-------------------|
| `PeerPropertiesResponseV1` | `['correlationId' => int, 'properties' => [['key' => string, 'value' => ?string], ...]]` |
| `OpenResponseV1` | `['correlationId' => int, 'connectionProperties' => [['key' => string, 'value' => ?string], ...]]` |
| `SaslHandshakeResponseV1` | `['correlationId' => int, 'mechanisms' => string[]]` |
| `QueryOffsetResponseV1` | `['correlationId' => int, 'offset' => int]` |
| `QueryPublisherSequenceResponseV1` | `['correlationId' => int, 'sequence' => int]` |
| `StreamStatsResponseV1` | `['correlationId' => int, 'stats' => [['key' => string, 'value' => int], ...]]` |
| `MetadataResponseV1` | `['correlationId' => int, 'brokers' => [...], 'streamMetadata' => [...]]` |
| `ExchangeCommandVersionsResponseV1` | `['correlationId' => int, 'commands' => [['key' => int, 'minVersion' => int, 'maxVersion' => int], ...]]` |
| `RouteResponseV1` | `['correlationId' => int, 'streams' => string[]]` |
| `PartitionsResponseV1` | `['correlationId' => int, 'streams' => string[]]` |
| `ResolveOffsetSpecResponseV1` | `['correlationId' => int, 'offset' => int]` |

### Task 1.7: Server-push Responses (no correlationId)

| Class | `fromArray()` input |
|-------|-------------------|
| `PublishConfirmResponseV1` | `['publisherId' => int, 'publishingIds' => int[]]` |
| `PublishErrorResponseV1` | `['publisherId' => int, 'errors' => [['publishingId' => int, 'code' => int], ...]]` |
| `DeliverResponseV1` | `['subscriptionId' => int, 'chunkBytes' => string]` |
| `MetadataUpdateResponseV1` | `['code' => int, 'stream' => string]` |
| `CreditResponseV1` | `['responseCode' => int, 'subscriptionId' => int]` |
| `ConsumerUpdateQueryV1` | `['correlationId' => int, 'subscriptionId' => int, 'active' => bool]` |

### Task 1.8: Special Responses

| Class | `fromArray()` input |
|-------|-------------------|
| `TuneResponseV1` | `['frameMax' => int, 'heartbeat' => int]` (outbound-only, but add for symmetry) |

### Task 1.9: VO classes — add `fromArray()` where needed

| Class | `fromArray()` input |
|-------|-------------------|
| `KeyValue` | `['key' => string, 'value' => ?string]` |
| `PublishingError` | `['publishingId' => int, 'code' => int]` |
| `Broker` | `['reference' => int, 'host' => string, 'port' => int]` |
| `StreamMetadata` | `['stream' => string, 'responseCode' => int, 'leaderReference' => int, 'replicasReferences' => int[]]` |
| `CommandVersion` | `['key' => int, 'minVersion' => int, 'maxVersion' => int]` |
| `Statistic` | `['key' => string, 'value' => int]` |

---

## Tests

### Task 1.10: Roundtrip tests for every Request

For each Request class, test that:
```php
$request = new SomeRequestV1(...);
$array = $request->toArray();
// assert $array has expected structure and values
```

### Task 1.11: Roundtrip tests for every Response

For each Response class, test that:
```php
$response = SomeResponseV1::fromArray([...]);
// assert getters return expected values
```

### Task 1.12: Consistency tests

For each Request/Response pair, verify that `toArray()` output can be used to reconstruct the same binary via `PhpBinarySerializer` (this will be tested in iteration 2).

---

## Implementation Notes

- Add `implements ToArrayInterface` to each Request class alongside existing interfaces
- Add `implements FromArrayInterface` to each Response class alongside existing interfaces
- Do NOT modify `toStreamBuffer()` or `fromStreamBuffer()` — they remain as-is
- Do NOT modify `StreamConnection` — it still uses `toStreamBuffer()` directly
- `ConsumerUpdateReplyV1` is special — it includes `correlationId` in `toArray()` because it manages it manually (not via CorrelationTrait)
- `TuneRequestV1` implements both `FromStreamBufferInterface` and `ToStreamBufferInterface` — add both `toArray()` and `fromArray()`
