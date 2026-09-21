# Review — #381 ExchangeCommandVersions handshake (round 1)

- **Branch**: `feature/issue-381-version-negotiation` @ `d2b5961`
- **Diff**: `main...HEAD` — 13 files, +988/−20
- **Reviewer**: REVIEW-CRITICAL subagent (read-only w.r.t. source)
- **Scope**: protocol wire format, version-range negotiation, fallback, error
  handling, security (untrusted server data / raw sockets / bounded loops),
  public API, test quality, docs, and the coder's own findings.
- **Verdict**: **Approve with comments.** No `high` findings; one `medium`
  (pre-3.11 fallback) and a handful of `low`/`nit`. All automated gates and the
  relevant E2E tests pass against RabbitMQ 4.

---

## 1. What the change does

`Connection::create()` gains a seventh handshake step, after `Open`: it sends
`ExchangeCommandVersions` (`0x001b`) advertising the ranges the client
implements, stores the broker's reply on `StreamConnection`, and exposes it via
`Connection::supportsCommandVersion()` / `getSupportedCommandVersions()` (also on
`ConnectionInterface`). `Producer::sendWithFilter()` now picks Publish v1 when
there is no filter value, Publish v2 when the broker negotiated it, and throws
`ProtocolException` rather than silently dropping a non-null filter value. The
step is best-effort: a timeout, a rejection, a malformed reply or a reply for a
different command falls back to the v1 baseline.

## 2. Gates

| Gate | Result |
|------|--------|
| `composer phpstan` (level 9) | PASS |
| `composer cs` (PSR-12) | PASS |
| `composer rector` | PASS |
| `composer lint` (incl. `kb-lint`) | PASS |
| `./vendor/bin/phpunit --testsuite unit` | PASS — 1199 tests, 8658 assertions |
| E2E: `ExchangeCommandVersionsTest` + `SubscribeFilterE2ETest` on RabbitMQ 4 | PASS — 4 tests, 231 assertions |

The E2E run is the strongest evidence: it exercises `Connection::create()`
against a real broker and asserts the negotiated map is non-empty and
`supportsCommandVersion(PUBLISH, 1)`/`(PUBLISH, 2)` are both true, and it
exercises the new `sendWithFilter(null)` v1 path plus the v2 filter path.

## 3. Protocol correctness

**Frame layout / endianness — correct.** Verified against `PROTOCOL.adoc`
("ExchangeCommandVersions") and the broker's own encoder
(`rabbit_stream_core:request_body/response_body`):

```
Request  0x001b: Key(16) Version(16) CorrelationId(32) Commands(32-bit count + N × Key/Min/Max 16-bit)
Response 0x801b: Key(16) Version(16) CorrelationId(32) ResponseCode(16) Commands(32-bit count + N × Key/Min/Max)
```

`ExchangeCommandVersionsRequestV1::toStreamBuffer()` writes
`key+version+correlationId` via `CommandTrait::getKeyVersion()` then
`addArray(...$commands)` (uint32 count + each `CommandVersion` = 3 × uint16).
`ExchangeCommandVersionsResponseV1::fromStreamBuffer()` mirrors it with
`getObjectArray()`. `tests/Request/ExchangeCommandVersionsRequestV1Test.php`
and `tests/Response/ExchangeCommandVersionsResponseV1Test.php` pin the exact
bytes with `pack('n'/'N')`, including the empty-array case. Big-endian is used
throughout (`pack('nnN')`).

**Request key vs `0x8000` rule — correct.** Request `0x001b`, response
`0x801b`; `validateKeyVersion()` rejects a mismatched key/version with
`ProtocolException`, and `ResponseBuilder` dispatches
`EXCHANGE_COMMAND_VERSIONS_RESPONSE` (`src/ResponseBuilder.php:94`). Server-push
frames are untouched by this diff and still use their request keys.

