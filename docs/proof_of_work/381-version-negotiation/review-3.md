# Review round 3 (convergence) — #381 ExchangeCommandVersions handshake

- **Branch**: `feature/issue-381-version-negotiation` @ `23cc283`
  (`fix(connection): advertise only multi-version commands in version negotiation`)
- **Diff vs `main`**: 23 files, +2818/−47 (source + tests + docs + PoW)
- **Reviewer**: REVIEW-CRITICAL subagent, round 3 (convergence check).
  Read-only w.r.t. `src/` and `tests/`; only this report and an append to
  `findings-review.md` were written. Nothing committed.
- **Scope**: re-verify every open round-2 finding (R2-1..R2-5) and every open
  round-1 item against the round-2 fixes; then a fresh look at `main...HEAD`.
  Special focus: R2-1 safety on the full broker matrix, whether the drift guard
  really fails on over-/under-advertising, and how the new bounded-abandon set
  interacts with the handshake timeout path.
- **Verdict**: **Approved.** No open high or medium findings. The R2-1 fix is
  real and independently reproduced on real RabbitMQ 3.11→4.3; all R2 fixes are
  present in code and pinned by tests. One new `nit` (R3-1: a documented, latent
  future risk in the drift guard's policy) and one pre-existing `nit` (R3-2).
  Neither blocks the cycle.

---

## 1. Gates (HEAD `23cc283`)

| Gate | Command | Result |
|------|---------|--------|
| Code style (PSR-12) | `composer cs` | **PASS** — 279 files |
| Static analysis (level 9) | `composer phpstan` | **PASS** — 273 files, no errors |
| Refactor preview | `composer rector` | **PASS** — 6 files, no changes |
| Full lint (cs + rector + phpstan + kb-lint) | `composer lint` | **PASS** — 12 KB entries, links resolve |
| Unit tests | `./vendor/bin/phpunit --testsuite unit` | **PASS** — 1215 tests, 8700 assertions |
| Full E2E (RabbitMQ 4.3.6 / `4-management`) | `./run-e2e.sh` | **PASS** — 147 tests, 3060 assertions |
| Full E2E (RabbitMQ 3.13.7) | `phpunit --testsuite e2e` | **PASS** — 147 tests, 3038 assertions, 3 expected skips |
| Broker matrix probe (`Connection::create()`) | 3.11 / 3.12 / 3.13 / 4.0 / 4.1 / 4.2 / 4.3 | **CREATE_OK on every broker, no broker crash** — see §3 |

Round 2 reported unit tests at `1212 tests / 8767 assertions`; HEAD adds the R2
fixes and now runs `1215 tests / 8700 assertions`. All pass. The only E2E target
in CI remains the floating `rabbitmq:4-management` (`docker-compose.yml:3`,
`.github/workflows/ci.yml:124`).

---

## 2. Round-2 findings — disposition

| ID | R2 severity | Status at `23cc283` | Evidence |
|----|-------------|---------------------|----------|
| R2-1 | high | **Fixed** | §3 — only `Publish` advertised; `CREATE_OK` on 3.11–4.3, no `function_clause` |
| R2-2 | nit | **Fixed** | Drift guard now asserts key-set equality + max equality; mutation-tested → §4 |
| R2-3 | low | **Fixed** | `StreamConnection.php:191,409,639-651`; `Connection.php:391-408`; 3 tests |
| R2-4 | nit | **Fixed** | `ConnectionHandshakeTest.php:962-1003` now asserts id `42` |
| R2-5 | low | **Fixed** | Docs updated and now accurate (see §6) |

Round-1 items still open and re-checked (unchanged, deliberately):

| ID | Status | Evidence |
|----|--------|----------|
| R1-8 (`PublishRequestV2` validation timing) | still present, out of scope (#404) | `src/Request/PublishRequestV2.php:35-40` unchanged |
| R1-9 (public `setCommandVersions()`) | still present, accepted by design | `src/StreamConnection.php:576` |
| R1-10 (two E2E tests for one command) | still present, accepted | `tests/E2E/ExchangeCommandVersionsTest.php:19,49` |

All other round-1 items (R1-1..R1-7, R1-11) remain fixed and were re-confirmed by
the round-2/round-3 evidence below.

---

## 3. R2-1 deep verification — does the fix hold on 3.11–4.3?

**The advertised set is now exactly one range: `Publish` (0x0002) v1–v2.**
`Connection::clientCommandVersions()` (`src/Client/Connection.php:459-464`)
returns a single `CommandVersion(KeyEnum::PUBLISH->value, 1, 2)`. No other
command is advertised, so the keys that killed brokers
(`CREATE_SUPER_STREAM` 0x001d, `DELETE_SUPER_STREAM` 0x001e,
`RESOLVE_OFFSET_SPEC` 0x001f) can no longer reach `parse_command_id/1`.

I reproduced the round-2 crash scenario's *fix* on real brokers (all images
present locally), calling `Connection::create()` from a fresh PHP process:

| Broker | `Connection::create()` | `supportsCommandVersion(PUBLISH,2)` | broker crash evidence |
|--------|------------------------|-------------------------------------|-----------------------|
| 3.11-management | `CREATE_OK` | no (broker reports Publish 1–1) | none |
| 3.12-management | `CREATE_OK` | no (Publish 1–1) | none |
| 3.13-management | `CREATE_OK` | yes (Publish 1–2) | none |
| 4.0-management | `CREATE_OK` | yes | none |
| 4.1-management | `CREATE_OK` | yes | none |
| 4.2-management | `CREATE_OK` | yes | none |
| 4-management (4.3.x) | `CREATE_OK` | yes | none |

No `function_clause` / `parse_command_id` in any broker's log. `0x0002` is
understood by every broker the `>= 3.11` gate admits, so the advertised key is
safe everywhere the gate sends it. **The coder's claim holds.**

Two claims in the coder's fix were independently corroborated:

1. **"Publish v2 is a 3.13 feature, not 3.11."** The matrix shows 3.11/3.12
   answer `Publish 1–1` and 3.13+ answer `Publish 1–2`. Advertising v1–v2 to
   3.11/3.12 is harmless (the broker replies 1–1 and the client stays on v1).
2. **The old full list really did depend on the one CI image.** On 3.13+ the
   broker's own reply now additionally lists 0x001d/0x001e (and 0x001f on 4.3) —
   the keys the old advertisement echoed back — confirming why a single modern
   broker was blind to R2-1.

### Residual unknown-key risk

The rule implemented is "advertise every client-initiated command the library
implements more than one version of". For `Publish` this coincides with the real
safety rule ("the key must be known to the oldest admitted broker"), because the
`Publish` key predates 3.11. It is a **proxy, not a proof**: if a future
multi-version command arrives on a key that an older admitted broker does not
parse, the drift guard (§4) would *force* advertising it and R2-1 would return
for that key. The coder discloses exactly this invariant in
`code-decision-3.md:99-107`. Classified `nit` (R3-1), not a defect in the
current change.

---

## 4. R2-2 — does the drift guard actually fail on over/under-advertising?

`testClientCommandVersionsAdvertisesOnlyImplementedMultiVersionCommands`
(`tests/Client/ConnectionHandshakeTest.php:1113-1201`) reflects over
`src/Request/*.php`, skips response-direction keys (`& 0x8000`) and the seven
handshake keys, and computes `implementedMax[key]`. It then asserts the
advertised key set equals `array_keys(array_filter($implementedMax, fn($v) => $v > 1))`,
each min is 1, and each max is **identical** to `implementedMax[key]`.

I verified it is not vacuous by mutating the real `clientCommandVersions()` in an
**isolated throwaway git worktree** (main working tree untouched, worktree
since removed) and running the guard against the mutated source:

| Mutation | Expected | Observed |
|----------|----------|----------|
| baseline (HEAD) | pass | `OK (1 test, 5 assertions)` |
| A: add a v1-only key (`CREATE` 1–1) → over-advertising | fail | `Failed asserting that two arrays are identical` → FAIL (R2-1 regression pinned) |
| B: remove `PUBLISH` → under-advertising | fail | `Failed asserting that two arrays are identical` → FAIL |
| C: overstate max (`PUBLISH` 1–3) | fail | `Failed asserting that 3 is identical to 2` → FAIL (R2-2) |

So the guard fails on all three drift directions. **R2-2 fixed and R2-1's
regression is pinned.**

---

## 5. R2-3 / R2-4 verification in code

**R2-3 (bounded, write-timeout-aware abandon set) — fixed.**

- `src/StreamConnection.php:191` `MAX_ABANDONED_CORRELATION_IDS = 64`;
  `abandonCorrelation()` (`:639-651`) evicts the oldest entry via
  `array_key_first()` before inserting. PHP arrays preserve insertion order, so
  this is oldest-first.
- `close()` clears the set (`:409`).
- `Connection::negotiateCommandVersions()` (`src/Client/Connection.php:391-408`)
  tracks a `$sent` flag: `abandonCorrelation()` is called only when
  `$sent && $e instanceof TimeoutException`. A write-side timeout leaves
  `$sent === false`, so no entry is created that no reply can ever clear.
- Pinned by `testCreateDoesNotAbandonWhenTheExchangeWriteTimesOut`
  (`ConnectionHandshakeTest.php:1005`), `testAbandonedCorrelationIdsAreClearedByClose`
  and `testAbandonedCorrelationIdsAreBounded` (`StreamConnectionTest.php:2066,2080`).

**R2-4 (abandon test pins the wire id) — fixed.** The `sendMessage` mock in
`testCreateAbandonsTheExchangeCorrelationIdWhenItTimesOut`
(`ConnectionHandshakeTest.php:962-1003`) now calls
`$request->withCorrelationId(42)` for any `CorrelationInterface`, mirroring the
real `StreamConnection::sendMessage()` (`src/StreamConnection.php:812-819`,
which assigns the id before serialization), and the test asserts
`abandonCorrelation(42)`. A wrong-id regression now fails.

### Interaction of the bounded-abandon set with the handshake timeout path

No defect found:

- The handshake can abandon **at most one** id per connection, so the 64-cap is
  never reached there and eviction cannot reintroduce the R1-2 desync from the
  handshake path.
- Ordering in `readResponse()` (`src/StreamConnection.php:1090-1137`) is
  correct: server-push dispatch first, then the abandoned-id discard **before**
  the uncorrelated return (`:1110`) and **before** parking (`:1133`). An
  abandoned reply therefore can never be parked into `pendingResponses` (and
  `readMessage()`'s pending shortcut at `:1014` never needs the check).
- After a read timeout, `negotiateCommandVersions()` returns `[]`, create()
  continues, and a late `0x801b` frame is dropped on the next read — the R1-2
  fix. If the connection is closed first, `close()` clears the id.

Residual (disclosed): a caller that abandons >64 in-flight requests could have
the oldest late reply evict and desync a later read. Not reachable from library
code (only one handshake abandon per connection). Accepted.

---

## 6. Other verifications

- **R2-5 docs** — `docs/en/api-reference/connection.md` now states only
  multi-version commands are advertised (Publish v1–v2), why, and that the step
  is best effort (accurate now that R2-1 is fixed);
  `docs/en/guide/connection-lifecycle.md` step 6, the low-level example, and
  `docs/en/protocol/connection-management-commands.md` (Deliver v1–v2 line
  removed) all agree. `docs/en/api-reference/producer.md` documents the new
  `ProtocolException` and the RabbitMQ 3.13+ requirement.
- **`README.md:261`** already marks ExchangeCommandVersions implemented; the
  `CHANGELOG.md` `[Unreleased]` entry carries the R1-5 BC note for the two new
  `ConnectionInterface` methods.
- **`Producer::sendWithFilter()`** (`src/Client/Producer.php:324-355`) selects
  v2 only for a non-null filter on a broker that negotiated Publish v2, v1 for a
  null filter, and throws `ProtocolException` (before any write / id advance) for
  a non-null filter without v2. The four branches are unit-tested
  (`tests/Client/ProducerTest.php`, new tests) and the `null`-filter path is
  exercised by the filtering E2E.
- **Behaviour change (documented)** — `sendWithFilter($msg, null)` now sends
  Publish v1 instead of v2-with-empty-filter. This matches the protocol and the
  docblock; the coder calls it out in `findings-coder.md:23-33`. Not a defect.

---

## 7. New findings (round 3)

### R3-1 — Drift guard's "multi-version ⇒ safe to advertise" rule is an invariant, not a proof

- **file:line**: `src/Client/Connection.php:459-464` (`clientCommandVersions()`)
  + `tests/Client/ConnectionHandshakeTest.php:1113-1201` (the guard).
- **Severity**: `nit` (latent future risk; disclosed by the coder in
  `code-decision-3.md:99-107`).
- **What is wrong**: the guard derives its expected set from "implemented
  version count > 1", and requires the advertised set to equal it exactly.
  Today that forces the safe `Publish` range only. A future multi-version
  command on a key unknown to the oldest admitted broker would be *forced* to be
  advertised, re-introducing the R2-1 `function_clause` crash. The rule encodes
  a coincidence as a policy.
- **What happened**: not a current defect (verified all seven brokers); recorded
  so the invariant is explicit.
- **Check that could have caught it**: an E2E matrix over broker versions in CI
  (at minimum the oldest admitted broker, 3.11) — the one thing that surfaced
  R2-1 in round 2; or a guard that requires a per-key "known since" annotation
  before a key may be advertised.

### R3-2 — Low-level doc-example step numbering is off by one vs the section headings (pre-existing)

- **file:line**: `docs/en/guide/connection-lifecycle.md:271` (`// 7.
  ExchangeCommandVersions`) vs the `### 6. ExchangeCommandVersions` heading.
- **Severity**: `nit`.
- **What is wrong**: the low-level PHP snippet counts "Create and connect" as
  step 1, so Open is "6" and Exchange is "7", while the protocol sections number
  PeerProperties=1 … Open=5 … Exchange=6. This off-by-one predates the change
  (Open was already `// 6. Open` against a `### 5. Open` heading), so it is not
  a regression from this feature.
- **Check that could have caught it**: none (prose/example review).

---

## 8. Summary by severity

| Severity | Count | IDs |
|----------|-------|-----|
| high | 0 | — |
| medium | 0 | — |
| low | 0 | — |
| nit | 2 | R3-1 (latent, disclosed), R3-2 (pre-existing) |

**There are no open high, medium or low findings.** R2-1..R2-5 are all fixed and
independently verified; the only remaining items are two `nit`s that do not
block. The cycle can proceed to lint/PR.

---

## 9. Knowledge-base candidate (for the retro step — not written here)

- **Title**: ExchangeCommandVersions — the advertised set must be a subset of
  the keys the *oldest admitted* broker knows, and the "multi-version" rule is
  only a proxy.
- **Tags**: `protocol`, `rabbitmq-stream`, `version-negotiation`, `broker-compat`.
- **Body**: RabbitMQ's stream reader runs every advertised command key through
  `rabbit_stream_core:parse_command_id/1`, which has no catch-all clause and
  crashes the connection process (`function_clause`) on an unknown key. Verified
  fixed on 3.11–4.3 after switching to advertising only Publish v1–v2. The
  "advertise commands with >1 implemented version" rule works only because
  Publish's key predates 3.11; any future multi-version command must be checked
  against the broker floor, and CI should run the handshake against at least the
  oldest admitted broker.
