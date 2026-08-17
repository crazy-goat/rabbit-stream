# Code decision 1 — Producer::waitForConfirms() blocks the full timeout

**Issue:** #385
**Branch:** `feature/issue-385-waitforconfirms-full-timeout`

## What was asked

`Producer::waitForConfirms()` (`src/Client/Producer.php:112`) always blocked
for the entire `$timeout` even when the broker confirmed in milliseconds,
because it called `$this->connection->readLoop(timeout: $remaining)` without
`maxFrames`. `readLoop()` (`src/StreamConnection.php:418`) never inspects
`pendingConfirms`; without `maxFrames` it keeps looping internally — reading
frames, dispatching them, going back to `socket_select()` — until its own
deadline expires, `stop()` is called, or the connection drops. The
publish-confirm callback decrements `pendingConfirms` but nothing tells the
loop to return early, so the outer `while ($this->pendingConfirms > 0)` in
`waitForConfirms()` only gets a chance to re-check the counter after the
*entire* `$remaining` has elapsed.

## Approach taken

One-line fix at `src/Client/Producer.php:124`:

```php
$this->connection->readLoop(maxFrames: 1, timeout: $remaining);
```

This is exactly the pattern `Consumer::read()` already uses
(`src/Client/Consumer.php:82`), which is why consumers were never affected by
this bug. With `maxFrames: 1`, `readLoop()` returns to the caller as soon as
it has dispatched one server-push frame (or its own deadline/disconnect/stop
fires), so the outer `while` in `waitForConfirms()` regains control and
re-checks `pendingConfirms` immediately after every frame instead of only
after the whole timeout.

### Correctness points I verified before accepting the one-liner

1. **Multiple confirm frames in one batch.** A `sendBatch()` of N messages
   can arrive as more than one `PublishConfirm`/`PublishError` frame server
   side. Because the outer `while ($this->pendingConfirms > 0)` still loops,
   each `readLoop(maxFrames: 1, …)` call only needs to knock `pendingConfirms`
   down by whatever the one dispatched frame covered; the loop keeps calling
   `readLoop()` until `pendingConfirms` hits 0 or the recomputed `$remaining`
   is `<= 0`. Verified this holds by inspection and by the
   `testSendBatchAndWaitForConfirms` (3 messages) and
   `testLargeBatchPublish` / `testBatchPublishWith1KbMessages` e2e tests,
   all still green after the change.

2. **A dispatched frame that is not a confirm.** `maxFrames: 1` counts any
   dispatched *server-push* frame (heartbeat, metadata update, a delivery for
   a consumer sharing the connection, etc. — see
   `StreamConnection::SERVER_PUSH_KEYS`), not specifically a publish confirm.
   If such a frame trips the counter, `readLoop()` returns, the outer
   `while` sees `pendingConfirms` unchanged and still `> 0`, recomputes
   `$remaining` from the *original* `$deadline` (computed once, outside the
   loop, at `Producer.php:118`), and calls `readLoop()` again. No deadline
   time is lost across re-entries because the deadline is anchored once and
   `$remaining` is always `$deadline - microtime(true)`; each re-entrant
   `readLoop()` call gets a fresh, correctly-shrunk timeout.

3. **Busy-spin risk.** Read `readLoop()` (`StreamConnection.php:418-490`)
   carefully for this: could `readLoop(maxFrames: 1, timeout: $remaining)`
   return instantly, over and over, without ever really waiting, and turn
   the outer `while` into a CPU-burning hot loop until the deadline? No —
   `readLoop()`'s internal `while ($this->running && $this->connected)` only
   returns in three ways: (a) the deadline it computes internally from the
   `$timeout` argument expires, (b) it dispatches `$maxFrames` server-push
   frames, or (c) the connection drops. Path (a) is a real wait — it is
   reached only after `socket_select()` has been polled with real timeouts
   summing to the deadline. Path (b) is reached only after `readFrame()`
   actually returned frame bytes, which itself only happens after
   `socket_select()` reported the socket readable — i.e. real data arrived.
   There is no path where `readLoop()` returns without either (i) having
   blocked in `socket_select()` for a non-trivial slice of time, or (ii)
   having consumed one real frame. So the outer `while` in
   `waitForConfirms()` cannot hot-spin: every re-entrant call either blocks
   for real wall-clock time or makes real progress toward `pendingConfirms`
   reaching 0. (One caveat, not a spin risk but worth recording: the
   non-server-push "discard and warn" branch at `StreamConnection.php:477-482`
   does not increment `$dispatched`, so a non-server-push frame arriving
   here would keep `readLoop()` looping *internally* rather than returning
   to `waitForConfirms()` — but it is still bounded by `readLoop()`'s own
   deadline, so it cannot spin past `$remaining` either.)

4. **Timeout behavior preserved.** When confirms never arrive, each
   `readLoop(maxFrames: 1, timeout: $remaining)` call still blocks for up to
   `$remaining`, `pendingConfirms` never reaches 0, the outer `while`'s
   `$remaining <= 0` check eventually breaks it, and
   `waitForConfirms()` throws `TimeoutException` exactly as before. Verified
   with the existing `testWaitForConfirmsTimeoutThrows` (`timeout: 0`) and
   `testProducerRemainsUsableAfterTimeout` (`timeout: 0.001`, then a
   follow-up `timeout: 5.0` that must succeed) — both still pass unmodified.

## What was rejected and why

