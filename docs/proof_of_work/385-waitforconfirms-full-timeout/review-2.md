# Review 2 — issue #385 (`Producer::waitForConfirms()` blocks the full timeout)

**Branch:** `feature/issue-385-waitforconfirms-full-timeout` (commit `c3f0890`)
**Reviewer:** `review-critical`, round 2 (public API surface `Producer::waitForConfirms`
+ interaction with the `socket_select` loop)
**Round-1 record:** `review-1.md`, `findings-review.md`

## Verdict

**Ready for step 7 and step 9.** All five items round 1 required are fixed, verified
independently (including a mutation test of the new unit tests and a bracketed
clock-skew measurement against the running broker). Everything I still have is
`low` or `nit`; none of it should trigger a third round.

---

## 1. Disposition of round-1 findings

### FINDING A (high, blocker) — `usleep(5_000_000)` non-fix — **FIXED**
`tests/E2E/ConsumerTest.php:385` now contains `usleep(20_000)`, the client-clock
sample `$timestamp = (int)(microtime(true) * 1000)` is gone entirely, and the
boundary is derived from broker-written data (`ConsumerTest.php:399-421`). No
`microtime()` call remains anywhere in the test. The wrong root-cause comment is
replaced (`:378-384`). Measured by me on this machine against the running fixture
broker: `--filter testSubscribeFromTimestamp` **8/8 PASS** (0.059-0.071s each),
full e2e suite **6/6 PASS** (16.7 / 18.9 / 19.8 / 19.9 / 18.8 / 19.9s, 128 tests,
1325 assertions each). The `ConnectionException: connection closed by peer` second
failure mode (N5) did not reappear in any of the 6 runs.

### N1 (high) — falsified root cause on the record — **FIXED**
`code-decision-1.md:181-234` ("Correction (round 2, post-review) — the chunk-boundary
story above is wrong") and `findings-coder.md:104-129` state the original claim, refute
it with the evidence, and give the real mechanism (1 ms `>=` equality race). The bogus
follow-up is explicitly **withdrawn** in both files (`code-decision-1.md:229-234`,
`findings-coder.md:125-129`). Original text preserved, as the workflow requires. One
residual (R2-4 below): the corrections sit at the end of each file with no forward
marker on the refuted sections.

### N2 (medium) — unit tests as the real gate — **FIXED, with a caveat**
Three tests added (`tests/Client/ProducerTest.php:290`, `:328`, `:366`). I mutation-tested
them: in a throwaway worktree I reverted `Producer.php:124` to `readLoop(timeout: $remaining)`
and re-ran `--filter testWaitForConfirms`:

```
....F..                                  7 / 7 (100%)
1) ProducerTest::testWaitForConfirmsCallsReadLoopWithMaxFramesOneAndPositiveTimeout
readLoop() must be called with maxFrames === 1
Failed asserting that null is identical to 1.
```

So `testWaitForConfirmsCallsReadLoopWithMaxFramesOneAndPositiveTimeout` **does** fail
against the buggy code — #385 is now guarded by the fastest gate. (Mechanism: PHPUnit's
generated mock always materialises every declared parameter, so a `readLoop(timeout: ...)`
named-arg call hands the callback `maxFrames === null`. Verified directly.)

Caveat, R2-1: the other two new tests pass unchanged against the reverted fix. They are
useful (drain-loop and timeout-path coverage) but they are **not** #385 regression guards.
The PR description should not claim three.

### N3 (low) — vacuous e2e timing assertion — **FIXED**
`tests/E2E/ProducerTest.php:118-122` passes an `onConfirm` collector, `:153-154` assert
`assertCount(1, $confirmations)` + `isConfirmed()`, `:145` tightens the bound to 1.0.
Both are correct: `Producer::declare()` (`src/Client/Producer.php:52-56`) invokes the
user callback once per `publishingId` in the confirm frame, and one `send()` yields
exactly one id, so `assertCount(1, ...)` is exact, not merely "at least one". The
vacuous-`$elapsed == 0` hole is closed — a `send()` that stopped incrementing
`pendingConfirms` now fails on the count, not just on the timing.

