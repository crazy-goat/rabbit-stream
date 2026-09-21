# Findings — Issue #467 (Review round 1)

Reviewed diff `git diff main...HEAD` at `5147ced`
(`feature/issue-467-query-offset-no-offset`).

No **high** or **medium** findings. The change is correct on the wire, on the
public API and in the docs; the remaining items are low/nit.

| ID | Location | Severity | Status | Automated check that could catch it |
|----|----------|----------|--------|-------------------------------------|
| R1-F1 | `CHANGELOG.md:13` | low | fixed (round 1) | none (a doc/interface-conformance example test) |
| R1-F2 | `src/Response/QueryOffsetResponseV1.php:54-57` | low | fixed (round 1) | assert full-frame consumption (`getPosition() === strlen($raw)`) |
| R1-F3 | `tests/Response/QueryOffsetResponseV1Test.php` | low | fixed (round 1) | unit test: `OK` + offset `0` → `getOffset() === 0` |
| R1-F4 | `src/Client/SuperStreamConsumer.php:129` | nit | fixed (round 1) | docblock-reflection gate (cf. `ConsumerDocblockTest`) |
| R1-F5 | `src/Response/QueryOffsetResponseV1.php:74` | nit | fixed (round 1) | `fromArray` round-trip test with `offset => null` |
| R1-F6 | `docs/en/api-reference/enums.md:171` | nit | fixed (round 1) | none (`kb-lint` cannot judge semantics) |
| R1-F7 | `tests/Response/QueryOffsetResponseV1Test.php:44-53` | nit | fixed (round 1) | assert `getResponseCode() === STREAM_NOT_EXIST` |

---

## R1-F1 — CHANGELOG's BC guidance for implementors is inaccurate (low)

`CHANGELOG.md:13` states:

> The `ConnectionInterface`, `ConsumerInterface` and `SuperStreamConsumerInterface`
> signatures changed from `int` to `?int` — **external implementors must widen
> their return types.**

In PHP, return types are **covariant**: a class method that returns `int`
still satisfies an interface method declared `: ?int`. Verified:

```
interface I { public function q(): ?int; }
class C implements I { public function q(): int { return 5; } }   // loads fine
```

So existing external implementors declaring `: int` do **not** have to change
anything. The real (and correctly documented elsewhere in the same entry)
impact is on **callers**, who must now handle `null`. The sentence sends
implementors on an unnecessary migration and mis-states the direction of the
break. No gate catches docs prose; an interface-conformance example test would.

## R1-F2 — `NO_OFFSET` branch does not consume the trailing `uint64` (low)

`src/Response/QueryOffsetResponseV1.php:54-57` returns immediately after the
response code, so `$buffer->getUint64()` is never called and the cursor is left
mid-frame. In the current architecture this is harmless: `readFrameNoWait()`
builds the buffer over exactly one frame (`src/StreamConnection.php:1513`),
`readMessage()` then hands `getRemainingBytes()` to a fresh `deserialize()`
(`:1090`, `PhpBinarySerializer.php:27`), and the buffer is discarded. The
response is never re-parsed, so nothing observes the leftover bytes, and the
committed test `testNoOffsetParsesAsNullOffset` passes with an 8-byte tail
present.

