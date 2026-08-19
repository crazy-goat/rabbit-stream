# Review Round 1 — Issue #423 (docs-only, branch feature/issue-423-docs-getstreamconnection-removed)

**Commit:** a47bb50  
**Reviewer:** review agent  
**Date:** 2025-01-21  

---

## Scope

9 English doc files rewritten to remove calls to non-existent methods. No `src/` changes. This review verifies every code snippet against the real signatures in `src/`.

## Earlier findings

`findings-review.md` does not exist yet — this is round 1.

## Methodology

1. Read `AGENTS.md`.
2. Read all relevant source files: `Connection.php`, `Producer.php`, `Consumer.php`, `StreamConnection.php`, `OffsetSpec.php`, `ProtocolException.php`, `SimpleCorrelatedResponseV1.php`, `StreamMetadata.php`, `Broker.php`, `Statistic.php`, `Message.php`, `ConfirmationStatus.php`, `PublishingError.php`, `PublishErrorResponseV1.php`, `AmqpMessageEncoder.php`, `AmqpMessageDecoder.php`, `OsirisChunkParser.php`, `UnexpectedResponseException.php`, `ResponseCodeEnum.php`, and all Request/Response constructors referenced in the docs.
3. Read all 9 changed doc files in full.
4. Ran acceptance greps.
5. Checked proof-of-work files for existence and substance.

---

## Acceptance criteria checks

### Grep 1: `getStreamConnection` / `registerDeliverCallback` in docs/en
```
grep -rn 'getStreamConnection\|registerDeliverCallback' docs/en
→ 0 hits. PASS.
```

