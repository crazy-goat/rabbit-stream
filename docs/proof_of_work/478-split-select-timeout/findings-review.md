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

---

## Round 2 — review (review-critical)

Round 2 verification of HEAD `dd63ca5` against the round-1 findings. Full
write-up in `review-2.md`. Status: **all three lows and the nit are fixed**;
no new high/medium/low. No open high/medium/low remains.

- tests/StreamConnectionTest.php:1533 (low 1, static call-site guard) |
  **Fixed.** `testOnlySplitSelectTimeoutConvertsSecondsToMicroseconds()` reads
  `src/StreamConnection.php`, blanks comments via `codeWithoutComments()` (:1588,
  `token_get_all()`, line numbers preserved), locates `splitSelectTimeout()` by
  reflection (:1537-1539) and fails if
  `~[*/]\s*(?:1_000_000|1000000|1e6)(?![0-9_])~i` (:1547) matches outside the
  helper. Positive control `assertGreaterThanOrEqual(1, $helperMatches)`
  (:1571-1575) prevents a no-op. Independently verified (no source edits): the
  actual helper line matches once, a synthetic inlined `* 1_000_000` line matches
  once, and docblock prose matches zero times; current source has zero offenders.
- src/StreamConnection.php:1709 (low 2, readBytes precondition) | **Fixed.**
  Call site now passes `max(0.0, $remainingTime)` with the rationale in the
  comment at :1704-1708. `readTimeout()` (:1752-1765) still returns `true` or
  throws and never returns `false`, so the clamp is defensive and
  behaviour-preserving on every reachable path.
- tests/StreamConnectionTest.php:1518/1520 (low 3, assertion volume) | **Fixed.**
  A single `assertSame([], $violations)` at :1518 plus the non-vacuity
  `assertSame(5096, $checked)` at :1520 replace the per-iteration asserts; the
  sweep was trimmed from 100,000 to 5,000 values at :1508 while keeping the six
  edge cases (:1501) and the 90-value near-1.0 sweep (:1514). Measured: the two
  round-2 tests run in ~0.04 s / 7 assertions; the unit suite totals 8,753
  assertions (was 509,230).
- tests/StreamConnectionTest.php:1509 (nit, global MT seeding) | **Fixed.**
  `mt_srand()`/`mt_rand()` removed (grep: 0 hits); fractions come from
  `crc32((string) $i) / 4_294_967_296.0`, which is deterministic and never
  touches the process-global RNG.

### Round 2 gate results

- `composer cs` — passed (279 files, 0 violations).
- `composer phpstan` (level 9) — passed (273 files, 0 errors).
- `composer rector` (dry-run) — passed (0 changes).
- `./vendor/bin/phpunit --testsuite unit` — passed (1227 tests, 8753 assertions,
  ~9.0 s).
- E2E skipped: delta is tests + a defensive clamp with no wire /
  `stream_select()` argument change for any reachable input. Round 1 ran E2E
  green; the new platform-independent unit gate subsumes the Linux-only E2E
  signal for the class of bug under review.

### Round 2 new-issue sweep

No new high/medium/low. One informational nit (non-blocking):
`tests/StreamConnectionTest.php:1509` — `crc32()` is signed on 32-bit PHP, so the
derived fraction could be negative there; the project targets 64-bit
(`src/Buffer/ReadBuffer.php`), where `crc32()` is always non-negative and the
test is correct.

---

## Round 2 — verification (review-critical)

Format: `item | evidence | disposition`. Full detail in `review-2.md`.

- **low 1 (static gate)** | In a throwaway copy (prepended PSR-4 autoloader so
  reflection == scanned file) the gate **fails** on all six injected inline
  shapes: `* 1_000_000`, `* 1000000`, `* 1e6`, `*1E6`, `* 1_000_000.0`,
  `/ 1_000_000`, naming `src/StreamConnection.php:931`. False-positive probes
  (hex `0x100000000`, comments, bare literal, `+ 1_000_000`, const name) pass.
  Line-number fidelity exact (injection at 1505 reported as 1505). Positive
  control fires for both a neutralised regex (`~ZZZNEVERMATCH~`) and helper-body
  drift (`* self::USEC_PER_SECOND`). | **Closed.**
- **low 2 (`max(0.0, $remainingTime)`)** | 200,000-value reflection sweep: 0
  invariant violations and 0 mismatches vs the old inline arithmetic;
  `readTimeout()` returns `true` or throws, never `false`, so the reachable path
  has `$remainingTime > 0` where `max()` is the identity. The unreachable
  fall-through now yields `(0, 0)` instead of a negative pair. | **Closed.**
- **low 3 (assertion volume)** | Measured invariant test = **2 assertions /
  ~0.012 s** (was 500,480 / ~2.7 s); combined with the static gate = 7. Edge
  cases, 90-value near-1.0 boundary sweep and the `assertSame(5096, $checked)`
  non-vacuity check are retained. | **Closed.**
- **nit (global RNG)** | No `mt_srand`/`mt_rand`/bare `rand(`/`random_int`/
  `Randomizer`/`srand` anywhere under `tests/`. Fractions come from
  `crc32(...) / 4_294_967_296.0`. | **Closed.**

### New findings this round

- None. No high, medium, or low defect in `git diff main...HEAD`. Two
  informational nits only (no action): the round-2 doc says "4 assertions" where
  the invariant test measures 2 (7 across both new tests), and it cites 8,757 unit
  assertions vs 8,753 measured (PHP-version variance). Residual heuristic limits
  of the gate (`pow(10, 6)`, `10 ** 6`, exotic underscore grouping evade it) are
  accepted by design and documented.

### Local QA (HEAD `dd63ca5`, clean tree)

- `composer cs`: 279 files, 0 violations.
- `composer phpstan`: 273 files, 0 errors (level 9).
- `composer rector`: 0 changes.
- `kb-lint`: 12 entries, 0 warnings, 0 stale. `check-docs-links`: OK.
- `./vendor/bin/phpunit --testsuite unit`: 1227 tests, 8753 assertions, 9.0 s.
- `./run-e2e.sh` (Docker): **147 tests, 3059 assertions** — passed.

**Converged: no open high/medium/low findings.**
