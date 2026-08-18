# Coder Findings — Issue #382

## Biggest problem during implementation

**Where:** `src/StreamConnection.php:438-452` (`readLoop()`).

**Why hard:** the same `(sec, usec)` split pattern appears three times in the
file (`sendFrame` 319-320, `readLoop` 446-447, `readFrame` 632-633), and two
of the three are correct — only `readLoop` caps the seconds with
`min($remaining, 1)` while deriving the microseconds from the **unclamped**
remainder, producing `tv_usec >= 1_000_000` for any timeout > 1 s. The risk
was "fixing" the correct sites too.

**How solved:** applied the issue's exact clamp (`$capped = min(...)` before
both halves) only in `readLoop()`, and verified by inspection that in the
other two sites `sec = (int) $remaining` makes `usec` the true fractional
part (< 1e6 always). Confirmed empirically that pre-fix `select(1, 1_500_000)`
blocks 2.501 s in one poll on macOS (poll-interval overshoot / lost
stop()-responsiveness symptom), while post-fix polls block ≤ 1 s.

## Obstacles & surprises

- On macOS the unit regression test **cannot** distinguish pre-fix from
  post-fix by timing: pre-fix `select(1, 1_500_000)` blocks the full 2.5 s in
  one call, total elapsed is identical to the fixed three-poll version. The
  regression signal (EINVAL → `ConnectionException`) fires only on Linux.
  The E2E > 1 s test is therefore the cross-platform CI guard.
- `tests/StreamConnectionTest.php` already had all the helpers needed
  (`createSocketPair()`, `injectSocket()`), so the unit regression was cheap.
  It is explicitly included in the `unit` testsuite (`phpunit.xml`).

## Discovered bugs / places to improve (also outside this issue's scope)

1. **`tests/StreamConnectionTest.php:567` — `testDispatchMetadataUpdateWithoutCallbackDoesNotCrash` performs no assertions** (PHPUnit marks it "risky"). Suggested fix: assert `$connection->isConnected()` (or that the callback-less path leaves the connection open) after `readLoop()`.

2. **`src/StreamConnection.php:337-348` — `sendFrame()` ignores partial writes.** `socket_write()` returning `0 < $written < strlen($frame)` is accepted and the caller believes the full frame was sent — the broker then desyncs and data is silently truncated. Suggested fix: loop until `$written === strlen($frame)` (bounded by the write deadline) or throw `ConnectionException`. **Already tracked: #389.**

3. **`src/StreamConnection.php:364-406` — `readMessage()` never matches the response correlation id** to the last request's id (`correlationId` is write-only in `sendMessage()`). A stale/timeout-shifted response is returned to the wrong caller. Suggested fix: capture the expected id per request and validate it in `readMessage()`; on mismatch, keep reading (responses are ordered for non-concurrent use). **Already tracked: #387.**

4. **`src/StreamConnection.php:632-633` — `readFrame()`'s single `socket_select` can block up to the full remaining timeout (30 s default) in one call** — valid tv (no EINVAL), but `stop()` cannot interrupt `readMessage()` mid-select and the `$this->running` flag is irrelevant there. Likely by design for request/response, but worth confirming; if responsiveness is desired, split into ≤ 1 s polls like `readLoop()`.

5. **`src/StreamConnection.php:319-320` — same tv pattern in `sendFrame()` is correct but shares the code shape that produced #382.** Consider extracting a single helper (`tvForRemaining(float): array{int,int}`) with a unit test for the `usec < 1_000_000` invariant, so the class of bug cannot recur in the third site if the cap logic is ever modified.
