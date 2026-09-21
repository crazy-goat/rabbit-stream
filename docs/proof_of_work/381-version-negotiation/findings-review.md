# Findings — review round 1 (#381 ExchangeCommandVersions handshake)

Reviewer: REVIEW-CRITICAL subagent. Branch `feature/issue-381-version-negotiation`
@ `d2b5961`, diff vs `main`. Read-only w.r.t. source; no earlier review rounds.

Severity legend: `high` / `medium` / `low` / `nit`.

Gates (all run on the reviewed commit):

| Gate | Command | Result |
|------|---------|--------|
| Static analysis (level 9) | `composer phpstan` | **PASS** — 273 files, no errors |
| Code style (PSR-12) | `composer cs` | **PASS** — 279 files |
| Refactor preview | `composer rector` | **PASS** — 6 files, no changes |
| Full lint (cs + rector + phpstan + kb-lint) | `composer lint` | **PASS** — 12 KB entries, links resolve |
| Unit tests | `./vendor/bin/phpunit --testsuite unit` | **PASS** — 1199 tests, 8658 assertions |
| E2E (ExchangeCommandVersions + SubscribeFilter) | `phpunit tests/E2E/ExchangeCommandVersionsTest.php tests/E2E/SubscribeFilterE2ETest.php` against RabbitMQ 4 | **PASS** — 4 tests, 231 assertions |

---

## R1-1 — Fallback is not graceful for a broker that rejects `0x001b` by closing the connection (pre-3.11)

- **file:line**: `src/Client/Connection.php:330-345` (`negotiateCommandVersions()`; catch clause at `:339`)
- **Severity**: `medium`
- **What is wrong**: `negotiateCommandVersions()` catches only `ProtocolException | DeserializationException | TimeoutException`. A broker that does not implement `ExchangeCommandVersions` and responds to an unrecognised frame by *closing* the connection is not handled. On RabbitMQ ≤ 3.10, `rabbit_stream_reader:handle_frame_post_auth/4`'s catch-all sends a `{close, RESPONSE_CODE_UNKNOWN_FRAME}` frame and sets `connection_step = close_sent`; the PHP client parses that frame (key `0x0016`), raises `ProtocolException`, swallows it, and `create()` returns a `Connection` whose socket the broker is tearing down. The failure then surfaces later as a `TimeoutException`/`ConnectionException` on the first real call. The issue's acceptance criterion "Graceful fallback when the broker does not support the command" is therefore only met for brokers that *reject-with-a-response-code* or *stay silent*, not for ones that close.
- **What happened to it (round 1 fixes)**: **Fixed.** `Connection::create()` now reads the broker `version` property from the `PeerProperties` reply and calls the new `brokerSupportsCommandVersions()` gate: on a broker below 3.11 (or a missing/unparseable version) the exchange is skipped entirely and the map is set to `[]`, so no `0x001b` frame is ever sent to a broker that may close on it. On a broker that does answer with a server-initiated `Close`, the existing `StreamConnection` server-push path already handles it (`handleServerClose()` echoes the Close response, closes the socket and `readResponse()` raises `ConnectionException("Connection closed by server")`), which is deliberately *not* caught by `negotiateCommandVersions()` — `create()` fails loudly rather than returning a half-dead connection. Covered by `ConnectionHandshakeTest::testCreateOnlyExchangesCommandVersionsOnBroker311OrNewer` (data provider: 3.10.2 / 2.9.0 / missing / garbage → skipped; 3.11 / 4.1.2 → sent).
- **Check that could have caught it**: none of the automated gates; it needs a pre-3.11 broker or a fake-broker test that replies with a `Close` frame to `0x001b`. Not covered by the current E2E suite (image is `rabbitmq:4-management`).

## R1-2 — Handshake read is uncorrelated; a late `0x801b` reply desyncs the next call

