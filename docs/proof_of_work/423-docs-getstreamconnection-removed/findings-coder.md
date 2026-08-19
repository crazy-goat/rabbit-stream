# Issue #423 — coder findings

What I found while rewriting the documentation. Entries marked **[in-scope]**
were fixed in this branch; entries marked **[out-of-scope]** are bugs or
weak spots that remain (mostly in docs outside the 9 files listed in the
issue) or in `src/` — they are reported here because the task asks for them.

## Biggest obstacle

**The low-level "response-code inspection" pattern that the docs were built
around cannot work with the library as it is today.** `CreateResponseV1`,
`DeleteStreamResponseV1`, `CreateSuperStreamResponseV1`,
`DeleteSuperStreamResponseV1` all extend `SimpleCorrelatedResponseV1`
(`src/Response/SimpleCorrelatedResponseV1.php:36-55`), which throws
`ProtocolException` during `fromStreamBuffer()` for every non-OK response
code and exposes **no** `getResponseCode()` at all. Four guides and two
example files were built on
`$response->getResponseCode() === ResponseCodeEnum::STREAM_ALREADY_EXISTS`
(stream-management.md, super-streams.md, examples/stream-management.md,
examples/super-stream-routing.md). So the "already exists / does not exist"
sections could not be fixed by swapping the connection class — the whole
pattern had to be redesigned as try/catch on `ProtocolException` +
`$e->getResponseCode()` (which does exist: `src/Exception/ProtocolException.php:25`).
This also invalidates the protocol reference docs' countless
`$response->getResponseCode()` calls (see out-of-scope findings below) —
anyone who copied them into real code got "Call to undefined method".

