# Review Findings — Issue #478 (extract splitSelectTimeout() helper)

Round 1. Format: `file:line | what is wrong | severity | what happened to it`.
Append-only for subsequent rounds.

## Findings on this diff

- tests/StreamConnectionTest.php:1474-1508 | The new unit test exercises
  `splitSelectTimeout()` in isolation only; no automated check ties the six
  `stream_select()` call sites to the helper. A future site that re-inlines the
  `(sec, usec)` arithmetic (the file grew from 3 to 6 sites since #478 was filed,
  so this is plausible) would compile, pass every test, and reintroduce exactly
  the #382 code shape. | low | Open. The arithmetic is now centralised and the
  helper is correct, so the *logic* is guarded; only the "every site routes
  through it" property is unguarded. A cheap check: a unit/static test that
  fails if `* 1_000_000` appears in `src/StreamConnection.php` outside
  `splitSelectTimeout()`. Not a defect introduced by this diff.

- src/StreamConnection.php:1704 (readBytes) | The helper's documented
  precondition is "argument must be non-negative", but `readBytes()` is the one
  site that could pass a negative `$remainingTime`: the guard at line 1700 only
  returns/throws when `readTimeout()` returns `true`, so the precondition holds
  solely because `readTimeout()` (line 1747) can never return `false`. That is a
  non-local invariant in a different method. | low | Open (documented in
  code-decision-1.md). Behaviour is nevertheless preserved: even in the
  impossible fall-through, the helper computes exactly the same negative
  `(sec, usec)` the old inline expression did (`helper(-0.5) === (0, -500000)`,
  verified), so there is no regression — only a hidden coupling. Could be
  hardened by an explicit `max($remainingTime, 0)` at the call site or a
  short-circuit rewrite of the guard.

- tests/StreamConnectionTest.php:1499-1508 | The 100,000-value sweep plus the
  90-value near-1.0 sweep produce **500,480 assertions** and ~2.7 s of runtime —
  23% of the 11.7 s unit suite and 98% of its 509,230-assertion total. The test
  is correct and meaningful; the cost is the reporting shape (a single arithmetic
  test dominates the suite's assertion count, which can obscure real behaviour
  assertions in CI dashboards). | low | Open. Suggested structure: accumulate
  violations in the loop and `assertSame([], $violations)` once, or trim the
  sweep to ~5k values; the boundary coverage comes from the explicit edge-case
  list, not from the 100k count. Acceptable as-is if the team values the fuzz
  volume.

- tests/StreamConnectionTest.php:1499 | `mt_srand(478)` seeds the process-global
  MT RNG. Deterministic replay is good, but the seed leaks into every later test
  that uses `mt_rand()` without seeding itself (order-dependent). | nit | Open.
  Use a local `\Random\Randomizer` or restore the previous seed; not a
  correctness issue for the current suite (passes in default order).

## Non-findings (verified, recorded as evidence)

- src/StreamConnection.php:404-410 (helper) | Correct for all non-negative
  inputs: `(int)` truncates a non-negative float, `$seconds - $sec ∈ [0,1)` is
  the true fractional part, so `usec = (int)(frac * 1e6) ∈ [0, 999_999]`. Max
  representable fraction `1 - 2^-53` gives `999999.999…` → `999999`. Verified by
  a 200,000-value sweep: 0 invariant violations. | — | Not a finding.
- The `#382` shape is genuinely caught: if the helper were ever changed to clamp
  `sec` with `min(...,1)` but derive `usec` from the unclamped value, the edge
  cases `2.5`/`30.0` in the test and the sweep's integer parts `0..999` would
  produce `usec >= 1_000_000` and fail. | — | Not a finding.

## Summary

- high: 0
- medium: 0
- low: 3 (test↔call-site integration gap, readBytes negative-precondition
  coupling, test assertion volume) — none introduced by the diff's logic change
- nit: 1 (global MT seeding)

---

## Round 2 — dispositions (coder)

- tests/StreamConnectionTest.php:1474 (low 1, static gate) | **Closed.**
  Added `testOnlySplitSelectTimeoutConvertsSecondsToMicroseconds()`. It reads
  `src/StreamConnection.php`, strips comments via `token_get_all()` (line
  numbers preserved), locates `splitSelectTimeout()` with reflection
  (`getStartLine()`/`getEndLine()`), and fails if
  `~[*/]\s*(?:1_000_000|1000000|1e6)(?![0-9_])~i` matches outside that range.
  Requiring a `*`/`/` immediately before the literal keeps prose and hex masks
  out of the net, so it does not flag unrelated code. A positive-control
  `assertGreaterThanOrEqual(1, $helperMatches)` stops a broken regex from
  silently passing. Verified by temporarily inlining `1.5 * 1_000_000` at
  `src/StreamConnection.php:933`: the test failed and named the line, then the
  source was restored.
- src/StreamConnection.php:1704 (low 2, readBytes precondition) | **Closed.**
  The call site now passes `max(0.0, $remainingTime)` and carries a comment
  stating that `readTimeout()` never returns false, so the non-negative
  precondition is enforced locally instead of relying on a non-local
  invariant. No observable behaviour change: the fall-through that would have
  produced a negative value is unreachable, and the reachable branch is
  unchanged.
- tests/StreamConnectionTest.php:1474 (low 3, assertion volume) | **Closed.**
  The per-iteration `assertGreaterThanOrEqual`/`assertLessThan` calls were
  replaced with a single accumulated `$violations` list and one
  `assertSame([], $violations)` plus a non-vacuity count assertion. The sweep
  was trimmed from 100,000 to 5,000 values, keeping the six explicit edge
  cases and the 90-value near-1.0 boundary sweep (5,096 checked inputs). The
  test drops from 500,480 assertions / ~2.7 s to 4 assertions / ~0.02 s.
- tests/StreamConnectionTest.php:1499 (nit, global MT seeding) | **Closed.**
  `mt_srand()`/`mt_rand()` removed. Fractional parts now come from
  `crc32((string) $i) / 4_294_967_296.0` — deterministic, reproducible, and
  it never touches the process-global RNG. Suites run in any order.

### Round 2 impact

- Unit suite: 1227 tests, 8757 assertions (was 509,230), 9.0 s (was 11.7 s).
- `composer lint` clean: PHPCS, Rector dry-run, PHPStan level 9, kb-lint, docs
  links.