- **file:line**: `src/Client/Connection.php:335-338` (`sendMessage()` + `readMessage(self::COMMAND_VERSION_EXCHANGE_TIMEOUT)`)
- **Severity**: `low`
- **What is wrong**: The exchange uses the uncorrelated `sendMessage()`/`readMessage()` pair, and the response's correlation ID is never checked. If the 5 s read times out while the broker's reply is in flight, the stale `0x801b` frame is consumed by the next uncorrelated read (e.g. `Connection::createStream()`), which then sees an unexpected response and raises `UnexpectedResponseException`; the real reply is consumed by the call after that, cascading the desync. The infrastructure to avoid this already exists (`StreamConnection::request()` matches by correlation ID and parks foreign replies in `pendingResponses`).
- **What happened to it (round 1 fixes)**: **Fixed (real handling, not just `request()`).** Switching the exchange to `StreamConnection::request()` alone would *not* close the window — `request()` abandons its correlation id on timeout, and the next uncorrelated `readMessage()` (used by every `Connection` management method) would still return the stale frame. Instead `StreamConnection` gained `abandonCorrelation(int $correlationId)`: `negotiateCommandVersions()` calls it when the read times out, and `readResponse()` now discards (and logs) any late reply whose correlation id was abandoned, so it is neither returned nor parked. Covered by `StreamConnectionTest::testLateReplyForAnAbandonedCorrelationIdIsDiscarded` (a timed-out correlation-1 reply arrives ahead of correlation 2; the next `request()` gets correlation 2 and nothing is left parked) and `ConnectionHandshakeTest::testCreateAbandonsTheExchangeCorrelationIdWhenItTimesOut`. Residual: a timeout still costs the full 5 s once (see R1-4), but it no longer desyncs the stream.
- **Check that could have caught it**: none automated; a unit test with a `readMessage` mock that returns the reply only after the timeout would pin the desync.

## R1-3 — `clientCommandVersions()` is a hand-kept list with no drift guard

- **file:line**: `src/Client/Connection.php:378-411`
- **Severity**: `low`
- **What is wrong**: The advertised ranges are a second, hand-maintained source of truth next to the request classes' `getKey()`/`getVersion()`. A future command (or a version bump such as a `PublishRequestV3`) is advertised only if someone remembers to edit this array; nothing fails if they don't. There is no test asserting the advertised key set matches the implemented request classes.
- **What happened to it (round 1 fixes)**: **Fixed.** Added `ConnectionHandshakeTest::testClientCommandVersionsCoversEveryClientInitiatedRequestClass`, a reflection drift guard that scans `src/Request/*.php` for every `KeyVersionInterface` class, skips handshake commands (`PeerProperties`/`Sasl*`/`Tune`/`Open`/`Close`/`Heartbeat`) and response-direction keys, and asserts each remaining key is advertised from v1 with a `maxVersion` at least the class's `getVersion()`. Adding a request class (or bumping `PublishRequestV3`) without updating `clientCommandVersions()` now fails the suite. It deliberately allows the advertised set to contain extra server-initiated keys (deliver, publish confirm/error, metadata/consumer update), which have no request class.
- **Check that could have caught it**: `phpstan`/`rector` cannot; a unit test comparing the array against the request classes could.

## R1-4 — Fixed 5 s timeout paid in full on a broker that silently ignores the command

- **file:line**: `src/Client/Connection.php:83` (`COMMAND_VERSION_EXCHANGE_TIMEOUT = 5.0`), used at `:338`
- **Severity**: `low`
- **What is wrong**: A broker that accepts the frame but never answers costs a flat 5 s on every `create()`, and the value is not configurable. On the supported RabbitMQ 4 broker the reply is immediate, so this is latent.
- **What happened to it (round 1 fixes)**: **Fixed by the R1-1 version gate.** The exchange is now only sent to a broker that reports RabbitMQ >= 3.11, all of which implement `0x001b`, so the flat 5 s timeout no longer applies to a broker that would silently ignore the frame. The constant `COMMAND_VERSION_EXCHANGE_TIMEOUT` (5.0 s) remains as the bound for a *supporting* broker that nonetheless stalls; it is deliberately not exposed as a knob (no evidence a caller needs to tune it, and the gate removed the only realistic full-cost case). A non-RabbitMQ broker that advertises >= 3.11 but ignores the command would still pay it — recorded in `code-decision-2.md`.
- **Check that could have caught it**: none.

## R1-5 — `ConnectionInterface` gains two methods (BC break for external implementors)

- **file:line**: `src/Contract/ConnectionInterface.php:121-131`
- **Severity**: `low`
- **What is wrong**: Adding `supportsCommandVersion()` and `getSupportedCommandVersions()` to a published interface is a backwards-compatibility break for any external class implementing it. In-repo only `Connection` implements it, so nothing breaks here, but the CHANGELOG `[Unreleased]` entry does not call the BC break out.
- **What happened to it (round 1 fixes)**: **Fixed.** The `[Unreleased]` CHANGELOG entry now ends with an explicit **BC note** naming both added `ConnectionInterface` methods and stating that external implementors must add them.
- **Check that could have caught it**: none.

