# Review round 2 — #381 ExchangeCommandVersions handshake

- **Branch**: `feature/issue-381-version-negotiation` @ `8c30501`
  (`fix(connection): skip ExchangeCommandVersions on pre-3.11 brokers and discard abandoned replies`)
- **Diff**: `main...HEAD` — 20 files, +1985/−46
- **Reviewer**: REVIEW-CRITICAL subagent (read-only w.r.t. source; only this
  report and an append to `findings-review.md` were written; nothing committed)
- **Scope**: round-1 findings verification + fresh look at the full diff,
  with emphasis on the round-1 fixes (version gate, abandoned-correlation
  discard, drift guard, new tests).
- **Verdict**: **Request changes.** One **high** finding (R2-1): the handshake
  now advertises command keys that crash every RabbitMQ from 3.11 to 4.2 —
  exactly the range the round-1 version gate intended to support. All automated
  gates pass because CI/E2E only ever runs `rabbitmq:4-management` (currently
  4.3.6), and 4.3 is the first release that tolerates the full advertised list.

---

## 1. Gates (HEAD `8c30501`)

| Gate | Command | Result |
|------|---------|--------|
| Code style (PSR-12) | `composer cs` | **PASS** — 279 files |
| Static analysis (level 9) | `composer phpstan` | **PASS** — 273 files, no errors |
| Refactor preview | `composer rector` | **PASS** — 6 files, no changes |
| Unit tests | `./vendor/bin/phpunit --testsuite unit` | **PASS** — 1212 tests, 8767 assertions |
| E2E (full suite, RabbitMQ 4.3.6) | `phpunit --testsuite e2e` | **PASS** — 147 tests, 3059 assertions |
| Manual broker matrix (this review) | `Connection::create()` against real images | **3.10 SKIP/OK; 3.11–4.2 CRASH; 4.3 OK** — see R2-1 |

The E2E target is `rabbitmq:4-management` (`.github/workflows/ci.yml:124`,
`docker-compose.yml:3`), a floating tag that currently resolves to **4.3.6**.
No older broker is exercised anywhere in CI or the test suite.

Round 1 reported unit tests as `1199 tests, 8658 assertions`; HEAD adds the
R1 fixes and now runs `1212 tests, 8767 assertions`. Both pass.

---

## 2. Round-1 findings — disposition