**Version-range semantics — correct (inclusive containment).**
`StreamConnection::supportsCommandVersion()` (`src/StreamConnection.php:585-594`)
returns `true` iff `min <= version <= max`, with both bounds inclusive; the
`min > 1` case (range starts at 2, so v1 is *not* supported) is explicitly
tested (`StreamConnectionTest::testSupportsCommandVersionUsesTheReportedRangeInclusively`).
This matches the protocol's range-containment semantics and the Go client's
`ParseCommandVersions()` (which also checks the broker range contains the
client's wanted version), rather than a brittle exact match.

**v1 baseline for unlisted commands — correct and load-bearing.** The broker's
`rabbit_stream_utils:command_versions()` does *not* list `deliver`,
`publish_confirm`, `publish_error`, `metadata_update`, `consumer_update`,
`exchange_command_versions` or `resolve_offset_spec`. The fallback
(`return $version === 1`) is therefore required for the client to keep using v1
for those, and it does. It also makes the "broker never answered" case behave as
"v1 for everything", which is exactly the acceptance criterion.

**Deliver pinned to v1 — deliberate and safe.** The client advertises
`DELIVER` 1..1; the broker only flips `deliver_version` to 2 when the client
advertises `{deliver, _, 2}` (`process_client_command_api`), so v2 delivery
stays off as intended. Advertising the full command list is harmless: the broker
ignores the request list when building its reply and only consults `deliver`.

**Fallback on rejection / no answer.** A non-OK response code is asserted during
deserialization (`assertResponseCodeOk()` → `ProtocolException`), a malformed
frame raises `DeserializationException`, and a silent broker raises
`TimeoutException`; all three are caught and logged at warning level. See
**R1-1** for the one gap (a broker that closes the connection).

## 4. Error handling

- New code throws library exceptions only — `ProtocolException` in
  `Producer::sendWithFilter()`; no bare `\Exception` (DEC-002 honoured).
- The `catch (ProtocolException | DeserializationException | TimeoutException)`
  in `negotiateCommandVersions()` is intentionally narrow; `ConnectionException`
  propagates. That is a defensible choice for a genuinely dead socket, but it is
  also what makes **R1-1** possible.
- `fromStreamBuffer()` continues to return `null` on graceful parse failure; the
  new path never bypasses `ResponseBuilder`.
- The fallback branch logs at `warning` with the exception as context, so a
  negotiation failure is observable without failing setup.

## 5. Security

- **Untrusted server data**: the response is parsed through `ReadBuffer`;
  `getObjectArray()` bounds the declared count against the remaining window and
  throws `DeserializationException` (no unbounded allocation from a hostile
  count). `CommandVersion::fromStreamBuffer()` reads fixed-width uint16s.
  `assertResponseCodeOk()` rejects non-OK codes. No `unserialize`, no eval.
- **Raw socket I/O**: unchanged by this diff; the exchange reuses the existing
  bounded `sendMessage`/`readMessage` path with a 5 s read bound. `writeAll()`
  handles partial writes.
- **Bounded loops**: the only new loop iterates the broker's command array, whose
  length was validated by `getObjectArray()`. `clientCommandVersions()` iterates
  a fixed literal. No unbounded loops.
- **Memory**: the response is capped by the negotiated frame max; the request is
  ~160 bytes.
- No credentials/secrets touched. No injection surface.

## 6. Public API

`ConnectionInterface` gains `supportsCommandVersion(KeyEnum, int): bool` and
`getSupportedCommandVersions(): array<int, CommandVersion>`. Both are
implemented by `Connection` (the only in-repo implementor), typed, and documented
in `docs/en/api-reference/connection.md` (new `supportsCommandVersion()` and
`getSupportedCommandVersions()` sections plus a "Version negotiation" subsection
in the handshake walkthrough). The CHANGELOG `[Unreleased]` entry describes the
feature. The interface addition is technically a BC break for external
implementors — recorded as **R1-5**.

Storing the map on `StreamConnection` rather than `Connection` is a sound
deviation from the issue's literal wording: `Producer` is constructed with a
`StreamConnection`, so a map on the high-level `Connection` would not reach it,
and the negotiated frame sizes already live on `StreamConnection`. The public
surface the issue asks for is still on `Connection`. I agree with
`code-decision-1.md` here.

## 7. Test quality

**New unit tests do assert the new behaviour:**

- `ConnectionHandshakeTest::testCreateExchangesCommandVersionsAndStoresThem`
  captures the requests and asserts the 6th is an
  `ExchangeCommandVersionsRequestV1`, then inspects the *advertised* ranges
  (`PUBLISH => [1,2]`, `DELIVER => [1,1]`, `CREATE => [1,1]`) and the map stored
  via `setCommandVersions()`.
- `testCreateFallsBackToV1WhenCommandVersionExchangeFails` (data-provider:
  timeout / rejection / deserialization) and
  `...RepliesWithAnotherCommand` assert an empty map is stored and
  `getSupportedCommandVersions()` is `[]`.
- `ProducerTest` covers all four selection branches: v2 when negotiated, v1 when
  the filter value is null even with v2 negotiated, v1 when v2 was not
  negotiated, and `ProtocolException` (with no write and no publishing-id
  consumed) when a non-null filter cannot be sent. `testPlainSendStaysOnV1...`
  pins `send()`.
- `StreamConnectionTest` covers the v1 default (empty map), inclusive range
  containment including a `min > 1` range, and getter round-tripping.

**E2E is meaningful**: `testCreateNegotiatesCommandVersionsAgainstTheBroker`
drives the real handshake, asserts the map is non-empty, every key/range is
sane, and Publish v1+v2 are supported. It is not a tautology.

**Missing edge cases** (R1-11): empty command list at the `Connection` level,
duplicate/overlapping keys (last-wins), `min > max`, and the late-reply desync.
Minor, not blocking.

## 8. Drift check — `clientCommandVersions()` vs what the client implements

Compared the hand-kept list (`src/Client/Connection.php:378-411`) against every
`src/Request/*` `getKey()` and against `KeyEnum`:

- The list covers all post-handshake client-initiated commands and the
  server-initiated ones the library parses (publish confirm/error, deliver,
  metadata update, consumer update, stream stats, super-stream, route,
  partitions, resolve-offset-spec, exchange-command-versions).
- It omits `PEER_PROPERTIES`, `SASL_HANDSHAKE`, `SASL_AUTHENTICATE`, `TUNE`,
  `OPEN`, `CLOSE`, `HEARTBEAT`. These are handshake/connection commands; the
  broker's own `command_versions()` omits them too, and the reference Go client
  advertises only Publish. Omitting them has no functional effect (the v1
  baseline covers them).
- The list includes commands the broker's map omits (`deliver`,
  `publish_confirm`, `publish_error`, `metadata_update`, `consumer_update`,
  `exchange_command_versions`, `resolve_offset_spec`). Harmless: the broker
  ignores the request list except `{deliver, _, 2}` and replies with its own map.