## R1-6 — Documentation drift outside `connection.md`

- **file:line**: `docs/en/guide/connection-lifecycle.md:41-56`; `docs/en/guide/architecture-overview.md:152-156`; `docs/en/protocol/connection-management-commands.md:280-281`
- **Severity**: `low`
- **What is wrong**: The two guide pages still describe the handshake as five steps and omit the new `ExchangeCommandVersions` step, while `docs/en/api-reference/connection.md` was correctly updated. The protocol page's PHP example advertises `Deliver v1–v2` (`:281`), which contradicts the deliberate "Deliver is v1-only" decision documented in `code-decision-1.md`.
- **What happened to it (round 1 fixes)**: **Fixed.** `docs/en/guide/connection-lifecycle.md` now describes a 6-step handshake (diagram, new step-6 section with the 3.11 fallback note, high-level summary, low-level example and imports), `docs/en/guide/architecture-overview.md` lists step 6 in both the sequence diagram and the numbered list, and `docs/en/protocol/connection-management-commands.md` now advertises `Deliver v1 only` (`maxVersion: 1`), matching the deliberate decision in `code-decision-1.md`.
- **Check that could have caught it**: none; manual doc review.

## R1-7 — `ExchangeCommandVersionsResponseV1::fromArray()` reads `correlationId` without `?? 0`

- **file:line**: `src/Response/ExchangeCommandVersionsResponseV1.php:74`
- **Severity**: `nit`
- **What is wrong**: `TypeCast::toInt($data['correlationId'])` raises an "Undefined array key" warning before the cast when the key is absent; `PeerPropertiesResponseV1::fromArray()` (`:64`) already uses `?? 0` for the same field.
- **What happened to it (round 1 fixes)**: **Fixed.** `ExchangeCommandVersionsResponseV1::fromArray()` now reads `TypeCast::toInt($data['correlationId'] ?? 0)`, matching `PeerPropertiesResponseV1.php:64`. The file was already part of this feature's scope (it is the response for `0x001b`), so the one-line alignment is kept here rather than deferred.
- **Check that could have caught it**: none (PHPStan level 9 does not flag undefined array keys on `mixed`).

## R1-8 — `PublishRequestV2` validates `publisherId` in `toStreamBuffer()`, not the constructor