It is still worth consuming the field: the documented frame layout
(`docs/en/protocol/consuming-commands.md`) now says the offset is always
present and `0` on `NO_OFFSET`, and a parser that stops mid-frame is fragile
against any future shared/strict-buffer path. The `code-decision-1.md`
rejected-alternative 5 rationale ("a truncated `NO_OFFSET` frame would fail in
the parser") is inverted: the outer 4-byte size prefix already guarantees the
frame is whole, so reading the field can only succeed — and if it somehow
could not, that *is* protocol corruption worth surfacing. Low, because no
current code path is affected. Check: assert the buffer is fully consumed
(`getPosition() === strlen($raw)` / `getRemainingBytes() === ''`) in the
`NO_OFFSET` unit test.

## R1-F3 — stored offset `0` vs `NO_OFFSET` is not pinned by a test (low)

The core contract of the change is that `NO_OFFSET` (`null`) is
distinguishable from a legitimately stored offset of `0`. `fromStreamBuffer()`
does implement this correctly (`0` is not `null`, so it takes the
`getUint64()` path and returns `0`), but no test covers that case — the
deserialization test uses `123456` and the E2E test stores `42`. A regression
that collapsed `0 → null` (e.g. a future `empty()` / falsy check) would go
unnoticed. Add a unit case with response code `OK` and offset `0`, asserting
`getOffset() === 0`.

## R1-F4 — `SuperStreamConsumer::queryOffset()` has no docblock (nit)

`src/Client/SuperStreamConsumer.php:129` carries no PHPDoc, so the new `null`
semantics are only visible in the interface and API reference. `Consumer`
guards the analogous docblock with `ConsumerDocblockTest`; the same reflection
gate would catch this for `SuperStreamConsumer`. (Already recorded by the coder
as finding 4.)

## R1-F5 — `fromArray()` treats a missing `offset` key as `NO_OFFSET` (nit)

`src/Response/QueryOffsetResponseV1.php:74` uses `$data['offset'] ?? null`, so
an absent key is indistinguishable from an explicit `null`. `fromArray()` is
only used to rebuild the object in tests/serializers, so the risk is low, but a
typo such as `['correlationId' => 1]` would silently read as "no offset stored"
rather than failing. A round-trip test with an explicit `null` (coder's
suggested fix) at least pins the intended branch. (Coder finding 3.)

## R1-F6 — enum table does not note the normal `NO_OFFSET` outcome (nit)

`docs/en/api-reference/enums.md:171` still reads `NO_OFFSET | 0x13 | No offset
available`, which reads like an error, while the rest of the docs now describe
it as a normal first-run answer. `kb-lint` cannot judge this. (Coder finding 2.)

## R1-F7 — non-OK test does not assert the propagated response code (nit)

`tests/Response/QueryOffsetResponseV1Test.php:44-53` was correctly tightened
from `\Exception` to `ProtocolException` + message `0x0002` (good — the
suggestion in the issue was to rely on `getResponseCode()`). It does not,
however, assert `getResponseCode() === ResponseCodeEnum::STREAM_NOT_EXIST`, so
the property the issue explicitly called out as "already available" is not
pinned by the test that exists to protect it.

---

## Checks performed (all clean)

- `NO_OFFSET` handling: reads code first, returns a response with `null` offset
  for `0x13`; all other codes still go through `assertResponseCodeOk()` and
  throw `ProtocolException` carrying `getResponseCode()`.
- Offset still consumed for non-`NO_OFFSET`: yes (`getUint64()` on the OK
  path).
- `NO_OFFSET` vs stored `0`: distinguishable (`null` vs `0`); see R1-F3.
- `getOffset(): ?int` semantics and `fromArray(null/missing)`: consistent.
- `ResponseBuilder` handles the non-null response unchanged.
- All callers updated: `Connection`, `Consumer`, `SuperStreamConsumer`; no
  `SuperStreamProducer` or other `queryOffset` caller exists (`grep` across
  repo; `examples/` has none).
- Behaviour change in `defaultConsumerUpdateHandler()`: on `null` resumes at
  `$this->offset` (initial `OffsetSpec`) — byte-for-byte the same outcome as
  the removed `catch (ProtocolException) / NO_OFFSET` branch; other
  `ProtocolException`s still propagate. No regression.
- E2E changes are meaningful: the non-existent-reference case now asserts a
  `QueryOffsetResponseV1` with `null`; the two "no offset stored" cases assert
  `null`; the resume test narrows `?int` with `assertNotNull`.
- README resume example: the old `OffsetSpec::offset($storedOffset + 1)` was a
  pre-existing off-by-one (#396 — `queryOffset()` already returns the next
  offset); the new `$storedOffset === null ? OffsetSpec::first() :
  OffsetSpec::offset($storedOffset)` is correct.

---

# Round 1 fix disposition

Every finding below was actioned in this round. Fix commit: `fix(client):
consume NO_OFFSET offset field, test 0-vs-null and response code; fix BC note`
(see the branch HEAD after this file).

| ID | Disposition | What changed |
|----|-------------|--------------|
| R1-F1 | **Fixed** | `CHANGELOG.md` `[Unreleased] → Changed` now states return types are covariant, so existing implementors declaring `: int` need not change, and names callers (may now receive `null`) as the real impact. |
| R1-F2 | **Fixed** | `QueryOffsetResponseV1::fromStreamBuffer()` calls `$buffer->getUint64()` and discards it on the `NO_OFFSET` branch, so the cursor lands at the frame boundary. `testNoOffsetParsesAsNullOffset` now asserts `$buffer->getPosition() === strlen($raw)`. |
| R1-F3 | **Fixed** | Added `testZeroOffsetIsNotConfusedWithNoOffset`: `OK` + offset `0` → `getOffset() === 0` (asserted with `assertSame`, which fails on `null`). |
| R1-F4 | **Fixed** | Added a docblock to `SuperStreamConsumer::queryOffset()` mirroring `Consumer::queryOffset()`: `null` = `NO_OFFSET` is normal, `@return int|null`, and the accurate `@throws` set (`InvalidArgumentException` for an unknown partition; the delegated `Consumer` exceptions otherwise). Imports added for the documented exception classes. |
| R1-F5 | **Fixed (documented)** | Decided to keep the lenient behaviour and document it: the `fromArray()` docblock now states a missing `offset` key means the same as an explicit `null` (NO_OFFSET), justified because `fromArray()` only round-trips data this library produced, never untrusted wire input. Pinned by `testQueryOffsetResponseFromArrayWithNullOffset` and `testQueryOffsetResponseFromArrayWithMissingOffsetIsNoOffset`. |
| R1-F6 | **Fixed** | `docs/en/api-reference/enums.md` `NO_OFFSET` description is now "No offset stored yet (normal `QueryOffset` reply, not an error)", aligned with `docs/en/guide/error-handling.md`. |
| R1-F7 | **Fixed** | `testThrowsOnErrorResponseCode` now catches the `ProtocolException` and asserts `getResponseCode() === ResponseCodeEnum::STREAM_NOT_EXIST` in addition to the message. |

## Answers to the reviewer's questions / disagreements

- **R1-F2 (accepted the reviewer's argument).** The outer 4-byte size prefix
  already delimits the frame, so the `code-decision-1.md` rejected-alternative 5
  rationale was inverted. Consuming the field can only succeed; the parser now
  ends exactly at the frame boundary. `code-decision-2.md` records the reversal.
- **R1-F1 (factual correction accepted).** Covariant return types mean the
  widening is not an implementor break; the CHANGELOG now says so.
- **R1-F5 (resolved, not deferred).** The lenient mapping is kept deliberately
  and is now documented and test-pinned rather than left implicit.

## Findings from this round

None new. No regression introduced: `testThrowsOnErrorResponseCode` still
passes with the malformed (offset-less) frame because `assertResponseCodeOk()`
throws before the offset read, and the non-`NO_OFFSET` frame layout is
unchanged.

---

# Findings — Issue #467 (Review round 2, convergence)

Reviewed `git diff main...HEAD` at `61dbf0a` (round 1 was `5147ced`). Full
narrative in [review-2.md](review-2.md).

## Round-1 disposition (all fixed, re-verified)

| ID | Status | Evidence |
|----|--------|----------|
| R1-F1 | **Fixed (accurate)** | `CHANGELOG.md:13` now states covariance correctly; callers named as the real impact. |
| R1-F2 | **Fixed (correct)** | `QueryOffsetResponseV1.php:58` consumes the `uint64`; `rabbit_stream_core.erl` `response_body({query_offset,…}) -> <<Code:16, Offset:64>>` always emits it, and E2E passes. `getPosition()` is window-relative and the test uses offset 0, so `getPosition() === strlen($raw)` is a valid full-consumption assertion. |
| R1-F3 | **Fixed** | `assertSame(0, …)` on `?int` distinguishes `0` from `null`. |
| R1-F4 | **Fixed** | `SuperStreamConsumer.php:134-154` docblock present. |
| R1-F5 | **Fixed** | Documented equivalence + two `FromArrayTest` cases. |
| R1-F6 | **Fixed** | `enums.md:171` wording corrected. |
| R1-F7 | **Fixed** | `getResponseCode() === STREAM_NOT_EXIST` asserted. |

## New findings

| ID | Location | Severity | Status | Check that could catch it |
|----|----------|----------|--------|---------------------------|
| R2-F1 | `docs/helpers/faq.md:126` | low | fixed (round 2) | grep docs for `NO_OFFSET` when its handling changes (kb-lint is semantic-blind) |
| R2-F2 | `src/Client/SuperStreamConsumer.php:147-148` | nit | fixed (round 2) | docblock-completeness gate (absent; `ConsumerDocblockTest` only checks presence) |
| R2-F3 | `docs/en/examples/offset-resume.md:616-617` vs `docs/en/guide/offset-tracking.md:589-590` | nit | fixed (round 2) | none (examples are not cross-checked) |

### R2-F1 — FAQ-007 still says `NO_OFFSET` surfaces as `ProtocolException` (low)

`docs/helpers/faq.md:126` lists `"no offset stored" (#467)` among the non-OK
codes "asserted inside `SimpleCorrelatedResponseV1::fromStreamBuffer()`" that
surface as a `ProtocolException`. After #467 that is false:
`QueryOffsetResponseV1::fromStreamBuffer()` handles `0x13` before
`assertResponseCodeOk()` and returns a `null` offset. The branch did not touch
the file, but the behaviour change invalidated it. Fix: drop the example (or
note the new normal-`null` reply). `kb-lint` only validates structure/tags.

### R2-F2 — `SuperStreamConsumer::queryOffset()` docblock misses the unnamed-consumer throw (nit)

`createSuperStreamConsumer()` allows `name: null`
(`src/Client/Connection.php:1236`); a nameless consumer makes the delegated
`Consumer::queryOffset()` throw `ProtocolException('Cannot query offset for
unnamed consumer')` (`Consumer.php:767-769`). The new docblock documents only
the broker non-OK case, while `docs/en/api-reference/super-stream-consumer.md:231`
lists both. Add "or this consumer has no name" to the `@throws` text.

### R2-F3 — offset-lag examples disagree when nothing is stored (nit)

`offset-resume.md:616-617` returns `0` (nothing stored ⇒ caught up);
`offset-tracking.md:589-590` maps `null → 0` and returns `latest - 0` (nothing
stored ⇒ maximally behind). Both snippets were edited in this branch. Align
them (the `offset-tracking.md` form is the more defensible one).

### Pre-existing, not counted

`docs/en/examples/error-handling-patterns.md:339,638` still teaches catching
`NO_OFFSET`; the file is already unrunnable (`OffsetSpecification`,
`StreamConnection::subscribe()` do not exist) and was logged out-of-scope by the
coder. Not a regression from this branch.

## Gate results (HEAD `61dbf0a`)

| Gate | Result |
|------|--------|
| `composer cs` | ✅ clean (279 files) |
| `composer phpstan` | ✅ no errors (level 9, `src` + `tests`) |
| `composer rector` | ✅ no changes suggested |
| `./vendor/bin/phpunit --testsuite unit` | ✅ 1222 tests, 8714 assertions |
| `php bin/kb-lint.php` | ✅ 12 entries, 0 warnings, 0 stale |
| `php bin/check-docs-links.php` | ✅ all relative links resolve |
| `./run-e2e.sh` | ✅ 147 tests, 3059 assertions |

## Verdict

Converged. All round-1 findings fixed and broker-verified; no open source/test
findings. Only R2-F1 (low doc accuracy) plus two doc nits remain, none
blocking.

---

# Round 2 fix disposition

Every R2 finding was actioned. See [code-decision-3.md](code-decision-3.md).

| ID | Disposition | What changed |
|----|-------------|--------------|
| R2-F1 | **Fixed** | `docs/helpers/faq.md` FAQ-007 no longer lists "no offset stored" among the codes that surface as a `ProtocolException`. It now names `QueryOffset` as the exception: `QueryOffsetResponseV1::fromStreamBuffer()` intercepts `NO_OFFSET` (`0x13`) before `assertResponseCodeOk()` and returns a `null` offset (#467); all other non-OK codes still throw. |
| R2-F2 | **Fixed** | `SuperStreamConsumer::queryOffset()` `@throws ProtocolException` now reads "If this consumer has no name, or the broker returns a non-OK response code other than NO_OFFSET", matching `docs/en/api-reference/super-stream-consumer.md:231`. |
| R2-F3 | **Fixed** | `docs/en/examples/offset-resume.md` `checkOffsetLag()` now maps `null → $storedOffset = 0` and returns `$latestOffset - $storedOffset`, i.e. a never-consumed consumer is maximally behind — consistent with `docs/en/guide/offset-tracking.md`. The `offset-tracking.md` form was judged correct: from `null` the consumer resumes at `OffsetSpec::first()`, so the whole stream is unprocessed. |

## Rationale for R2-F3 (which example was wrong)

`queryOffset()` returns the **next offset to consume**. When it returns `null`
the consumer resumes from `OffsetSpec::first()` (offset `0`), so every message
up to the latest offset is still to be processed. `offset-tracking.md`'s
`null → 0`, `latest - 0` therefore describes the real lag; `offset-resume.md`'s
`return 0` (implying "caught up") was the incorrect one and was aligned.

## Gate results after round-2 fixes

| Gate | Result |
|------|--------|
| `./vendor/bin/phpunit --testsuite unit` | ✅ 1222 tests, 8722 assertions |
| `composer lint` (PHPCS + Rector dry-run + PHPStan level 9 + kb-lint + docs links) | ✅ all clean (279 files, 273 PHPStan paths) |

---

# Findings — Issue #467 (Review round 3, convergence)

Reviewed `git diff 61dbf0a..HEAD` (HEAD `90bf9b9`; round 2 was `61dbf0a`). Full
narrative in [review-3.md](review-3.md). The round-2→3 delta is documentation
only: `docs/helpers/faq.md`, `docs/en/examples/offset-resume.md`, a `@throws`
docblock line in `src/Client/SuperStreamConsumer.php`, and the PoW files.

## Round-2 disposition (all fixed, re-verified at HEAD)

| ID | Severity | Status | Evidence |
|----|----------|--------|----------|
| R2-F1 | low | **Fixed (accurate)** | `faq.md:122-136` no longer lists "no offset stored" as a `ProtocolException`; it names `QueryOffsetResponseV1::fromStreamBuffer()` as the `NO_OFFSET` exception and keeps "stream already exists" / invalid SASL. Matches `QueryOffsetResponseV1.php:54-65`. |
| R2-F2 | nit | **Fixed (accurate)** | `SuperStreamConsumer.php:147-148` now says "If this consumer has no name, or the broker returns a non-OK response code other than NO_OFFSET"; matches `Consumer.php:767-769` and `super-stream-consumer.md:231-232`. |
| R2-F3 | nit | **Fixed (consistent + correct)** | `offset-resume.md:615-623` now maps `null → 0` and returns `latest - 0`, identical to `offset-tracking.md:588-597`. Correct given `Consumer.php:455-461` (null resumes at the initial `OffsetSpec`; the examples use `OffsetSpec::first()`). |

## Round-1 findings (re-verified at HEAD)

All still fixed: R1-F1 (`CHANGELOG.md:13` covariance note), R1-F2
(`QueryOffsetResponseV1.php:58` consumes the `uint64`; full-frame assertion at
`QueryOffsetResponseV1Test.php:46`), R1-F3 (`assertSame(0, …)` at `:49-64`),
R1-F4 (docblock present), R1-F5 (`FromArrayTest.php:141-155`), R1-F6
(`enums.md:171`), R1-F7 (`getResponseCode()` asserted at `:77`).

## New findings

None. No source/test behaviour changed in the delta and no doc/code drift was
introduced.

### Pre-existing, informational, not counted

`TestReadWaitsThroughResubscribeBackoffAfterLostSubscription`
(`tests/Client/ConsumerTest.php:308-376`, from #461 / `bb9c5bb`, **not touched
by #467**) asserts inside `foreach ($forwarded as …)` where the slice count is
bounded by a 0.05 s wall-clock deadline, so the unit suite's assertion total is
nondeterministic: repeated runs report 8710 / 8714 / 8718 assertions while the
test count stays 1222. This is why the round-2 PoW table recorded "8722
assertions" and this round observes 8714. The test always passes; only the
metric varies. Out of scope for #467.

## Gate results (HEAD `90bf9b9`)

| Gate | Result |
|------|--------|
| `composer lint` (PHPCS 279 files + Rector dry-run + PHPStan level 9, 273 paths + kb-lint 12 entries/0 warnings/0 stale + docs links) | ✅ all clean |
| `./vendor/bin/phpunit --testsuite unit` | ✅ `OK (1222 tests, 8714 assertions)` |

## Verdict

**Converged. No open high, medium, low or nit findings remain for #467.** All
round-1 and round-2 findings are fixed and re-verified; the round-3 delta is
documentation-only and correct. Recommended: merge.
