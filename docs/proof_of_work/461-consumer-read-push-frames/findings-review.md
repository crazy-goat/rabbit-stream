# Findings — Review Round 1 (Issue #461)

1. **tests/Client/ConsumerTest.php:173-182 & 222-231** — The success-path tests
   inject messages via `setBuffer()` (reflection) instead of routing a Deliver
   through the callback passed to `registerSubscriber()`. The comment "Third
   dispatched frame is our Deliver" implies frame dispatch that does not
   actually happen; nothing on these tests exercises the deliver callback
   (chunk parsing → `buffer[]`/`unreadCount` accounting → credit handling) that
   `readLoop()` would really invoke. A regression in the subscriber wiring or
   the callback's buffer accounting would not be caught here.
   **Severity: medium — status: fixed.** Both tests now capture the deliver
   callback registered by the Consumer (via the new `makeConsumerWithHandlers()`
   helper) and push a real Osiris chunk through it inside the `readLoop()` mock,
   so the full callback path (chunk parsing, buffer accounting, credits) is
   exercised. The `readLoop()` mock itself still only simulates "a frame was
   dispatched" — the misleading comments were rewritten to state that plainly,
   and the choice is documented in `code-decision-1.md` (appended section).

2. **tests/Client/ConsumerTest.php:173 & 222** — The `readLoop()` mock
   (`willReturnCallback`) ignores its arguments; `waitForMessages()` is
   expected to pass the *remaining* deadline (`$timeout = $deadline - microtime(true)`)
   to each `readLoop(maxFrames: 1, timeout: $timeout)` call, and the tests
   cannot detect a regression that forwards the original full timeout on every
   iteration (which would over-wait past the caller's deadline after a lost
   subscription). Capturing the args and asserting `timeout` decreases would
   close this gap. **Severity: low — status: fixed.** The `readLoop()` mock
   callback now receives `(int $maxFrames, float $timeout)` and records every
   forwarded pair; the tests assert `maxFrames === 1` and a positive,
   non-increasing timeout bounded by the caller's deadline on each iteration.

3. **tests/Client/ConsumerTest.php (new tests, coverage note)** — None of the
   new tests exercise the `resubscribeIfLost() === false` slice branch of
   `waitForMessages()` (`src/Client/Consumer.php:476-481`), i.e. waiting while
   a lost subscription backs off. Coverage observation only; may be covered by
   E2E/resubscribe suites. **Severity: low — status: fixed.** New test
   `testReadWaitsThroughResubscribeBackoffAfterLostSubscription` fires the
   MetadataUpdate handler to lose the subscription, makes the re-subscribe
   fail with `STREAM_NOT_EXIST`, and asserts `read()` keeps servicing the
   connection through short `readLoop()` slices (positive, deadline-bounded
   timeouts) until the caller's deadline passes.

4. **tests/Client/ConsumerTest.php:177 & 226** — Comments claim a frame "is our
   Deliver", but the mock performs no dispatch; the message is placed in the
   buffer directly. Reword (e.g. "simulate a Deliver having buffered a
   message") to avoid misleading future readers.
   **Severity: nit — status: fixed.** Comments rewritten ("Simulate a Deliver
   carrying one message having been dispatched: feed the chunk through the
   real deliver callback instead of injecting into the buffer directly") and
   the tests' docblocks now state plainly what the mock does and does not
   simulate.

5. **tests/Client/ConsumerTest.php:174 & 223** — The closures capture `&$consumer`
   before `$consumer` is assigned (reference binding to a not-yet-declared
   variable). It works, but capturing it after construction, or asserting
   inside via a held reference set up first, would be less subtle.
   **Severity: nit — status: fixed (moot).** The `&$consumer` capture is gone
   entirely: the deliver callback is captured at construction by the
   `makeConsumerWithHandlers()` helper, so no closure references a
   not-yet-assigned variable any more.

6. **tests/Client/ConsumerTest.php:166-171, 215-220** — The 4-line mock setup
   (`registerSubscriber`/`request`/`sendMessage` expectations) is repeated in
   each new test and differs subtly from the existing `makeConnection()`
   helper (which mocks `readMessage` instead of `request`). A small
   parameterized helper would reduce duplication.
   **Severity: nit — status: fixed.** The shared `makeConsumerWithHandlers()`
   helper builds the connection mock (deliver-callback + MetadataUpdate
   handler capture, `request`/`sendMessage` stubs) and the `Consumer`, and is
   used by both reworked success-path tests. Pre-existing tests with similar
   inline setups were left untouched (out of scope for this branch's diff).

---

# Round 2 Dispositions (review-2.md)

1. Deliver-callback path — **fixed (verified)**: real callback from `registerSubscriber()`
   exercised in both success-path tests; offsets 41/7 flow through the chunk parser.
2. Forwarded maxFrames/timeout — **fixed (verified)**: `$forwarded` records every pair;
   asserted `maxFrames === 1`, positive, ≤ deadline, non-increasing (`read()` variant).
3. Back-off slice branch — **fixed (verified, deterministic)**: new test covers
   `resubscribeIfLost() === false` slice; assertions structurally bound (`min($timeout, ...)`),
   no flaky wall-clock walls.
4. Misleading comments — **fixed (verified)**: mock behavior stated plainly.
5. `&$consumer` pre-assignment capture — **fixed (verified)**: no longer exists.
6. Mock setup duplication — **fixed (verified)**: `makeConsumerWithHandlers()` helper; the
   back-off test's differing `request()` mock is essential divergence, not duplication.

New in round 2: 1 nit — `makeConsumerWithHandlers()` docblock prose indices are shifted by one
relative to the actual return shape (prose says [0]=Consumer; actually [0]=connection mock).
Doc-only; the `@return` type annotation is correct.

**Round-2 verdict: clean** (1 doc-only nit). cs / phpstan(9) / rector / unit (1074 tests)
all green.