Second obstacle: **the docs invented a whole API surface that never existed
in src/** — `OffsetType` enum, `OffsetSpecification`, `groupName` /
`consumerName` / `consumerReference` on `SubscribeRequestV1`,
`registerDeliverCallback()`, `DeliverResponseV1::getMessages()`,
`StreamStatsResponseV1::getFirstOffset()/getLastOffset()`,
`StreamMetadata::getReplicaReferences()`,
`Connection::createPublisher/subscribe/unsubscribe/authenticate/setTimeout/
reconnect`. Every snippet had to be checked against real signatures; I
verified each class in `src/` before rewriting.

## Findings

### In scope (fixed on this branch)
1. **[in-scope, fixed]** `docs/en/guide/super-streams.md` — the whole
   super-stream guide called `sendMessage/readMessage` on the high-level
   `Connection` (create/delete super stream, route, partitions, subscribe,
   complete example) and used the non-existent `OffsetType`/`getResponseCode`
   patterns. Rewritten to high-level `createSuperStream/deleteSuperStream/
   route/getStreamStats` + labelled low-level `StreamConnection` for
   partitions listing.
2. **[in-scope, fixed]** `docs/en/examples/super-stream-routing.md` — the
   runnable example class used `getStreamConnection()` 15 times and
   `registerDeliverCallback()` 3 times. Rewritten: one `StreamConnection`
   (`$this->stream`) wrapped by `Connection::create(streamConnection:)`,
   high-level consumers per partition.
3. **[in-scope, fixed]** `docs/en/guide/error-handling.md` — documented at
   least 10 methods that exist nowhere: `Connection::createPublisher()`,
   `Connection::subscribe()/unsubscribe()`, `Connection::authenticate()`,
   `Connection::setTimeout()`, `Connection::reconnect()`,
   `StreamConnection::authenticate()/open()`, `OffsetSpecification`,
   `registerPublisher(onConfirm: $status)` with `ConfirmationStatus`
   (low-level `registerPublisher` delivers `int[]`/`PublishingError[]`,
   not `ConfirmationStatus`), `$error->getMessage()` on `PublishingError`
   (no such getter). Rewritten to high-level equivalents.
4. **[in-scope, fixed]** `docs/en/guide/flow-control.md` — `onDeliver()` and
   `registerConsumerUpdateCallback()` do not exist (real: `registerSubscriber()`,
   `onConsumerUpdate()`); `Stop the loop` used `$connection->stop()`;
   `credit=100` subscribe with `consumerReference` (no such parameter);
   `readLoop()` claimed to throw `TimeoutException` (it does not — it
   returns when the deadline passes; only `readMessage()` throws).
5. **[in-scope, fixed]** `docs/en/guide/publishing.md` —
   `DeclarePublisherRequestV1(streamName:)` named argument did not exist
   (real: `stream:`); low-level `PublishedMessage` payloads were raw strings
   although the consumer now expects an AMQP 1.0 Data section (wrapped via
   `AmqpMessageEncoder::encodeDataSection()`), and "Publishing to
   Non-existent Stream" claimed `PublishError` arrives after `send()` —
   actually `createProducer()` throws `ProtocolException` at declare time.
6. **[in-scope, fixed]** `docs/en/guide/consuming.md`,
   `docs/en/guide/offset-tracking.md` — low-level sections used
   `$connection->sendMessage/readMessage` (high-level) and
   `$deliver->getMessages()`; `ResolveOffsetSpecRequestV1` was called with
   a non-existent `reference:` argument (constructor: `stream`, `offsetSpec`).
7. **[in-scope, fixed]** `docs/en/guide/stream-management.md`,
   `docs/en/examples/stream-management.md` —
   `StreamMetadata::getReplicaReferences()` → real name
   `getReplicasReferences()` (8 occurrences), and the low-level examples'
   `getResponseCode()` calls (methods do not exist, see biggest obstacle).

### Out of scope (still broken on main)
8. **[out-of-scope]** `docs/en/examples/error-handling-patterns.md` is the
   most broken docs file in the repo — it uses an entirely invented API
   surface: `CrazyGoat\RabbitStream\OffsetSpecification` (+ `::first()/
   ::stored()`), `$connection->subscribe(...)` (lines 328, 342, 359, 632,
   640), `$consumer->subscribe(...)` (line 411), `Connection::readMessage()`
   (line 447). Suggested fix: rewrite like `docs/en/examples/offset-resume.md`
   using `OffsetSpec` + `createConsumer()/createProducer()` (same rewrite
   as this issue did for error-handling.md).
9. **[out-of-scope]** `docs/en/api-reference/stream-connection.md` — while
   `$connection` there IS a `StreamConnection` (so `sendMessage/readMessage/
   stop` are valid), the snippets call non-existent APIs:
   - line 407: `new DeclarePublisherRequestV1(1, 'my-stream')` — constructor
     takes 3 args (publisherId, publisherReference, stream);
   - line 411: `new PublishRequestV1(1, [new Message('hello')])` — takes
     variadic `PublishedMessage`; there is no `Message` VO in `src/VO`;
   - lines 423-436: `registerSubscriber(..., onDeliver:)` is fine, but
     `$deliver->getMessages()` and `SubscribeRequestV1(offsetType:
     OffsetType::NEXT)` do not exist (use `OffsetSpec`; parse chunk bytes).
   Suggested fix: `new DeclarePublisherRequestV1(1, null, 'my-stream')`,
     `new PublishRequestV1(1, new PublishedMessage(1,
     AmqpMessageEncoder::encodeDataSection('hello')))`,
     `offsetSpec: OffsetSpec::next()`, decode via `OsirisChunkParser` +
     `AmqpMessageDecoder`.
10. **[out-of-scope]** `docs/en/protocol/consuming-commands.md` —
    line 140: `$connection->registerConsumer(...)` does not exist (real:
    `registerSubscriber`); lines 143, 153, 453: `$deliver->getMessages()`
    / `$response->getMessages()` do not exist on `DeliverResponseV1`.
11. **[out-of-scope]** `docs/en/protocol/stream-management-commands.md`
    line 224 and `docs/en/protocol/publishing-commands.md` lines 200, 238:
    `$response = $connection->readLoop(maxFrames: 1);` — `readLoop()`
    returns `void`; assigning the result is a bug.
12. **[out-of-scope]** `docs/en/protocol/stream-management-commands.md`
    lines 421-480: `$response->getResponseCode()` on
    `CreateResponseV1`/`DeleteStreamResponseV1`/... — does not exist
    (see biggest obstacle; those responses throw `ProtocolException` on
    non-OK instead).
13. **[out-of-scope]** `docs/en/advanced/performance-tuning.md` lines
    170, 241-247 and `docs/en/advanced/psr-logging.md` line 355:
    `$connection->sendMessage()/readMessage()` on the high-level
    `Connection` (no such methods) — these files predate the split into
    high-level `Connection` / low-level `StreamConnection`.
14. **[out-of-scope]** `docs/en/protocol/routing-commands.md` line ~295
    `assert($response->getResponseCode()->value === 0x0001)` — same
    non-existent `getResponseCode()` on correlated responses.
15. **[out-of-scope, docs-implied]** `docs/en/api-reference/response-builder.md`
    lines 285-288 call `$connection->sendMessage(new OpenRequestV1(...))`
    (high-level `Connection` has no `sendMessage`).
16. **[out-of-scope, src]** `src/Client/Connection.php` — nice-to-have gap,
    not a bug: there is no high-level wrapper for `PartitionsRequestV1` and
    for `ResolveOffsetSpecRequestV1`; docs on this branch label those
    explicitly as low-level. Consider `Connection::partitions(string $superStream)`.

## Notes on library behaviour discovered while writing docs
- `StreamConnection::readLoop()` does not throw on timeout (returns
  silently); `StreamConnection::readMessage()` throws `TimeoutException`.
  Guides previously suggested catching `TimeoutException` around
  `readLoop()` — fixed in flow-control.md.
- `Producer::createProducer()` declares the publisher immediately and
  throws `ProtocolException` for a missing stream, so "publish to
  non-existent stream" is a declare-time error at the high level
  (async `PublishError` only occurs for stream deletion mid-life).
- Low-level publish now requires `AmqpMessageEncoder::encodeDataSection()`
  for payloads (the AMQP 1.0 Data section round-trip, #413); several
  remaining protocol docs still show raw strings (#9 above).
