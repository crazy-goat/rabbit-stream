# Findings — review of #522 (dropped-confirm observability)

Reviewed against `feature/issue-522-dropped-confirm-observability` @ `b796894`, diff vs `main`.
Full review narrative: [review-1.md](review-1.md).

Summary: **0 high, 1 medium, 2 low.** No blocking correctness defect. Dispatch semantics unchanged.

## Resolution — review round 1

| Finding | Severity | Disposition |
|---------|----------|-------------|
| F1 — unbounded publishing-id lists in warning context | Medium | **Fixed** in `code-decision-2.md` |
| F2 — stranded ids never retired from `pendingConfirms` | Low | **Out of scope / deliberate** — pre-existing #474, no behaviour change (rationale below) |
| F3 — doc wording "survives repeated drains" | Low | **Fixed** |

F1 fix: a count plus a bounded prefix (max `StreamConnection::MAX_LOGGED_PUBLISHING_IDS = 10`) in all three log sites; the message states the true total and that only the first 10 are logged. New tests pin the bound. See [code-decision-2.md](code-decision-2.md).

## Resolution — review round 2 (convergence)

Verified at HEAD `8a622ed`; full narrative in [review-2.md](review-2.md).

| Finding | Severity | Round-2 disposition |
|---------|----------|---------------------|
| F1 — unbounded publishing-id lists in warning context | Medium | **Verified fixed** — bound (`MAX_LOGGED_PUBLISHING_IDS = 10`) applied consistently at all three sites and tested at each; no full id list reaches any PSR-3 context in the changed paths |
| F2 — stranded ids never retired from `pendingConfirms` | Low | **Confirmed deliberately deferred** — pre-existing #474 behaviour, documented; a valid optional follow-up for a separate issue |
| F3 — doc wording "survives repeated drains" | Low | **Verified fixed** — reworded; `drainPendingConfirms()` is reachable only from an idempotent `close()` |

**Round-2 new-issue sweep: none actionable.** The added `return`s are no-ops (dispatch and the
tombstone guard are unchanged), `readLoop()` still counts dropped frames as dispatched, the `Producer`
logger param is trailing/optional with a safe default, and `getLostConfirmCount()` is cumulative,
idempotent, producer-only and documented. The only residual note is a cosmetic QA-evidence count
(8746 vs 8750), corrected above.

**Convergence verdict: no open high/medium/low actionable findings.** All round-1 findings are either
fixed-and-verified or explicitly deferred with rationale.

---

## F1 — Unbounded publishing-id lists embedded in `warning` context (Medium)

**Where**
- `src/StreamConnection.php:1292-1296` — `handlePublishConfirm()` unregistered-id warning:
  `'publishingIds' => $confirm->getPublishingIds()`
- `src/StreamConnection.php:1312-1323` — `handlePublishError()` unregistered-id warning:
  `array_map(... getPublishingId(), $error->getErrors())`
- `src/Client/Producer.php:483-501` — drain-timeout warning:
  `'publishingIds' => $lost` where `$lost = array_keys($this->pendingConfirms)`

**Severity:** Medium

**What happened.** The issue only asked to log "publisher id and frame type", but the implementation
also puts the complete list of affected publishing ids into the PSR-3 context. That list is not
bounded. A single `PublishConfirm` is 8 bytes per id and `StreamConnection::DEFAULT_MAX_FRAME_SIZE` is
8 MiB (`src/StreamConnection.php:106`), so one late confirm frame can carry roughly 1,000,000 ids —
the logger then serializes all of them into a single warning record. The drain-timeout path is bounded
by `maxPendingConfirms` (default 10,000) only when backpressure is enabled; with
`maxPendingConfirms: 0` (documented fire-and-forget mode) the pending set is unbounded, so `close()`
can log an arbitrarily large id list.

