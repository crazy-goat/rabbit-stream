# Iteration 10: Cleanup (Optional, Major Version)

> **For Claude:** REQUIRED SUB-SKILL: Use superpowers:executing-plans to implement this plan task-by-task.

**Goal:** Remove deprecated classes and fix long-standing tech debt. This is the only iteration with breaking changes — should be released as a major version bump.

**When:** Only after the new API has been stable for a while and users have migrated.

---

## Breaking Changes

### Task 10.1: Remove deprecated classes

Delete:
- `src/Client/StreamClient.php`
- `src/Client/StreamClientConfig.php`
- `src/Client/ProducerConfig.php`

### Task 10.2: Remove legacy examples

Delete:
- `examples/legacy/` directory

### Task 10.3: Fix `gatString()` typo in `ReadBuffer`

Rename `gatString()` → `getString()`. Update all callers:
- All Response classes that call `$buffer->gatString()`
- `PhpBinarySerializer` if it uses `ReadBuffer` directly
- Any VO classes that use it

This is a BC break for anyone who extended `ReadBuffer` or called `gatString()` directly.

### Task 10.4: Fix `static public` → `public static` ordering

Update all classes to use PSR-12 compliant `public static function` instead of `static public function`. This is technically not a BC break (PHP accepts both), but it's a code style cleanup.

### Task 10.5: Translate Polish error messages in `WriteBuffer`

Replace Polish validation messages with English equivalents.

### Task 10.6: Move interfaces out of `Trait\` namespace

Move:
- `CrazyGoat\RabbitStream\Trait\CorrelationInterface` → `CrazyGoat\RabbitStream\Contract\CorrelationInterface`
- `CrazyGoat\RabbitStream\Trait\KeyVersionInterface` → `CrazyGoat\RabbitStream\Contract\KeyVersionInterface`

Update all `use` statements across the codebase.

### Task 10.7: Remove `toStreamBuffer()` / `fromStreamBuffer()` (optional)

If `BinarySerializerInterface` is fully adopted and no external code depends on these methods, they can be removed. The `toArray()`/`fromArray()` path becomes the only way.

**Decision:** Keep them. They are still useful for debugging and testing. Only remove if there's a strong reason.

### Task 10.8: Update version

Bump to next major version in `composer.json`.

---

## BC Impact Summary

| Change | BC Impact |
|--------|-----------|
| Remove `StreamClient`, `StreamClientConfig`, `ProducerConfig` | **Breaking** — users must migrate to `Connection` |
| Remove legacy examples | None — examples are not part of the API |
| `gatString()` → `getString()` | **Breaking** — anyone calling `gatString()` directly |
| `static public` → `public static` | None (PHP accepts both) |
| Polish → English error messages | None (error messages are not API) |
| Move interfaces to `Contract\` | **Breaking** — anyone importing from `Trait\` namespace |

---

## Migration Guide

```diff
- use CrazyGoat\RabbitStream\Client\StreamClient;
- use CrazyGoat\RabbitStream\Client\StreamClientConfig;
- use CrazyGoat\RabbitStream\Client\ProducerConfig;
+ use CrazyGoat\RabbitStream\Client\Connection;

- $client = StreamClient::connect(new StreamClientConfig(
-     host: 'localhost',
-     port: 5552,
- ));
+ $connection = Connection::create(
+     host: 'localhost',
+     port: 5552,
+ );

- $producer = $client->createProducer('stream', new ProducerConfig(
-     name: 'my-producer',
-     onConfirmation: fn($status) => ...,
- ));
+ $producer = $connection->createProducer('stream',
+     name: 'my-producer',
+     onConfirm: fn($status) => ...,
+ );

- $client->close();
+ $connection->close();
```
