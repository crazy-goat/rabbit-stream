# Review 3 — Issue #467: treat QueryOffset `NO_OFFSET` as a normal outcome (round 3, convergence)

- **Branch:** `feature/issue-467-query-offset-no-offset`
- **HEAD:** `90bf9b9` (`docs: correct NO_OFFSET guidance and align offset-lag examples (#467)`)
- **Round-2 baseline:** `61dbf0a` — the only delta this round is `git diff 61dbf0a..HEAD`
- **Reviewer:** review-critical subagent (read-only w.r.t. source)
- **Round:** 3 (convergence)

## Verdict

**Converged. No open high, medium, low or nit findings remain for #467.**
All round-1 (R1-F1…F7) and round-2 (R2-F1…F3) findings are fixed and
re-verified at HEAD. The round-2→3 delta is documentation/docblock only
(`docs/helpers/faq.md`, `docs/en/examples/offset-resume.md`, a 4-line `@throws`
edit in `src/Client/SuperStreamConsumer.php`, plus the PoW files); no `src/`
behaviour or test changed, and no new issue was introduced.

---

## Round-2→3 delta (`git diff 61dbf0a..HEAD`)

Exactly six files: `docs/en/examples/offset-resume.md`, `docs/helpers/faq.md`,
`src/Client/SuperStreamConsumer.php`, and the three new/updated PoW docs
(`code-decision-3.md`, `findings-review.md`, `review-2.md`). The only source
file touched is `SuperStreamConsumer.php`, where **only a docblock line
changed** — no executable code.

## R2 findings — disposition with evidence

### R2-F1 — FAQ-007 taught `NO_OFFSET` as a `ProtocolException` → **Fixed (accurate)**

`docs/helpers/faq.md:122-136` no longer lists "no offset stored" among the codes
asserted via `assertResponseCodeOk()`. It keeps "stream already exists" and
invalid SASL credentials, and explicitly names `QueryOffset` as the exception:
it "intercepts the normal `NO_OFFSET` (`0x13`) answer *before* the assertion and
returns a response whose offset is `null`, so 'no offset stored' is **not** a
`ProtocolException` (all other non-OK codes still are)."

Verified against `src/Response/QueryOffsetResponseV1.php:54-65`: the `0x13`
branch reads the code, consumes the `uint64`, sets `offset = null` and returns
before `assertResponseCodeOk()`; every other code reaches the assertion. The FAQ
text is now factually correct.

### R2-F2 — `SuperStreamConsumer::queryOffset()` docblock omitted the unnamed-consumer throw → **Fixed (accurate)**

`src/Client/SuperStreamConsumer.php:147-148` now reads:

> `@throws ProtocolException If this consumer has no name, or the broker returns
> a non-OK response code other than NO_OFFSET.`

This matches both the delegated code (`src/Client/Consumer.php:767-769` throws
`ProtocolException('Cannot query offset for unnamed consumer')` when
`$this->name === null`) and the API reference
(`docs/en/api-reference/super-stream-consumer.md:231-232`), which already
listed both paths. No code path is affected.

### R2-F3 — offset-lag examples disagreed on "nothing stored" → **Fixed (consistent and factually correct)**

`docs/en/examples/offset-resume.md:615-623` now maps `null → $storedOffset = 0`
and returns `$latestOffset - $storedOffset`, byte-for-byte the same policy as
`docs/en/guide/offset-tracking.md:588-597` (`null → 0`, `latest - 0`). A
never-consumed consumer is therefore treated as maximally behind in both docs,
and the "fully caught up" `return 0` contradiction is gone.

The rationale is correct given the code: `Consumer::queryOffset()` returns the
stored **next offset to consume**, and on `null` the default activation handler
resumes at the consumer's initial `OffsetSpec`
(`src/Client/Consumer.php:455-461`); the examples build the probe consumer with
`OffsetSpec::first()`, so offset `0` (nothing processed) is the right lag
baseline. **Consistent.**

## Round-1 findings — re-verified at HEAD

| ID | Status | Evidence |
|----|--------|----------|
| R1-F1 | Fixed | `CHANGELOG.md:13` states return types are covariant, so external implementors declaring `: int` need not change; callers may now get `null`. |
| R1-F2 | Fixed | `QueryOffsetResponseV1.php:58` consumes the `uint64` on `NO_OFFSET`; `testNoOffsetParsesAsNullOffset` asserts `getPosition() === strlen($raw)` (`tests/Response/QueryOffsetResponseV1Test.php:46`). |
| R1-F3 | Fixed | `testZeroOffsetIsNotConfusedWithNoOffset` asserts `assertSame(0, getOffset())` (`:49-64`). |
| R1-F4 | Fixed | Docblock present (`SuperStreamConsumer.php:134-154`). |
| R1-F5 | Fixed | `fromArray()` docblock documents missing-key == explicit `null`; pinned by two `FromArrayTest` cases (`tests/Response/FromArrayTest.php:141-155`). |
| R1-F6 | Fixed | `docs/en/api-reference/enums.md:171` reads "No offset stored yet (normal `QueryOffset` reply, not an error)". |
| R1-F7 | Fixed | `testThrowsOnErrorResponseCode` asserts `getResponseCode() === ResponseCodeEnum::STREAM_NOT_EXIST` (`:77`). |

## Gates (run at HEAD `90bf9b9`)

| Gate | Command | Result |
|------|---------|--------|
| Full lint | `composer lint` | ✅ clean — PHPCS (279 files), Rector dry-run (no changes), PHPStan level 9 (273 paths, no errors), `kb-lint` (12 entries, 0 warnings, 0 stale), docs links (all resolve) |
| Unit tests | `./vendor/bin/phpunit --testsuite unit` | ✅ `OK (1222 tests, 8714 assertions)` |

### Note on the unit assertion count (non-blocking, pre-existing)

The unit suite's **assertion count is not deterministic** across runs:
`TestReadWaitsThroughResubscribeBackoffAfterLostSubscription`
(`tests/Client/ConsumerTest.php:308-376`) loops `foreach ($forwarded as …)`
with three assertions per `readLoop()` slice, and the number of slices that fit
inside the 0.05 s wall-clock deadline varies with timing. Across repeated runs
the suite reports 8710 / 8714 / 8718 assertions (test count stable at 1222).
That variation explains why `code-decision-3.md` recorded "8722 assertions"
while this round observes 8714 — the figure is inherently unstable, not a
misreported gate. The test always passes; only the metric is noisy. This test
came from #461 (`bb9c5bb`) and is **not** touched by, nor in scope for, #467.

## New issues found in round 3

**None affecting #467.** The delta was re-read in full (three docs + six-line
docblock change). No behavioural change, no test change, no doc/code drift. The
only new observation is the pre-existing nondeterministic assertion count noted
above (informational, out of scope).

## Verdict summary

- Blocking issues: **none.**
- Round-1 and round-2 findings: **all fixed and re-verified.**
- **No open high, medium, low or nit findings remain.**
- Recommended: **merge.**