| ID | Round-1 claim | Status at `8c30501` | Evidence |
|----|---------------|---------------------|----------|
| R1-1 | pre-3.11 fallback (medium) | **Fixed for <3.11 (verified on real 3.10.25); but the fix leaves a new high regression on 3.11–4.2 → R2-1** | `Connection.php:312,341`; real 3.10.25 gate skip; real 3.11 Close path verified |
| R1-2 | late reply desync (low) | **Fixed** (residual: unbounded set → R2-3; weak test → R2-4) | `StreamConnection.php:618,1064-1080`; `StreamConnectionTest.php:2033` |
| R1-3 | hand-kept list drift (low) | **Fixed** (does not catch over-advertising → R2-2, nor R2-1) | `ConnectionHandshakeTest.php:1062` |
| R1-4 | 5 s timeout (low) | **Fixed by the gate; now largely moot** (3.11–4.2 crash before the timeout) | `Connection.php:83,393` |
| R1-5 | interface BC (low) | **Fixed** — BC note in CHANGELOG | `CHANGELOG.md` `[Unreleased]` |
| R1-6 | docs drift (low) | **Fixed** | `connection-lifecycle.md:150-181,267`; `architecture-overview.md`; `connection-management-commands.md:281` |
| R1-7 | `fromArray()` `?? 0` (nit) | **Fixed** | `ExchangeCommandVersionsResponseV1.php:74` |
| R1-8 | `PublishRequestV2` validation (nit) | **Still present, deliberately out of scope (#404)** | `PublishRequestV2.php:35-40` |
| R1-9 | public `setCommandVersions()` (nit) | **Still present, accepted by design** | `StreamConnection.php:558` |
| R1-10 | duplicate E2E (nit) | **Still present, accepted** | `tests/E2E/ExchangeCommandVersionsTest.php:19,49` |
| R1-11 | missing edge tests (low) | **Fixed** (four tests added) — one is weak → R2-4 | `ConnectionHandshakeTest.php:993,1019`; `StreamConnectionTest.php:2018,2033` |

Nothing from round 1 was deleted; the original entries are intact in
`findings-review.md` and the new R2 entries are appended below as a round-2
section.

### 2.1 R1-1 deep verification (as requested)

**The server-initiated `Close` claim holds, on the real wire.** I ran a full
handshake against a real **RabbitMQ 3.10.25** (`rabbitmq:3.10-management`,
stream plugin enabled) and then force-sent `ExchangeCommandVersions` (`0x001b`):

```
full handshake done; sending 0x001b
raised: ConnectionException: Connection closed by server
connected after? false
```

That is exactly the documented path: the `Close` frame arrives with key
`0x0016`, which is in `SERVER_PUSH_KEYS` (`StreamConnection.php:100`), so
`readResponse()` (`:1051-1057`) dispatches it to `handleServerClose()`
(`:1284-1305`), which echoes the Close response and calls `close()`, then
`readResponse()` throws `ConnectionException("Connection closed by server")`.
`negotiateCommandVersions()` catches only
`ProtocolException | DeserializationException | TimeoutException`
(`Connection.php:388`), so `ConnectionException` propagates and `create()`
fails loudly rather than returning a half-dead connection. (Before
authentication the broker just drops the socket, which surfaces as
`Failed to read from socket: connection closed by peer` — also a
`ConnectionException`; either way the exchange cannot silently "succeed".)

**The version gate reads the right property, before `Open`.** A live probe
shows the PeerProperties reply carries exactly the key the gate looks for:

```
version = 3.10.25   (real 3.10)
version = 4.3.6     (compose broker)
```

`peerResponse` is read at `Connection.php:229` (step 1) and retained until the
gate at `:312` (step 7), i.e. after `Open` — so it is parsed well before use.
`brokerSupportsCommandVersions()` (`:341-372`) scans `getPeerProperty()`, skips
other keys, rejects `null`, requires numeric major+minor and returns
`major > 3 || ($major === 3 && $minor >= 11)`. Missing/garbage → `false` (skip);
unit-pinned by `testCreateOnlyExchangesCommandVersionsOnBroker311OrNewer`
(`:907`, data provider `brokerVersionGate`).

**The minimum (3.11) is correct for the *existence* of the command.** I verified
against real images: RabbitMQ **3.10.25 closes** on `0x001b`; RabbitMQ **3.11.28
answers** it with `ExchangeCommandVersionsResponseV1`. The reference version
where the command exists is therefore 3.11, matching the gate.

**Conclusion for R1-1:** the round-1 fix genuinely closes the `< 3.11` hole and
the gate is correct for what it decides. It is nevertheless **incomplete as a
"graceful fallback"**: the gate only decides *whether* to send the frame, never
*what to advertise*, and the advertised list is fatal on 3.11–4.2 (R2-1).

### 2.2 R1-2 safety analysis (as requested)

The fix is real and targeted:
- `abandonCorrelation()` (`StreamConnection.php:618`) records the id;
- `readResponse()` (`:1064-1080`) drops a deserialized `CorrelationInterface`
  whose id is in the set, **before** the uncorrelated return at `:1082` and
  **before** parking at `:1105`.
- `negotiateCommandVersions()` calls it only on `TimeoutException`
  (`Connection.php:398-402`), i.e. only when a reply may still be in flight.

Correctness / safety:
- **Cannot discard a legitimate reply today.** `StreamConnection::$correlationId`
  (`:58`) is a monotonically increasing 64-bit per-connection counter
  (`:790-791`); ids are never reused in practice, so an abandoned id cannot
  collide with a later request's id. Two in-flight requests cannot share an id
  because every `sendMessage()` assigns a fresh one.
- **Server-push dispatch is unaffected** — the check runs after the
  `SERVER_PUSH_KEYS` branch, so `handleServerClose()`/heartbeats behave as before.
- **The parked-reply shortcut is safe.** `readMessage()` returns
  `pendingResponses` without re-checking abandonment (`:986-988`), but an
  abandoned reply can never reach `pendingResponses`: it is discarded earlier in
  the same `readResponse()` pass, and the only abandon call site
  (`negotiateCommandVersions`) uses `sendMessage`/`readMessage`, which never
  parks. No reachable path parks an abandoned response.
- **BC**: `abandonCorrelation()` is added to the concrete `StreamConnection`, not
  to an interface, so it is not a BC break; it is a public wiring seam, same
  pattern as `setCommandVersions()` (R1-9).
- **Residual** (R2-3): the set is unbounded and only cleared when a matching late
  reply arrives; a write-side timeout (frame never sent) leaves an entry forever.

### 2.3 R1-3 drift guard (as requested)

`testClientCommandVersionsCoversEveryClientInitiatedRequestClass`
(`ConnectionHandshakeTest.php:1062`) reflects over `src/Request/*.php`, skips
non-`KeyVersionInterface` classes, response-direction keys (`& 0x8000`), and the
seven handshake keys, then asserts each remaining key is advertised from v1 with
`maxVersion >= getVersion()`. It **does fail** on the drift it targets: deleting
`PUBLISH` from `clientCommandVersions()` fails `assertArrayHasKey`; adding
`PublishRequestV3` (`getVersion() === 3`) without raising the advertised max
fails `assertGreaterThanOrEqual(3, 2)`. It deliberately allows extra
server-initiated keys (correct — they have no request class).

Gaps (R2-2): it only asserts `maxVersion >= getVersion()`, so a hand-edited
over-advertised max (e.g. PUBLISH 1..5) passes; and, crucially, it **endorses**
advertising `CREATE_SUPER_STREAM`/`DELETE_SUPER_STREAM`/`RESOLVE_OFFSET_SPEC` —
the keys that cause R2-1 — giving false confidence that the list is safe.

### 2.4 R1-11 test quality (as requested)

The four tests added do assert real behaviour:
- `testCreateStoresEmptyMapWhenTheBrokerRepliesWithNoCommands` (`:993`):
  valid `0x801b` with an empty list → `setCommandVersions([])`.
- `testCreateStoresLastRangeWhenTheReplyRepeatsACommandKey` (`:1019`):
  duplicate/overlapping `PUBLISH` → last range wins (pinned).
- `testSupportsCommandVersionReturnsFalseForAMalformedRange`
  (`StreamConnectionTest.php:2018`): `min > max` → false for every version.
- `testLateReplyForAnAbandonedCorrelationIdIsDiscarded` (`:2033`): a real
  socket-pair test; the stale correlation-1 frame is dropped and the correlation-2
  reply is delivered, and nothing is left parked.

The version gate did **not** make the round-1 handshake tests vacuous: the shared
`peerPropertiesResponse()` helper defaults to `'4.0.0'`, so the exchange is still
sent in every "success" test and `testCreateFallsBackToV1WhenCommandVersionExchangeFails`
(`:794`) genuinely throws on the 6th read. `testCreateAbandonsTheExchangeCorrelationIdWhenItTimesOut`
(`:958`) is the one weak case → R2-4.

---

## 3. New findings (round 2)

### R2-1 — Advertising command keys the broker does not implement crashes RabbitMQ 3.11–4.2 (and fails `create()`)

- **file:line**: `src/Client/Connection.php:441-469` (`clientCommandVersions()`),
  specifically the three entries at `:466-468`
  (`CREATE_SUPER_STREAM` `0x001d`, `DELETE_SUPER_STREAM` `0x001e`,
  `RESOLVE_OFFSET_SPEC` `0x001f`); sent from `:389`.
- **Severity**: **high** (functional break on a supported broker range + remote
  crash of the broker's stream connection process).
- **What is wrong**: The version gate decides *whether* to send
  `ExchangeCommandVersions`, but `clientCommandVersions()` unconditionally
  advertises every key the library implements, including commands that only
  exist in newer RabbitMQ. RabbitMQ's `rabbit_stream_core:parse_command_id/1`
  has no clause for an unknown key, so the broker's stream-reader process
  terminates with `error:function_clause`. Verified on real images:

  | Broker | Result of `Connection::create()` | Broker crash key |
  |--------|----------------------------------|------------------|
  | RabbitMQ 3.10.25 | SKIP (gate) → `create()` OK, map `[]` | n/a |
  | RabbitMQ 3.11.28 | `ConnectionException: connection closed by peer` | `parse_command_id(29)` = `0x001d` |
  | RabbitMQ 3.12.x | `ConnectionException` | `parse_command_id(29)` |
  | RabbitMQ 3.13.x | `ConnectionException` | `parse_command_id(31)` = `0x001f` |
  | RabbitMQ 4.0.x | `ConnectionException` | `parse_command_id(31)` |
  | RabbitMQ 4.1.x | `ConnectionException` | `parse_command_id(31)` |
  | RabbitMQ 4.2.x | `ConnectionException` | `parse_command_id(31)` |
  | RabbitMQ 4.3.6 (CI image) | OK, map negotiated | none |

  The broker log shows:
  `** Reason for termination = error:function_clause` …
  `rabbit_stream_core:parse_command_id(29) (rabbit_stream_core.erl, line 1118)`
  (3.11) / `…(31) (…line 1254)` (4.2). With repeated reconnects the reader hits
  `reached_max_restart_intensity` and the stream connection supervisor shuts
  down, so this is not merely a failed handshake — it is a client-triggered
  crash of the broker's stream subsystem.

  In other words: R1-1's "graceful fallback" is defeated exactly on the version
  range the gate newly admits (`>= 3.11`). On every one of 3.11–4.2 the
  handshake is neither graceful nor a fallback — `create()` throws, and the
  broker process dies. Only 4.3+ tolerates the list, which is why the E2E suite
  (floating `rabbitmq:4-management`, currently 4.3.6) is green.
- **Why it happens**: the broker treats the request's command list as trusted
  and dispatches each key through `parse_command_id`; a key it never defined is
  a hard error rather than "unknown/ignored". The reference Go client advertises
  only `Publish`, precisely avoiding this.
- **Fix direction**: advertise only commands that actually have a negotiated
  version (`Publish`; possibly `Deliver`), or filter the advertised keys by the
  broker version detected at `:341`, or restrict the list to keys known to the
  lowest supported broker. For v1-only commands the advertisement buys nothing
  (the v1 baseline already covers them), so dropping `0x001c–0x001f` is a safe,
  minimal fix. Update `clientCommandVersions()` to not include keys the target
  broker cannot parse.
- **Automated check that could have caught it**: an E2E matrix over broker
  versions (at least 3.11 and one of 4.0/4.2) — currently CI pins a single
  floating `4-management`. A fake-broker/unit test that asserts the advertised
  key set is a subset of the keys known to the oldest supported broker would also
  catch it. `composer cs/phpstan/rector` cannot; the R1-3 drift guard actively
  *blesses* the offending keys.

### R2-2 — Drift guard allows over-advertising (`maxVersion` above any implemented class)

- **file:line**: `tests/Client/ConnectionHandshakeTest.php:1113-1121`
- **Severity**: `nit`
- **What is wrong**: the guard asserts
  `assertGreaterThanOrEqual($class::getVersion(), $advertised[$key]->getMaxVersion())`
  but never `assertLessThanOrEqual`, so a hand-edited `maxVersion` higher than
  any request class (e.g. advertising `PUBLISH` 1..5 with only V1/V2 classes)
  passes. It also cannot detect a key that is implemented but unsafe to
  advertise (R2-1), because it has no concept of broker support.
- **Fix direction**: assert the advertised max equals the highest `getVersion()`
  among classes with that key.
- **Automated check that could have caught it**: this test, strengthened.

### R2-3 — `abandonedCorrelationIds` is unbounded and not cleared on a write-side timeout

- **file:line**: `src/StreamConnection.php:187` (storage), `:618-621`
  (`abandonCorrelation()`), `:1064-1080` (only removal)
- **Severity**: `low`
- **What is wrong**: the set only shrinks when a late reply with the abandoned id
  arrives. `negotiateCommandVersions()` also calls `abandonCorrelation()` when
  `sendMessage()` itself throws `TimeoutException` (the frame was never sent, so
  no reply will ever arrive), leaving a permanent entry. It is bounded by the
  number of handshake timeouts on one `StreamConnection` (normally one), and ids
  are not reused, so the practical impact is a tiny leak rather than a wrong
  discard. Still, nothing caps or clears it.
- **Fix direction**: only abandon on the read timeout; or cap the set / clear it
  in `close()`.
- **Automated check that could have caught it**: none; a unit test asserting the
  set does not grow on a write-side timeout would pin it.

### R2-4 — `testCreateAbandonsTheExchangeCorrelationIdWhenItTimesOut` does not assert the real correlation id

- **file:line**: `tests/Client/ConnectionHandshakeTest.php:958-991` (assertion at
  the `with(0)` on `abandonCorrelation`)
- **Severity**: `nit`
- **What is wrong**: `sendMessage()` is mocked, so the request keeps its default
  correlation id `0`; the test therefore asserts `abandonCorrelation(0)` rather
  than that the abandoned id equals the id actually assigned to the wire frame
  (which in production is `1`, assigned in `StreamConnection::sendMessage()`).
  The test pins "abandon was called" but not "with the right id"; a regression
  passing the wrong id would not be caught.
- **Fix direction**: have the `sendMessage` mock call
  `$request->withCorrelationId(1)` and assert `abandonCorrelation(1)`, or assert
  against the request's own `getCorrelationId()`.
- **Automated check that could have caught it**: this test, strengthened.

### R2-5 — Docs overstate the fallback as "no failure" on brokers where it now fails

- **file:line**: `docs/en/api-reference/connection.md:209-214` ("There is no
  exception and no failure"), `docs/en/guide/connection-lifecycle.md:150-155`
  (fallback note covers only "< 3.11" and "timeout/rejected")
- **Severity**: `low` (consequence of R2-1; fix R2-1 and this disappears)
- **What is wrong**: on RabbitMQ 3.11–4.2 `create()` throws, contradicting the
  documented contract. The docs present the gate as a complete answer to
  "broker does not support the command".
- **Fix direction**: fix R2-1, then keep the docs as written.
- **Automated check that could have caught it**: none (doc review).

---

## 4. Summary by severity

| Severity | Count | IDs |
|----------|-------|-----|
| high | 1 | R2-1 |
| medium | 0 | — |
| low | 2 | R2-3, R2-5 |
| nit | 2 | R2-2, R2-4 |

Round 1's `medium` (R1-1) is closed for `< 3.11` and verified; R2-1 is the
higher-severity consequence of the round-1 fix, discovered only by testing
against brokers other than the single CI image.

## 5. Disagreements with round 1

1. **R1-1 "fixed" is too generous.** The gate is correct for what it decides,
   and I verified the `< 3.11` path on a real 3.10.25 broker, but the round-1
   fix did not make the handshake safe on `>= 3.11`; it moved the failure from
   `< 3.11` to `3.11–4.2` and made it *worse* (broker crash). It should be
   scored **partially fixed**, with R2-1 carrying the residual.
2. **R1-3's drift guard is not a safety net for the advertised list.** It
   enforces completeness but not safety; it would happily keep the three keys
   that crash older brokers. Round 1 called it a "fix" for hand-kept-list drift;
   it is, but the review should not have implied the list is now trustworthy.
3. Round 1's E2E evidence ("PASS on RabbitMQ 4") was correct but insufficient:
   the single floating `4-management` image is the one release where the full
   list works. Any broker-version matrix would have surfaced R2-1 immediately.

## 6. Knowledge-base candidate (for the retro step — not written here)

- **Title**: ExchangeCommandVersions — never advertise command keys the target
  broker cannot parse.
- **Tags**: `protocol`, `rabbitmq-stream`, `version-negotiation`, `broker-compat`.
- **Trigger**: when sending or editing the `ExchangeCommandVersions` request /
  `Connection::clientCommandVersions()`.
- **Body**: RabbitMQ's stream reader runs each advertised command key through
  `rabbit_stream_core:parse_command_id/1`, which has no clause for an unknown key
  and crashes the connection process (`function_clause`). Advertising
  `CREATE_SUPER_STREAM`/`DELETE_SUPER_STREAM` (`0x001d`/`0x001e`) crashes 3.11–3.12
  and `RESOLVE_OFFSET_SPEC` (`0x001f`) crashes 3.13–4.2; only 4.3+ tolerates the
  full list. Advertise only commands with more than one version (the Go client
  advertises only Publish), and test the handshake across broker versions.
