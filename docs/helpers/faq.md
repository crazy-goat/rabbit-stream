# FAQ — Recurring Pitfalls and Their Solutions

**How to read this file:** load the tag index below, pick the tags that match
the files in your diff, then read only those `###` entries. Do not read the
whole file.

**Who writes here:** only the retro step. Implementation and review
subagents *propose* candidate entries in their report — they never append
(see [README.md](README.md)).

## Tag index

<!-- kb-index:start -->
- `ci` — FAQ-018
- `conventions` — FAQ-008, FAQ-009
- `credit` — FAQ-010
- `docblocks` — FAQ-008
- `documentation` — FAQ-007, FAQ-013
- `e2e` — FAQ-001, FAQ-002, FAQ-004, FAQ-011, FAQ-013, FAQ-017
- `exceptions` — FAQ-007
- `gh` — FAQ-003
- `git` — FAQ-018
- `lint` — FAQ-008
- `memory` — FAQ-014
- `offset` — FAQ-004
- `phpunit` — FAQ-012
- `protocol` — FAQ-002, FAQ-004, FAQ-005, FAQ-006, FAQ-007, FAQ-010, FAQ-011, FAQ-016
- `rector` — FAQ-008
- `security` — FAQ-016
- `single-active-consumer` — FAQ-010
- `socket` — FAQ-002, FAQ-005, FAQ-015
- `tests` — FAQ-009, FAQ-012, FAQ-014, FAQ-017
- `tls` — FAQ-017
- `tyci` — FAQ-018
<!-- kb-index:end -->

## Pitfalls

### E2E tests need the RabbitMQ Stream plugin: `run-e2e.sh` picks free ports
<!-- id=FAQ-001 date=2026-11-06 tags=e2e trigger="when touching tests/E2E or running run-e2e.sh" hits=1 status=active -->

`./run-e2e.sh` boots `mirror.gcr.io/library/rabbitmq:4-management` (ECR Public as fallback) via `docker compose`.
The stream protocol port is **5552** (not 5672 AMQP). The script picks a free
host port for each port variable that is not set: `RABBITMQ_PORT`,
`RABBITMQ_AMQP_PORT`, `RABBITMQ_MANAGEMENT_PORT` and `RABBITMQ_TLS_PORT`.
The broker must report `healthy` before the suite runs, and the script waits
for that. The ports 5552, 5672 and 15672 are only the defaults of a plain
`docker compose up -d`. "Address already in use" occurs only with a broker
that you started by hand, or with ports that you exported before
`./run-e2e.sh`. Then run `docker compose down` to stop the old broker. The
`EXIT` trap does this, but an interrupted run can leave the broker up. E2E
tests read `RABBITMQ_HOST`, `RABBITMQ_PORT` and `RABBITMQ_MANAGEMENT_PORT`,
with the defaults `127.0.0.1:5552` and `15672`. Run E2E **only** for wire-level / protocol changes; pure
serialization unit tests never need the broker.

