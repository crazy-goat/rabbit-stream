# Review — Round 1 — Issue #382 (select timeout tv_usec clamp)

**Reviewer:** review-critical (deep, evidence-backed)
**Branch:** `feature/issue-382-select-timeout-clamp`
**Base:** `origin/main`
**Files in diff:** `src/StreamConnection.php`, `tests/StreamConnectionTest.php`, `tests/E2E/ReadLoopTimeoutTest.php`, plus two POW docs.

## Earlier-round findings

`docs/proof_of_work/382-select-timeout-clamp/findings-review.md` did **not exist** before this
round (round 1). The only prior artifact is `findings-coder.md`; its five "discovered" items are
all explicitly out-of-scope/pre-existing and are addressed at the end of this review. None of them
is a defect introduced by this diff.

---

## Overall verdict: **APPROVE**

The change is correct, minimal, and well-targeted. The `tv_usec >= 1_000_000` overflow that caused
`select(2)` to return `EINVAL` → `ConnectionException` on Linux is eliminated for every value of
`$remaining >= 0`, with **no behaviour change for timeouts ≤ 1 s**. The two other call sites that
share the `(sec, usec)` split pattern were correctly left untouched. Tests guard the regression on
Linux CI (E2E + unit). No wire-format, security, type, or style regressions. No new defect found.

---

## Correctness proof — the fix (src/StreamConnection.php:449-451)

Changed code:
```php
$capped = min($remaining, 1);
$selectTimeoutSec  = (int) $capped;
$selectTimeoutUsec = (int) (($capped - $selectTimeoutSec) * 1_000_000);
```
guard above it: `if ($remaining <= 0) break;` so the cap only runs for `$remaining > 0`.

**Invariant:** `$selectTimeoutUsec < 1_000_000` for all `$remaining > 0`.

- `$remaining >= 1`: `capped = 1`, `sec = (int)1 = 1`, `capped - sec = 0`, `usec = 0`. ✓
- `0 < $remaining < 1`: `capped = $remaining`, `sec = (int)$remaining = 0`
  (truncation toward zero of a value in `[0,1)`), `capped - sec = $remaining < 1`,
  `usec = (int)($remaining * 1e6) <= 999_999`. ✓
- `$remaining` exactly `1.0`: `capped = 1`, `sec = 1`, `usec = 0`. ✓

The cap removes the floating-point risk entirely: any value `>= 1` collapses to the exact pair
`(1, 0)`, and any value `< 1` yields `usec < 1e6` because the integer part is `0`.

**Empirical confirmation** (PHP script run locally, edge cases + 100 000-value brute force):

| remaining | pre-fix (sec,usec) | post-fix (sec,usec) | pre  | post |
|-----------|--------------------|---------------------|------|------|
| 0.0       | (0,0)              | (0,0)               | OK   | OK   |
| 0.5       | (0,500000)         | (0,500000)          | OK   | OK   |
| 0.9999999 | (0,999999)         | (0,999999)          | OK   | OK   |
| 1.0       | (1,0)              | (1,0)               | OK   | OK   |
| 1.5       | (1,500000)         | (1,0)               | OK   | OK   |
| **2.5**   | **(1,1500000)**    | **(1,0)**           | **EINVAL** | OK |
| 5.0       | (1,4000000)        | (1,0)               | EINVAL | OK  |
| 30.0      | (1,29000000)       | (1,0)               | EINVAL | OK  |
| 1e6       | (1,999999000000)   | (1,0)               | EINVAL | OK  |

Brute force over `remaining ∈ [0.001, 100]` step `0.001` (100 000 values): pre-fix produces
`usec >= 1_000_000` for **all** values `> 1.0` (max invalid `98_999_000`); post-fix produces
**zero** invalid values. Pre- and post-fix are byte-identical for every `remaining <= 1.0`.

**Conclusion:** fix is correct; no behaviour change for `timeout <= 1 s`; the poll sequence for a
2.5 s deadline is `select(1,0) → select(1,0) → select(0,500000)` then a deadline-check `break`,
total blocking ≈ 2.5 s (overshoot bounded by syscall overhead, not by a poll length).

## The two other sites are correctly NOT changed

- `sendFrame()` — `src/StreamConnection.php:319-320`:
  `$timeoutSec = (int) $remaining; $timeoutUsec = (int)(($remaining - $timeoutSec) * 1e6);`
  Here `sec` is the **full** integer part (no `min()` cap), so `remaining - sec` is the true
  fractional part in `[0, 1)` and `usec < 1e6` always. Correct; not touching it is right.
- `readFrame()` — `src/StreamConnection.php:636-637`: same pattern (`sec = (int) $timeout`,
  `usec = frac * 1e6`), additionally guarded by `$timeout > 0 ? ... : 0`. Correct; not touching it
  is right. (Called from `readLoop` with `timeout: 0.0`, i.e. a non-blocking poll, so even the
  `readLoop` path cannot trigger the overflow here.)

`grep` over `src/` confirms these are the only three `(sec, usec)` split sites in the codebase.

## Tests

### Unit — `tests/StreamConnectionTest.php:657-680` (`testReadLoopHandlesTimeoutLongerThanOneSecond`)
- AF_UNIX socket pair, injects client end, `readLoop(maxFrames: 1, timeout: 2.5)` with no data,
  asserts `elapsed ∈ (2.3, 3.5)` and `isConnected()` true.
