# Review Round 2 (convergence) — Issue #443, branch `feature/issue-443-timestamp-docs`

**Commit:** `ca8993f` — `docs: sweep remaining per-message timestamp claims and disposition #443 review 1`
**Reviewer:** review agent
**Date:** 2026-09-21
**Base of comparison:** `git diff main...HEAD` (HEAD `ca8993f`)
**Scope of round 1:** LOW 1/2/3, NIT 4/5 (commit `2992f03`).

---

## Verdict

**No open HIGH, MEDIUM or LOW findings remain.** All three round-1 LOWs and both
NITs are correctly dispositioned: the same-class sweep is complete, the two
deferrals are recorded and still valid, and the E2E comment now cites the real
code. The chunk-granular semantics are accurate, #418 was respected, and there
is no behaviour change. **This PR is converged.**

One non-blocking observation (`docs/en/examples/basic-consumer.md:253`) is
recorded under "New-issue sweep"; it is the same #418 unit/client-clock class as
the already-deferred `consuming.md:220`, does **not** state per-message
semantics, and is therefore not #443 scope.

---

## Round-1 findings — verification with evidence

### LOW 1 — same per-message claim in unowned docs → FIXED

Each previously-missed location was re-read; all six now state chunk granularity
and none still asserts per-message `OffsetSpec::timestamp()` semantics:

| Location | Current text | Status |
|---|---|---|
| `docs/en/api-reference/value-objects.md:23` | `TYPE_TIMESTAMP` "Start at the first chunk whose chunk timestamp is `>=` the value (chunk-granular, delivered in full)" | ✅ |
| `docs/en/api-reference/value-objects.md:971` | `$timestamp` "Chunk timestamp shared by every entry of the chunk (milliseconds since the Unix epoch)" | ✅ |
| `docs/en/api-reference/value-objects.md:997` | `getTimestamp()` "Returns the chunk timestamp ... shared by every entry of the chunk." | ✅ |
| `docs/en/guide/flow-control.md:633` | "Start at the first chunk with chunk timestamp >= the value (chunk-granular)" | ✅ |
| `docs/en/protocol/consuming-commands.md:59` | "Start at the first chunk with chunk timestamp >= the value (chunk-granular), followed by uint64 ms" | ✅ |
| `docs/en/api-reference/connection.md:869` | "Start at the first chunk with chunk timestamp `>=` the value, delivered in full (chunk-granular)" | ✅ |

Scope discipline held: only the granularity statement changed. No `first`/`last`/
`next`/`offset`/`interval()` docblocks and no ms/s unit correction were added to
`OffsetSpec`. `git diff main...HEAD -- src/` is still +42/-0, docblocks only.

### LOW 2 — `docs/en/guide/consuming.md:220` deferred to #418 → CORRECT, #418 OPEN

- The line is unchanged: `Processing recent data only?   →  OffsetSpec::timestamp(time() - 3600)`
  (client clock, seconds) — the #418 unit fix, deliberately left.
- `gh issue view 418` → `state: OPEN`, milestone **v1.5.0**, title *"Docs:
  OffsetSpec factories are undocumented, and timestamp() units are documented
  wrongly (ms vs s)"*. Confirmed still open.

### LOW 3 — `docs/en/api-reference/message.md:530-531` follow-up candidate → RECORDED

- The bug is still present at `message.md:529-531`
  (`$latency = $serverTime - $creationTime;` printed as "seconds" while both
  values are ms).
- Recorded as a follow-up in **both** `code-decision-2.md` (LOW 3, with the
  proposed `intdiv($serverTime - $creationTime, 1000)` fix) and
  `findings-review.md` round-1 row 3. Correctly excluded from #443 and from
  #418.

### NIT 4 — stale `OsirisChunkParser.php` refs in E2E comment → FIXED

`tests/E2E/ConsumerTest.php:432-434` now cites `:324` (header read once), `:210`
(plain), `:264` (sub-batch), `:432`/`:483` (zero-copy view). Spot-checked the
file: line 324 is `$timestamp = $buffer->getInt64();`, 210/264 stamp the raw
`$timestamp`, 432/483 stamp the zero-copy view. References are accurate.

### NIT 5 — `docs/en/api-reference/connection.md:869` → FIXED

