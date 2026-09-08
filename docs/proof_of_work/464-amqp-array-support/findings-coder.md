# Findings — coder (#464)

## Obstacles

1. **Field order is size-then-count** — the issue says "read element count + size", but
   per the AMQP 1.0 spec (and the issue's own repro bytes `e0 02 01 41`: size=2,
   count=1, element `0x41`) the size field comes *first*. A first implementation that
   read count first failed its own fixtures. Fixed in
   `src/Client/AmqpDecoder.php` (`readArray8()` / `readArray32()`).
2. **Size-field semantics are easy to get wrong.** The declared size includes the count
   field itself, not just the elements (see the comment on `testDecodeList8WithNestedValues`
   in `tests/Client/AmqpDecoderTest.php`, which documents the same gotcha for list8).
   Several initial test fixtures were off by the count-field width; all corrected.
3. **phpstan level 9 docblocks.** Inserting the new methods before `readMap32()`
   initially split `readMap32` from its `@return` docblock, which surfaced as three
   unrelated-looking phpstan errors (including one on `decodeValue()` line 58 —
   caused by the orphaned docblock now applying to `readArray8`). Fixed by restoring
   `readMap32`'s docblock and giving `readArray32` its own `@return array{0: list<mixed>, 1: int}`.

## Surprises

- The `MAX_COMPOUND_ELEMENTS` / available-bytes OOM guards (#449) only fire for
  **array32**; array8's count is a single byte (≤255) so it is structurally safe.
  The test proving the array32 element cap (`testDecodeArray32HonestLargeFrameThrowsBeforeAllocating`)
  needs a ~131 KB fixture — same trade-off the existing list32 test made.
- `decodeMessage()` keys the AmqpValue section off descriptor byte `0x76`
  (`\x00\x53\x76 …`); my first test used `0x70` (a different descriptor) and got
  `body = ''` silently rather than an error. Not a bug, but a footgun when writing
  decoder fixtures.

## Bugs / improvements noticed (out of scope)

1. **char (0x73) and decimal (0x74/0x84) still unsupported** — same `default` arm
   throws for conforming producers (reported in #464, deferred to a follow-up here).
   Suggested fix: `readChar` (uint32 codepoint, return UTF-8 string) and `readDecimal`
   (int32 unscaled + int8 scale, return a decimal VO or string) in
   `src/Client/AmqpDecoder.php`.
2. **Silent section skip on unknown descriptors** in `decodeMessage()` — a described
   section whose descriptor is not recognised yields `body = ''` with no signal
   (observed while writing `testDecodeMessageBodyAsArray`). Consider at least a
   debug-level marker in the returned `$sections` array. Location:
   `src/Client/AmqpDecoder.php`, the section-dispatch loop of `decodeMessage()`.
3. **`assertCompoundConsumed()` error message is confusing** —
   `'%s size mismatch: declared %d bytes, elements consumed %d'` prints
   `$size + ($position - $contentEnd)` as "declared", which is not the declared size.
   Cosmetic; fix by printing `$size` and the delta separately.
4. **Nested single-constructor (packed) array encoding** is not supported (see
   code-decision-1.md, uncertainty 1). If a producer using it appears, add a
   packed-form branch to `readArray8`/`readArray32`.

## Verification

- `composer lint` (PHPCS + Rector dry-run + PHPStan level 9 + kb-lint): clean
- `./vendor/bin/phpunit --testsuite unit`: 1083 tests, 8216 assertions, OK
- E2E not run (per instructions)
