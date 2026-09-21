# Review — Round 2 — Issue #478 (extract splitSelectTimeout() helper)

**Reviewer:** review-critical (round 2)
**Branch:** `feature/issue-478-split-select-timeout`
**HEAD reviewed:** `dd63ca5` (`test(connection): guard splitSelectTimeout call sites
and trim fuzz cost (#478)`)
**Diff vs round 1:** `b97043e..dd63ca5` — `src/StreamConnection.php` (+7/-2) and
`tests/StreamConnectionTest.php` (+128/-16); the remaining delta is POW docs.
**Scope note:** the round-2 delta is **tests + one defensive clamp only — no wire
format, protocol-byte, or public-API change.** Behaviour at all six
`stream_select()` call sites is unchanged for every reachable input.

---

## Overall verdict: **APPROVE**

All three round-1 lows and the nit are **fixed** with evidence. No new
high/medium/low issue was introduced by the round-2 delta. All static gates and
the unit suite pass (E2E skipped — see §5).

**No open high, medium, or low findings remain.** One informational nit is
recorded in §4 (32-bit `crc32()`), non-blocking and out of the project's 64-bit
target.

---

## 1. Round-1 finding dispositions

| # | Round-1 finding | Severity | Status | Evidence |
|---|-----------------|----------|--------|----------|
| 1 | No automated guard ties the six `stream_select()` sites to the helper | low | **Fixed** | `tests/StreamConnectionTest.php:1533` `testOnlySplitSelectTimeoutConvertsSecondsToMicroseconds()` |
| 2 | `readBytes()` non-negative precondition was a non-local invariant | low | **Fixed** | `src/StreamConnection.php:1709` `max(0.0, $remainingTime)` |
| 3 | 500,480 assertions / ~2.7 s in one arithmetic test | low | **Fixed** | `tests/StreamConnectionTest.php:1518`, `:1520`, `:1508` |
| 4 | `mt_srand(478)` mutates process-global MT RNG | nit | **Fixed** | `tests/StreamConnectionTest.php:1509` (`crc32`) |

### low 1 — static call-site guard — FIXED

New test `testOnlySplitSelectTimeoutConvertsSecondsToMicroseconds()`
(`tests/StreamConnectionTest.php:1533`):

- Reads `src/StreamConnection.php`, blanks comments via the new private helper
  `codeWithoutComments()` (`tests/StreamConnectionTest.php:1588`) which uses
  `token_get_all()` and preserves line numbers.
- Locates the helper by reflection (`getStartLine()`/`getEndLine()`,
  `tests/StreamConnectionTest.php:1537-1539`) and flags any line matching
  `~[*/]\s*(?:1_000_000|1000000|1e6)(?![0-9_])~i`
  (`tests/StreamConnectionTest.php:1547`) outside that range.
- Positive control at `tests/StreamConnectionTest.php:1571-1575`
  (`assertGreaterThanOrEqual(1, $helperMatches)`) prevents a broken regex from
  silently passing.

**Verified the gate is not a no-op** (regex exercised in isolation, no source
files touched):

| Probe | Matches |
|-------|---------|
| Actual helper line `... - $sec) * 1_000_000);` | 1 |
| Synthetic inlined `... * 1_000_000)];` | 1 |
| Docblock prose ``0 <= tv_usec < 1_000_000`` | 0 |

Current source has **0 offenders**; the only `src/` hit is the helper itself
(`src/StreamConnection.php:407`). `grep` confirms six call sites route through
the helper (`src/StreamConnection.php:342, 931, 982, 1241, 1504, 1709`), and the
only `* 1_000_000` elsewhere is the unrelated `usleep()` backoff in
`src/Client/Producer.php:196` (different file, not scanned). Finding closed.

### low 2 — `readBytes()` precondition — FIXED

`src/StreamConnection.php:1709` now reads:

```php
[$timeoutSec, $timeoutUsec] = $this->splitSelectTimeout(max(0.0, $remainingTime));
```

with the rationale in the comment at `src/StreamConnection.php:1704-1708`. The
non-negativity precondition is now enforced locally at the call site instead of
depending on `readTimeout()`. The underlying non-local invariant still holds —
`readTimeout()` (`src/StreamConnection.php:1752-1765`) returns `true` or throws
`ConnectionException`, never `false` — so the clamp is purely defensive.
Behaviour-preserving: on the reachable path `$remainingTime > 0`, `max(0.0, …)`
is the identity; on the unreachable fall-through the old value would have been
negative (an invalid `stream_select` argument anyway). No reachable behaviour
change. Finding closed.

