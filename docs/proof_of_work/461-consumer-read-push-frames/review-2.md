# Review Round 2 — Issue #461 (branch feature/issue-461-consumer-read-push-frames)

Scope reviewed: full diff `git diff origin/main...HEAD` (2 commits: d4425e1, 70a2b8b), focus on
`tests/Client/ConsumerTest.php` rework.

## QA run results

| Check | Result |
|---|---|
| `composer cs` (PHPCS PSR-12) | ✅ clean |
| `composer phpstan` (level 9) | ✅ OK — no errors |
| `composer rector` (dry-run) | ✅ OK — no suggestions |
| `./vendor/bin/phpunit --testsuite unit` | ✅ OK — 1074 tests, 8373 assertions |

## Round-1 findings — round-2 dispositions

1. **Success-path tests bypass the real deliver callback — FIXED (verified).**
   Both `testReadReturnsMessageArrivingAfterNonDeliverFrames` and
   `testReadOneReturnsMessageArrivingAfterNonDeliverFrames` now obtain the deliver callback
   captured by the Consumer's `registerSubscriber()` call via the new
   `makeConsumerWithHandlers()` helper and invoke it inside the `readLoop()` mock with a real
   chunk built by `buildOneEntryChunk()`. That exercises the actual callback chain: chunk
   parsing → `$this->buffer[]`/`unreadCount` accounting → credit handling
   (`creditsInFlight`/`pendingCredits`, see `src/Client/Consumer.php:400-409`). The tests
   assert the message offset returned from `read()`/`readOne()` matches the chunk's
   `firstOffset` (41 / 7 — the new `$firstOffset` parameter also makes the offsets distinct
   from the default 0, proving the value flows through the parser rather than by accident).
   A regression in subscriber wiring or buffer accounting now fails these tests.

2. **readLoop() mock ignores forwarded args — FIXED (verified).** Both reworked tests capture
   `(int $maxFrames, float $timeout)` per call into `$forwarded` and assert `maxFrames === 1`,
   `timeout > 0`, `timeout <= 5.0` (caller deadline), and (in the `read()` variant)
   `timeout` non-increasing across iterations. A regression forwarding the original full
   timeout every iteration or a wrong `maxFrames` is caught. The non-increasing assertion is
   robust to microtime ties (`assertLessThanOrEqual`). The remaining pre-existing test
   `testReadOneKeepsWaitingWhileNonDeliverFramesArrive` still ignores args, but it targets a
   different property (deadline honored under continuous no-op frames) and asserts elapsed
   time ≥ timeout — acceptable, not re-raised.

3. **Back-off slice branch untested — FIXED (verified, deterministic).**
   `testReadWaitsThroughResubscribeBackoffAfterLostSubscription` fires the captured
   MetadataUpdate handler (`nextResubscribeAt = now`, Consumer.php:229), makes re-subscribe
   attempt 2 throw `ProtocolException(STREAM_NOT_EXIST)` (so `resubscribeIfLost()` resets
   `nextResubscribeAt` and returns false, Consumer.php:259), and asserts: ≥2 subscribe
   attempts, elapsed ≥ caller's 0.05s deadline, and every forwarded readLoop slice has
   `maxFrames === 1`, `> 0`, `≤ 0.05` — exactly the `min($timeout, max(0, nextResubscribeAt -
   now))` slice branch at Consumer.php:476-481. Determinism: no wall-clock wall assertions
   beyond deadline-honoring (`≥ 0.05`), the mock always returns 1 and sleeps only 1ms, so the
   loop terminates by the deadline check `$timeout > 0` regardless of machine speed. The
   `≤ 0.05` bound on slices holds structurally (`min($timeout, ...)`) and cannot flake.

4. **Misleading "is our Deliver" comments — FIXED (verified).** Comments now say the mock
   "simulates a Deliver … having been dispatched" and the docblocks state plainly that
   `readLoop()` only stands in for "a frame was dispatched". No remaining claim of end-to-end
   frame dispatch inside the mock.

5. **`&$consumer` capture before assignment — FIXED (verified, moot).** No closure in the new
   tests references `$consumer`; the deliver callback and metadata handler are captured via
   `use (&$deliverCallback)` / `use (&$metadataHandler)` into locally-declared variables at
   helper construction. No not-yet-assigned captures remain.

6. **Duplicated mock setup — FIXED (verified).** `makeConsumerWithHandlers()` centralizes
   registerSubscriber/registerMetadataUpdateHandler capture + request/sendMessage stubs and is
   used by both reworked success-path tests. The back-off test keeps its own inline setup
   because its `request()` mock must differ (throwing `STREAM_NOT_EXIST`); that divergence is
   essential, not duplication.

**Summary: all 6 round-1 findings are fixed. None regressed.**

## New findings (round 2)

1. **tests/Client/ConsumerTest.php — `makeConsumerWithHandlers()` docblock off-by-one
   (nit).** The `@return array{...}` description says "[0] the Consumer, [1] the deliver
   callback, [2] the MetadataUpdate handler", but the actual shape is `[0]` connection mock,
   `[1]` Consumer, `[2]` deliver callback, `[3]` metadata handler. The type annotation itself
   (`0: StreamConnection, 1: Consumer, 2: callable|null, 3: callable|null`) is correct; only
   the prose bullet list is shifted. Suggest renumbering the prose.
   **Severity: nit — open (doc only).**

2. No other new issues. The `buildOneEntryChunk(string, int $firstOffset = 0)` signature
   change is backward-compatible and existing call sites (default 0) are unaffected. Unused
   4th destructured element in the first two success-path tests is idiomatic PHP list
   destructuring and not a violation.

## Verdict

**Clean** — no blocking findings. QA suite (cs, phpstan level 9, rector, unit: 1074 tests)
all green. Single open item is a doc-only nit (off-by-one prose in a helper docblock) that
does not affect correctness; safe to merge as-is or fix in a trivial follow-up.
