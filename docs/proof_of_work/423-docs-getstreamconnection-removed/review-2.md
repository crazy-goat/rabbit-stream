# Review Round 2 — Issue #423

**Branch:** feature/issue-423-docs-getstreamconnection-removed
**Commits reviewed:** a47bb50 (original rewrite) + 4156c42 (round-1 fixes)
**Date:** 2026-08-18

---

## Per-Finding Status (Round 1, findings 1–6)

| # | File:Line | Round-1 severity | Round-2 status | Evidence |
|---|-----------|-----------------|----------------|----------|
| 1 | docs/en/guide/error-handling.md:406 | high | **Fixed** | The `$consumer->close()` call is gone. The `SUBSCRIPTION_ID_ALREADY_EXISTS` branch now retries with `name: 'my-consumer-group-2'` and a comment explaining createConsumer() threw before returning an object. Verified at current line 408–414. |
| 2 | docs/en/guide/error-handling.md:424 | medium | **Fixed** | `$consumer = null` is initialized before the try block (line 397). A null guard `if ($consumer === null) { return; }` is present before the NO_OFFSET block (line 425–428). Every path reaching `$consumer->queryOffset()` (line 432) has `$consumer` defined. |
| 3 | docs/en/guide/consuming.md:589 | low | **Fixed** | `use CrazyGoat\RabbitStream\Request\CreditRequestV1;` is present at line 578, alongside the existing `DeliverResponseV1`, `OsirisChunkParser`, `AmqpMessageDecoder` imports. |
| 4 | docs/en/guide/flow-control.md:317,371 | low | **Fixed** | Both the "Basic Usage" (line 320) and "Stopping the Loop" (line 377) snippets now include `use CrazyGoat\RabbitStream\Request\PublishRequestV1;`, `use CrazyGoat\RabbitStream\VO\PublishedMessage;`, and `use CrazyGoat\RabbitStream\Client\AmqpMessageEncoder;` after `<?php`. |
| 5 | docs/en/guide/stream-management.md:410 | nit | **Fixed** | `use CrazyGoat\RabbitStream\Client\Connection;` and `use CrazyGoat\RabbitStream\VO\StreamMetadata;` are present at lines 399–400, before the `StreamMetadataCache` class definition. |
| 6 | docs/en/guide/super-streams.md:476 | nit | **Fixed** | `use CrazyGoat\RabbitStream\Response\ConsumerUpdateResponseV1;` is present at line 474, before the `onConsumerUpdate` snippet. |

**All 6 round-1 findings are confirmed fixed.**

---

## Use-Import FQCN Verification (round-1 fix commit 4156c42)

All 7 `use` imports added by the fix commit were verified against `src/`:

| FQCN in docs | Namespace in src/ | Match |
|---|---|---|
| `CrazyGoat\RabbitStream\Request\CreditRequestV1` | `CrazyGoat\RabbitStream\Request` | ✅ |
| `CrazyGoat\RabbitStream\Request\PublishRequestV1` | `CrazyGoat\RabbitStream\Request` | ✅ |
| `CrazyGoat\RabbitStream\VO\PublishedMessage` | `CrazyGoat\RabbitStream\VO` | ✅ |
| `CrazyGoat\RabbitStream\Client\AmqpMessageEncoder` | `CrazyGoat\RabbitStream\Client` | ✅ |
| `CrazyGoat\RabbitStream\Client\Connection` | `CrazyGoat\RabbitStream\Client` | ✅ |
| `CrazyGoat\RabbitStream\VO\StreamMetadata` | `CrazyGoat\RabbitStream\VO` | ✅ |
| `CrazyGoat\RabbitStream\Response\ConsumerUpdateResponseV1` | `CrazyGoat\RabbitStream\Response` | ✅ |

---

## Method-Signature Verification (changed lines of 4156c42)

Every method call in the fix commit's changed lines was verified against real `src/` signatures:

