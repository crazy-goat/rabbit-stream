# Review — Round 1 — Issue #478 (extract splitSelectTimeout() helper)

**Reviewer:** review-critical (deep, evidence-backed)
**Branch:** `feature/issue-478-split-select-timeout`
**Base:** `main` (HEAD `b97043e`)
**Files in diff:** `src/StreamConnection.php`, `tests/StreamConnectionTest.php`, plus two POW docs.

## Earlier-round findings

`docs/proof_of_work/478-split-select-timeout/findings-review.md` did not exist before this round.
The only prior artifact is `findings-coder.md` (its three "discovered" items are pre-existing / out
of scope and are addressed at the end). This is round 1.

---

## Overall verdict: **APPROVE**

The refactor is correct, complete, and behaviour-preserving. A single private helper
`StreamConnection::splitSelectTimeout(float $seconds): array{int,int}` now owns the
`(sec, usec)` decomposition, and **all six** `stream_select()` call sites in the file route through
it. The arithmetic is correct for every non-negative input, the `readLoop()` one-second poll cap
is correctly retained at the call site, and the `timeout <= 0 → (0,0)` non-blocking branch in
`readFrame()` is preserved. No high or medium defect. Three low observations and one nit, none of
which are logic regressions introduced by this diff.

---

## 1. Arithmetic correctness — `src/StreamConnection.php:404-410`

```php
private function splitSelectTimeout(float $seconds): array
{
    $sec  = (int) $seconds;
    $usec = (int) (($seconds - $sec) * 1_000_000);

    return [$sec, $usec];
}
```

**Invariant:** for every `$seconds >= 0`, `0 <= $sec` and `0 <= $usec < 1_000_000`.

- `(int)` on a non-negative float truncates toward zero = floor, so `$sec <= $seconds`.
- Therefore `$seconds - $sec ∈ [0, 1)` — the true fractional part.
- `frac * 1e6 ∈ [0, 1e6)`; the largest representable frac below 1 is `1 - 2^-53`, giving
  `999_999.999…`, which truncates to `999_999`. The product cannot round up to exactly `1e6`
  because the gap to `1e6` exceeds half an ULP at that magnitude.

**Empirical confirmation** (200,000-value sweep, integer parts `0..1499` + random fraction in
`[0,1)`, plus edge cases):

| input | result | valid |
|-------|--------|-------|
| `0.0` / `-0.0` | `(0, 0)` | ✓ |
| `PHP_FLOAT_EPSILON` | `(0, 0)` | ✓ |
| `0.000001` | `(0, 1)` | ✓ |
| `0.5` | `(0, 500000)` | ✓ |
| `0.999999` | `(0, 999999)` | ✓ |
| `1.0` | `(1, 0)` | ✓ |
| `1.0000001` | `(1, 0)` | ✓ |
| `2.5` | `(2, 500000)` | ✓ |
| `30.0` | `(30, 0)` | ✓ |
| `999.9999999` | `(999, 999999)` | ✓ |
| `1e6` | `(1000000, 0)` | ✓ |
| `123456.789` | `(123456, 789000)` | ✓ |

200,000-value sweep: **0** violations of `0 <= usec < 1_000_000`. (Only deliberately negative
inputs — `-0.5 → (0,-500000)`, out of contract — violate it; see §4.)

**The #382 failure is genuinely prevented.** If the helper is ever changed to the #382 shape
(`$capped = min($seconds,1); $sec = (int)$capped; $usec = (int)(($seconds-$sec)*1e6)`), then
`seconds = 2.5` yields `usec = 1_500_000`; the test's edge cases (`2.5`, `30.0`) and the sweep's
integer parts `> 1` would fail immediately. Demonstrated, not assumed.

## 2. No behaviour change at any of the six call sites

The helper's body is byte-for-byte the "full split" the four non-capped sites used before.
I compared old vs new arithmetic for 200,000 values at the full-split shape and 200,000 at the
capped shape: **0 differences, 0 invariant violations**.