**Why it matters.** Rare (late/tombstone frames only), but a real log-volume / memory hazard: log
processors, JSON formatters, or in-memory handlers can spike or OOM on a single record. With the
default `NullLogger` the cost is negligible (context is not rendered), so this is a deployment-dependent
issue — it bites exactly the operators who enabled logging to diagnose a dropped-confirm incident.

**Suggested fix.** Log a count plus a bounded prefix, e.g.
`'lostCount' => count($ids), 'publishingIds' => array_slice($ids, 0, 10)` (and note truncation in the
message when `count($ids) > 10`). Apply to all three sites.

**Check that could have caught it.** A unit test that feeds a late `PublishConfirm` with a large id
batch to an unregistered publisher and asserts the warning context is bounded (e.g.
`count($context['publishingIds']) <= N`). There is no logging-convention gate in
`docs/helpers/` or a lint rule limiting context payload size — adding such a convention or a small
assertion would prevent recurrence. The existing tests only assert message substrings, never context
size.

**Resolution (round 1): Fixed.** All three sites now log `publishingIdCount`/`lostCount` (the true
total) plus `publishingIds` sliced to `StreamConnection::MAX_LOGGED_PUBLISHING_IDS = 10`; the message
adds ` (N publishing ids in total; only the first 10 are logged)` when the total exceeds the bound.
`handlePublishError()` slices before mapping to avoid an intermediate 1M-element int array. Added
`testUnregisteredPublishConfirmWarningContextIsBounded`,
`testUnregisteredPublishErrorWarningContextIsBounded` and `testDrainTimeoutWarningContextIsBounded`,
which assert the count and the truncation. `RecordingLogger::warningContexts()` exposes the context.
See [code-decision-2.md](code-decision-2.md).

---

## F2 — Stranded ids are never retired from `pendingConfirms` after a timed-out close drain (Low)

**Where:** `src/Client/Producer.php:476-501` (`drainPendingConfirms`) and `:543-546`
(`getPendingConfirms`).

**Severity:** Low (pre-existing, out of scope, now documented)

