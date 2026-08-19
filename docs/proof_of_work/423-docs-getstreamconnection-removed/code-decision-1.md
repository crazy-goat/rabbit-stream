# Issue #423 — code decisions

Docs-only issue: the English docs called methods that do not exist on the
class they were called on (`Connection::getStreamConnection()`,
`$connection->sendMessage()`, `$connection->registerPublisher()`,
`registerDeliverCallback()`, …). This file records the approach, rejected
alternatives, and points I was unsure about.

## Approach

1. **Read the real API first.** I read `src/Client/Connection.php`,
   `src/Client/Producer.php`, `src/Client/Consumer.php` and
   `src/StreamConnection.php`, then every request/response/VO class used in
   the docs (`SubscribeRequestV1`, `StoreOffsetRequestV1`,
   `ResolveOffsetSpecRequestV1`, `PartitionsResponseV1`,
   `StreamStatsResponseV1`, `SimpleCorrelatedResponseV1`,
   `ResponseCodeEnum`, `OffsetSpec`, `Message`, …) so every snippet I wrote
   calls methods/constructors that actually exist with real parameter names.

2. **High-level rewrites.** Stream management, super-stream creation/
   deletion/routing, publishing and consuming snippets were rewritten to the
   documented high-level API exactly as `quick-start.md` and
   `basic-producer.md` / `basic-consumer.md` do it:
   `Connection::create()`, `createStream()`, `deleteStream()`,
   `createSuperStream()`, `deleteSuperStream()`, `route()`, `getMetadata()`,
   `getStreamStats()`, `createProducer()->send()/waitForConfirms()`,
   `createConsumer()->read()/readOne()/storeOffset()/queryOffset()`,
   reference-based `$connection->storeOffset()/queryOffset()`.
   The `getStreamConnection()->sendMessage(...)->readMessage()` pattern was
   removed entirely.

3. **Low-level sections were labelled, not deleted.** Sections whose
   teaching intent is the raw protocol (``§7 Low-Level Consuming``,
   ``§5 Low-Level Publishing``, flow-control guide, Low-Level Offset
   Operations, partitions listing, protocol error codes) keep the raw
   commands but now operate on a `$stream` that is a real
   `StreamConnection`. To keep them runnable without repeating the 6-step
   handshake, I used the public, tested pattern from
   `tests/Client/ConnectionHandshakeTest.php`:

   ```php
   $stream = new StreamConnection($host, $port);
   $stream->connect();
   $connection = Connection::create(host: …, port: …, streamConnection: $stream);
   ```

   `Connection::create(streamConnection:)` runs the whole handshake
   (PeerProperties, SASL, Tune, Open) on the connection you hand it.
   Important detail: the returned `Connection` **must** be kept in a
   variable — if it is garbage-collected, its destructor closes the socket.
   All my snippets keep it referenced.

## Decisions I explicitly made

### PartitionsRequestV1 (issue: option (a) vs (b))
Option (b) (`Connection::getMetadata()` instead of Partitions) was rejected:
`getMetadata()` returns broker/leader/replica topology of *streams*, and the
task itself confirmed there is no `partitions()` high-level method. Option
(a) was taken: the partitions listing uses `PartitionsRequestV1` on a
handshaken `StreamConnection`, explicitly labelled "low-level API" in all
three places (super-streams guide "Listing Partitions",
examples/super-stream-routing.php class `verifyPartitions()`, and the
complete example in guide/super-streams.md). The high-level `$connection`
wraps the SAME `$stream`, so one socket serves both levels.

### Low-level response-code inspection is dead in this library
`CreateResponseV1`/`DeleteStreamResponseV1`/`CreateSuperStreamResponseV1`/
`DeleteSuperStreamResponseV1` extend `SimpleCorrelatedResponseV1`, which has
**no** `getResponseCode()` — it throws `ProtocolException` during
deserialization for any non-OK code. The old docs' pattern
(`$response->getResponseCode() === ResponseCodeEnum::STREAM_ALREADY_EXISTS`)
therefore could never work, so all "already exists / not exist" sections
were rewritten as try/catch on `ProtocolException` +
`$e->getResponseCode()` (high-level) instead. Relevant to the issue's
"keep the teaching intent" rule: the intent (handle STREAM_ALREADY_EXISTS /
STREAM_NOT_EXIST) is preserved, via the only mechanism the library offers.