- **Linux pre-fix:** `socket_select(1, 1_500_000)` returns `false` (EINVAL) → `readLoop` throws
  `ConnectionException` → the test has no `try/catch` expecting it → **test fails (red)**. Real
  guard on Linux CI. Confirmed the new test passes post-fix locally (`--filter` run: 1 test,
  3 assertions, 2.526 s).
- **macOS pre-fix:** BSD `select` clamps `tv_usec` instead of returning EINVAL; the single poll
  blocks ~2.5 s, elapsed is identical post-fix → the test **cannot distinguish** pre/post-fix on
  macOS (false-green for this specific regression). This is honestly documented in
  `code-decision-1.md` and mitigated by the E2E test on Linux CI.
- **Timing bounds:** with no data, the polls sum to exactly the deadline (each `select` blocks for
  `min(remaining, 1) <= remaining`, so it can never overshoot the deadline by more than syscall
  overhead). Lower bound 2.3 s (0.2 s cushion) and upper bound 3.5 s (1.0 s cushion for scheduler
  jitter between polls) are safe and consistent with the existing 0.3 s test's cushion convention.
- **False-green risk:** only on macOS for this regression; Linux CI catches it. Acceptable.

### E2E — `tests/E2E/ReadLoopTimeoutTest.php:72-87` (`testReadLoopReturnsAfterTimeoutLongerThanOneSecond`)
- Same 2.5 s pattern against a real broker; same `(2.3, 3.5)` bounds; sets `$this->connection` for
  `tearDown` (no `streamName`, so delete is skipped, connection is closed). Correct teardown wiring.
- This is the **cross-platform CI guard** that catches EINVAL on Linux. Not run locally (no broker),
  which is correct per the task (do not run `./run-e2e.sh`).

### Existing tests
- `testReadLoopReturnsAfterTimeout` (0.3 s) and the rest of the suite still pass — confirmed by
  `./vendor/bin/phpunit --testsuite unit`: 845 tests, 2789 assertions, only **1 risky** test
  (`testDispatchMetadataUpdateWithoutCallbackDoesNotCrash`), which is **pre-existing on
  `origin/main`** (verified via `git show origin/main:tests/StreamConnectionTest.php`) and out of
  scope for #382. Not introduced by this branch.

## Local QA results

| Command | Result |
|---------|--------|
| `composer cs` (PHPCS PSR-12) | **passed** (241 files, 0 violations) |
| `composer phpstan` (level 9) | **passed** (237 files, 0 errors) |
| `composer rector` (dry-run) | **passed** (0 changes) |
| `./vendor/bin/phpunit --testsuite unit` | **passed** (845 tests; 1 pre-existing risky, out of scope) |
| `./run-e2e.sh` | **not run** (no broker, per task) |

## High-risk areas checked

- **socket_select loop / server-push dispatch:** the change is purely in the timeout-arithmetic
  block of `readLoop`; the dispatch/`readFrame`/callback paths are untouched. Server-push handling
  is unaffected. ✓
- **Raw socket I/O / untrusted server data:** no server data flows into the changed arithmetic
  (`$remaining = $deadline - microtime(true)`, both internally derived). No new parsing. ✓
- **Wire format:** no protocol bytes changed; this is a local syscall-argument fix only. ✓
- **Types (PHPStan 9):** `min(float,int)` → `float`, `(int)` casts → `int`; clean. ✓
- **PSR-12 / style:** PHPCS clean; new comment uses `//` line comments consistent with the file. ✓
- **Backward compatibility:** no public API change; `readLoop`/`sendFrame`/`readFrame` signatures
  unchanged. ✓
- **Concurrency / ordering / idempotency:** N/A — single-threaded select loop; the fix does not
  alter ordering of dispatch or deadline checks. ✓

## Out-of-scope items raised in findings-coder.md (confirmed, not introduced by this diff)

1. `tests/StreamConnectionTest.php:567` risky no-assertion test — **pre-existing on `origin/main`**,
   out of scope, not introduced here.
2. `sendFrame()` partial-write ignore (#389) — pre-existing, tracked separately, out of scope.
3. `readMessage()` correlation-id not validated (#387) — pre-existing, tracked separately, out of
   scope.
4. `readFrame()` single 30 s select — by design for request/response, out of scope.
5. Suggested `splitSelectTimeout()` helper to prevent recurrence — improvement, not a defect; the
   coder deliberately kept the diff minimal per the issue. Reasonable tradeoff (see residual risks).

## Residual risks (not blockers)

- The unit regression test is **false-green on macOS** for this specific EINVAL regression (BSD
  `select` clamps `tv_usec` instead of erroring). Mitigated by the E2E test on Linux CI. If a
  macOS-only contributor reverts the fix, only Linux CI catches it. The coder's rejected
  helper-with-direct-arithmetic-unit-test (findings-coder item 5) would have made the
  `usec < 1e6` invariant testable on every platform; revisiting it in a follow-up would harden
  against recurrence in the two sibling sites.
- Timing bounds rely on CI scheduler latency staying within the 1.0 s upper cushion; bounded and
  consistent with existing conventions.
