# Review Round 1 — Issue #461 (consumer read()/readOne() waiting through unrelated server-push frames)

Scope: `git diff origin/main...HEAD` — test-only change. One commit
(`d4425e1`), adding three tests to `tests/Client/ConsumerTest.php` plus
pre-existing coder notes under `docs/proof_of_work/`.

## QA runs (all pass locally)

| Command | Result |
|---|---|
| `composer cs` (PHPCS PSR-12) | ✅ 0 violations |
| `composer phpstan` (level 9) | ✅ No errors |
| `composer rector` (dry-run) | ✅ No changes proposed |
| `./vendor/bin/phpunit --testsuite unit` | ✅ 1073 tests, 8200 assertions, OK |

## What the change does

Adds regression coverage for `Consumer::waitForMessages()`
(`src/Client/Consumer.php:469`), which is the #461 behavior: frames other than
Deliver (heartbeats, publish confirms, ConsumerUpdate) dispatched by
`StreamConnection::readLoop()` must not terminate the wait; only the deadline
or a genuine `readLoop() === 0` return may.

1. `testReadReturnsMessageArrivingAfterNonDeliverFrames` — readLoop mock
   dispatches 2 "unrelated" frames (returning 1 each) and only buffers a
   message on the 3rd call; asserts `read()` returns the message and `calls === 3`.
2. `testReadOneKeepsWaitingWhileNonDeliverFramesArrive` — readLoop mock
   dispatches 1 frame per call, never buffering; asserts `readOne(0.05)` blocks
   at least the full deadline and is called more than once.
3. `testReadOneReturnsMessageArrivingAfterNonDeliverFrames` — same success
   path as (1) for `readOne()`, buffering on the 2nd call.

## Assessment

### Do the tests assert the success path? — Yes

Tests (1) and (3) verify the actual regression the issue describes: a message
arriving *after* unrelated frames is returned, not an empty array/null. The
`assertSame(N, $calls)` assertions pin the loop iteration count, so an
implementation that returned early on the first dispatched frame fails both the
value assertion and the call-count assertion. Test (2) complements the existing
`testReadKeepsWaitingWhileNonDeliverFramesArrive` for `readOne()`.

### Mock fidelity to real `StreamConnection` behavior — Partial (see Findings 1–2)

The mock short-circuits two real mechanisms:

- The message is injected via `setBuffer()` (reflection on `buffer` +
  `unreadCount`) instead of through the deliver callback that
  `registerSubscriber()` installs. That is acceptable for testing
  `waitForMessages()` in isolation, and `setBuffer()` keeps `unreadCount` in
  sync (the #408 invariant), but the test comments ("Third dispatched frame is
  our Deliver") overstate what is simulated — no frame is dispatched through
  the subscriber path.
- The mocked `readLoop()` ignores both its `maxFrames` and `timeout`
  arguments, so the tests never verify that `waitForMessages()` forwards the
  *remaining* deadline (not the original timeout) to each `readLoop()` call.

### Determinism — Good

No wall-clock flakiness of concern: the deadline assertions
(`assertGreaterThanOrEqual(0.05, ...)`) hold by construction because
`waitForMessages()` loops until `microtime(true) >= $deadline`. The
`usleep(2000)` in the timeout tests keeps the loop fast (~25 iterations, ~50 ms)
without busy-spinning; the success-path tests use `timeout: 5.0` but always
terminate in 2–3 mock iterations, so they cannot hit the deadline. The
`assertGreaterThan(1, $calls)` assertions are monotone (the mock always returns
1), so they cannot be flaky.

### Style / static analysis — Clean

PSR-12, PHPStan 9 and Rector all pass. The new tests follow the file's existing
conventions (inline mock setup, `setBuffer()` helper, snake_case method names).

## Findings

See `findings-review.md`. Summary: **0 high, 1 medium, 2 low, 3 nit**.

1. **Medium** — Tests bypass the real deliver-callback path; the regression
   suite would not catch a break in `registerSubscriber()` wiring or in the
   deliver callback's buffer accounting on this code path.
2. **Low** — Mocked `readLoop()` ignores `timeout`/`maxFrames`; remaining-deadline
   forwarding is unverified.
3. **Low** — Timeout-slice branch (`resubscribeIfLost() === false`) is not
   exercised by any new test (only reachable after a MetadataUpdate; may be
   covered elsewhere — noted as coverage observation, not a defect).
4–6. **Nits** — misleading "dispatched frame" comments, capture-by-reference of
   `$consumer` before it exists (works, but subtle), and duplicated mock setup
   that could reuse `makeConnection()`-style helpers.

## Verdict

Approve with comments. The tests are deterministic, correct against the
current implementation, and do assert the success path for both `read()` and
`readOne()`. Findings 1 and 2 are worth addressing in a follow-up commit or in
this PR at the author's discretion — none blocks merge.
