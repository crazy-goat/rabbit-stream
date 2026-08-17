# Review 1 — issue #385 (`Producer::waitForConfirms()` blocks the full timeout)

**Branch:** `feature/issue-385-waitforconfirms-full-timeout` (commit `5c4e675`)
**Reviewer:** `review-critical`, round 1 (mandatory: public API surface +
`socket_select` loop)
**No prior `findings-review.md` existed** — confirmed, this is round 1.

## Verdict

**The production fix is correct. The test changes are not — the PR must not
proceed to step 7 as it stands.**

`src/Client/Producer.php:124` (`readLoop(maxFrames: 1, timeout: $remaining)`)
is right, minimal, matches the existing `Consumer::read()` pattern
(`src/Client/Consumer.php:82`) and violates none of the `AGENTS.md`
conventions or the KB entries that match this diff (FAQ-001, FAQ-002,
DEC-003).

`tests/E2E/ConsumerTest.php:389` (`usleep(5_000_000)`) **does not fix the
flakiness it claims to fix**, and the root-cause analysis recorded in
`code-decision-1.md` / `findings-coder.md` is factually wrong. Measured on
this branch, on this machine, against the running fixture broker:

| scenario | result |
|---|---|
| `phpunit --filter testSubscribeFromTimestamp`, 6 runs, committed 5s sleep | **2 failures** (`before-0` leaked) |
| full e2e suite, 4 runs, committed 5s sleep | **2 red runs** (1 assertion failure, 1 `ConnectionException`) |
| harness replicating the test, `usleep(5_000_000)`, 8 runs | **6 failures** |
| harness, `usleep(100_000)` (the #343 value), 12 + 10 runs | 3/12 and 5/10 failures |
| harness, deterministic boundary (below), 12 runs | **0 failures, 0.16s total** |

## FINDING A — verdict: the change is wrong on the merits, not merely costly

### The chunk-boundary claim is false

The claim "a short gap can put both batches in one chunk" is not what
happens. A `PublishConfirm` is only emitted after osiris has committed the
chunk, so `waitForConfirms()` returning already implies the "before" chunk is
closed; the "after" batch therefore *always* lands in a new chunk. I verified
this directly by reading the chunk timestamps back through
`Message::getTimestamp()` (which is the per-chunk timestamp — see
`src/Client/OsirisChunkParser.php:66` `$timestamp = $buffer->getInt64();
// Chunk timestamp` and `:84`, where every `ChunkEntry` in the chunk is
constructed with that one value). At a 50 ms gap:

```
before-0..4  off=0..4  chunkTs=1786998965467
after-0..4   off=5..9  chunkTs=1786998965523     <- always a different chunk
```

Two distinct chunks at 50 ms, at 10 ms, at 1 ms, and at 0 ms.

### The real cause is a 1-millisecond equality race

`OffsetSpec::timestamp()` (`src/VO/OffsetSpec.php:63`) resolves broker-side to
the first chunk whose timestamp is **>=** the requested value. The test takes
`$timestamp = (int)(microtime(true) * 1000)` immediately after
`waitForConfirms()` returns — which, now that the fix works, is in the *same
millisecond* as the broker wrote the "before" chunk. Correlation over 10 runs
was exact:

```
beforeChunkTs - $timestamp == -1  -> PASS (5x)
beforeChunkTs - $timestamp ==  0  -> FAIL, first message is before-0 (5x)
```

Because the gap is inserted *after* `$timestamp` is sampled, **no value of
`usleep()` can affect this race.** That is confirmed empirically: the
committed `usleep(5_000_000)` failed 6/8 in the harness and 2/6 as the real
PHPUnit test. The coder's "3/3 clean full-suite runs" was luck at roughly a
50 % per-run pass rate (p ~ 0.12).

A second, distinct failure mode also appeared once in a full-suite run — the
5 s idle window on a live connection produced
`ConnectionException: Failed to read from socket: connection closed by peer`
at `tests/E2E/ConsumerTest.php:391` (`createProducer()` right after the
sleep, via `src/Client/Producer.php:77`). So the sleep does not only fail to
help; it adds a new way for the test to go red.

### Verdict

**Severity: high — blocker.** Not "a timing crutch with a runtime cost"; a
non-fix. It leaves the suite red ~30-50 % of the time, costs 5 s (~25 % of
the new 20 s runtime), reverts closed issue #343 to 5x worse than the state
#343 removed, and — worst for the long term — bakes an incorrect explanation
into a permanent code comment at `tests/E2E/ConsumerTest.php:380-388`.

### Recommendation: option (a), a deterministic fix, in this PR

Scope discipline says a pre-existing latent defect belongs in its own issue,
but the fix unmasked it and a red suite cannot be merged; the deterministic
fix is smaller than the sleep it replaces, so splitting it out would cost
more than it saves. Concretely, replace the `$timestamp` sampling and the
sleep with a boundary derived from the data actually stored:

```php
$producer->waitForConfirms(timeout: 5);
$producer->close();

// Derive the boundary from the chunk timestamp the broker actually recorded,
// not from the client clock: OffsetSpec::timestamp() resolves to the first
// chunk whose timestamp is >= the requested value, and chunk timestamps have
// millisecond resolution, so sampling microtime() here races the chunk write
// within the same millisecond.
$probe = $this->connection->createConsumer($this->streamName, OffsetSpec::first());
$beforeChunkTs = 0;
$seen = 0;
$deadline = time() + 5;
while ($seen < 5 && time() < $deadline) {
    foreach ($probe->read(timeout: 0.5) as $m) {
        $seen++;
        $beforeChunkTs = max($beforeChunkTs, $m->getTimestamp());
    }
}
$probe->close();
$this->assertSame(5, $seen);

$timestamp = $beforeChunkTs + 1;               // strictly past the "before" chunk
while ((int)(microtime(true) * 1000) < $timestamp) {
    usleep(200);                               // <= 1 ms in practice
}
// ... publish after-0..4 as today, then subscribe with OffsetSpec::timestamp($timestamp)
```

Why this is deterministic, not a shorter guess:
- `$beforeChunkTs + 1` is strictly greater than the "before" chunk timestamp,
  so that chunk can never be selected — the equality race is gone by
  construction, not by margin.
- The `while` loop guarantees the wall clock has already passed the boundary
  millisecond before the "after" batch is published, so the "after" chunk
  timestamp is necessarily `>= $timestamp` and cannot be skipped (the
  `count === 0` failure mode I saw at a 0 ms gap).
- No wall-clock guess anywhere; nothing depends on broker load.

Measured: **12/12 PASS, 0.16 s for all 12 runs** (~13 ms per run) versus 5 s
and ~50 % flaky today. Also worth adding, as a self-check inside the test:
`assertGreaterThan($beforeChunkTs, $afterFirstMessage->getTimestamp())`.

Options (b) and (d) were rejected because the sleep length is irrelevant to
the actual race; (c) (weakening the assertion) would delete the only
coverage of `OffsetSpec::timestamp()` semantics; (e) is the acceptable
fallback if the main session insists on strict scope separation — revert
`ConsumerTest.php` to `usleep(100_000)` and file a blocking issue — but note
that the test is then flaky at ~30 %, so the issue must block the merge, not
follow it.

## FINDING B — verdict: pre-existing, low, not part of this change's correctness story

`src/StreamConnection.php:477-482` discards a non-server-push frame without
incrementing `$dispatched`. Consequences for the new code path:

- **Cannot make `waitForConfirms()` overrun.** `readLoop()` re-checks its own
  `$deadline` at the top of every iteration (`:429-431`) and again before
  `socket_select()` (`:444-447`), so discarded frames keep the call inside
  `readLoop()` but never past `$remaining`.
- **Cannot busy-spin.** Every iteration of that branch requires
  `socket_select()` to report readable *and* `readFrame(timeout: 0.0)` to
  return a complete frame — i.e. real bytes from the peer.
- The `timeout: null` spin the coder describes is real but unreachable from
  `waitForConfirms()`, which always passes a positive `$remaining`.

So this weakens the *guarantee* ("`maxFrames: 1` returns after one frame")
but not the *fix*. **Severity: low, file separately.** Preferred resolution
in that separate issue: count discarded frames toward `$maxFrames` (they are
still frames the caller paid a syscall for), or bound the discard branch with
a counter, and add a unit test for it — `tests/StreamConnectionTest.php`
already drives `readLoop()` with a socket pair style setup.

## New findings

### N1 — recorded root cause is wrong (high)
`docs/proof_of_work/385-waitforconfirms-full-timeout/code-decision-1.md:150-176`,
`findings-coder.md:5-30`, and the comment at `tests/E2E/ConsumerTest.php:380-388`
all state that the two batches can share a chunk. They cannot (evidence
above). This matters beyond tidiness: it is the justification for the wrong
fix, it will be read as authoritative by the next person touching timestamp
offsets, and `findings-coder.md:32-38` proposes turning it into guidance
("publish enough volume to force a size-based chunk roll") that would not
help. Fix: correct all three to the millisecond-equality explanation.

### N2 — no unit test asserts `maxFrames: 1` (medium)
`tests/Client/ProducerTest.php:87`, `:138`, `:242` already mock
`StreamConnection::readLoop()` and drive the captured `onConfirm` callback,
and `tests/Client/ProducerTest.php:96-109` already exercises the timeout
path — yet none of them assert `readLoop()`'s **arguments**, which is exactly
why #385 shipped. The whole fix is unit-testable without a broker, at level 9:

```php
$connection->expects($this->once())->method('readLoop')
    ->with($this->identicalTo(1), $this->anything())
    ->willReturnCallback(function () use (&$onConfirm): void { $onConfirm([0]); });
```

Plus a second test: `readLoop` that never confirms -> `TimeoutException`, and
a third: 3 pending confirms delivered one frame at a time -> `readLoop` called
3 times, returns without throwing (this is the multi-confirm drain, currently
only covered incidentally by e2e). Per the workflow ("if the same class of
defect has been seen before, the check should be written in this PR"): the
defect class is "existing mock-based test calls the method under test but
asserts nothing about the call it makes", and the check is these unit tests.
They should be added here — the e2e test alone leaves the fastest gate blind.

### N3 — the new e2e assertion can pass vacuously and is loose (low)
`tests/E2E/ProducerTest.php:138` — `assertLessThan(2.0, $elapsed)` is
satisfied by `$elapsed == 0`, which is what a future regression where
`send()` stops incrementing `pendingConfirms` would produce: `waitForConfirms()`
returns at `Producer.php:114` without waiting for anything and the test still
goes green. The test never asserts a confirm was actually received. Fix: pass
an `onConfirm` callback to `createProducer()` and assert exactly one
`ConfirmationStatus` with `true` arrived, and tighten the bound to `1.0`
(observed 0.026 s; 1.0 still catches the 5.0 s pre-fix behaviour with 5x
headroom, whereas 2.0 lets a hypothetical 1.9 s regression through).
Pre/post-fix discrimination itself is fine — I reproduced the pre-fix
behaviour class independently.

### N4 — the #382 reasoning is unsound; do not let it downgrade #382 (low, docs)
`findings-coder.md:40-75` concludes "neither Linux nor BSD enforces the POSIX
range, that is why CI passes". The experiment (macOS only) cannot support a
claim about Linux, and the mechanism given is wrong in both directions:
Linux's `select` **does** reject `tv_usec >= 1_000_000` with `EINVAL` at the
kernel level. The reason nothing ever fails is one layer higher — PHP's
`ext/sockets` normalises `usec > 999999` into `tv_sec` before calling
`select()` (the "Solaris + BSD do not like microsecond values >= 1 sec"
branch), so the overflow never reaches libc from PHP. A single-platform
`socket_select()` test cannot distinguish PHP-level from libc-level
normalisation, so this note should not be used to lower #382's priority
without checking the PHP source.
**Frequency answer for this PR:** the value passed is unchanged and is hit
*more often*, not less. `$remaining` is recomputed from a deadline anchored
once at `Producer.php:118`, and post-fix each `readLoop()` re-entry happens
milliseconds later, so `$remaining` is still ~5.0 and
`src/StreamConnection.php:446-447` still yields `sec=1, usec=3_999_997` —
now once per dispatched frame instead of once per `waitForConfirms()` call.
Correctly out of scope here.

### N5 — second failure mode of the 5 s sleep (medium, subsumed by A)
`tests/E2E/ConsumerTest.php:389-391` — one of four full-suite runs died with
`ConnectionException: connection closed by peer` at `createProducer()`
immediately after the sleep. Holding a live stream connection idle for 5 s
inside a test is a new liability regardless of the assertion race. Removing
the sleep (option (a)) removes this too.

### N6 — `readLoop()` re-entry silently undoes `stop()` (low, informational)
`src/StreamConnection.php:424` sets `$this->running = true` on entry, so a
`stop()` called from within a dispatched callback is cancelled by the outer
`while` in `waitForConfirms()` on the next iteration. Semantics are unchanged
by this PR (`Consumer::read()` has always had the same shape and the pre-fix
`waitForConfirms()` re-entered too), but the fix multiplies the re-entries.
No action needed in this PR; worth a sentence in `readLoop()`'s PHPDoc.

### N7 — `CHANGELOG.md` `[Unreleased]` entry missing (nit)
No #385 entry yet (step 8's job — noted only). It should mention the
behavioural consequence as well as the speedup: `waitForConfirms()` no longer
incidentally services the socket (heartbeats, deliveries for other consumers
on the same connection) for the whole timeout.

## Correctness questions I was asked to check

- **Multiple confirm frames:** correct. `pendingConfirms` is decremented by
  `count($publishingIds)` per frame (`Producer.php:50`), the outer `while`
  re-checks after every frame, and the deadline is anchored once
  (`Producer.php:118`) so no time is lost or double-counted across
  re-entries. Frames already in the kernel buffer when `maxFrames` trips are
  simply read by the next entry — nothing is dropped.
- **Busy-spin:** no. `readLoop()` returns only via deadline expiry
  (`:429-431`, `:444-447`), `maxFrames` after a real dispatched frame
  (`:470-486`), or disconnect. The two `continue` paths (`:459` select
  timeout, `:465` `readFrame()` returned null) stay *inside* `readLoop()` and
  re-`select()`, so they cost one syscall per iteration at worst and are
  bounded by the deadline; EOF throws at `:694` rather than looping. With a
  sub-millisecond `$remaining` the outer `while` breaks on `$remaining <= 0`.
- **`timeout: 0` / `timeout: 0.001`:** still correct.
  `tests/E2E/ProducerTest.php` `testWaitForConfirmsTimeoutThrows` (timeout 0)
  never reaches `readLoop()` at all — `$remaining <= 0` breaks immediately and
  `TimeoutException` is thrown. `testProducerRemainsUsableAfterTimeout`
  (0.001) performs one ~1 ms `select` and then throws; the theoretical race
  (confirm arriving inside 1 ms) is unchanged from pre-fix. Both pass.

## Checks run

| check | result |
|---|---|
| `composer cs` | PASS (239 files) |
| `composer phpstan` | PASS, level 9, no errors (235 files) |
| `composer rector` (dry-run) | PASS |
| `composer kb-lint` | PASS (7 entries, 0 warnings, 0 stale) |
| `composer test:unit` | 632 tests, 1364 assertions, OK (1 pre-existing risky test, `StreamConnectionTest:567`, unrelated) |
| e2e full suite, run 1 | **ERROR** — `ConsumerTest::testSubscribeFromTimestamp`, connection closed by peer (24.1 s, 128 tests) |
| e2e full suite, run 2 | OK (22.9 s) |
| e2e full suite, run 3 | **FAILURE** — `testSubscribeFromTimestamp`, got `before-0` (24.0 s) |
| e2e full suite, run 4 | OK (25.1 s) |
| `--filter testSubscribeFromTimestamp` x6 | 4 PASS / **2 FAIL** |

The 302 s -> ~23 s speedup claim reproduces. The suite is **not green**.

## Candidate KB entries (proposed only — `docs/helpers/` is single-writer)

1. **`OffsetSpec::timestamp()` resolves to a chunk, and it resolves with `>=`**
   tags: `e2e, protocol` — trigger: "when writing or debugging a test or
   example that uses `OffsetSpec::timestamp()`". Chunk timestamps are
   per-chunk and millisecond-resolution (`OsirisChunkParser` header,
   surfaced by `Message::getTimestamp()`); resolution picks the first chunk
   with `chunkTs >= requested`, so a client-sampled `microtime()` boundary
   races the chunk write within the same millisecond. Derive boundaries from
   `Message::getTimestamp() + 1`, never from the client clock; a bigger
   `usleep()` does not help because the gap is on the wrong side of the
   sample. A `PublishConfirm` implies the chunk is committed, so consecutive
   confirmed batches are always in different chunks.
2. **`readLoop()` callers must pass `maxFrames` to react to callback-driven
   state** — tags: `protocol, socket` — essentially as the coder drafted it
   in `findings-coder.md`; accurate and worth keeping. Pair it with the N2
   unit tests as the actual gate.
3. **A wall-clock `sleep`/`usleep` in an e2e test is a smell, not a fix** —
   tags: `e2e` — #343 removed one, #385 re-added a bigger one, and the bigger
   one did not work. Before adding or lengthening a sleep, prove the failure
   mode by reading back what the broker stored.

## Ready for step 7?

**No.** Required before step 7:
1. Fix `tests/E2E/ConsumerTest.php` per FINDING A option (a) and drop the 5 s
   sleep; the suite must go green over at least 5 consecutive runs.
2. Correct the wrong root-cause explanation in the two proof-of-work files
   and the in-test comment (N1).
3. Add the unit tests from N2 (the fastest gate for this defect class).

Recommended but not blocking: N3 (strengthen the new assertion), N4 (correct
the #382 note so it does not mislead that issue's triage), and file separate
issues for FINDING B and, if not fixed here, the `ConsumerTest` timestamp
determinism.
