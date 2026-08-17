# Code decision 2 — fix `ConsumerTest::testSubscribeFromTimestamp` for real

**Issue:** #385 (round 2, post-review)
**Branch:** `feature/issue-385-waitforconfirms-full-timeout`

## What was asked

Round-1 critical review (`review-1.md`) and an independent deep-reasoning
consult both empirically refuted the round-1 diagnosis of the
`testSubscribeFromTimestamp` flake (see the "Correction (round 2)" sections
appended to `code-decision-1.md` and `findings-coder.md`) and showed the
committed `usleep(5_000_000)` does not fix it — it fails at roughly a 50%
rate and adds a second failure mode (a `ConnectionException` from a 5s idle
connection). The task: replace it with a deterministic fix.

## Root cause, confirmed

`OffsetSpec::timestamp()` (`src/VO/OffsetSpec.php:63`) resolves broker-side
to the first chunk whose timestamp is `>=` the requested value, and
delivers that chunk in full from its first offset. The old test sampled
`$timestamp = (int)(microtime(true) * 1000)` on the client, immediately
after `waitForConfirms()` returned — which, now that #385 is fixed, is in
the *same millisecond* the broker committed the "before" chunk. When
`beforeChunkTs == $timestamp`, the `>=` comparison selects the "before"
chunk and `before-0` leaks into the "after" read. The gap (`usleep`) is
inserted *after* the client samples `$timestamp`, so no gap length can
affect this race — it is a client-clock-vs-broker-clock equality problem,
not a "did enough time pass" problem.

## Approach taken: derive the boundary from broker-written data

Implemented in `tests/E2E/ConsumerTest.php::testSubscribeFromTimestamp`:

1. Publish 5 `before-*`, `waitForConfirms`, close producer1 — unchanged.
2. `usleep(20_000)` (20ms) — not a wall-clock boundary, just enough to make
   the two chunk timestamps differ by at least 1ms. The next step's
   assertion verifies this rather than assuming it, so if 20ms ever turned
   out insufficient under load, the test would fail with a precise
   diagnostic (`assertGreaterThan($beforeTs, $afterTs, ...)`) instead of a
   mystery `before-0` leak.
3. Publish 5 `after-*`, `waitForConfirms`, close producer2 — unchanged.
4. Read the whole stream back from `OffsetSpec::first()` in a
   deadline-guarded loop until 10 messages arrive, `assertCount(10, ...)`.
   Then derive `$beforeTs = max()` of `getTimestamp()` over the first 5
   messages (`max()`, not `$all[0]`, because the before-batch can itself
   split across two chunks if a chunk rolls mid-batch) and
   `$afterTs = $all[5]->getTimestamp()`. Assert
   `assertGreaterThan($beforeTs, $afterTs, ...)`.
5. Subscribe with `OffsetSpec::timestamp($afterTs)`. By the `>=` semantics
   this resolves exactly to the after-chunk (never the before-chunk, since
   `$afterTs > $beforeTs` by construction of step 4's assertion). Kept the
   read loop, but strengthened the assertions: `assertCount(5, $received)`
   plus an exact body check for `after-0`..`after-4` (was previously only
   checking `$received[0]`).

The client's wall clock (`microtime()`) is never read at any point in this
test now — only `Message::getTimestamp()` (the broker-assigned, per-chunk
timestamp) is used for the boundary decision.

## What was rejected and why

**The review's own proposed variant** (`$timestamp = $beforeChunkTs + 1`,
then a client-side spin-wait `while ((int)(microtime(true)*1000) <
$timestamp) usleep(200);` before publishing "after") was **not**
implemented, despite being validated by the review at 12/12 passes in
0.16s. Rejected because it mixes clocks in a way that can fail in the other
direction: `$beforeChunkTs` is the broker's clock; the spin-wait's
`microtime()` is the *client's* (test-runner host) clock. Under Docker
Desktop, host and container clocks are not guaranteed to be
sub-millisecond synchronized — if the host clock runs even slightly ahead
of the broker's clock (e.g. by scheduling jitter or a small NTP-uncorrected
skew), the spin-wait's `microtime()` check could already exceed
`$beforeChunkTs + 1` immediately, exiting the wait with less than 1ms of
real elapsed time and no guarantee the *next* publish lands in a
new-enough chunk — reproducing exactly the equality race being fixed, just
moved to a different clock pair. The implemented approach never compares a
client clock to a broker clock at all: every timestamp used in a
comparison (`$beforeTs`, `$afterTs`) comes from `Message::getTimestamp()`,
so the only remaining precondition is "the two publish calls, which are
strictly sequential real events with a real broker committing between
them, produced different millisecond chunk timestamps" — verified by the
explicit assertion in step 4 rather than assumed.

This is deliberately a different (and, if anything, more conservative)
choice than the review's own recommendation; flagging it explicitly for
the next review round in case the skew concern is judged overcautious for
this environment.

## Measured results

- `--filter testSubscribeFromTimestamp`, 12 consecutive runs: **12/12 PASS**,
  ~0.21-0.30s wall time per run (each run is the full PHPUnit bootstrap;
  the test body itself is a small fraction of that — no more multi-second
  sleeps).
- Full suite (`RABBITMQ_HOST=127.0.0.1 RABBITMQ_PORT=5552 ./vendor/bin/phpunit
  --testsuite e2e`), 5 consecutive runs: see `findings-coder.md` / final
  report for the per-run numbers recorded at the time of the PR.
- The `ConnectionException: connection closed by peer` failure mode
  (previously seen once in 4 runs with the 5s sleep in place) was
  investigated: it did not reappear in any of the runs performed in this
  round. Consistent with the review's suspicion that it was caused by
  holding the connection idle for 5s (now removed entirely — the longest
  deliberate pause in the test is the 20ms in step 2).

## What remains uncertain

- Whether 20ms is enough headroom under significantly slower or more loaded
  CI hardware than this dev machine to guarantee two chunk commits land in
  different milliseconds. The test cannot silently pass if it isn't — the
  `assertGreaterThan($beforeTs, $afterTs, ...)` in step 4 will fail with a
  clear message ("batches must land in chunks with distinct millisecond
  timestamps") rather than the old test's `before-0` leak, so if this ever
  becomes a problem in CI it will report as an actionable message here, not
  as an unrelated-looking `after-0` mismatch.
- Whether the review's spin-wait variant would actually have been unsafe
  in this specific Docker Desktop setup, or whether that risk is
  theoretical here (this project's e2e run instructions point at
  `127.0.0.1:5552`, i.e. host-network-mapped, which may or may not imply
  meaningful host/container clock skew in this particular setup). Flagging
  for the next review round rather than asserting it as fact.
