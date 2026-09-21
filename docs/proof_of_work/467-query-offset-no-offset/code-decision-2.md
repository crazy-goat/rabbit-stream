# Decision 2 — Issue #467: review round 1 follow-ups

This supersedes one point in `code-decision-1.md` (rejected alternative 5) and
records the calls made for the round-1 nits.

## 1. Consume the trailing `uint64` on `NO_OFFSET` (reverses decision-1 alt. 5)

`code-decision-1.md` rejected reading the offset field on `NO_OFFSET` on the
grounds that a truncated `NO_OFFSET` frame should be tolerated. That rationale
was wrong: the outer 4-byte size prefix (`readFrameNoWait()`) already guarantees
exactly one whole frame, so a frame that carries a response code but no offset
cannot occur on a real read path. Skipping the read therefore buys nothing and
leaves the parser mid-frame (position 8 instead of 18 on the wire).

`QueryOffsetResponseV1::fromStreamBuffer()` now calls `$buffer->getUint64()` and
discards the value on the `NO_OFFSET` branch. The parser ends exactly at the
frame boundary. If the field were ever missing, reading it would raise
`DeserializationException` — which is the correct outcome for a genuinely
corrupt frame.

The unit test asserts full-frame consumption
(`$buffer->getPosition() === strlen($raw)`).

## 2. `fromArray()` missing-key behaviour: keep it and document it

Kept `$data['offset'] ?? null`. A missing `offset` key is treated exactly like
an explicit `null` (both mean NO_OFFSET).

Justification: `fromArray()` is only ever fed data this library produced — a
round-trip of a parsed response or a hand-written test fixture — never raw wire
input, and it is not part of the public request/response path. Requiring the key
would turn a benign fixture omission into a runtime error for no safety gain,
while the ambiguity the reviewer noted (a typo reading as "no offset") is
inherent to an untyped `array<string, mixed>` and better pinned by tests than by
a hard failure. The docblock now states the equivalence explicitly and two tests
pin both `offset => null` and the key being absent.

## 3. `SuperStreamConsumer::queryOffset()` docblock

Added, mirroring `Consumer::queryOffset()`: `null` = `NO_OFFSET` is a normal
answer, `@return int|null`, and the accurate `@throws` set. The unknown-partition
path throws `InvalidArgumentException` (not `ProtocolException`); the remaining
throws come from the delegated `Consumer::queryOffset()`
(`ProtocolException`, `UnexpectedResponseException`, `ConnectionException`,
`DeserializationException`, `TimeoutException`). Exception imports were added so
the `@throws` names resolve under PHPStan level 9.

No new reflection gate was added for `SuperStreamConsumer` (the analogy to
`ConsumerDocblockTest` is noted in `findings-coder.md` as a possible follow-up,
out of scope for #467).

## 4. CHANGELOG BC sentence (R1-F1)

Corrected: PHP return types are covariant, so an external implementation
declaring the narrower `int` still satisfies the `?int` interface. The entry now
says existing implementors do **not** have to change and that the real impact is
on **callers**, which may now receive `null`.

## 5. Docs and tests

- `docs/en/api-reference/enums.md` — `NO_OFFSET` description no longer reads
  like an error: "No offset stored yet (normal `QueryOffset` reply, not an
  error)".
- `tests/Response/QueryOffsetResponseV1Test.php` — full-frame assertion on the
  NO_OFFSET case, new `OK` + offset `0` case, and `getResponseCode()` assertion
  in the non-OK case.
- `tests/Response/FromArrayTest.php` — null-offset and missing-offset
  round-trips.

## Gates

`./vendor/bin/phpunit --testsuite unit` (1222 tests, 8714 assertions),
`composer lint` (PHPCS + Rector + PHPStan level 9 + `kb-lint` +
`check-docs-links`) and `./run-e2e.sh` (147 tests, 3059 assertions) all pass.