### Flow-control credit examples
The flow-control guide is inherently low-level (readMessage dispatch,
readLoop, credit requests). I kept those examples low-level with the
handshaken-`$stream` preamble, and added an explicit note in "Initial
Credit" that the high-level `createConsumer()` manages credits internally
(`initialCredit` parameter only). Where the original mixed high-level
objects into low-level snippets (`$connection->onDeliver(...)`,
`registerDeliverCallback(...)`), they were replaced by
`$stream->registerSubscriber(1, …)` — the real method — with
`DeliverResponseV1` chunk parsing via `OsirisChunkParser::parse()` +
`AmqpMessageDecoder::decodeAll()` (deliver frames expose raw chunk bytes,
NOT decoded messages; the old `$deliver->getMessages()` did not exist).

### Single Active Consumer / consumer groups
`SubscribeRequestV1` in this client has no consumer/group reference
parameter (`groupName`, `consumerName`, `consumerReference` never existed).
I rewrote those sections to state this explicitly and show what works
(independent named consumers / `onConsumerUpdate()` on StreamConnection
with real `ConsumerUpdateResponseV1`), instead of documenting a feature the
library cannot send on the wire. This is a real (documented) library gap —
see findings-coder.md.

### Things I was unsure about
- **`Connection::create(streamConnection:)` in docs** — it is a public API
  and covered by unit tests (ConnectionHandshakeTest), so it is a
  legitimate pattern; without it the low-level snippets would have needed
  the full 6-step handshake repeated 15 times. I preferred the factory
  trick + a one-line comment over 15 copies of the handshake or over
  non-runnable snippets.
- **`$consumer->read(timeout: 0)` vs pumping with `$connection->readLoop()`**
  in the consuming loops of the two complete examples — I used
  `$consumer->read(timeout: 0.1)` only, because `read()` internally pumps
  the read loop; no manual `readLoop` needed. Not verified against a live
  broker (e2e explicitly out of scope for this task), but it matches how
  `Consumer::read()` is implemented.
- **`StreamStatsResponseV1`** — guide's `checkPartitionBalance()` used
  non-existent `getFirstOffset()/getLastOffset()`; I switched it to the
  high-level `getStreamStats()` array keys (`first_offset`, `last_offset`),
  which is what `getStreamStats()` actually returns (keys from the server:
  `first_offset`, `last_offset`, `committed_chunk_id`, `chunk_count`).
- **`StreamMetadata::getReplicaReferences()`** does not exist; the real
  method is `getReplicasReferences()` (8 hits in docs) — fixed in the two
  stream-management files, listed in findings for anything outside scope.

## What was rejected
- Adding any new method/exception to `src/`: forbidden by the issue and
  unnecessary.
- Keeping `$connection->sendMessage(...)` anywhere on the high-level
  `Connection`: it does not exist there.
- Removing the low-level teaching sections: they are the only place the
  raw protocol is explained; they were labelled instead.
- Inventing a `Partitions()` high-level wrapper: out of scope, docs-only.

## Verification performed
- `grep -rn 'getStreamConnection\|registerDeliverCallback' docs/en` → 0 hits.
- `grep` for `$connection->sendMessage|readMessage|registerPublisher|
  registerSubscriber|stop` → 0 hits in the 9 files of this issue (the only
  remaining hits anywhere in docs/en are in files where `$connection` is a
  genuine `StreamConnection` — api-reference/stream-connection.md and
  protocol/*.md — or in out-of-scope broken docs, see findings).
- All 13 complete `<?php` code blocks in the edited files pass `php -l`.
- Every constructor call and named argument in the rewritten snippets was
  cross-checked against the actual signatures in `src/`.
