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

---

# Findings — review — issue #385 (round 2, commit `c3f0890`)

Nothing above is deleted or edited. Dispositions and new findings are appended.
Full reasoning and measurements in `review-2.md`.

## Disposition of round-1 findings

- **FINDING A** (high, blocker) — **FIXED.** `tests/E2E/ConsumerTest.php:385` is now
  `usleep(20_000)`, the `microtime()` boundary sample is gone (no `microtime()` call
  remains in the test), and the boundary comes from broker data
  (`ConsumerTest.php:399-421`). My own runs: full e2e suite **6/6 green**
  (16.75/18.90/19.81/19.92/18.84/19.91 s), `--filter testSubscribeFromTimestamp`
  **8/8 PASS** (0.059-0.071 s). N5's `connection closed by peer` did not reappear.
  Round-1 recommendation withdrawn in favour of the implemented skew-immune variant
  (see "clock skew" below).
- **N1** (high) — **FIXED.** `code-decision-1.md:181-234` and `findings-coder.md:104-129`
  preserve the original text, refute it with evidence, state the 1 ms `>=` equality race
  as the real mechanism, and mark the "force a size-based chunk roll" follow-up
  **withdrawn** (`code-decision-1.md:229-234`, `findings-coder.md:125-129`). The wrong
  in-test comment is replaced (`ConsumerTest.php:378-384`). Residual: R2-4.
- **N2** (medium) — **FIXED.** `tests/Client/ProducerTest.php:290`, `:328`, `:366`.
  Mutation-verified: reverting `Producer.php:124` to `readLoop(timeout: $remaining)` makes
  `testWaitForConfirmsCallsReadLoopWithMaxFramesOneAndPositiveTimeout` fail
  ("Failed asserting that null is identical to 1"). The other two still pass against the
  bug — see R2-1.
- **N3** (low) — **FIXED.** `tests/E2E/ProducerTest.php:118-122` collects confirms,
  `:153-154` assert exactly one confirmed `ConfirmationStatus`, `:145` tightens the bound
  to 1.0. `assertCount(1, ...)` is exact given `Producer.php:52-56`. The tightening is
  safe: 10/10 runs at 0.020-0.027 s whole-process wall time (~40x headroom).
- **N4** (low) — **FIXED.** `findings-coder.md:131-155` records the unsoundness, the
  `ext/sockets` normalisation as the actual reason, the `EINVAL` correction for Linux, the
  instruction not to downgrade #382 on this basis, and the corrected frequency note.
- **N5** (medium) — **FIXED** (subsumed by A). Absent from 6/6 full-suite runs.
- **FINDING B** (low) — **STILL PRESENT**, untouched at `src/StreamConnection.php:477-482`,
  correctly out of scope. **No follow-up issue exists yet** — step-9/10 action.
- **N6** (low, informational) — **STILL PRESENT**, `src/StreamConnection.php:424`. No action.
- **N7** (nit) — **STILL PRESENT.** `CHANGELOG.md` has no `[Unreleased]` entry for #385
  (step 8).

## Clock skew — the question `code-decision-2.md` asked this round

**No meaningful skew on this setup; none at all on GitHub Actions.** Bracketed measurement
through the stream protocol (host `microtime()` before/after a confirm vs the chunk
timestamp read back), 8 runs: chunk timestamp inside a 0-1 ms host bracket every time
(`ts - hostBefore` in {0, -1}). `docker exec date +%s%3N` fell inside the host bracket 3/3.
GHA service containers share the runner's kernel and `CLOCK_REALTIME`, so skew there is
zero by construction. The stated mechanism was therefore overcautious — but **the
implemented variant is still the better choice**, because it compares no client clock to a
broker clock at all, needs no spin-wait, and asserts its one precondition. Two residual
grains of truth: 1 ms quantisation makes a `host >= brokerTs + 1` spin-wait exit up to ~1 ms
early in broker time, and Docker Desktop VM clocks lag transiently after a macOS suspend
(dev-only, not CI).

## `$all[5]` positional indexing — sound