| Call | Source signature | Match |
|---|---|---|
| `$connection->createConsumer(stream, OffsetSpec, name:)` | `Connection::createConsumer(string, OffsetSpec, ?string, int, int)` | ✅ |
| `$consumer->queryOffset()` | `Consumer::queryOffset(): int` | ✅ |
| `$e->getResponseCode()` | `ProtocolException::getResponseCode(): ?ResponseCodeEnum` | ✅ |
| `$stream->registerSubscriber(subscriptionId:, onDeliver:)` | `StreamConnection::registerSubscriber(int, callable)` | ✅ |
| `$stream->registerPublisher(publisherId:, onConfirm:, onError:)` | `StreamConnection::registerPublisher(int, callable, callable)` | ✅ |
| `$stream->sendMessage(new CreditRequestV1(1, count(...)))` | `CreditRequestV1::__construct(int, int)` | ✅ |
| `$stream->sendMessage(new PublishRequestV1(1, new PublishedMessage(...)))` | `PublishRequestV1::__construct(int, PublishedMessage...)`, `PublishedMessage::__construct(int, string)` | ✅ |
| `AmqpMessageEncoder::encodeDataSection('Hello')` | `AmqpMessageEncoder::encodeDataSection(string): string` | ✅ |
| `$stream->readLoop(maxFrames:)` / `$stream->readLoop(timeout:)` | `StreamConnection::readLoop(?int, ?float)` | ✅ |
| `$stream->stop()` | `StreamConnection::stop(): void` | ✅ |
| `$connection->getMetadata([$stream])` → `getStreamMetadata()` | `Connection::getMetadata(array): MetadataResponseV1`, `MetadataResponseV1::getStreamMetadata(): array` | ✅ |
| `$meta->getStreamName()` / `$meta->getLeaderReference()` / `$meta->getReplicasReferences()` | `StreamMetadata::getStreamName(): string`, `getLeaderReference(): int`, `getReplicasReferences(): array` | ✅ |
| `$stream->onConsumerUpdate(fn(ConsumerUpdateResponseV1): array)` | `StreamConnection::onConsumerUpdate(callable)` — callback destructures `[$offsetType, $offset]` | ✅ |
| `$query->getSubscriptionId()` | `ConsumerUpdateResponseV1::getSubscriptionId(): int` | ✅ |
| `$response->getResponseCode()` comparisons with `ResponseCodeEnum::SUBSCRIPTION_ID_ALREADY_EXISTS`, `STREAM_NOT_EXIST`, `ACCESS_REFUSED`, `NO_OFFSET` | All enum cases exist in `ResponseCodeEnum` | ✅ |

---

## Acceptance Greps

```
grep -rn 'getStreamConnection\|registerDeliverCallback' docs/en
→ 0 matches (exit 1) ✅

grep -rn '$connection->sendMessage\|$connection->readMessage\|$connection->registerPublisher\|$connection->registerSubscriber\|$connection->stop' docs/en/guide docs/en/examples/stream-management.md docs/en/examples/super-stream-routing.md
→ 0 matches (exit 1) ✅
```