### Grep 2: forbidden `$connection->` low-level calls in changed files
```
grep -rn '\$connection->sendMessage\|\$connection->readMessage\|\$connection->registerPublisher\|\$connection->registerSubscriber\|\$connection->stop' docs/en/guide docs/en/examples/stream-management.md docs/en/examples/super-stream-routing.md
→ 0 hits. PASS.
```
(Remaining hit in `docs/en/examples/error-handling-patterns.md` is out of scope — not a changed file, already reported by coder in findings-coder.md item #8.)

---

## Per-file findings

### docs/en/guide/error-handling.md

**FINDING [HIGH] — line 406: `$consumer->close()` on undefined variable**

The "Consuming Errors" snippet was rewritten from the old `$connection->unsubscribe(1)` pattern to the high-level `$consumer->close()` pattern. However, `$consumer` is assigned inside the `try` block:

```php
try {
    $consumer = $connection->createConsumer(
        'my-stream',
        OffsetSpec::first(),
        name: 'my-consumer-group'
    );
} catch (ProtocolException $e) {
    $code = $e->getResponseCode();
    
    if ($code === ResponseCodeEnum::SUBSCRIPTION_ID_ALREADY_EXISTS) {
        // A previous consumer still holds the subscription - close it and retry
        $consumer->close();   // ← $consumer is UNDEFINED here
        $consumer = $connection->createConsumer('my-stream', OffsetSpec::first());
    } elseif ...
```

`createConsumer()` threw before the assignment completed, so `$consumer` is never assigned. Calling `$consumer->close()` would fatal with "Call to a member function close() on null" (PHP 8.x: Error on undefined variable).

The old code used `$connection->unsubscribe(1)` which operated on the already-defined `$connection`, so this is a regression introduced by the rewrite.

**Automated check that could catch this:** `php -l` would not catch it (it's a runtime logic error, not a parse error). PHPStan on extracted snippets would flag it as "undefined variable".

**FINDING [MEDIUM] — line 424: `$consumer->queryOffset()` may be on undefined variable**

After the catch block, the code proceeds unconditionally:

```php
// Handling NO_OFFSET on first consumer run
try {
    $lastOffset = $consumer->queryOffset();  // ← $consumer may be undefined
```

If the catch block took the `STREAM_NOT_EXIST` or `ACCESS_REFUSED` branches (which just `error_log()` and fall through), `$consumer` was never assigned. This would fatal.

**Automated check:** Same as above — PHPStan would flag undefined variable.

**Checked clean:**
- `ProtocolException::getResponseCode()` — exists, returns `?ResponseCodeEnum`. Correct usage.
- `AuthenticationException` extends `ProtocolException` — hierarchy correct.
- `UnexpectedResponseException::getExpectedClass()` / `getActualClass()` — both exist.
- `Connection::create()` named args (host, port, user, password, vhost) — all correct.
- `ConfirmationStatus::isConfirmed()`, `getPublishingId()`, `getErrorCode()` — all exist.
- `ResponseCodeEnum::isSuccess()`, `isError()`, `fromInt()`, `getMessage()` — all exist.
- `InvalidArgumentException` thrown by `Producer::querySequence()` on unnamed producer — confirmed.
- `createProducer('non-existent-stream')` throws at declare time — confirmed (DeclarePublisherResponseV1 extends SimpleCorrelatedResponseV1, throws on non-OK).

### docs/en/guide/consuming.md

**FINDING [LOW] — line 589: missing `use` import for `CreditRequestV1`**

The "Handle Deliver Frames" snippet has `use` statements for `DeliverResponseV1`, `OsirisChunkParser`, `AmqpMessageDecoder`, but uses `CreditRequestV1` without importing it:

```php
use CrazyGoat\RabbitStream\Response\DeliverResponseV1;
use CrazyGoat\RabbitStream\Client\OsirisChunkParser;
use CrazyGoat\RabbitStream\Client\AmqpMessageDecoder;

// ...
        $stream->sendMessage(new CreditRequestV1(1, count($messages)));
```

The "Complete Low-Level Example" later in the file does import `CreditRequestV1`, so the pattern is inconsistent.

**Automated check:** Running the snippet through `php -l` after wrapping in `<?php` with the shown imports would fatal with "Class 'CreditRequestV1' not found".

**Pre-existing (not in diff, not counted as finding):** The "Error Handling" section (lines 737-771) has the same `$consumer` undefined-variable pattern (createConsumer throws, then `$consumer->read()` is called). This was NOT changed by this commit — it predates it.

**Checked clean:**
- `Connection::createConsumer()` named args: `stream`, `offset`, `name`, `autoCommit`, `initialCredit` — all match.
- `Consumer::read(timeout:)`, `readOne(timeout:)`, `storeOffset(int)`, `queryOffset()`, `close()` — all match.
- `SubscribeRequestV1(subscriptionId:, stream:, offsetSpec:, credit:)` — matches constructor.
- `StoreOffsetRequestV1(reference:, stream:, offset:)` — matches.
- `QueryOffsetRequestV1(reference:, stream:)` — matches.
- `UnsubscribeRequestV1(subscriptionId:)` — matches.
- `StreamConnection::registerSubscriber(subscriptionId:, onDeliver:)` — matches parameter names.
- Low-level section labelled with "low-level API" and handshaken-`$stream` preamble — confirmed.

### docs/en/guide/flow-control.md

**FINDING [LOW] — lines 317, 371: `<?php`-tagged snippets missing `use` imports**

Two snippets with `<?php` tags use `PublishRequestV1`, `PublishedMessage`, `AmqpMessageEncoder` without `use` imports:

1. "Basic Usage" (line 317): `<?php` tag, no `use` statements, uses all three classes.
2. "Stopping the Loop" (line 371): `<?php` tag, no `use` statements, uses all three classes.

The "readMessage() Transparent Dispatch" example (line 276) DOES include `use` statements for the same classes, making the inconsistency visible.

**Automated check:** Running these snippets as-is would fatal with "Class not found".

**Checked clean:**
- `StreamConnection::registerPublisher(publisherId:, onConfirm:, onError:)` — matches.
- `StreamConnection::registerSubscriber(int, callable)` — matches.
- `StreamConnection::readLoop(maxFrames:, timeout:)` — matches.
- `StreamConnection::stop()` — exists.
- `StreamConnection::onHeartbeat(callable)` — exists.
- `StreamConnection::onConsumerUpdate(callable)` — exists.
- `ConsumerUpdateResponseV1::getSubscriptionId()` — exists.
- `Connection::readLoop(maxFrames:, timeout:)` — exists (delegates to StreamConnection).
- `PublishedMessage(int, string)` — matches.
- `CreditRequestV1(subscriptionId:, credit:)` — matches.
- `SubscribeRequestV1(subscriptionId:, stream:, offsetSpec:, credit:)` — matches.
- Low-level sections labelled — confirmed ("low-level API", "handshaken StreamConnection").
- `readLoop()` does NOT throw `TimeoutException` (returns silently) — correctly documented.
- `readMessage()` DOES throw `TimeoutException` — correctly documented.
- `PublishingError::getCode()`, `getPublishingId()` — both exist.

### docs/en/guide/stream-management.md

**FINDING [NIT] — line 410: `StreamMetadata` and `Connection` used without `use` imports**

The `StreamMetadataCache` class snippet uses `Connection` and `StreamMetadata` as type hints without `use` statements. This is an inline class definition (no `<?php` tag), so it's illustrative rather than runnable.

**Checked clean:**
- `Connection::create()` named args — correct.
- `createStream(name, arguments)`, `deleteStream(name)`, `streamExists(name)`, `getStreamStats(name)`, `getMetadata(array)`, `close()` — all match.
- `CreateRequestV1(string, array)`, `DeleteStreamRequestV1(string)`, `MetadataRequestV1(array)`, `StreamStatsRequestV1(string)` — all match.
- `StreamMetadata::getReplicasReferences()` — correct (not `getReplicaReferences`).
- `Broker::getReference()`, `getHost()`, `getPort()` — all exist.
- `SimpleCorrelatedResponseV1` has NO `getResponseCode()` — confirmed (throws ProtocolException on non-OK). Try/catch pattern is the correct replacement.
- `ResponseCodeEnum::OK->value` comparison with `getResponseCode()` (int) on StreamMetadata — correct.
- Low-level example labelled with "low-level API" and handshaken-`$stream` preamble — confirmed.

### docs/en/guide/super-streams.md

**FINDING [NIT] — line 476: `ConsumerUpdateResponseV1` used without `use` import**

Inline snippet uses `ConsumerUpdateResponseV1` as a type hint without `use` statement. Illustrative snippet, not a `<?php` block.

**Checked clean:**
- `createSuperStream(name, partitions, bindingKeys, arguments)` — matches.
- `deleteSuperStream(name)` — matches.
- `route(routingKey, superStream)` — matches, returns `string[]`.
- `createProducer(stream)`, `createConsumer(stream, OffsetSpec, name:)` — match.
- `PartitionsRequestV1(string)` — matches.
- `PartitionsResponseV1::getStreams()` — exists, returns `string[]`.
- `OffsetSpec::first()` — exists.
- Low-level sections (partitions listing, ConsumerUpdate) labelled — confirmed.
- Single Active Consumer limitation correctly documented (SubscribeRequestV1 has no group/consumer reference parameter — confirmed).
- `Producer::send(string)`, `waitForConfirms(timeout:)`, `sendBatch(array)` — all match.
- Complete example has all necessary imports.

### docs/en/examples/stream-management.md

**Checked clean — no findings.**
- All high-level API calls verified (createStream, deleteStream, streamExists, getMetadata, getStreamStats, close).
- All named args correct.
- Low-level section labelled with "low-level API" and handshaken-`$stream` preamble.
- `CreateResponseV1`, `DeleteStreamResponseV1`, `MetadataResponseV1`, `StreamStatsResponseV1` — all exist, `instanceof` checks valid.
- `Statistic::getKey()`, `getValue()` — both exist.

### docs/en/examples/super-stream-routing.md

**Checked clean — no findings.**
- Complete example class with all API calls verified.
- `Connection::create(host:, port:, user:, password:, vhost:, streamConnection:)` — all named args match.
- `StreamConnection` constructor and `connect()` — match.
- `createSuperStream`, `deleteSuperStream`, `route`, `createProducer`, `createConsumer` — all match.
- `PartitionsRequestV1(string)` — matches.
- `PartitionsResponseV1::getStreams()` — exists.
- `Producer::send(string)`, `waitForConfirms(timeout:)`, `close()` — match.
- `Consumer::read(timeout:)`, `close()` — match.
- `Message::getBody()` — exists.
- Low-level partitions query labelled — confirmed.

### docs/en/guide/publishing.md

**Checked clean — no findings.**
- `Producer::send(string)`, `sendBatch(array)`, `waitForConfirms(timeout:)`, `close()`, `getLastPublishingId()`, `querySequence()` — all match.
- `Connection::createProducer(stream, name:, onConfirm:)` — matches.
- `ConfirmationStatus::isConfirmed()`, `getPublishingId()`, `getErrorCode()` — all exist.
- `PublishedMessage(publishingId:, message:)` — matches.
- `PublishRequestV1(publisherId, PublishedMessage...)` — matches.
- `DeclarePublisherRequestV1(publisherId:, publisherReference:, stream:)` — matches.
- `DeletePublisherRequestV1(publisherId)` — matches.
- `AmqpMessageEncoder::encodeDataSection(string)` — exists.
- `PublishedMessageV2(publishingId:, filterValue:, message:)` — matches.
- `PublishRequestV2(publisherId, PublishedMessageV2...)` — matches.
- `ProtocolException::getResponseCode()` — used correctly for non-existent stream handling.
- Low-level section labelled with handshaken-`$stream` preamble — confirmed.

### docs/en/guide/offset-tracking.md

**Checked clean — no findings.**
- `Connection::storeOffset(reference, stream, offset)` — matches.
- `Connection::queryOffset(reference, stream)` — matches.
- `Consumer::storeOffset(int)`, `queryOffset()`, `close()` — match.
- `OffsetSpec::first()/last()/next()/offset(int)/timestamp(int)/interval(int)` — all exist.
- `ResolveOffsetSpecRequestV1(stream:, offsetSpec:)` — matches constructor.
- `ResolveOffsetSpecResponseV1::getOffset()` — exists.
- `StoreOffsetRequestV1(reference:, stream:, offset:)` — matches.
- `QueryOffsetRequestV1(reference:, stream:)` — matches.
- Low-level section labelled — confirmed.

---

## Proof-of-work files

- `docs/proof_of_work/423-docs-getstreamconnection-removed/code-decision-1.md` — exists, 140 lines, substantive. Documents approach, rejected alternatives, decisions about PartitionsRequestV1, SimpleCorrelatedResponseV1, flow-control, single active consumer, and verification performed.
- `docs/proof_of_work/423-docs-getstreamconnection-removed/findings-coder.md` — exists, 150 lines, substantive. Documents 7 in-scope (fixed) findings and 9 out-of-scope findings with file:line references and suggested fixes.

Both files are well-structured and demonstrate thorough understanding of the codebase.

---

## Summary

| Severity | Count | Details |
|----------|-------|---------|
| HIGH     | 1     | `$consumer->close()` on undefined variable (error-handling.md:406) |
| MEDIUM   | 1     | `$consumer->queryOffset()` on potentially undefined variable (error-handling.md:424) |
| LOW      | 2     | Missing `use` imports in `<?php`-tagged or import-bearing snippets (consuming.md:589, flow-control.md:317/371) |
| NIT      | 2     | Missing `use` imports in inline illustrative snippets (stream-management.md:410, super-streams.md:476) |

**Acceptance criterion ("No snippet calls a method that does not exist on the class"):** MET. Every method call in the 9 changed files targets a method that exists on the correct class. The HIGH/MEDIUM findings are logic errors (calling existing methods on undefined variables), not non-existent-method calls.

**Key positive observations:**
- The `SimpleCorrelatedResponseV1` insight is correct and well-documented: create/delete responses throw `ProtocolException` on non-OK and have no `getResponseCode()`. The try/catch redesign is the right approach.
- The `StreamMetadata::getReplicasReferences()` (not `getReplicaReferences`) fix is correct.
- The `Connection::create(streamConnection:)` pattern for low-level snippets is elegant and tested.
- Low-level sections are consistently labelled.
- The AMQP 1.0 encoding requirement for `Producer::send()` is correctly documented.
- The single-active-consumer limitation is honestly documented.