**No functional drift today.** The maintainability risk (a future v2/v3 command
silently not advertised) is real and recorded as **R1-3**.

## 9. Assessment of the coder's `findings-coder.md`

| Coder item | Verdict |
|------------|---------|
| 1. Late reply abandoned (uncorrelated read) | Valid, `low` → **R1-2**. Correctly scoped; the fix is `request()`. |
| 2. Silent broker costs 5 s | Valid, `low` → **R1-4**. |
| 3. `fromArray()` missing `?? 0` | Valid but **pre-existing and out of scope** (file untouched, path not used by this diff) → **R1-7**. |
| 4. Hand-kept list drift | Valid, `low` → **R1-3**. |
| 5. Public `setCommandVersions()` | Valid, `nit`, consistent with existing seams → **R1-9**. |
| 6. `PublishRequestV2` constructor validation | **Pre-existing/out of scope** (#404) → **R1-8**. |
| 7. Two E2E tests cover the command | Valid, `nit`, both meaningful → **R1-10**. |

**Where I disagree with the coder:** item 2 / the "Rejected alternatives"
section dismisses the broker that *closes the connection on an unknown frame*
as "pre-3.11 brokers are not a supported target". The project states no minimum
broker version, the issue explicitly requires graceful fallback "when the broker
does not support the command", and the reference Go client added a version gate
for exactly this reason. This is the only `medium` finding → **R1-1**. Everything
else in the coder's analysis is accurate and honestly disclosed.

## 10. Findings

Full entries (with file:line, severity and disposition) are in
`findings-review.md`. Summary:

| Severity | Count | IDs |
|----------|-------|-----|
| high | 0 | — |
| medium | 1 | R1-1 (pre-3.11 close-on-unknown fallback) |
| low | 6 | R1-2 (late reply desync), R1-3 (hand-kept list), R1-4 (5 s timeout), R1-5 (interface BC), R1-6 (docs drift), R1-11 (missing edge tests) |
| nit | 4 | R1-7, R1-8, R1-9, R1-10 |

### Recommended before merge (non-blocking)
1. Document a supported-broker floor (or handle the server-initiated `Close`
   reply) so R1-1 is an explicit contract rather than an accidental failure.
2. Add a CHANGELOG sentence for the `ConnectionInterface` addition (R1-5).
3. Bring the two handshake guide pages and the protocol-page example in line
   with the new step and the Deliver-v1 decision (R1-6).
