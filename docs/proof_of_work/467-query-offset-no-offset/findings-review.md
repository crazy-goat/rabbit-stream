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
