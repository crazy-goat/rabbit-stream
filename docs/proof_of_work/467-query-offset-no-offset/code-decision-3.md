# Decision 3 — Issue #467: review round 2 follow-ups

Round 2 found no code issues (see [review-2.md](review-2.md)). This records the
calls made for the one low documentation-accuracy finding and the two nits.

## 1. FAQ-007 no longer teaches `NO_OFFSET` as a `ProtocolException` (R2-F1)

`docs/helpers/faq.md` FAQ-007 explained that non-OK response codes are asserted
inside `SimpleCorrelatedResponseV1::fromStreamBuffer()` and therefore surface as
a `ProtocolException`, and listed "no offset stored" (#467) as an example.
After #467 that example is false: `QueryOffsetResponseV1::fromStreamBuffer()`
checks the response code *before* `assertResponseCodeOk()` and returns a
response with a `null` offset for `NO_OFFSET` (`0x13`).

The entry now:

- drops "no offset stored" from the list (keeping "stream already exists" and
  invalid SASL credentials), and
- explicitly names `QueryOffset` as the exception — the normal `NO_OFFSET`
  answer is a `null` offset, while every other non-OK code still throws.

Rationale: FAQ-007 exists to stop maintainers documenting throws that do not
occur; leaving a now-defunct throw in its example would reintroduce exactly the
error it warns against.

## 2. `SuperStreamConsumer::queryOffset()` `@throws` completeness (R2-F2)

`createSuperStreamConsumer()` accepts `?string $name = null`
(`Connection.php`), and a nameless consumer makes the delegated
`Consumer::queryOffset()` throw
`ProtocolException('Cannot query offset for unnamed consumer')`. The source
docblock documented only the broker non-OK case. It now reads:

> `@throws ProtocolException If this consumer has no name, or the broker returns
> a non-OK response code other than NO_OFFSET.`

This matches `docs/en/api-reference/super-stream-consumer.md`, which already
listed both paths. No new reflection gate was added (out of scope; noted as a
possible follow-up).

## 3. Offset-lag examples aligned on "nothing stored" (R2-F3)

`docs/en/examples/offset-resume.md` (`checkOffsetLag()`) returned `0` when the
stored offset was `null` — i.e. treated a never-consumed consumer as fully
caught up — while `docs/en/guide/offset-tracking.md` (`getOffsetLag()`) mapped
`null → $storedOffset = 0` and returned `$latestOffset - $storedOffset`, i.e.
maximally behind. The two contradicted each other.

**`offset-tracking.md` is correct.** `queryOffset()` returns the next offset to
consume; when it is `null` the consumer resumes at `OffsetSpec::first()`
(offset `0`), so every message up to the latest offset is still unprocessed. The
`offset-resume.md` snippet was aligned to the same mapping (`null → 0`,
`latest - 0`) instead of the early `return 0`.

## Gates

`./vendor/bin/phpunit --testsuite unit` (1222 tests, 8722 assertions) and
`composer lint` (PHPCS + Rector dry-run + PHPStan level 9 + `kb-lint` +
`check-docs-links`) both pass.