**What happened.** When the bounded 2 s drain times out, the outstanding ids stay in
`$this->pendingConfirms` forever; `finally` unregisters the callback, so no future frame can retire
them. `getPendingConfirms()` therefore returns `> 0` indefinitely after `close()`. This is the #474
behaviour kept deliberately so the #474 regression test (`the stranded message stays pending`) keeps
passing, and it is now explicitly documented in `docs/en/api-reference/producer.md` ("The stranded ids
are still visible through `getPendingConfirms()` after `close()`"). The new `getLostConfirmCount()`
gives the unambiguous "these were lost" signal that `getPendingConfirms()` cannot.

**Assessment.** Real but pre-existing and out of the issue's observability scope; not a regression.
Only noticeable if an application retains the closed `Producer` and misreads `getPendingConfirms()` as
"still in flight". Optional follow-up: clear the set after recording the loss (requires updating the
#474 test) or document the post-close meaning of `getPendingConfirms()` at the method itself.

**Check that could have caught it.** A test asserting `getPendingConfirms()` after a timed-out
`close()` (currently only the #474-era assertion "stays pending" exercises this, which enshrines the
current behaviour rather than flagging the misleading post-close count).

**Resolution (round 1): Out of scope / deliberate — no behaviour change.** The stranded ids are
pre-existing #474 behaviour, explicitly preserved by that issue's regression test
(`the stranded message stays pending`). #522 is an observability change: it adds `getLostConfirmCount()`
and the bounded warning precisely so "lost" is distinguishable from "in flight" without having to
mutate `pendingConfirms`. Clearing the set after a timed-out drain would be a behavioural change to
close semantics (outside this issue's scope) and would require rewriting the #474 test. The post-close
meaning of `getPendingConfirms()` is now documented at the API reference; a code comment at the method
is noted as an optional future follow-up, not part of #522. The maintainer should decide whether F2
deserves its own issue.

---

## F3 — Doc wording: "cumulative and survives repeated drains" (Low)

**Where:** `docs/en/api-reference/producer.md:410`

**Severity:** Low (documentation only)

**What happened.** The note says the counter is "cumulative and survives repeated drains; `close()` is
idempotent, so it cannot double-count." In the code, `drainPendingConfirms()` is only reachable from
`close()`, which is guarded by `$this->closed`, so a drain happens exactly once per producer — there
are no "repeated drains" to survive. The word "cumulative" is accurate in the API sense (it is not
reset) but the sentence implies multiple close/drain cycles that cannot occur.

**Suggested fix.** Reword to: "The counter is not reset by `close()`; `close()` is idempotent, so it
cannot double-count."

**Check that could have caught it.** Docs-only; no automated gate. The knowledge-base lint checks
structure/front matter, not semantic accuracy.

**Resolution (round 1): Fixed.** `docs/en/api-reference/producer.md` now reads "The counter is not
reset by `close()`; `close()` is idempotent, so it cannot double-count." The adjacent bullet was also
updated to describe the bounded id prefix instead of "the affected publishing ids".

---

## Checks explicitly requested and their verdicts

| Check | Verdict |
|-------|---------|
| New logging changes dispatch semantics? | No — registered path callback + no-op `return`; tombstone guard intact; `readLoop()` still counts dropped frames as dispatched (verified in code + tests). |
| `handlePublishConfirm`/`handlePublishError` context: log-blowup? publisherId/frame correct? | Blow-up risk real (F1). `publisherId` and `frame` values correct for both. |
| `Producer` `?LoggerInterface` param order/BC; `NullLogger` default; `Connection::newProducer()` logger; all call sites | Safe: trailing optional param, correct default, connection logger threaded, all 46 call sites compile; only named-arg use is the new test. |
| `getLostConfirmCount()` cumulative vs per-close; documented; on interface | Cumulative, documented; intentionally off-interface (BC-safe, matches `isStale()`/`getRedeclareCount()`); `close()` idempotent so no double count. |
| Drain-timeout path reachable / deterministic or flaky? | Reachable and deterministic (broker never confirms via mock); passes 3/3; ~2 s wall-clock by construction, not flaky. |
| Stranded ids never retired — real bug or out of scope? | Pre-existing and intentional; documented; out of scope (F2, Low). |
| `RecordingLogger` `array_values` correct? | Correct — 0-indexes filtered levels; no test relies on preserved keys. |

## QA evidence

- PHPCS PSR-12: OK · PHPStan level 9: OK · Rector dry-run: OK
- Unit: 1222 tests / 8734 assertions OK · E2E (Docker): 147 tests / 3059 assertions OK
- kb-lint: OK (12 entries) · docs link check: OK
- Targeted re-run of the 5 new/changed tests 3×: identical, ~2.1 s each

### Round 2 (after F1/F3 fixes)

- PHPCS PSR-12: OK · PHPStan level 9: OK · Rector dry-run: OK
- Unit: 1225 tests / 8746 assertions OK (+3 bounded-context tests) · E2E (Docker): 147 tests / 3059 assertions OK
- kb-lint: OK · docs link check: OK

> Correction (review round 2): the round-2 unit assertion count is **8746**, not 8750 (two
> independent runs at HEAD `8a622ed`). Cosmetic; no code impact.

## Proposed knowledge-base candidates (for the retro step, not committed here)

- **Candidate (tags: logging, observability, psr3, log-volume):** "Never put an unbounded collection
  into a PSR-3 context." A context value that scales with broker frame size (`PublishConfirm` →
  ~1M ids at the 8 MiB frame cap) turns one warning into a log-volume incident. Log the count plus a
  bounded prefix. Trigger: adding context to a log call on a hot/protocol path.
- **Candidate (tags: producer, close, confirms, close-drain):** "After a timed-out `close()` drain,
  the producer's `pendingConfirms` stays populated by design; use the dedicated lost-confirm counter to
  distinguish 'lost' from 'in flight'." Trigger: reasoning about `getPendingConfirms()` after close.
