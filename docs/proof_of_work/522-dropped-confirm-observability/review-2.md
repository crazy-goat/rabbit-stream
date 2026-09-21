# Review 2 — #522 dropped-confirm observability (REVIEW-CRITICAL, round 2 / convergence)

- Branch: `feature/issue-522-dropped-confirm-observability`
- HEAD: `8a622ed` (`fix(producer): bound publishing-id lists in dropped-confirm warnings (#522)`)
- Round-1 review was at `b796894`; this round verifies the F1/F3 fixes and hunts for new issues in
  `git diff main...HEAD`.
- Read-only: no `src/`/`tests/` changes were made and nothing was committed.

## Verdict

**Converged — no open high/medium/low _actionable_ findings.** F1 (Medium) is fixed and tested at
all three sites; F3 (Low) is fixed; F2 (Low) is a deliberately deferred, pre-existing behaviour with
a documented rationale. F1's bound is applied consistently, the constant choice is sound (documented
rationale, single source of truth), and **no full publishing-id list reaches a PSR-3 context anywhere
in the changed paths**. The only residual note is a cosmetic QA-evidence count discrepancy in
`findings-review.md` (8746 actual vs 8750 recorded) — no code impact.

## Round-1 finding verification

### F1 — unbounded publishing-id lists in warning context (Medium) → FIXED ✅

**Constant:** `StreamConnection::MAX_LOGGED_PUBLISHING_IDS = 10` (`src/StreamConnection.php:120`),
public because `Producer` reuses it (`code-decision-2.md`). Rationale documented in the const
docblock: recognises batch shape without letting one record scale with the frame.

**Consistency — all three sites use the same constant and slice:**

| Site | Slice | Count key | Message truncation note |
|------|-------|-----------|-------------------------|
| `StreamConnection::handlePublishConfirm` `:1306-1317` | `array_slice($publishingIds, 0, self::MAX_LOGGED_PUBLISHING_IDS)` `:1315` | `publishingIdCount` `:1314` | `publishingIdTruncationNote($count)` `:1310` |
| `StreamConnection::handlePublishError` `:1333-1347` | `array_slice($errors, 0, …)` **before** `array_map` `:1342-1345` | `publishingIdCount` `:1341` | `publishingIdTruncationNote($count)` `:1337` |
| `Producer::drainPendingConfirms` `:483-509` | `array_slice($lost, 0, StreamConnection::MAX_LOGGED_PUBLISHING_IDS)` `:507` | `lostCount` `:506` | inline, same text, gated on `MAX_LOGGED_PUBLISHING_IDS` `:494-499` |

- The `PublishError` path slices the `PublishingError` objects before mapping, so no intermediate
  ~1M-element int array is built (matches `code-decision-2.md`).
- `publishingIdTruncationNote()` (`:1354-1365`) is shared by both connection sites and only appends
  the "only the first 10 are logged" suffix when `count > bound`, so the message and the always-bounded
  context agree in every case. Boundary `count == 10` → no suffix and exactly 10 ids; `count == 11` →
  suffix appears. Consistent.
- `Producer` re-implements the same suffix text rather than sharing a helper; the numeric part is
  derived from the shared constant, so the two cannot drift. Cosmetic DRY note only.

**Tested at all three sites (and only bounded values are asserted):**
- `StreamConnectionTest::testUnregisteredPublishConfirmWarningContextIsBounded` — 40 ids in, asserts
  `publishingIdCount === 40`, exactly 10 context ids equal `range(1000, 1009)`, message contains
  `only the first`.
- `StreamConnectionTest::testUnregisteredPublishErrorWarningContextIsBounded` — same for
  `PublishError` (`publishingIdCount === 40`, `range(2000, 2009)`).
- `ProducerTest::testDrainTimeoutWarningContextIsBounded` — `maxPendingConfirms: 0`, 30 publishes,
  asserts `lostCount === 30`, exactly 10 context ids, message contains `only the first`.
- Unbounded-context regression guards also assert the *registered* paths emit **no** warning
  (`StreamConnectionTest` confirm/error tests, `:881-885`, `:999-1003`).

### F2 — stranded ids never retired from `pendingConfirms` (Low) → DEFERRED (deliberate) ✅

`Producer::drainPendingConfirms` still leaves `pendingConfirms` intact on timeout and only *reads* it
(`array_keys`, `Producer.php:483`); the callback is unregistered in `close()`'s `finally`
(`:450-458`). This is pre-existing #474 behaviour preserved on purpose; #522 adds the unambiguous
`getLostConfirmCount()` signal plus the warning, and the post-close meaning of
`getPendingConfirms()` is documented (`docs/en/api-reference/producer.md`). No behaviour change, no
regression. Remains a valid optional follow-up for a separate issue; not a #522 blocker.

### F3 — doc wording "survives repeated drains" (Low) → FIXED ✅