Changed 2026-11-06 (#1073): the text names the free-port selection of
`run-e2e.sh`. The old text gave 5552 as the only port that the run uses.

### Server-push frames use the request key, not the response key
<!-- id=FAQ-002 date=2025-05-20 tags=protocol,socket,e2e trigger="when implementing PublishConfirm/PublishError/Deliver/MetadataUpdate/Heartbeat/ConsumerUpdate handlers" hits=1 status=active -->

`PublishConfirm` (`0x0003`), `PublishError` (`0x0004`), `Deliver` (`0x0008`),
`MetadataUpdate` (`0x0010`), `Heartbeat` (`0x0017`) and `ConsumerUpdate`
(`0x001a`) arrive as **server → client** frames carrying the **request** key,
*not* the `0x8000`-ORed response key. Routing them through a `0x8000`
dispatcher will never match. `readMessage()` handles them transparently
inside its `stream_select()` loop; `readLoop()` dispatches them to registered
callbacks. Heartbeat must be **echoed back immediately**. See
`AGENTS.md` → "Server-Push Frames (Async)".

### `gh issue list` caps at 30 results unless --limit is raised
<!-- id=FAQ-003 date=2025-05-20 tags=gh trigger="when searching issues for triage or duplicate detection" hits=0 status=active -->

`gh issue list` returns **at most 30** issues by default. Triaging or
duplicate-detecting across the backlog silently misses everything past page
one. Always pass an explicit `--limit` (e.g. `--limit 150`, max 1000), for
both `--state open` and `--state closed`. For keyword search prefer
`gh search issues --repo crazy-goat/rabbit-stream --limit 50 "…"`.

### `OffsetSpec::timestamp()` is chunk-granular, not per-message
<!-- id=FAQ-004 date=2026-08-17 tags=protocol,offset,e2e trigger="when asserting on OffsetSpec::timestamp(), Message::getTimestamp(), or writing a test that needs two batches in distinct chunks" hits=1 status=active -->

An Osiris chunk carries **one** timestamp in its header, and
`OsirisChunkParser` stamps every entry of the chunk with it, so
`Message::getTimestamp()` is the *chunk* write time shared by all messages in
that chunk — never a per-message time. The broker resolves
`OffsetSpec::timestamp($t)` to the first chunk whose chunk timestamp is
`>= $t` and then delivers that chunk **in full**, so messages written before
`$t` are legitimately delivered, and a tie (`$t` equal to a chunk's timestamp)
selects the *earlier* chunk.

Two consequences, both learned the hard way in #385:

- **Never derive a timestamp boundary from the client clock.** Sampling
  `(int)(microtime(true) * 1000)` can land in the same millisecond as the
  broker's chunk, and the `>=` tie then hands back the batch you meant to
  exclude. Read the stream back and take the boundary from
  `Message::getTimestamp()` instead: only broker-written values are then
  compared, which is also immune to host/container clock skew.
- **`waitForConfirms()` is itself a chunk barrier.** A `PublishConfirm` is only
  emitted after the writer has committed the chunk holding that entry, so
  anything published after the confirm returns lands in a new chunk. Two
  confirmed batches are *always* in separate chunks, even with a zero gap —
  reaching for a `usleep()` to "force a chunk roll" is treating a
  millisecond-tie bug as a timing problem, and no sleep length fixes it.

### `readLoop()` callers must pass `maxFrames` to observe callback-driven state
<!-- id=FAQ-005 date=2026-08-17 tags=socket,protocol trigger="when writing a method that loops on readLoop() waiting for a callback to change state" hits=1 status=promoted gate="tests/Client/ProducerTest.php::testWaitForConfirmsCallsReadLoopWithMaxFramesOneAndPositiveTimeout" -->

`readLoop()` never inspects application state, so `readLoop(timeout: $t)`
without `maxFrames` always blocks the full `$t` (#385). Pass `maxFrames: 1`.

### When reviewing wire-format parsers, verify the transmit path — on-disk layout ≠ wire layout
<!-- id=FAQ-006 date=2026-08-19 tags=protocol trigger="when reviewing or implementing a parser for a broker payload (chunk, frame, message, sub-batch)" hits=0 status=active -->

A broker's on-disk record layout and what it actually transmits can differ.
For Osiris user chunks the header declares the on-disk sizes of the bloom
filter and trailer sections, but Deliver frames omit **both** sections'
bytes (`select_amount_to_send(user_data, ?CHNK_USER, ...)` skips the bloom
bytes; the trailer is written only on disk with tracking deltas). Round 1 of
#399 added a fit check `header + dataLength + trailerLength <= received`
based purely on the on-disk format — it would have rejected legitimate chunks
from exactly the auto-commit / named-producer workflows the library documents,
because a nonzero `trailerLength` with no bytes behind it is legal on the
wire. The check had to be relaxed to data-section-only (`header + dataLength
<= received`). Same lesson in the other direction: `bloomSize` is
informational in Deliver frames, so validating it against received bytes
would also reject legal traffic.

Before asserting a parser guard on any reserved/informational header field,
trace the broker's send path (osiris `osiris_writer`/`osiris_log.erl`) — not
just the on-disk format docs — and pin the wire contract with a test that
carries a nonzero informational field with no bytes behind it
(`testNonzeroTrailerLengthWithoutTrailerBytesParses`).

### A `Connection` method's real `@throws` is asserted during response deserialization
<!-- id=FAQ-007 date=2026-09-12 tags=exceptions,protocol,documentation trigger="when documenting or changing a Connection method that sends a request and reads a correlated response" hits=0 status=active -->

Non-OK broker response codes are asserted inside
`SimpleCorrelatedResponseV1::fromStreamBuffer()` (via
`CommandTrait::assertResponseCodeOk()`), so they surface as a `ProtocolException`
**before** the method's own `instanceof` check can run. "stream already exists"
and invalid SASL credentials follow this path; the explicit
`UnexpectedResponseException::create(...)` guards only catch a genuinely
different response *type*. `QueryOffset` is the exception: since #467
`QueryOffsetResponseV1::fromStreamBuffer()` intercepts the normal `NO_OFFSET`
(`0x13`) answer *before* the assertion and returns a response whose offset is
`null`, so "no offset stored" is **not** a `ProtocolException` (all other
non-OK codes still are). `readMessage()` adds `ConnectionException`,
`DeserializationException` and `TimeoutException` transitively. `StoreOffset` is
one-way (no response key in `KeyEnum`), so it throws none of the response-derived
types. Document the real set per method — see `src/Client/Connection.php`
(#414).

### Rector strips a bare `@return` tag — give every one a description
<!-- id=FAQ-008 date=2026-09-12 tags=conventions,docblocks,rector,lint trigger="when adding PHPDoc @return tags or a reflection-based docblock gate test" hits=0 status=active -->

`RemoveUselessReturnTagRector` (Rector `DEAD_CODE` set, active in `rector.php`)
deletes a `@return <type>` tag when the type merely repeats the native return
type and the tag has **no description**;
`RemoveVoidDocblockFromMagicMethodRector` deletes `@return void`. A reflection
gate that only asserts tag *presence* therefore passes for a tag Rector then
removes. Give every `@return` a one-line description and omit `@return void`
entirely (`tests/Client/ConnectionDocblockTest.php`, #414).

### Connection destructor sends Close, and constructor paths need typed mock replies
<!-- id=FAQ-009 date=2026-11-06 tags=tests,conventions trigger="when mocking StreamConnection under a Connection, Producer or Consumer in a unit test" hits=0 status=active -->

`Connection::__destruct()` sends a Close request when the test ends. A `$mock->expects($this->never())->method('request')` assertion counts that call, so the test fails. Record the frames instead and assert on the recorded list. See `recordingMock()` in `tests/Client/ReferenceLimitTest.php`.

A mock that answers `request()` must also return a typed reply for each request that the constructor or offset path sends: `QueryOffsetResponseV1`, `QueryPublisherSequenceResponseV1`, `PartitionsResponseV1`. A plain `CloseResponseV1` reply makes the code throw `UnexpectedResponseException`, which hides the error that the test expects.

### Single-active-consumer deactivation is a step-down handshake
<!-- id=FAQ-010 date=2026-11-06 tags=protocol,single-active-consumer,credit trigger="when changing the ConsumerUpdate handler, Credit sending or offset stores of a single active consumer" hits=0 status=active -->

Every `active=false` ConsumerUpdate from the coordinator has `stepping_down => true`. The broker activates the successor only after it gets the reply of the demoted member (`rabbit_stream_sac_coordinator.erl` `notify_csr_effect/5`, `rabbit_stream_reader.erl:2871-2876`). So a StoreOffset that the handler sends on the same socket before the reply reaches the broker first. Exception: the coordinator can presume a member down (network partition), and that member can get the notice after a successor is active. So stores must be monotonic. The broker closes the log before it sends the notice. It answers a Credit for an inactive subscription with PRECONDITION_FAILED and keeps the credit (`rabbit_stream_reader.erl:2249-2264`). Do not send Credit while inactive. See #934, PR #978.

### Tests that need unread buffered messages: publish with one sendBatch() and do not poll with readOne()
<!-- id=FAQ-011 date=2026-11-06 tags=e2e,protocol trigger="when an E2E test needs a Consumer to hold unread buffered messages (hasUnread()) at a given point" hits=0 status=active -->

A Consumer buffers whole chunks. A test holds unread messages only when the messages that it does not read are in a chunk that is already delivered. One `Producer::sendBatch()` call sends one Publish frame, and the broker normally stores one Publish frame as one chunk. This is broker behaviour, not a guarantee: the broker can also merge frames into one chunk (`tests/E2E/LargeMessageE2ETest.php`). Separate `send()` calls with a confirm wait for each message give one message per chunk (FAQ-004: a confirm is a chunk barrier). Then the consumer holds nothing unread after each read. Do not wait for `hasUnread()` in a loop of `readOne()` calls. `readOne()` pops one buffered message per call, so the loop drains each chunk before the check, and no public wait leaves messages unread. Publish the setup data with one `sendBatch()` and `waitForConfirms()`, and assert `hasUnread()` once. See #983 and https://github.com/crazy-goat/rabbit-stream/pull/986.

### PHPUnit runs `tearDown()` after a skip in `setUp()`: guard the cleanup
<!-- id=FAQ-012 date=2026-11-06 tags=tests,phpunit trigger="when writing a tearDown() that deletes files or directories, or a setUp() that calls markTestSkipped()" hits=0 status=active -->

PHPUnit calls `tearDown()` even when `setUp()` calls `markTestSkipped()`. The
after-test section of `runBare()` in `vendor/phpunit/phpunit/src/Framework/TestCase.php`
runs when the requirements are met, and it does not check that `setUp()` finished.
A `tearDown()` that deletes a directory then fails, or deletes a path that the test
did not create. Before any `glob()`, `unlink()` or `rmdir()`, check that `setUp()`
set the path: it is not empty, and it is inside `sys_get_temp_dir()`. An
`is_dir()` check alone is not sufficient: with an empty `$dir`, `glob($dir.'/*')`
reads `/*`. See the guard at the start of `tearDown()` in
`tests/Client/TlsConnectFailureTest.php`.

### A claim in an old issue that a test is missing can be wrong: check `tests/` first
<!-- id=FAQ-013 date=2026-11-06 tags=documentation,e2e trigger="when you copy a claim about missing tests or old behaviour from an issue into docs" hits=0 status=active -->

An old issue can say that a feature has no test, or that the docs describe
it. The text can be stale. Search `tests/E2E/` and `tests/` for the feature
before you copy the claim into the docs. Issue #910 said "Not tested: whether a
broker accepts a client certificate with a PLAIN login." The E2E test `testPublishAndConsumeOverMutualTls`
already covered that path, so the #910 docs fix did not copy the claim.

### `memory_get_peak_usage(true)` depends on the allocator: compare two paths
<!-- id=FAQ-014 date=2026-11-06 tags=tests,memory trigger="when a test asserts on memory_get_peak_usage()" hits=0 status=active -->

`memory_get_peak_usage(true)` depends on the allocator. On Linux CI, the same
frame read gave 96 MiB in one run and 128 MiB in the next, so the test was
flaky (#1071). Run each path in a child process and read
`memory_get_peak_usage(false)`. Compare the peak of the two paths, not a fixed
`memory_limit`. `tests/Repro/FrameSizeReadTest.php` shows the pattern. See also
#940.

### `stream_select()` fails above FD_SETSIZE: the process has too many descriptors
<!-- id=FAQ-015 date=2026-11-06 tags=socket trigger="when stream_select() reports 'You MUST recompile PHP with a larger value of FD_SETSIZE'" hits=0 status=active -->

`stream_select()` cannot wait on a descriptor at or above `FD_SETSIZE` (usually
1024). PHP then emits the warning "You MUST recompile PHP with a larger value of
FD_SETSIZE". The number of the descriptor depends on how many descriptors the
process has open, not on the library. The library reports the limit in the
`ConnectionException` message (`src/StreamConnection.php`, #938). That message
does not avoid the limit. Close the descriptors that the process does not use,
or run fewer connections in one process.

### A size that a peer declares is not a cap: check it before you allocate
<!-- id=FAQ-016 date=2026-11-06 tags=protocol,security trigger="when a parser allocates, reads or inflates a buffer whose size a broker or peer declares" hits=0 status=active -->

Check every peer-declared size against a fixed limit before you allocate or
inflate. A limit on one call does not limit the total. A `gzdecode($data, $max)`
call stops at `$max`, but each sub-batch of one chunk can still use up to its
own `$max`. Keep a budget for the total of the declared sizes of one chunk. Add
each declared size to a running total for the chunk, and check the total before
each inflate. `OsirisChunkParser` does this with a 32 MiB budget per chunk
(#915, PR #1068).

### TLS alert tests: use `openssl s_server`, and read the alert from `fread()`
<!-- id=FAQ-017 date=2026-11-06 tags=tls,tests,e2e trigger="when a test needs the TLS alert from a broker that rejects the client certificate" hits=0 status=active -->

PHP's own `ssl://` listener does not send the "certificate required" TLS alert
when the client sends no certificate. A test that needs the alert uses
`openssl s_server -Verify 1 -verify_return_error`, as
`tests/Client/TlsConnectFailureTest.php` does. macOS LibreSSL `s_server` rejects
`-accept host:port` and exits at once. The test reads the first stderr line,
and it skips with `markTestSkipped()` when the process exits.

For the reason of a rejected connect, read the alert from the `fread()`
warning (`tlsRejectionReason()` in `src/StreamConnection.php`, #957). After a
TLS 1.3 handshake, the alert text is in that warning. It can also be in the
OpenSSL error queue, so do not rely on the queue alone.

### tyci: `rebase.sh` can add a merge commit after the review
<!-- id=FAQ-018 date=2026-11-06 tags=git,tyci,ci trigger="when you write a tyci check that compares the reviewed sha with HEAD" hits=0 status=active -->

`.tyci/workflows/issue-to-merge/checks/rebase.sh` merges the default branch
into the issue branch, and it can add a merge commit. So a check that runs
after the `update` step cannot require the reviewed sha to equal HEAD.
`checks/post_review.sh` accepts the reviewed sha when it is HEAD, or when it is
a first-parent ancestor with only merge commits after it (#904).