- **Looping `readLoop(maxFrames: null, ...)` and adding a `pendingConfirms`
  check via a new callback/event inside `StreamConnection`.** Rejected —
  it would require threading a "stop condition" concept into
  `StreamConnection`, which currently only knows about `stop()`,
  `maxFrames`, and `timeout`. `maxFrames: 1` reuses an existing, already
  battle-tested mechanism (`Consumer::read()`) with zero new API surface.
- **Passing a larger `maxFrames` (e.g. `pendingConfirms` count) to reduce
  the number of `readLoop()` re-entries.** Rejected — `pendingConfirms` can
  shrink due to non-confirm server-push frames being dispatched in between
  (see point 2 above), so sizing `maxFrames` to the current
  `pendingConfirms` would not be correct without also tracking how many of
  the dispatched frames were actually confirms. `maxFrames: 1` is the
  simplest value that is unconditionally correct.
- **Fixing #382 (`socket_select()` tv_usec overflow) in the same change.**
  Explicitly out of scope per the task. See `findings-coder.md` for the
  investigation — it turns out not to reproduce on either macOS or Linux in
  practice, which is worth recording but not worth touching here.

## What I was unsure about

- Whether the e2e regression test belongs in `tests/E2E/ProducerTest.php`
  (chosen) or a new dedicated class. I kept it in `ProducerTest.php` next to
  the other `waitForConfirms()` tests (`testSendAndWaitForConfirms`,
  `testWaitForConfirmsTimeoutThrows`, `testProducerRemainsUsableAfterTimeout`)
  because it exercises the same method under the same `E2ETestCase` fixture
  (`createProducer` on a per-test stream) — a new class would only
  duplicate that setup for one test.
- The 2.0s threshold in the new test. A real confirm round-trip against the
  local Docker broker measured **0.026s**. 2.0s leaves ~2 orders of
  magnitude of headroom versus the observed value while still being far
  below the 5.0s timeout passed to `waitForConfirms()` — wide enough to
  absorb CI scheduling jitter without being so wide it stops meaningfully
  distinguishing "returned promptly" from "blocked for the full timeout".
- **Unplanned scope: `tests/E2E/ConsumerTest.php:389`
  (`testSubscribeFromTimestamp`).** This test relied on the *bug* being
  fixed here as an implicit timing crutch: it published 5 "before" messages,
  called `waitForConfirms(timeout: 5)` (which, pre-fix, *always* took the
  full 5s), took a timestamp, `usleep(100_000)`, then published 5 "after"
  messages and called `waitForConfirms(timeout: 5)` again (another full
  5s). That gave the two batches several seconds of real separation, which
  reliably pushed RabbitMQ to place them in distinct chunks (chunk
  timestamps are per-chunk, not per-message). Once `waitForConfirms()`
  returns in milliseconds, the two batches were separated only by the
  literal `usleep(100_000)` (100ms), which was not always enough for the
  broker to close and start a new chunk under load, causing the test to
  intermittently read `before-0` back in the timestamp-filtered results. I
  raised the explicit `usleep()` to `5_000_000` (5s) at
  `tests/E2E/ConsumerTest.php:389` and added a comment explaining why —
  verified clean over 3 consecutive full e2e suite runs (previously flaky
  at 100ms/1.5s/3s gaps when run as part of the full suite, even though it
  passed reliably standalone). This is a test-only change; no production
  code in `ConsumerTest`'s path was touched. I considered this in-scope
  because it is a direct regression introduced by fixing #385 (the
  "Confirm no e2e test regressed to a failure" requirement), not a
  pre-existing unrelated bug like #382/#395/#402/#390/#428.

## Evidence: before/after e2e timing

**Before (measured by the main session, from the issue triage):** full e2e
suite 302s / 127 tests. Slowest tests sat at exact multiples of the timeout
passed to `waitForConfirms()`:
- `ProducerTest::testLargeBatchPublish` — 30.0s
- `ProducerTest::testBatchPublishWith1KbMessages` — 30.0s
- `MultipleConsumersTest::testUnsubscribingOneConsumerDoesNotAffectOther` — 15.0s
- ~15 more tests at exactly 5.0s or 10.0s

**After (measured on this branch, 3 consecutive full runs, all green,
`--log-junit` captured on the last run):**
- Full e2e suite: **~19–25s wall clock** (128 tests, including the new
  regression test) — roughly a **12x** speedup.
- `ProducerTest::testLargeBatchPublish`: **0.0108s** (was 30.0s)
- `ProducerTest::testBatchPublishWith1KbMessages`: **0.0083s** (was 30.0s)
- `MultipleConsumersTest::testUnsubscribingOneConsumerDoesNotAffectOther`:
  **0.0143s** (was 15.0s)
- New test `ProducerTest::testWaitForConfirmsReturnsAsSoonAsConfirmArrives`:
  **0.026s** elapsed for `waitForConfirms(timeout: 5.0)`, asserted `< 2.0s`.
  Confirmed this test **fails** on the pre-fix code (measured **5.0037s**,
  assertion `5.0037 < 2.0` fails) and **passes** on the post-fix code
  (**0.026s**).
- Remaining slow outliers after the fix are unrelated to `waitForConfirms()`:
  `ConsumerTest::testSubscribeFromTimestamp` (5.0s — its own explicit
  `usleep`, see above), `ServerInitiatedCloseTest` (~4.3s),
  `ConnectionHandshakeTest` (~2-3s, connection-refused/auth-failure paths),
  `HeartbeatTest` (~2.5s) — none of these call `Producer::waitForConfirms()`.