### low 3 — assertion volume — FIXED

`tests/StreamConnectionTest.php:1479-1499` replaces the per-iteration asserts
with a `$check` closure that appends to `$violations`; a single
`assertSame([], $violations, …)` at `tests/StreamConnectionTest.php:1518`
asserts once. A non-vacuity guard `assertSame(5096, $checked, …)` at
`tests/StreamConnectionTest.php:1520` fails if the loops do not run. Coverage is
preserved: six explicit edge cases (`:1501`), a 5,000-value sweep (`:1508`,
trimmed from 100,000) and the 90-value near-1.0 boundary sweep (`:1514`).
Measured: the two round-2 tests together run in ~0.04 s / 7 assertions; the full
unit suite now reports **8,753 assertions** (was 509,230). Finding closed.

### nit — global MT seeding — FIXED

`mt_srand()`/`mt_rand()` are gone (`grep` returns 0 hits in the test file).
Fractions now come from `crc32((string) $i) / 4_294_967_296.0`
(`tests/StreamConnectionTest.php:1509`) — deterministic, order-independent, and
it never mutates the process-global RNG. Finding closed.

---

## 2. New-issue sweep

Reviewed the entire round-2 delta for regressions:

- **Source:** the only `src/` change is the `max(0.0, $remainingTime)` clamp
  (low 2). No call-site argument, wire byte, signature, or `readLoop()` cap
  changed. `grep` confirms exactly six `splitSelectTimeout()` call sites, all
  unchanged.
- **Guard test robustness:** reflection-based helper location removes line-number
  fragility; `codeWithoutComments()` has no name collision (1 definition); the
  regex requires a `*`/`/` immediately before the literal, so prose and hex masks
  are not flagged; `preg_match_all` returning `false` is handled.
- **Sweep determinism:** `crc32` on this 64-bit runtime yields `[7222,
  4294956866]` over 200k inputs, so fractions are in `[0, 1)` and inputs stay
  non-negative. Reproducible across runs.
- **Non-vacuity:** both new tests assert enough to fail on a regression (guard
  positive control; `5096` checked count).

**Conclusion: no new high, medium, or low issue.** One informational nit below.

---

## 3. Gate results

| Command | Result |
|---------|--------|
| `composer cs` (PHPCS PSR-12) | **passed** — 279 files, 0 violations (2.87 s) |
| `composer phpstan` (level 9) | **passed** — 273 files, 0 errors |
| `composer rector` (dry-run) | **passed** — 0 changes |
| `./vendor/bin/phpunit --testsuite unit` | **passed** — 1227 tests, 8753 assertions, ~9.0 s |
| Targeted run of both new tests | **passed** — 2 tests, 7 assertions, 0.04 s |

`composer lint` (PHPCS + Rector dry-run + PHPStan 9 + `kb-lint`) is covered by
the three static gates above plus the pre-push hook.

---

## 4. Informational nit (non-blocking)

- `tests/StreamConnectionTest.php:1509` | `crc32()` returns a **signed** int on
  32-bit PHP, so the fraction could be negative there and a sweep input could
  become negative, failing the invariant assertion. On 64-bit PHP (this project's
  target — cf. the 64-bit handling in `src/Buffer/ReadBuffer.php`) `crc32()` is
  always non-negative and the test is correct. Not a defect for supported
  platforms; no action required. If a 32-bit build ever matters, mask with
  `crc32(...) & 0xFFFFFFFF` or use a local `\Random\Randomizer`.

---

## 5. E2E

E2E (`./run-e2e.sh`) was **skipped**. Reason: the round-2 delta changes no wire
behaviour, no protocol bytes, and no `stream_select()` argument for any reachable
input (it is a test aggregate/seed change plus a defensive clamp on an
unreachable path). Round 1 already ran the full E2E suite green (147 tests, 3059
assertions, 56.6 s) against HEAD `b97043e`, and nothing in the connection timing
paths changed since. The only connection-timing risk class in this delta — a
future inline `tv_usec` conversion — is now covered by a platform-independent
unit gate, which is strictly stronger than the Linux-only E2E signal.

---

## Summary

- high: 0
- medium: 0
- low: 0 (all three round-1 lows fixed and verified)
- nit: 0 open (round-1 nit fixed; one informational 32-bit note above, out of
  target scope)

**Verdict: APPROVE.** No open high/medium/low findings. Static gates and unit
suite green.
