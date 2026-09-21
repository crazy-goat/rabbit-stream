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