### N4 (low) — unsound #382 analysis — **FIXED**
`findings-coder.md:131-155` records that "neither Linux nor BSD enforces the POSIX range"
is not supportable from a macOS-only experiment, that Linux's kernel `select()` *does*
return `EINVAL` for `tv_usec >= 1_000_000`, that the real reason is `ext/sockets`
normalising `usec > 999999` into `tv_sec`, and that the note must not be used to lower
#382's priority. The corrected frequency note ("hit more often, not less") is also there.

### Still open, unchanged, correctly out of scope
- **FINDING B** (low) — `src/StreamConnection.php:477-482` discards non-server-push frames
  without incrementing `$dispatched`. Untouched, still not a correctness problem for this
  fix (deadline re-checked at `:429-431` and `:444-447`). **No follow-up issue exists yet**
  (`gh issue list` shows nothing matching); filing it is a step-9/10 action for the main
  session, not a blocker for the diff.
- **N6** (low, informational) — `readLoop()` sets `running = true` on entry. Unchanged.
- **N7** (nit) — `CHANGELOG.md` still has no `[Unreleased]` entry for #385. Step 8's job.

---

## 2. The host/container clock-skew question — definite answer

**On this setup there is no meaningful skew; on GitHub Actions there is none by
construction. The main session was overcautious about the magnitude — but the
implemented variant is still the better choice, and I withdraw my round-1 recommendation.**

Evidence, measured today against the running `rabbit-stream-rabbitmq-1` container:

1. **Broker-clock vs host-clock, bracketed through the actual stream protocol.** Publish
   one message, sample host `microtime()` immediately before and after the confirm, then
   read the chunk timestamp back via `Message::getTimestamp()`. 8 runs:

   ```
   ts-hostBefore = +0 +0 +0 -1 +0 -1 +0 -1   (ms)
   ts-hostAfter  = -1 -1 -1 -1 +0 -1 +0 -1   (ms)
   bracket       =  1  1  1  0  0  0  0  0   (ms)
   ```

   The broker's chunk timestamp always lands inside a 0-1 ms host bracket. True skew is
   sub-millisecond — i.e. below the resolution of the quantity in dispute.
2. **`docker exec date +%s%3N` bracketed between two host samples:** container time fell
   inside `[H1, H2]` on 3/3 attempts (bracket ~147 ms, dominated by `docker exec` startup),
   consistent with (1) and ruling out any skew of tens of ms.
3. **GitHub Actions service containers** share the host kernel; Docker does not put
   `CLOCK_REALTIME` in a time namespace by default, so a Linux service container reads
   *the same* clock as the runner. Skew is exactly zero there — the environment where CI
   actually runs is the *safest* one for a client-clock spin-wait, not the riskiest.

