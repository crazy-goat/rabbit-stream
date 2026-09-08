# Findings review — round 1 (#464 AMQP array support)

Reviewer feedback addressed in commit `fix(protocol): address round-1 review findings`.

| # | Severity | Finding | Status |
|---|----------|---------|--------|
| 1 | low | The #449 count/size guard blocks are duplicated between `readArray32` and `readList32` (and also present in `readMap32`). | ✅ **Fixed** — extracted into one private helper `assertCompoundCount(int $count, int $available, string $what): void` in `src/Client/AmqpDecoder.php`, used by `readList32`, `readMap32` and `readArray32`. Exception messages and check order (available-bytes first, then MAX_COMPOUND_ELEMENTS) are byte-for-byte identical, so no test message changed. |
| 2 | low | "array8 generic message" — claim that array8 handling differs from list8 in some way. | ❌ **Not a real finding (existing list8 parity)** — `readArray8` already mirrors `readList8` exactly (same size/count field order, same `compoundContentEnd` window, same `assertCompoundConsumed` check). No change needed. |
| 3 | low | Missing inclusive boundary test at exactly `MAX_COMPOUND_ELEMENTS` (131072) for arrays. | ✅ **Fixed** — added `testDecodeArray32AtElementCapDecodes` in `tests/Client/AmqpDecoderTest.php`: builds the fixture programmatically (131072 one-byte null elements, truthful size), asserts the array decodes in full with peak memory bounded (< 32 MB delta), keeping runtime ~milliseconds. |
| 4 | nit | The available-bytes guard test only covers 1-byte elements; add a multi-byte-element case. | ✅ **Fixed** — added `testDecodeArray32MultiByteElementsOverrunStillThrows`: 3 declared elements of 2 bytes each against 4 declared content bytes. The up-front guard does not fire (count 3 ≤ 4 available bytes), the per-element loop guard rejects the frame — documenting exactly which guard catches the multi-byte overrun. |
| 5 | nit | `chr($size & 0xFF)` in test fixtures silently wraps if a size exceeds 255. | ✅ **Fixed** — both occurrences (`buildNestedList8()` and `testDecodeArrayRespectsMaxDepth()`) now compute the size explicitly and throw a `\LogicException` when it exceeds the 1-byte size field, instead of masking it away. |

## Verification

- `composer lint` (PHPCS + Rector dry-run + PHPStan level 9 + kb-lint): clean
- `./vendor/bin/phpunit --testsuite unit`: 1085 tests, 8222 assertions, OK