The only remaining `$connection->readMessage()` hit is in `docs/en/examples/error-handling-patterns.md` which is explicitly OUT OF SCOPE (pre-existing broken file, coder's finding #8).

---

## Error-Handling.md Deep Analysis (lines ~393–445)

**Null guard correctness:** `$consumer = null` is set before the try block (line 397). After the catch block, `if ($consumer === null) { return; }` (line 425–428) guards the NO_OFFSET section. Every code path that reaches `$consumer->queryOffset()` (line 432) has `$consumer` non-null:
- Success path: `$consumer` assigned in try block.
- `SUBSCRIPTION_ID_ALREADY_EXISTS`: `$consumer` assigned via retry `createConsumer()` (if retry throws, it propagates and never reaches the guard).
- `STREAM_NOT_EXIST` / `ACCESS_REFUSED`: `$consumer` stays null → guard returns early.

**Retry-with-unique-name logic:** The retry `$connection->createConsumer('my-stream', OffsetSpec::first(), name: 'my-consumer-group-2')` is sound — it uses a different consumer name to avoid the collision. The retry is NOT wrapped in a try/catch, so if it also throws, the exception propagates uncaught. This is an acceptable documentation simplification (the pattern is illustrative, not production-ready error handling).

**NO_OFFSET re-create:** `$consumer = $connection->createConsumer('my-stream', OffsetSpec::first())` creates a new consumer without a name. If the user later calls `queryOffset()` on this consumer, `Consumer::queryOffset()` would throw "Cannot query offset for unnamed consumer". The snippet ends after this line, so no downstream call is shown. This is a pre-existing pattern, not introduced by the fix.

---

## NEW Findings

### Finding 7 (low) — Missing `use AmqpMessageEncoder` in publishing.md

**File:** `docs/en/guide/publishing.md:367–376`
**Introduced by:** a47bb50 (original rewrite)

The "Publish Messages" low-level snippet was changed from `message: 'Hello'` to `message: AmqpMessageEncoder::encodeDataSection('Hello')` but the `use` import block was not updated:

```php
<?php

use CrazyGoat\RabbitStream\Request\PublishRequestV1;
use CrazyGoat\RabbitStream\VO\PublishedMessage;
// ← missing: use CrazyGoat\RabbitStream\Client\AmqpMessageEncoder;

$message = new PublishedMessage(
    publishingId: 1,
    message: AmqpMessageEncoder::encodeDataSection('Hello')
);
```

Would fatal with "Class 'AmqpMessageEncoder' not found" if the snippet is extracted and run as-is. Same class of issue as round-1 findings #3 and #4. The round-1 fix added the `AmqpMessageEncoder` import to `flow-control.md` snippets but missed this one in `publishing.md`.

**Fix:** Add `use CrazyGoat\RabbitStream\Client\AmqpMessageEncoder;` after the `PublishedMessage` import.

### Finding 8 (low) — Incorrect offsetType values in ConsumerUpdate callback

**File:** `docs/en/guide/super-streams.md:486–487`
**Introduced by:** a47bb50 (original rewrite)

The `onConsumerUpdate` callback returns incorrect `offsetType` values. The `OffsetSpec` constants in `src/VO/OffsetSpec.php` are:

```
TYPE_FIRST     = 0x0001 (1)
TYPE_LAST      = 0x0002 (2)
TYPE_NEXT      = 0x0003 (3)
TYPE_OFFSET    = 0x0004 (4)
TYPE_TIMESTAMP = 0x0005 (5)
```

The snippet:
```php
return [0, 0];   // comment: "from the beginning" — but offsetType=0 is not a valid type
return [1, 0];   // comment: "OFFSET, start from 0" — but offsetType=1 is TYPE_FIRST, not TYPE_OFFSET
```

- `[0, 0]`: offsetType=0 is invalid. The comment says "from the beginning" which should be `[1, 0]` (TYPE_FIRST).
- `[1, 0]`: The comment says "OFFSET" but offsetType=1 is TYPE_FIRST. If the intent was OFFSET, the value should be `4`.

The `StreamConnection::handleConsumerUpdate()` method does not validate the return value — it passes `[$offsetType, $offset]` directly to `ConsumerUpdateReplyV1`, which sends it to the server. An invalid offsetType=0 would be sent to the broker, which may reject it or behave unexpectedly.

**Fix:** Change `return [0, 0]` to `return [1, 0]` (TYPE_FIRST) and either fix the comment on `return [1, 0]` to say "FIRST" or change the value to `return [4, 0]` if OFFSET was intended.

---

## Summary

All 6 round-1 findings are confirmed fixed. The round-1 fix commit (4156c42) is clean — every added `use` import is a correct FQCN and every method call matches the real `src/` signatures. The error-handling.md null guard is correct and covers all paths.

Two new low-severity findings were discovered in the original rewrite (a47bb50) that were not caught in round 1:
- **#7:** Missing `use AmqpMessageEncoder` in publishing.md (would fatal if run).
- **#8:** Incorrect offsetType values in super-streams.md ConsumerUpdate snippet (misleading comments + invalid offsetType=0).

Neither is a blocker for the docs rewrite, but both should be fixed for correctness.