Now "Start at the first chunk with chunk timestamp `>=` the value, delivered in
full (chunk-granular)". ✅ (same change as LOW 1.)

---

## Final grep sweep — remaining timestamp phrasing

Searched `docs/`, `src/`, `README.md` (excluding `docs/proof_of_work/`) for
`published after`, `messages after`, `messages at or after`,
`from messages`, `specific timestamp`, `when the message was published`,
`publish time`, `seconds since epoch`. Results:

- **No remaining per-message / "messages published after" claim about
  `OffsetSpec::timestamp()` anywhere.** Hits for "messages after" are all
  "reprocess up to N messages after crash" (auto-commit) — unrelated.
- Correct chunk-granular wording now in `message.md`, `consumer.md`,
  `value-objects.md`, `flow-control.md`, `consuming-commands.md`,
  `connection.md`, `consuming.md`, `offset-tracking.md`, plus the internal
  `docs/helpers/faq.md` (FAQ-004, correctly chunk-granular, read-only).
- `CHANGELOG.md:75` (an *old* #155 release note) still says "messages published
  after a recorded timestamp are delivered while earlier messages are skipped".
  This is historical changelog prose for a shipped version, not a live user
  doc; rewording released changelog entries is out of scope. Left as-is.
- `docs/en/api-reference/value-objects.md:124` "Create an offset spec for a
  specific timestamp" is silent on granularity, not false. That factory section
  is #418's (all six factories); the enum table at `:23` already carries the
  chunk statement, so the page does not contradict itself.
- `docs/en/api-reference/message.md:538` "`getTimestamp()` is set by the server
  when received" is imprecise about *when* but does not assert per-message
  semantics, and the same file states the chunk semantics in full. Nit at most;
  no action.

**Judgement:** none of the remnants is genuinely #443's scope. The false
per-message claim is gone; the leftovers are #418's unit/client-clock work or
historical changelog.

---

## New-issue sweep (`git diff main...HEAD`)

Files touched: 2 `src/` docblocks, 8 English doc pages, `CHANGELOG.md`, one E2E
comment, and 6 proof-of-work files. No executable line changed.

New observations (none an open #443 finding):

1. **`docs/en/examples/basic-consumer.md:253`** — `OffsetSpec::timestamp(time() - 3600)`
   under "// From a specific timestamp". Same #418 class as the deferred
   `consuming.md:220` (client clock, seconds) and was not listed in round 1's
   "remaining seconds examples". It does **not** claim per-message semantics, so
   it is **not #443 scope** — it belongs with #418's unit fix. Recording it here
   so #418's implementer sweeps it too.
2. **`message.md:529-531`** — the ms-labelled-seconds latency example is the
   already-recorded follow-up candidate (round-1 LOW 3). Still open as a future
   issue; correctly not fixed here.

No new correctness, security, regression or test-coverage issue was introduced.

---

## Checks run (round 2)

```
composer lint                              → OK
  phpcs PSR-12 (281 files)                 → pass
  rector dry-run                            → OK
  phpstan level 9 (275 files)               → no errors
  kb-lint (docs/helpers)                    → 12 entries, 0 warnings
  check-docs-links (docs/en)                → all relative links resolve
  test:suite-coverage                       → 150 files all covered
./vendor/bin/phpunit --testsuite unit       → OK (1242 tests, 8797 assertions)
```

No E2E run: documentation + comment-only change; the E2E timestamp comment was
verified against the code by reading, not re-running.

---

## Summary

| Round-1 item | Severity | Disposition verified |
|---|---|---|
| LOW 1 same-class claims | low | FIXED — all six locations correct |
| LOW 2 `consuming.md:220` | low | DEFERRED to #418 (OPEN) — correct |
| LOW 3 `message.md:530-531` | low | Recorded as follow-up candidate — correct |
| NIT 4 E2E refs | nit | FIXED — refs match code |
| NIT 5 `connection.md:869` | nit | FIXED |

**Accuracy:** chunk-granular semantics correct. **#418:** untouched, open.
**Named locations:** all fixed. **Behaviour:** none changed.
**Open HIGH/MEDIUM/LOW for #443: NONE.** **Lint/unit:** green.