Sound by protocol guarantee, not by luck. Single subscription from `OffsetSpec::first()`
delivers ascending offsets (verified: `o0..o9` in order, 6/6 runs); `waitForConfirms()`
guarantees all 5 `before-*` are committed at lower offsets before any `after-*` is
published, so indices 0-4 are the before batch regardless of how it chunks; `setUp()`'s
`uniqid()` stream plus `assertCount(10, $all)` rules out foreign messages. A shared chunk
across the boundary, or a before chunk newer than `$afterTs`, both fail loudly on
`assertGreaterThan`. Measured layout: `before-0..4 @T /o0..o4`, `after-0..4 @T+23..27ms
/o5..o9`. Body-keying would be more self-documenting but not more correct — R2-2, nit.

## New findings (round 2)

### R2-1 — `tests/Client/ProducerTest.php:328`, `:366` | low | open
`testWaitForConfirmsDrainsMultipleConfirmFramesOneAtATime` and
`testWaitForConfirmsThrowsTimeoutExceptionWhenNoConfirmEverArrives` both PASS against the
reverted fix (mutation-verified), because the mock's behaviour, not `Producer`'s, sets the
cadence. Only `:290` is a #385 regression guard. The other two are legitimate drain/timeout
coverage — just don't call them #385 guards in the PR body. `exactly(3)` is **not**
over-specified: the cadence is mock-imposed, so a future multi-confirm-per-frame refactor
would not break it, and it does catch a `while` degraded to `if`.

### R2-2 — `tests/E2E/ConsumerTest.php:411` | nit | open
`$afterTs = $all[5]->getTimestamp()` is correct but leaves the "index 5 is `after-0`"
invariant implicit. `assertSame('after-0', $all[5]->getBody())` before it would make it
explicit and give a better failure message.

### R2-3 — `tests/E2E/ProducerTest.php:116`, `:120` | nit | open
`/** @var \CrazyGoat\RabbitStream\Client\ConfirmationStatus[] ... */` uses an FQCN although
the class is imported at `:7`, and the closure param is untyped, unlike `:41`, `:61`, `:223`
in the same file. Typing the param `ConfirmationStatus $status` removes the need for the
annotation and matches the file's convention.

### R2-4 — `findings-coder.md:3`, `code-decision-1.md:129` | low | open
The corrections are appended at the end of each file with no forward pointer on the refuted
sections, so a reader arriving cold meets the falsified explanation first. Add
`**Superseded — see "Correction (round 2)" at the end of this file.**` under each.

### R2-5 — `tests/Client/ProducerTest.php:366` | nit | open
Overlaps `testWaitForConfirmsThrowsOnTimeout` (`:184`); it does cover a distinct path
(`timeout: 0.01` enters `readLoop()`, `timeout: 0` never does). It busy-loops the mock for
10 ms, so PHPUnit records an unbounded number of invocations — measured harmless (unit
suite 0.256 s / 46 MB, unchanged). Informational.

## Not findings — verified this round
- `src/Client/Producer.php:124` **unchanged from round 1** (`git diff HEAD~1..HEAD -- src/`
  is empty). Still correct.
- `readMessage()->willReturn(new \stdClass())` is not a landmine: `readMessage()` is declared
  `: object` (`src/StreamConnection.php:364`) and both callers discard the value; the same
  stub is pre-existing at `ProducerTest.php:84`, `:137`, `:195`, `:229`.
- `$this->assertNotNull(...)` inside a mock callback surfaces correctly — verified by
  inverting it: all three tests failed with a clean trace
  (`ProducerTest.php:314 -> Producer.php:124 -> ProducerTest.php:321`). Nothing swallowed.
- 20 ms gap headroom: measured chunk-timestamp delta 23-27 ms against a >0 ms requirement,
  and insufficient headroom fails on a named assertion before the subscription, not as a
  mystery `before-0` leak.
- `composer cs`, `composer phpstan` (level 9), `composer rector`, `composer kb-lint`,
  `composer test:unit` (635 tests / 1377 assertions, OK) all pass. No new `AGENTS.md` or KB
  violations (round-2 diff is tests + `docs/proof_of_work/` only).

## Verdict
**Ready for step 7 and step 9.** All remaining findings are `low`/`nit`; no third review
round is warranted.