| # | Site | Old | New | Non-negative guard |
|---|------|-----|-----|--------------------|
| 1 | `enableCrypto()` :342-349 | inline `(int)$remaining` / frac | `$this->splitSelectTimeout($remaining)` | `if ($remaining <= 0)` throws at :329 → `> 0` ✓ |
| 2 | `sendFrame()` :931-933 | named full split | helper | `if ($remaining <= 0)` throws at :927 → `> 0` ✓ |
| 3 | `writeAll()` :982-983 | inline full split | helper | `if ($remaining <= 0)` → `writeTimeout()` throws at :979 ✓ |
| 4 | `readLoop()` :1241-1244 | capped `min($remaining,1)` split | `splitSelectTimeout(min($remaining,1))` | `if ($remaining <= 0) break` at :1238 → `> 0` ✓ |
| 5 | `readFrame()` :1503-1517 | full split, `$timeout>0 ? … : 0` | `if ($timeout > 0) helper else (0,0)` | explicit branch ✓ |
| 6 | `readBytes()` :1704-1705 | inline full split | helper | `readTimeout()` never returns `false` (see §4) ✓ |

- **`readLoop()` cap retained (`min($remaining,1)`)** — the cap is applied to the *argument* before
  the split, exactly as the issue prescribes. The `$selectTimeoutSec = 1; $selectTimeoutUsec = 0;`
  default for `$deadline === null` is untouched. Polling stays ≤ 1 s, so `stop()`/deadline
  responsiveness is unchanged.
- **`readFrame()` special branch preserved** — `$timeout > 0` uses the full budget; `$timeout <= 0`
  (including `0.0` and negatives) passes `(0,0)` for a non-blocking poll. The old code computed
  discarded values for the `<= 0` case; the new code skips them. Observably identical.
- **`writeAll()`/`writeTimeout()`** — `writeTimeout()` has `void` return but both branches throw
  (`TimeoutException` / close + `ConnectionException`), so execution past :979 always has
  `$remaining > 0`. Preserved.
- **`enableCrypto()`** — throw at :329-336 guarantees `$remaining > 0` at the split. Preserved.
- **`sendFrame()`** — throw at :927-929 guarantees `$remaining > 0`. Preserved.

**No `stream_select()` argument value changes for any real input.** `grep` confirms no inline
`* 1_000_000` split remains anywhere in `src/` outside the helper (the only other hit,
`Producer.php:196`, is an unrelated `usleep()` backoff).

## 3. Scope: 6 sites, not 3 — correct call

The issue named three sites; the file now has six. Routing the extra three (`enableCrypto`,
`writeAll`, `readBytes`) is right: leaving them inline would have preserved the EINVAL-prone
arithmetic in half its occurrences and defeated the refactor's purpose. Verified each extra site's
argument is non-negative (§2). This is a scope *expansion within the issue's intent*, not creep.

## 4. `readBytes()` negative-value coupling — low, no regression

`src/StreamConnection.php:1700`:

```php
$remainingTime = $deadline - microtime(true);
if ($remainingTime <= 0 && $this->readTimeout($data, $length, $mustComplete)) {
    return null;
}
```

`readTimeout()` (line 1747) returns `true` for an empty frame-boundary read and otherwise closes
the connection and throws — it never returns `false`. So execution past line 1702 always has
`$remainingTime > 0`, and the helper's non-negativity precondition holds. This is a **non-local
invariant in a different method**, so the low finding is that the precondition is implicit.

Importantly, there is **no behaviour change even in the impossible fall-through**: `helper(-0.5)`
yields `(0, -500000)`, exactly the old inline expression's `((int)(-0.5), (int)((-0.5 - 0)*1e6))`.
So this is a maintainability/robustness note, not a bug. The coder documented the reasoning in
`code-decision-1.md`.

## 5. Tests

### Unit — `tests/StreamConnectionTest.php:1474-1508` (`testSplitSelectTimeoutKeepsMicrosecondsBelowOneMillion`)

- Reflection-invokes the private helper (consistent with the existing pattern in this file) and
  asserts `sec >= 0`, `usec >= 0`, `usec < 1_000_000` for `0.0`, `PHP_FLOAT_EPSILON`, `0.999999`,
  `1.0`, `2.5`, `30.0`, a seeded 100,000-value sweep, and a 90-value near-1.0 boundary sweep.
