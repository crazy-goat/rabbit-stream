# Findings — coder — issue #385

## Obstacle: an e2e test was silently relying on the bug being fixed here

`tests/E2E/ConsumerTest.php::testSubscribeFromTimestamp` (around line 368,
pre-existing before this branch) published 5 "before" messages, called
`waitForConfirms(timeout: 5)`, took a millisecond timestamp, `usleep(100_000)`,
published 5 "after" messages, called `waitForConfirms(timeout: 5)` again, and
then asserted that an `OffsetSpec::timestamp($timestamp)` subscription only
returned the "after" messages.

Pre-fix, each `waitForConfirms(timeout: 5)` call *always* took the full 5
seconds (that is the bug), so the two batches ended up several seconds apart
in real time, which reliably made RabbitMQ close the first chunk and start a
new one before the "after" batch — chunk timestamps are per-chunk, not
per-message, so this test's correctness silently depended on chunk
boundaries lining up with the artificial 5+5+0.1s gap. Once
`waitForConfirms()` returns in milliseconds (the fix), the real gap between
the two batches shrank to ~100ms, which was not consistently enough for the
broker to roll over to a new chunk under the load of a full e2e run — the
test started intermittently reading `before-0` back as if it were `after-0`.

Fixed by raising the explicit gap to `usleep(5_000_000)` at
`tests/E2E/ConsumerTest.php:389` with a comment explaining why. Verified
clean over 3 consecutive full-suite runs (I had tried 100ms -> fails reliably
standalone once the producer fix is in place, 1.5s -> passes standalone but
flakes under full-suite load, 3s -> still flakes under full-suite load, 5s ->
3/3 clean full-suite runs). This suggests chunk-boundary timing is sensitive
to overall broker load, not just the gap between the two producers — a 5s
gap is comfortably conservative for the CI machine.

**Suggested follow-up (not filed as a separate issue by me, flagging for
triage):** this test's reliance on wall-clock separation to force a chunk
boundary is inherently a little fragile; a more robust version would
actively verify chunk-boundary placement (e.g. via stream metadata or by
publishing enough volume to force a size-based chunk roll) rather than
sleeping. I left it as a minimal, verified-stable timing bump rather than
redesigning the test, since a broader rewrite was out of scope for #385.

## #382 interaction analysis (per instructions: investigate, do not fix)

`StreamConnection.php:446-447`:

```php
$selectTimeoutSec = (int) min($remaining, 1);
$selectTimeoutUsec = (int) (($remaining - $selectTimeoutSec) * 1_000_000);
```

For `$remaining = 5.0`: `$selectTimeoutSec = 1`, `$selectTimeoutUsec =
3_999_997` — outside the POSIX-documented `[0, 999999]` range for
`tv_usec`. My fix calls this same code path (inside `readLoop()`) more
often — once per dispatched frame instead of once per `waitForConfirms()`
call — but does not change what values are computed or passed to
`socket_select()`; the interaction is purely "same bug, invoked more times."

I tested whether this is actually harmful, since the issue itself notes
"CI e2e currently passes on Linux, which is surprising if #382 is real":

1. Reproduced the exact computation in isolation
   (`$remaining = 5.0` -> `sec=1, usec=3999997`).
2. Called `socket_select()` directly (via `socket_create_pair`) with
   `(sec=1, usec=3999997)` on this machine (macOS/Darwin, PHP 8.5.9). Result:
   no error, `$ready === 0` after **~5.002s** elapsed — i.e. the kernel
   correctly normalized the overflowing `tv_usec` into the total wait time
   (1s + 3.999997s ~= 5s), rather than rejecting it or misbehaving.

Both Linux (glibc, in CI) and BSD-derived libc (macOS, here) build the
`timeval` into an internal deadline/jiffie count that simply adds
`tv_sec + tv_usec/1e6` rather than validating `tv_usec < 1e6` and returning
`EINVAL`. That is why CI has never failed on this: in practice, neither
platform this project runs on enforces the POSIX range restriction for
`socket_select()`'s microsecond argument. #382 is a real deviation from the
documented/portable contract (strict POSIX behavior is technically
undefined for `tv_usec >= 1_000_000`), but it does not appear to be
*reachable as a bug* on the platforms this project currently tests against.
It could bite on a stricter POSIX libc (some embedded/BSD variants are
documented to enforce the range and return `EINVAL`), so it is still worth
fixing for portability — just correctly out of scope for #385, and lower
urgency than its "priority" label might suggest given it is not observed to
cause failures in practice today.

## Other observations (not filed, flagging for triage)

- `StreamConnection.php:477-482` — when `readLoop()` reads a frame whose key
  is *not* in `SERVER_PUSH_KEYS`, it logs a warning and discards the frame,
  but does not increment `$dispatched`. This means a stray/unexpected frame
  here cannot trip `maxFrames`, so `readLoop(maxFrames: 1, ...)` would loop
  internally past that frame waiting for a "real" server-push frame. This
  is not a bug for #385 specifically (still bounded by the deadline), but is
  worth a comment or a dedicated unit test — right now the only line of
  defense against this path silently looping forever is the `$timeout`
  argument, and `readLoop()` can be called with `$timeout = null` (unlimited)
  elsewhere in the codebase, in which case a `while(true)` fed a stream of
  unexpected frames (e.g. a protocol version mismatch on the wire) would spin
  forever with only a warning per iteration. Suggested fix: either count
  discarded frames toward `$maxFrames` too, or add a comment documenting the
  intentional exclusion.

## Candidate KB entry proposal

I'd propose a **gate over a KB entry** here, per the workflow's stated
preference:

**Gate:** the new regression test itself
(`tests/E2E/ProducerTest::testWaitForConfirmsReturnsAsSoonAsConfirmArrives`)
is exactly the right guard against this class of defect — any future change
that reintroduces "block for the full timeout regardless of actual confirm
latency" in `waitForConfirms()` (or a similarly-shaped future method) will
fail this test. No static-analysis rule can catch "this loop doesn't
actually check its own exit condition promptly" — it is a timing property,
not a type/shape property — so a regression test is the correct gate, not a
lint rule.

If a KB entry is still wanted (e.g. because the *pattern* — "any
`readLoop()`/blocking-wait caller must pass `maxFrames` if it wants to
react to state changed by the dispatched callback, not just `timeout`" — is
likely to recur when someone adds a new blocking wait method), I'd suggest:

- **Title:** `readLoop() callers must pass maxFrames to react promptly, not just timeout`
- **Tags:** `protocol, socket, e2e`
- **Trigger:** "when adding or reviewing a method that calls
  `StreamConnection::readLoop()` in a loop waiting for a callback-driven
  state change (e.g. a new `waitForXxx()`-style method)"
- **One paragraph:** `readLoop(timeout: $t)` without `maxFrames` only
  returns when `$t` fully elapses, `stop()` is called, or the connection
  drops — it never inspects application state changed by the dispatched
  callbacks (see `Producer::waitForConfirms()`, fixed in #385).
  `Consumer::read()` (`src/Client/Consumer.php:82`) shows the correct
  pattern: `readLoop(maxFrames: 1, timeout: $remaining)` inside a `while`
  that re-checks the state after every call. Any new blocking-wait method
  built on `readLoop()` should follow this pattern, or it will always block
  for the full timeout regardless of how fast the awaited condition is
  actually satisfied.
