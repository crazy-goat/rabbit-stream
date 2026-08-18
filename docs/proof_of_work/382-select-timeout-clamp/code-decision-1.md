# Code Decision — Round 1 — Issue #382

## Approach taken

Applied the issue's suggested fix verbatim in `src/StreamConnection.php:readLoop()`:

```php
$capped = min($remaining, 1);
$selectTimeoutSec  = (int) $capped;
$selectTimeoutUsec = (int) (($capped - $selectTimeoutSec) * 1_000_000);
```

The key property is that the remainder is taken from the **capped** value, so
`($capped - $selectTimeoutSec)` is always in `[0, 1)` and `tv_usec` is always
`< 1_000_000`. The deadline semantics are unchanged: for a 2.5 s timeout the
loop now polls in three ≤ 1 s chunks (1 s + 1 s + 0.5 s) instead of one
1.5 s-overshooting select, and `stop()`/deadline checks run between polls.

## What I rejected

1. **Extracting the tv split into a private static helper
   (`splitSelectTimeout(float): array{int,int}`) and unit-testing the
   arithmetic directly.** This would have made the `tv_usec < 1_000_000`
   invariant testable on every platform, including macOS. I rejected it
   because the issue prescribes the inline fix, the change is 2 lines, and a
   refactor expands the diff surface for a bug whose regression signal
   (ConnectionException) fires on Linux anyway.
2. **Applying the same clamp to `sendFrame()` (line 319-320) and
   `readFrame()` (line 632-633).** Those two compute `sec` as the full
   integer part (`(int) $remaining`), so `usec` is already always `< 1e6`
   there — the pattern is correct and needs no change. Only `readLoop`
   capped the seconds via `min()` while deriving usec from the unclamped
   remainder.
3. **Asserting stop() responsiveness in the unit test** (the macOS symptom).
   `stop()` is designed to be called from another thread/signal handler;
   there is no in-process way to observe poll granularity without threads.
   Instead I verified the overshoot empirically with a raw socket pair demo
   (below) and rely on the E2E > 1 s test for Linux CI.

## Verification of the macOS symptom (empirical)

`select(1, 1_500_000)` on this macOS machine blocked **2.501 s in one call**
(the pre-fix tv for a 2.5 s deadline), while the post-fix polls
(`select(1, 0)` → 1.0 s, `select(0, 500_000)` → 0.5 s) block at most ~1.0 s
each. This confirms the "5.06 s instead of 1 s" behaviour described in the
issue.

## Tests

- Unit regression: `tests/StreamConnectionTest.php::testReadLoopHandlesTimeoutLongerThanOneSecond`
  — injects an AF_UNIX socket pair, runs `readLoop(maxFrames: 1, timeout: 2.5)`
  with no data, asserts no exception and elapsed ∈ (2.3, 3.5) s and that the
  connection stays usable. On Linux this test **throws pre-fix**
  (EINVAL → ConnectionException); on macOS pre-fix it would pass (no throw,
  same total elapsed), so the macOS regression is guarded by the code fix +
  the E2E test rather than by timing assertions.
- E2E: `tests/E2E/ReadLoopTimeoutTest.php::testReadLoopReturnsAfterTimeoutLongerThanOneSecond`
  — same pattern against a real broker (not run locally; no Docker broker
  available and no wire-format change). This is the test that catches the bug
  in Linux CI.

## Uncertainties

- Timing bounds (2.3 / 3.5 s) follow the existing convention in
  `tests/E2E/ReadLoopTimeoutTest.php` (generous cushions). A heavily loaded
  CI machine could in theory exceed 3.5 s of wall time (preemption inside the
  PHP loop), but the loop itself can never wait more than ~1 s past the
  deadline, so this is bounded by scheduler hiccups only.
- `readLoop()` resets `$this->running = true` on entry (existing behaviour) —
  calling `stop()` before `readLoop()` does not exit early. Unchanged by this
  fix.
