# Findings — review — issue #385 (round 1)

Format: `file:line` | what is wrong | severity | status

## FINDING A — `tests/E2E/ConsumerTest.php:389` | high | open
`usleep(5_000_000)` does not fix the flakiness it claims to fix. The recorded
cause ("both batches land in one chunk") is false: a `PublishConfirm` implies
the chunk is committed, so consecutive confirmed batches are always in
different chunks (verified via `Message::getTimestamp()`, the per-chunk
timestamp — `src/Client/OsirisChunkParser.php:66`/`:84`). The real cause is a
1 ms equality race: `OffsetSpec::timestamp()` resolves to the first chunk with
`chunkTs >= requested`, and `$timestamp = (int)(microtime(true)*1000)` is
sampled in the same millisecond the "before" chunk was written
(`beforeChunkTs - $timestamp == 0` -> FAIL, `== -1` -> PASS, 1:1 over 10 runs).
The gap is inserted *after* the sample, so no `usleep` value can affect it.
Measured with the committed 5 s sleep: 2/6 failures for the single test, 2/4
red full-suite runs, 6/8 failures in an equivalent harness. Also reverts
closed issue #343 to 5x worse and costs ~25 % of the new suite runtime, and
introduced a second failure mode (`ConnectionException: connection closed by
peer` at `ConsumerTest.php:391`).
**Recommendation: option (a).** Derive the boundary from stored data —
`$timestamp = max(Message::getTimestamp()) + 1` over the read-back "before"
batch, then spin `usleep(200)` until the wall clock passes that millisecond
before publishing "after". Validated 12/12 PASS in 0.16 s total. Full recipe
in `review-1.md`.

## FINDING B — `src/StreamConnection.php:477-482` | low | open
Non-server-push frames are discarded without incrementing `$dispatched`, so
they cannot trip `maxFrames`. This weakens the guarantee "`maxFrames: 1`
returns after one frame" but not the #385 fix: the deadline is re-checked at
`:429-431` and `:444-447`, so the call still cannot exceed `$remaining`, and
every iteration of that branch requires real bytes from the peer, so it cannot
busy-spin. The `timeout: null` spin is real but unreachable from
`waitForConfirms()`. Pre-existing — file as a separate issue; preferred fix is
to count discarded frames toward `$maxFrames` plus a unit test.

## N1 — `code-decision-1.md:150-176`, `findings-coder.md:5-30`, `tests/E2E/ConsumerTest.php:380-388` | high | open
The recorded root cause (chunk batching) is wrong; it justifies the wrong fix,
is baked into a permanent code comment, and `findings-coder.md:32-38` proposes
follow-up guidance ("force a size-based chunk roll") that would not help.
Correct all three to the millisecond-equality explanation.

## N2 — `tests/Client/ProducerTest.php:87,138,242` | medium | open
Existing unit tests mock `readLoop()` and drive the captured `onConfirm`
callback but never assert `readLoop()`'s arguments — which is why #385 shipped
undetected. The fix is fully unit-testable with the existing mock style:
assert `readLoop()` is called with `maxFrames === 1`; assert `TimeoutException`
when the mock never confirms; assert a 3-pending-confirm drain calls
`readLoop()` three times. Per the workflow, this check belongs in this PR.

## N3 — `tests/E2E/ProducerTest.php:138` | low | open
`assertLessThan(2.0, $elapsed)` passes vacuously when `$elapsed == 0`, e.g. a
future regression where `send()` stops incrementing `pendingConfirms` —
`waitForConfirms()` then returns at `Producer.php:114` having awaited nothing.
The test never asserts a confirm arrived. Add an `onConfirm` callback and
assert exactly one successful `ConfirmationStatus`; tighten the bound to 1.0
(observed 0.026 s).

## N4 — `findings-coder.md:40-75` | low | open
The #382 analysis is unsound: a macOS-only `socket_select()` experiment cannot
support a claim about Linux, and Linux's kernel `select` *does* reject
`tv_usec >= 1_000_000` with `EINVAL`. The actual reason CI never fails is that
PHP's `ext/sockets` normalises `usec > 999999` into `tv_sec` before calling
`select()`. Do not use this note to lower #382's priority. For this PR:
`src/StreamConnection.php:446-447` still computes `sec=1, usec=3_999_997` for
a ~5 s `$remaining`, and the new path hits it *more often* (once per dispatched
frame) with the same value, because `$remaining` barely shrinks. Out of scope
to fix here.

## N5 — `tests/E2E/ConsumerTest.php:389-391` | medium | open
Second observed failure mode of the 5 s sleep: one of four full-suite runs
errored with `ConnectionException: Failed to read from socket: connection
closed by peer` at `createProducer()` immediately after the sleep. Resolved by
removing the sleep (FINDING A option (a)).

## N6 — `src/StreamConnection.php:424` | low | open
`readLoop()` sets `$this->running = true` on entry, so a `stop()` issued from a
dispatched callback is silently undone by the next `waitForConfirms()`
iteration. Semantics unchanged by this PR (`Consumer::read()` is identical),
but re-entry is now far more frequent. Informational; worth a PHPDoc sentence.

## N7 — `CHANGELOG.md:7` | nit | open
No `[Unreleased]` entry for #385 yet (step 8's job). Should note the behaviour
change as well as the speedup: `waitForConfirms()` no longer incidentally
services the socket for the whole timeout.

## Not findings — verified correct
- `src/Client/Producer.php:124` — the fix itself: multi-confirm drain is
  correct (deadline anchored once at `:118`, no lost or double-counted
  frames), no busy-spin possible, `timeout: 0` never enters `readLoop()`,
  `timeout: 0.001` behaves as before. No `AGENTS.md` / KB convention
  violations.
- `composer cs`, `composer phpstan` (level 9), `composer rector`,
  `composer kb-lint`, `composer test:unit` all pass.
