# Review 1 — Issue #467: treat QueryOffset `NO_OFFSET` as a normal outcome

- **Branch:** `feature/issue-467-query-offset-no-offset`
- **HEAD:** `5147ced` (diff vs `main`)
- **Reviewer:** review-critical subagent (read-only w.r.t. source)
- **Round:** 1

## Verdict

**Accept with low/nit follow-ups. No high or medium findings.** The design —
representing `NO_OFFSET` as a parsed response whose `getOffset()` is `null`
rather than returning literal `null` from the parser — is sound and well
justified in `code-decision-1.md` (the deserializer chain is typed `object` and
`ResponseBuilder` turns a `null` parser result into a `DeserializationException`,
so the issue's literal suggestion would have forced a much larger, riskier
change). The change is correct on the wire, correct for non-`NO_OFFSET`
responses, preserves the single-active-consumer resume semantics exactly, and
the docs/README/CHANGELOG were updated consistently.

Detailed findings live in [findings-review.md](findings-review.md).

## Findings by severity

### High
None.

### Medium
None.

### Low
1. **R1-F1 — `CHANGELOG.md:13` BC guidance is wrong.** Return types are
   covariant in PHP: an implementor declaring `: int` still satisfies an
   interface declaring `: ?int`. The note says "external implementors **must**
   widen their return types" — they need not. The real break is for callers
   (already noted in the same entry). Fix the sentence.
2. **R1-F2 — `QueryOffsetResponseV1::fromStreamBuffer()` leaves the `uint64`
   offset unconsumed on `NO_OFFSET`.** Harmless today (per-frame buffers), but
   inconsistent with the documented layout. Consume it and assert the frame is
   fully read in the unit test.
3. **R1-F3 — the `NO_OFFSET` (`null`) vs stored-offset-`0` distinction is
   untested.** Add a unit case with response code `OK` and offset `0`.

### Nit
4. **R1-F4 — `SuperStreamConsumer::queryOffset()` has no docblock** (already
   reported by the coder).
5. **R1-F5 — `fromArray()` maps a missing `offset` key to `NO_OFFSET`**
   (coder finding 3).
6. **R1-F6 — `docs/en/api-reference/enums.md:171` still frames `NO_OFFSET`
   as an error** (coder finding 2).
7. **R1-F7 — non-OK test asserts the message but not
   `getResponseCode()`**, the very affordance the issue pointed at.

## Gate results

| Gate | Command | Result |
|------|---------|--------|
| Code style | `composer cs` | ✅ clean (279 files) |
| Static analysis | `composer phpstan` | ✅ no errors (level 9) |
| Refactoring | `composer rector` | ✅ no changes suggested |
| Unit tests | `./vendor/bin/phpunit --testsuite unit` | ✅ 1219 tests, 8705 assertions |
| Full lint | `composer lint` | ✅ PHPCS + Rector + PHPStan + `kb-lint` + `check-docs-links` |
| E2E (Docker) | `./run-e2e.sh` | ✅ 147 tests, 3059 assertions |

## Specific questions from the review brief

- **`fromStreamBuffer()` correctness / offset consumption.** For non-`NO_OFFSET`
  the code and `uint64` offset are read as before. For `NO_OFFSET` the code is
  read but the trailing `uint64` is **not** consumed; the frame is otherwise
  discarded so there is no functional bug, but it is fragile and documented as
  a deliberate skip (see R1-F2).
- **`NO_OFFSET` vs a real `0`.** Distinguishable: the OK path returns `0`, the
  `NO_OFFSET` path returns `null`. Not test-pinned (R1-F3).
- **`fromArray(null/missing)` and `ResponseBuilder`.** OK-path unchanged and
  fine; a missing key silently becomes `NO_OFFSET` (R1-F5, minor).
- **Public API / BC.** Interface return types widened `int → ?int`. For
  implementors this is *not* a hard break (covariance) — contrary to the
  CHANGELOG (R1-F1). It is a break for callers that assumed non-null, which the
  entry's upgrade note covers. All in-repo call sites were updated; no
  `SuperStreamProducer`/`examples/` caller exists.
- **`defaultConsumerUpdateHandler()` behaviour.** On `null` it resumes at the
  consumer's initial `OffsetSpec` (`$this->offset`), which is exactly what the
  removed `catch (ProtocolException) / NO_OFFSET` branch did. No regression;
  other `ProtocolException`s still propagate.
- **Tests.** The response test uses the concrete `ProtocolException` (not
  `\Exception`) — good. E2E changes are meaningful (they assert `null` instead
  of an exception). Gaps: stored-`0` case (R1-F3), `getResponseCode()`
  (R1-F7).
- **Docs.** README's resume example is fixed and correct: the old
  `OffsetSpec::offset($storedOffset + 1)` double-skipped because `queryOffset()`
  already returns the next offset (#396); the new form resumes at
  `$storedOffset`, or from `first()` when `null`. The rest of the docs migrate
  the try/catch examples consistently.

## Disagreements with the coder

- `code-decision-1.md` rejected alternative 5 ("read the trailing `uint64` even
  on `NO_OFFSET`") on the grounds that skipping it tolerates a truncated
  `NO_OFFSET` frame. The outer 4-byte size prefix already delimits the frame,
  so a whole-but-truncated QueryOffset frame cannot occur; skipping the field
  buys nothing and leaves the parser mid-frame. Recommend consuming it
  (R1-F2, low).
- `CHANGELOG.md:13`'s "implementors must widen" is a factual error (R1-F1).

## Recommendation

Address R1-F1 (one-line CHANGELOG fix), R1-F2 and R1-F3 before merge; the nits
can ride along or be deferred. None block the change functionally.