`docs/en/api-reference/producer.md` now reads: "The counter is not reset by `close()`; `close()` is
idempotent, so it cannot double-count." `drainPendingConfirms()` is reachable only from `close()`,
which is guarded by `$this->closed` (`Producer.php:430-435`), so the drain runs at most once — the
rewording is accurate. The adjacent bullet now describes the bounded id prefix. Verified in the
`git diff main...HEAD` of `producer.md`.

## New-issue sweep (round 2)

### 1. Can any full id list reach a PSR-3 context? — NO ✅

Audited **every** `logger->{level}(...)` call in the changed paths plus the neighbouring protocol
paths:

- New/changed sites: `StreamConnection.php:1308`, `:1335`, `Producer.php:501` — all sliced (above).
- Pre-existing `StreamConnection` warnings `:1115` (correlationId), `:1134` (response class),
  `:1248` (frame key), `:1408` (stream name/code) — scalars/classes only, no id collection.
- Pre-existing `Connection` log sites `:317`, `:410`, `:419`, `:819`, `:831`, `:909` — scalars,
  class names, or `Throwable`; no id lists.
- `Producer::markStale()` (`:133-150`) is the one other place that materialises `array_keys($this->pendingConfirms)`,
  but it reports ids through `onConfirm` callbacks, **not** the logger, so it cannot blow up a log
  record. (It is unbounded as *callbacks*, but that is the existing #521 contract and out of #522's
  scope.)

Conclusion: no `PublishingIds[]`/`array_keys(pendingConfirms)` collection reaches a PSR-3 context
unbounded.

### 2. Dispatch semantics unchanged? — YES ✅

The added `return` in both handlers (`:1300`, `:1329`) is a no-op: nothing followed the `if` block
before. The tombstone guard is intact and the frame still increments `$dispatched` in `readLoop()` —
the new tests assert `assertSame(1, $dispatched, …)` for unregistered confirm/error frames and
`assertSame([], $logger->warningMessages())` for registered ones. Two-directional regression guard.

### 3. `Producer` logger param / BC — SAFE ✅

Trailing optional `?LoggerInterface $logger = null` after `?callable $onClose = null`
(`Producer.php:104`), defaulted to `NullLogger` and assigned **before** `declare()` /
`initializePublishingId()` (`:110`), so even constructor-time logging has a logger. `Connection`
threads its logger through the single construction point used by both `newProducer()` and
`createProducer()` (`Connection.php:989`; `createProducer` delegates to `newProducer`). All call
sites compile (PHPStan level 9 + full unit suite green).

### 4. `getLostConfirmCount()` semantics — CORRECT ✅

Cumulative, incremented only in `drainPendingConfirms` on a timeout (`Producer.php:485`); `drain` runs
only when `!$this->stale`, but a stale producer already had `pendingConfirms` cleared and reported by
`markStale()`, so there is nothing to lose/count. `close()` is idempotent via `$this->closed`.
Deliberately producer-only (matches `isStale()`/`getRedeclareCount()`), BC-safe for interface
implementors. Documented in `producer.md`.

### 5. Drain-timeout test determinism — PASS ✅

`testCloseGivesUpAfterDrainTimeoutWhenBrokerNeverConfirms` mocks `readLoop()` to always return `0`, so
`drainUntilZero()` can only exit via its deadline — deterministic. Re-ran the 6 relevant tests 3×:
identical `OK (6 tests, 35 assertions)` each time (~4.1 s). No flake.

### 6. Constant placement / API surface — non-soundness concern

`MAX_LOGGED_PUBLISHING_IDS` is a new **public** constant on `StreamConnection`, widening the public
API just to share an int with `Producer`. `code-decision-2.md` justifies it (smallest change that
keeps both sites from drifting; `StreamConnection` owns `DEFAULT_MAX_FRAME_SIZE`). Acceptable; noted
only as API-surface observation, not a finding. The choice of `10` is sound and documented.

## QA gates (round 2, all green)

| Gate | Result |
|------|--------|
| `composer cs` (PHPCS PSR-12) | OK — 279 files, 0 errors |
| `composer phpstan` (level 9) | OK — No errors |
| `composer rector` (dry-run) | OK |
| `./vendor/bin/phpunit --testsuite unit` | OK — **1225 tests, 8746 assertions** |
| `./run-e2e.sh` (Docker available) | OK — 147 tests, 3059 assertions |
| `php bin/kb-lint.php` | OK — 12 entries, 0 warnings, 0 stale |
| `php bin/check-docs-links.php` | OK — all relative links resolve |
| Targeted relevant tests ×3 | OK (6 tests, 35 assertions) — identical each run |

**Note:** `findings-review.md` records the round-2 unit result as `8750 assertions`; two independent
runs today report `8746`. Cosmetic proof-of-work inaccuracy, no code impact.

## Recommendation

**Approve / converged.** F1 and F3 are correctly fixed and covered by bounded-context tests; F2 is a
documented, deliberately-deferred pre-existing low item. No new actionable findings.