- **Is it meaningful?** Yes. It directly asserts the invariant on every platform — closing the
  macOS-false-green blind spot the #382 review identified (`select(2)` on BSD clamps `tv_usec`
  silently, so a timing test can't see the EINVAL shape). And it *would* catch the #382 shape inside
  the helper (edge cases `2.5`/`30.0` + sweep integer parts `> 1`).
- **Does it guard against the #382 class?** Yes, for the helper.
- **Does it test integration / that the call sites pass the right argument?** **No.** It only
  exercises the helper in isolation. Nothing asserts that the six call sites use it, nor that
  `readLoop()` passes `min($remaining,1)`. The readLoop cap's *purpose* (≤1 s polling) is covered by
  the existing `testReadLoopHandlesTimeoutLongerThanOneSecond` / Linux E2E timing tests, and any
  `usec >= 1e6` leak at any site would surface as EINVAL on Linux CI — but only on Linux. A future
  7th inline split site would pass every test. Reported as **low** (findings-review.md), with the
  suggestion of a static guard that `* 1_000_000` appears in `src/StreamConnection.php` only inside
  the helper.
- **Performance:** 500,480 assertions, ~2.7 s (23% of the 11.7 s unit suite). The assertion count
  dominates the suite total (509,230). Acceptable, but a single `assertSame([], $violations)` per
  sweep or a ~5k trim would keep the fuzz value at a fraction of the cost. Reported as **low**.
- **`mt_srand(478)`** seeds the global RNG; deterministic replay is good, but it leaks into later
  tests. Reported as **nit**.

### Existing tests

`testReadLoopHandlesTimeoutLongerThanOneSecond` is unchanged and passes. Full unit suite green.

## 6. Local QA results

| Command | Result |
|---------|--------|
| `composer cs` (PHPCS PSR-12) | **passed** (279 files, 0 violations) |
| `composer phpstan` (level 9) | **passed** (273 files, 0 errors) |
| `composer rector` (dry-run) | **passed** (0 changes) |
| `./vendor/bin/phpunit --testsuite unit` | **passed** (1226 tests, 509230 assertions) |
| `composer lint` (incl. kb-lint, docs links) | covered by the above + pre-push gate |
| `./run-e2e.sh` (Docker available) | **passed** (147 tests, 3059 assertions, 56.6 s) |

The E2E run executed the connection-timing tests against a real broker, including the >1 s
readLoop test that is the Linux EINVAL guard from #382.

## 7. High-risk areas checked

- **Timing / `select` paths:** all six sites verified for argument equivalence and non-negativity;
  old-vs-new diff over 400k values = 0. ✓
- **`readLoop()` poll granularity / `stop()` responsiveness:** cap kept at call site; `min()` applied
  before the split, never to one half. ✓
- **`readFrame()` non-blocking poll:** `<= 0 → (0,0)` branch preserved. ✓
- **Wire format / protocol bytes:** untouched — local syscall-argument arithmetic only. ✓
- **Types (PHPStan 9):** `int` return shape; `array{int,int}` annotation; clean. ✓
- **PSR-12 / style:** PHPCS clean; docblock matches file style. ✓
- **Public API / BC:** helper is `private`; no public signature changed. ✓
- **Rector constraint:** non-static helper (locally-called static → non-static rule) — accepted and
  documented by the coder. Not a defect.

## 8. Out-of-scope items from findings-coder.md (confirmed)

1. Stale line numbers in issue #478 — documentation only; acceptance criteria are arithmetic, not
   line numbers. No action.
2. `enableCrypto`/`writeAll`/`readBytes` were the three extra sites — now covered; no follow-up.
3. `readFrame()`'s single select can block up to the full 30 s `socketTimeout` (stop() cannot
   interrupt mid-select) — pre-existing, noted in #382, unchanged by this pure refactor. The helper
   makes a future `min(...,1)` clamp a one-line change. Out of scope.

## Summary

- high: 0
- medium: 0
- low: 3 (test↔call-site integration gap; `readBytes()` implicit non-negativity coupling; test
  assertion volume) — none is a logic regression
- nit: 1 (global MT seeding)

**Verdict: APPROVE.**