- **file:line**: `src/Request/PublishRequestV2.php:35-40`
- **Severity**: `nit`
- **What is wrong**: The `0..255` range check runs at serialization time rather than construction, so `sendWithFilter()` fails after `ensureDeclared()`/`applyBackpressure()`. Pre-existing (#404); harmless today because the counters advance only after a successful write.
- **What happened to it (round 1 fixes)**: **Not fixed — deliberately out of scope.** Pre-existing (#404) in a file this feature does not otherwise change; moving the range check to the constructor is an unrelated refactor and is tracked by #404. The round-1 fixes leave it untouched.
- **Check that could have caught it**: none automated.

## R1-9 — `StreamConnection::setCommandVersions()` is a public mutating setter

- **file:line**: `src/StreamConnection.php:558-561`
- **Severity**: `nit`
- **What is wrong**: Any caller can overwrite the negotiated map. Not exploitable, but it widens the low-level API.
- **What happened to it (round 1 fixes)**: **Accepted by design, unchanged.** `setCommandVersions()` mirrors the existing `setMaxFrameSize()`/`setOutgoingMaxFrameSize()` wiring-seam pattern. Round 1 added a second low-level seam, `abandonCorrelation()`, for R1-2; it is documented as internal and is likewise consistent with that pattern. Making either non-public would require changing how `Connection`/`Producer` talk to `StreamConnection`.
- **Check that could have caught it**: none.

## R1-10 — Two E2E tests exercise the same command

- **file:line**: `tests/E2E/ExchangeCommandVersionsTest.php:19` (raw exchange) and `:49` (through `Connection::create()`)
- **Severity**: `nit`
- **What is wrong**: Mild duplication. Both are meaningful (one pins the raw request/response pair, one pins the handshake wiring), so this is not a defect.
- **What happened to it (round 1 fixes)**: **Accepted as-is, unchanged.** Both tests are meaningful (one pins the raw request/response pair, one pins the handshake wiring) and neither is redundant enough to justify the churn of removing one. `findings-coder.md` item 7 stays open as an optional future cleanup.
- **Check that could have caught it**: n/a.

## R1-11 — Missing unit-test edge cases

- **file:line**: `tests/Client/ConnectionHandshakeTest.php` (new tests `:678-867`)
- **Severity**: `low`
- **What is wrong**: No test at the `Connection` level for (a) a *valid* `0x801b` reply whose command list is empty, (b) duplicate/overlapping command keys in one reply (current code is last-wins: `Connection.php:358`), (c) a malformed range (`min > max`), or (d) the late-reply desync of R1-2. (a) and (c) are indirectly covered by the response/StreamConnection tests, (b) is not covered anywhere.
- **What happened to it (round 1 fixes)**: **Fixed.** Added: `ConnectionHandshakeTest::testCreateStoresEmptyMapWhenTheBrokerRepliesWithNoCommands` (valid `0x801b`, empty list → `[]`, v1 baseline); `testCreateStoresLastRangeWhenTheReplyRepeatsACommandKey` (duplicate/overlapping `PUBLISH` keys → last-wins, pinned); `StreamConnectionTest::testSupportsCommandVersionReturnsFalseForAMalformedRange` (`min > max` → not supported for any version, pinned); and the late-reply desync case (d) is covered by `StreamConnectionTest::testLateReplyForAnAbandonedCorrelationIdIsDiscarded` (see R1-2).
- **Check that could have caught it**: `phpunit --coverage` / mutation testing would highlight the untested branch; not a gate in this repo.

---

## Summary by severity

| Severity | Count | IDs |
|----------|-------|-----|
| high | 0 | — |
| medium | 1 | R1-1 |
| low | 6 | R1-2, R1-3, R1-4, R1-5, R1-6, R1-11 |
| nit | 4 | R1-7, R1-8, R1-9, R1-10 |

No `high` findings. The wire format, key/version handling, range-containment semantics and v1 fallback are correct and verified against the protocol spec and a real RabbitMQ 4 broker.

---

# Findings — review round 2 (#381 ExchangeCommandVersions handshake)

Reviewer: REVIEW-CRITICAL subagent, round 2. Branch
`feature/issue-381-version-negotiation` @ `8c30501`, diff vs `main`.
Read-only w.r.t. source. Round-1 entries above are unchanged; the entries below
are new. Full narrative and evidence in `review-2.md`.

Round-1 disposition (see `review-2.md` §2): R1-1 **fixed for < 3.11
(verified on real RabbitMQ 3.10.25) but leaves R2-1**; R1-2 **fixed** (residual
R2-3, weak test R2-4); R1-3 **fixed** (gaps R2-2); R1-4 **fixed/moot**;
R1-5, R1-6, R1-7, R1-11 **fixed**; R1-8, R1-9, R1-10 **still present,
deliberately out of scope / accepted**.

Gates at `8c30501`: `composer cs` PASS (279 files); `composer phpstan` PASS
(273 files, level 9); `composer rector` PASS; unit `1212 tests / 8767
assertions` PASS; full E2E `147 tests / 3059 assertions` PASS against
`rabbitmq:4-management` (4.3.6). Manual broker matrix (this round):
RabbitMQ 3.10.25 SKIP/OK, 3.11–4.2 **crash**, 4.3.6 OK.

## R2-1 — Advertised command keys crash RabbitMQ 3.11–4.2 and fail `create()`

- **file:line**: `src/Client/Connection.php:441-469` (`clientCommandVersions()`),
  entries at `:466-468` (`CREATE_SUPER_STREAM` `0x001d`,
  `DELETE_SUPER_STREAM` `0x001e`, `RESOLVE_OFFSET_SPEC` `0x001f`); sent at `:389`
- **Severity**: `high`
- **What is wrong**: The round-1 version gate decides only *whether* to send
  `ExchangeCommandVersions`, never *what to advertise*. `clientCommandVersions()`
  advertises every key the library implements, including commands that exist only
  in newer RabbitMQ. The broker's
  `rabbit_stream_core:parse_command_id/1` has no clause for an unknown key and
  terminates the connection process with `error:function_clause`. Verified on
  real images by running `Connection::create()`:
  - RabbitMQ **3.11.28 / 3.12.x** → `ConnectionException`, broker crash
    `parse_command_id(29)` (`0x001d`);
  - RabbitMQ **3.13.x / 4.0.x / 4.1.x / 4.2.x** → `ConnectionException`, broker
    crash `parse_command_id(31)` (`0x001f`);
  - RabbitMQ **4.3.6** (the only CI/E2E image) → OK.
  So on the entire range the gate newly admits (`>= 3.11`) there is no graceful
  fallback: `create()` throws and the broker's stream reader dies (repeated
  connects reach `reached_max_restart_intensity`). R1-1's `< 3.11` hole is closed,
  but the failure moved up to 3.11–4.2 and got worse. The reference Go client
  avoids this by advertising only `Publish`.
- **What happened to it**: new in round 2; not present before this feature
  (the command was never sent).
- **Check that could have caught it**: an E2E matrix over broker versions (3.11
  and one of 4.0/4.2), or a unit/fake-broker test asserting the advertised key
  set is a subset of the keys the oldest supported broker knows. CI pins a single
  floating `rabbitmq:4-management`. The R1-3 drift guard cannot catch it and in
  fact blesses the offending keys.

## R2-2 — Drift guard permits over-advertising

- **file:line**: `tests/Client/ConnectionHandshakeTest.php:1113-1121`
- **Severity**: `nit`
- **What is wrong**: the guard asserts `maxVersion >= getVersion()` but never the
  reverse, so an advertised max above any implemented class (e.g. `PUBLISH` 1..5
  with only V1/V2 classes) passes. It also has no notion of broker support, so it
  endorses the R2-1 keys.
- **Check that could have caught it**: the same test, asserting equality with the
  highest implemented `getVersion()`.

## R2-3 — `abandonedCorrelationIds` is unbounded and survives a write-side timeout

- **file:line**: `src/StreamConnection.php:187`, `:618-621`, `:1064-1080`
- **Severity**: `low`
- **What is wrong**: the set only shrinks when a matching late reply arrives.
  `negotiateCommandVersions()` also abandons when `sendMessage()` itself throws
  `TimeoutException` (frame never sent → no reply ever), leaving a permanent
  entry. Ids are never reused, so this is a small leak, not a wrong discard.
- **Check that could have caught it**: a unit test asserting no entry is added
  on a write-side timeout; or cap/clear the set in `close()`.

## R2-4 — Abandon test does not assert the wire correlation id

- **file:line**: `tests/Client/ConnectionHandshakeTest.php:958-991`
- **Severity**: `nit`
- **What is wrong**: `sendMessage` is mocked, so the request keeps the default
  correlation id `0` and the test asserts `abandonCorrelation(0)`. It pins that
  the call happens, not that the abandoned id matches the id assigned on the wire
  (`1` in production), so a wrong-id regression would pass.
- **Check that could have caught it**: the same test, having the `sendMessage`
  mock assign a correlation id and asserting it.

## R2-5 — Docs promise "no failure" for a step that now throws on 3.11–4.2

- **file:line**: `docs/en/api-reference/connection.md:209-214`;
  `docs/en/guide/connection-lifecycle.md:150-155`
- **Severity**: `low`
- **What is wrong**: the docs state the step is best-effort with "no exception and
  no failure", which is false on RabbitMQ 3.11–4.2 (R2-1). Consequence of R2-1.
- **Check that could have caught it**: none (doc review); fixing R2-1 restores the
  documented contract.

## Round-2 summary by severity

| Severity | Count | IDs |
|----------|-------|-----|
| high | 1 | R2-1 |
| medium | 0 | — |
| low | 2 | R2-3, R2-5 |
| nit | 2 | R2-2, R2-4 |

---

# Round 2 fixes — coder dispositions (#381)

Coder: CODER subagent. Branch `feature/issue-381-version-negotiation`, on top of
`8c30501`. Every round-2 finding has a disposition below; the round-1 entries and
the round-2 reviewer entries above are unchanged.

## R2-1 — advertised key crashes RabbitMQ 3.11–4.2 (high) — FIXED

`Connection::clientCommandVersions()` (`src/Client/Connection.php`) no longer
advertises every implemented command. It now returns exactly one range:
`Publish` v1–v2. Single-version commands (`CREATE_SUPER_STREAM` `0x001d`,
`DELETE_SUPER_STREAM` `0x001e`, `RESOLVE_OFFSET_SPEC` `0x001f`, and every other
v1-only key) are no longer sent, so a broker whose `parse_command_id/1` has no
clause for them can never be reached. `Publish` (`0x0002`) is known to every
broker the 3.11 gate admits, and a v1–v2 range is harmless on 3.11–3.12 (the
broker answers 1–1; the client stays on v1). The rule implemented is "advertise
only commands the client implements more than one version of".

Evidence (real brokers; `Connection::create()` exercised through
`tests/E2E/ExchangeCommandVersionsTest.php`) — all **PASS**:

| Broker | `create()` | negotiated map |
|--------|-----------|----------------|
| 3.11.28 | OK | non-empty (Publish 1–1) |
| 3.12.14 | OK | non-empty (Publish 1–1) |
| 3.13.7 | OK | non-empty (Publish 1–2) |
| 4.0.9 | OK | non-empty |
| 4.1.8 | OK | non-empty |
| 4.2.9 | OK | non-empty |
| 4.3.6 | OK | non-empty |

No `function_clause` and no stream-reader restart on any of them. Full E2E suite
also green: **147 tests PASS on 4.3.6, 4.2.9 and 3.13.7**.

Discovered while verifying: `Publish` v2 (per-message filter values) is a
**RabbitMQ 3.13** feature, not 3.11 — the round-2 report's "Go client advertises
only Publish" note did not imply a version, but the old docblock claimed 3.11 and
the E2E asserted v2 unconditionally. Both corrected; the E2E now asserts the
negotiated range and `supportsCommandVersion(PUBLISH, 2)` agree (true on 3.13+,
false on 3.11/3.12). Regression pinned by
`testClientCommandVersionsAdvertisesOnlyImplementedMultiVersionCommands` (drift
guard) and `testCreateExchangesCommandVersionsAndStoresThem` (advertised set is
exactly `Publish`).

## R2-2 — drift guard permits over-advertising (nit) — FIXED

`tests/Client/ConnectionHandshakeTest.php` drift guard rewritten: it derives the
highest implemented version per client-initiated request key from the
`src/Request/*.php` classes, then asserts (a) the advertised key set equals the
set of keys whose implemented max is > 1, (b) each advertised min is 1, and
(c) each advertised max **equals** (not `>=`) the highest implemented
`getVersion()`. Over-advertising (`Publish` 1..5 with only V1/V2) now fails, as
does advertising a v1-only key.

## R2-3 — unbounded abandonedCorrelationIds (low) — FIXED

Three changes in `src/StreamConnection.php`:

1. `abandonCorrelation()` is bounded by `MAX_ABANDONED_CORRELATION_IDS = 64`
   (oldest-first eviction). The set is otherwise only pruned by a matching late
   reply, so a bound is the only hard guarantee; 64 is far above the single id a
   real handshake can abandon, so eviction never happens in practice.
2. `close()` clears the set, so nothing is retained after a reset/reuse.
3. `Connection::negotiateCommandVersions()` abandons **only on a read-side
   timeout** (tracked with a `$sent` flag), so a write-side timeout that never
   put a frame on the wire cannot leak an entry.

Pinned by `testCreateDoesNotAbandonWhenTheExchangeWriteTimesOut`,
`testAbandonedCorrelationIdsAreClearedByClose`,
`testAbandonedCorrelationIdsAreBounded`.

## R2-4 — abandon test asserted id 0 (nit) — FIXED

`testCreateAbandonsTheExchangeCorrelationIdWhenItTimesOut` now has the
`sendMessage` mock assign correlation id `42` to the request (the same mutation
the real `StreamConnection::sendMessage()` performs) and asserts
`abandonCorrelation(42)`. A wrong-id regression now fails the test.

## R2-5 — docs promised "no failure" (low) — FIXED

With R2-1 fixed the documented contract holds, and the wording was made accurate
rather than left implying a full advertised list:

- `docs/en/api-reference/connection.md` — the step documents that only
  multi-version commands (currently Publish v1–v2) are advertised and why; the
  best-effort/fallback wording is retained (now true).
- `docs/en/guide/connection-lifecycle.md` — step 6 explains the
  multi-version-only advertisement.
- `docs/en/protocol/connection-management-commands.md` — example advertises only
  Publish (Deliver v1 line removed).
- `docs/en/api-reference/producer.md` — `sendWithFilter()` documents the
  `ProtocolException` and the RabbitMQ 3.13+ requirement for filtering.
- `src/Client/Connection.php` docblock corrected: Publish v2 is 3.13+, not 3.11.

## Round-1 findings — unchanged dispositions

- **R1-8** (`PublishRequestV2` range check in `toStreamBuffer()`) — **not fixed,
  deliberately out of scope (#404)**, unchanged.
- **R1-9** (`setCommandVersions()` public setter) — **accepted by design**;
  `abandonCorrelation()` remains the same documented wiring-seam pattern.
- **R1-10** (two E2E tests for the same command) — **accepted**, both tests
  remain meaningful (raw request/response vs. handshake wiring).