So the stated mechanism ("Docker Desktop host/container clocks are not sub-millisecond
synchronised") is not true for this setup as measured. Two things do rescue the caution,
partially:

- Even at zero true skew, both clocks are quantised to 1 ms and the sampling shows `-1`
  as often as `+0`. A spin-wait on `host_ms >= brokerChunkTs + 1` can therefore exit up to
  ~1 ms "early" in broker time — which is exactly the margin the round-1 recipe depended on.
- Docker Desktop's VM clock is known to lag transiently after a macOS sleep/suspend until
  it resyncs, which is a dev-laptop-only, non-CI failure mode, but a real one.

**Is the implemented variant nonetheless better? Yes, unambiguously, and it would still be
better if skew were provably zero.** It reads no client clock at all, so the question never
has to be answered; it needs no spin-wait; and its single remaining precondition (the two
batches' chunk timestamps differ) is *asserted* rather than assumed. My round-1 recipe was
correct-in-practice but bought its determinism with a cross-clock comparison, which is a
strictly weaker footing. Round-1 recommendation withdrawn.

---

## 3. `$all[5]` positional indexing — verdict

**Sound. Not "sound by luck" — sound by protocol guarantee.** I was asked to be pedantic,
so, taking each way it could break:

- **Ordering.** A single subscription from `OffsetSpec::first()` receives chunks in
  ascending offset order and entries within a chunk in ascending offset order, and
  `Consumer::read()` appends in parse order. Verified empirically over 6 runs — offsets
  came back `o0..o9` in order, every time.
- **Can the BEFORE batch occupy other than indices 0-4?** No. `waitForConfirms()` returns
  only after all 5 `before-*` confirms, so all five are committed at strictly lower offsets
  before any `after-*` is published. Chunking is irrelevant to *position*: whether the
  before batch is one chunk or three, it is still exactly the first five entries. The
  coder's `max()` over 0-4 correctly handles the multi-chunk case for the timestamp;
  `$all[5]` correctly handles it for the position.
- **Could `$all` contain foreign messages?** No — `setUp()` creates a fresh
  `'test-consumer-' . uniqid()` stream per test and `tearDown()` deletes it, so
  `assertCount(10, $all)` is a real freshness check: any extra message makes the count
  exceed 10 and the test fails loudly.
- **Could the last BEFORE and first AFTER share a chunk?** Then `$afterTs == $beforeTs` and
  `assertGreaterThan` fails with the intended diagnostic. No silent wrong pass.
- **Could a BEFORE chunk have a timestamp *above* `$afterTs`?** Ruled out by the same
  assertion, since `$beforeTs` is the `max()`.

Measured layout, 6 runs (`ts % 100000`):
`before-0..4 @48670 /o0..o4`, `after-0..4 @48696 /o5..o9` — chunk-timestamp gap 23-27 ms
for a 20 ms sleep (the extra comes from producer2's `DeclarePublisher` round trip).

Keying off the body would not be *more* correct, but it would be more self-documenting and
would fail with a better message if the invariant ever broke. See R2-2 — nit, not a defect.

---

## 4. The 20 ms gap: headroom and diagnostics

Needed margin is >0 ms of broker chunk-timestamp difference; measured margin is 23-27 ms,
and the two batches are additionally separated by a producer close, a producer create
(`DeclarePublisher` + response) and 5 publish frames. For the gap to fail, a whole
client-broker round trip plus 20 ms of sleep would have to complete inside one millisecond
— impossible. **Headroom is ample even on a heavily loaded runner**, and unlike a wall-clock
guess, being slower only makes it safer.

Diagnostics on insufficient headroom are good: `assertGreaterThan($beforeTs, $afterTs,
'batches must land in chunks with distinct millisecond timestamps')`
(`ConsumerTest.php:413-417`) fires *before* the subscription, naming the precondition, rather
than surfacing as a `before-0` leak 20 lines later. This is the single biggest improvement
over the old test.

---

## 5. The tightened `assertLessThan(1.0, $elapsed)`

**Safe; keep it.** What the timer spans (`ProducerTest.php:141-143`) is not a confirm round
trip — `send()` happens before `$start`, so by the time `waitForConfirms()` runs the confirm
frame is often already in the kernel buffer; the measured cost is one `select` + one frame
parse. 10 consecutive filtered runs: whole-process wall time 0.020-0.027 s, i.e. `$elapsed`
is well under that. 1.0 s is ~40x headroom over the entire PHPUnit process, and the failure
being guarded against is 5.0 s.

I weighed the "looser e2e bound + strict unit test" argument, since I raised it. It does not
apply here: the unit test that now covers the mechanism deterministically
(`testWaitForConfirmsCallsReadLoopWithMaxFramesOneAndPositiveTimeout`) checks the *argument*,
not the *latency*, so the e2e assertion is not redundant — it is the only check that
`maxFrames: 1` actually produces prompt return against a real broker. Loosening it back to
2.0 would keep the redundancy and lose resolution. If it ever flakes on CI, the right
response is to loosen it then, with the flake as evidence; pre-emptively loosening a bound
with 40x measured headroom is not warranted.

---

## 6. Documentation corrections

`code-decision-1.md`, `findings-coder.md` and the new `code-decision-2.md` do correct the
record properly: the original wrong text is preserved verbatim, each correction states what
was concluded, why it is false, what the evidence was, and what the real mechanism is; the
bogus follow-up is marked withdrawn in both places. `code-decision-2.md` additionally
records the rejected alternative and flags the skew question for this round — exactly the
right way to hand a disagreement forward.

One gap (R2-4): a reader arriving cold reads the *wrong* explanation first
(`findings-coder.md:3-38`, `code-decision-1.md:129-150`) with nothing telling them to keep
reading. A one-line `**Superseded — see "Correction (round 2)" at the end of this file.**`
under each refuted heading would fix it. Low.

---

## 7. `src/Client/Producer.php:124` — unchanged

`git diff main...HEAD -- src/` is a single hunk, and `git diff HEAD~1..HEAD -- src/` is
**empty**. The production change is still exactly:

```php
-            $this->connection->readLoop(timeout: $remaining);
+            $this->connection->readLoop(maxFrames: 1, timeout: $remaining);
```

Confirmed correct in round 1; re-verified against `StreamConnection::readLoop()`
(`src/StreamConnection.php:418`) — no re-analysis needed.

---

## 8. New findings (round 2)

| id | location | description | severity |
|---|---|---|---|
| R2-1 | `tests/Client/ProducerTest.php:328`, `:366` | Both pass against the reverted fix (mutation-verified); only `:290` guards #385. Valuable as drain/timeout coverage, but do not describe them as #385 regression guards in the PR body. | low |
| R2-2 | `tests/E2E/ConsumerTest.php:411` | `$all[5]` is correct but implicit; `assertSame('after-0', $all[5]->getBody())` would make the invariant explicit and improve the failure message. | nit |
| R2-3 | `tests/E2E/ProducerTest.php:116`, `:120` | FQCN in a `@var` docblock plus an untyped closure param, where `ConfirmationStatus` is already imported (`:7`) and typed inline at `:41`, `:61`, `:223`. Typing the param makes the annotation unnecessary. | nit |
| R2-4 | `findings-coder.md:3`, `code-decision-1.md:129` | Refuted sections carry no forward pointer to the appended "Correction (round 2)"; a cold reader meets the wrong explanation first. | low |
| R2-5 | `tests/Client/ProducerTest.php:366` | Overlaps the existing `testWaitForConfirmsThrowsOnTimeout` (`:184`); the new one is distinguished only by `timeout: 0.01` vs `0` (which never enters `readLoop()`), so it does add the "readLoop entered, never confirms" path. It busy-loops the mock for 10 ms, so PHPUnit records a large unbounded number of invocations — measured harmless (unit suite 0.256 s, 46 MB, unchanged). Informational. | nit |

Over-specification check on `testWaitForConfirmsDrainsMultipleConfirmFramesOneAtATime`:
`exactly(3)` is **not** over-specified. The 1-confirm-per-call cadence is imposed by the
*mock*, not by `Producer`, so a legitimate future refactor to drain several confirms per
frame would still produce 3 calls here. It does catch a real regression class (a `while`
degraded to an `if`, which would leave 2 pending and throw `TimeoutException`).

Mock landmine check on `readMessage()->willReturn(new \stdClass())`: not a landmine, and
not new — `StreamConnection::readMessage()` is declared `: object` (`src/StreamConnection.php:364`)
and `Producer::declare()`/`close()` discard the return value without inspecting it. The same
stub is already used at `ProducerTest.php:84`, `:137`, `:195`, `:229`. If a future
`declare()` ever type-checks the response, every one of those tests fails together with a
clear `TypeError` — not a silent pass.

Assertions inside mock callbacks: **not swallowed.** I flipped
`assertNotNull($registeredCallbacks)` to `assertNull(...)` in a throwaway worktree; all
three affected tests failed, with the stack trace correctly showing
`ProducerTest.php:314 -> Producer.php:124 -> ProducerTest.php:321`.

No new `AGENTS.md` violation (round-2 diff is tests + `docs/proof_of_work/` only) and no KB
conflict (`e2e` -> FAQ-001/FAQ-002, `policy` -> DEC-002/DEC-003 re-read; nothing contradicted).

---

## 9. Checks run (my own numbers)

| check | result |
|---|---|
| `composer cs` | PASS (239 files, 1.04 s) |
| `composer phpstan` | PASS, level 9, no errors (235 files) |
| `composer rector` (dry-run) | PASS |
| `composer kb-lint` | PASS (7 entries, 0 warnings, 0 stale) |
| `composer test:unit` | **635 tests, 1377 assertions, OK** (0.256 s; 1 pre-existing risky test, `StreamConnectionTest:567`, unrelated) |
| mutation test: revert `Producer.php:124`, run `--filter testWaitForConfirms` | **1 failure** — `testWaitForConfirmsCallsReadLoopWithMaxFramesOneAndPositiveTimeout` (the gate works) |
| e2e full suite run 1 | OK — 128 tests, 1325 assertions, 16.75 s |
| e2e full suite run 2 | OK — 18.90 s |
| e2e full suite run 3 | OK — 19.81 s |
| e2e full suite run 4 | OK — 19.92 s |
| e2e full suite run 5 | OK — 18.84 s |
| e2e full suite run 6 | OK — 19.91 s |
| `--filter testSubscribeFromTimestamp` x8 | **8/8 PASS**, 0.059-0.071 s each |
| `--filter testWaitForConfirmsReturnsAsSoonAsConfirmArrives` x10 | **10/10 PASS**, 0.020-0.027 s each |

Round 1 saw 2 red runs out of 4 on the same machine and broker; round 2 is 6/6 green with
the suite ~4 s faster (the removed 5 s sleep).

---

## 10. Candidate KB entries (proposed only — `docs/helpers/` is single-writer)

1. **`OffsetSpec::timestamp()` resolves to a chunk, with `>=`, and chunk timestamps are on
   the broker's clock** — tags `e2e, protocol`; trigger "when writing or debugging a test or
   example that uses `OffsetSpec::timestamp()`". Derive boundaries from
   `Message::getTimestamp()` read back from the broker, never from `microtime()`; a longer
   `usleep()` does not help because the gap is on the wrong side of the sample; a
   `PublishConfirm` implies the chunk is committed, so consecutive confirmed batches are
   always in different chunks. (Re-proposed from round 1; round 2 is the worked example.)
2. **`readLoop()` callers must pass `maxFrames` to react to callback-driven state** — tags
   `protocol, socket`; essentially as the coder drafted it, paired with the *unit* test as
   the gate: assert the arguments `readLoop()` is called with, not just that it is called.
3. **A wall-clock `sleep`/`usleep` in an e2e test is a smell; if you must keep one, assert
   the property it is supposed to buy** — tags `e2e`. `#343` removed a sleep, `#385` re-added
   a bigger one that did not work, and round 2 kept a 20 ms one *plus* an assertion that
   verifies its effect. The assertion, not the duration, is what makes it safe.
4. **When a mock-based test exists but asserts nothing about the call the SUT makes, it is
   not a gate** — tags `policy`. `ProducerTest` mocked `readLoop()` for years without
   checking its arguments, which is precisely how #385 shipped. Mutation-test new
   regression tests by reverting the fix.

---

## 11. Ready for step 7 / step 9?

**Yes.** Round 1's blocker and all four secondary findings are resolved, the suite is green
6/6 by my own measurement, the fix is now guarded by a deterministic unit test that I
verified fails without it, and the record is corrected rather than quietly rewritten.

Everything remaining (R2-1 through R2-5) is `low` or `nit`. **Recommend proceeding to step
7 and step 9 without a third review round.** Optional polish, at the main session's
discretion and cheap enough to fold into the PR commit if it wants: R2-2 (one extra body
assertion) and R2-4 (two one-line "superseded" markers). Two step-9/10 bookkeeping items
carry over: file the FINDING B follow-up issue, and add the `CHANGELOG.md` `[Unreleased]`
entry (N7) noting both the speedup and the behaviour change — `waitForConfirms()` no longer
incidentally services the socket for the whole timeout.
