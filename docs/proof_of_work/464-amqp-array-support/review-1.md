# Review Round 1 — Issue #464 (AMQP array8 / array32 support)

Branch: `feature/issue-464-amqp-array-support`
Scope: `git diff origin/main...HEAD` — `src/Client/AmqpDecoder.php` (+89), `tests/Client/AmqpDecoderTest.php` (+140), plus prior coder PoC docs.

## QA run (local)

| Command | Result |
|---|---|
| `composer cs` (PHPCS PSR-12) | ✅ clean |
| `composer phpstan` (level 9) | ✅ 0/267 errors |
| `composer rector` (dry-run) | ✅ no suggested changes |
| `./vendor/bin/phpunit --testsuite unit` | ✅ 1083 tests, 8216 assertions, OK |

## Wire-format correctness (the critical check)

AMQP 1.0 spec (§1.6.17 array): the array constructor is
`size(uint8|uint32) count(uint8|uint32) ...elements`, where **size is the number of octets of the rest of the type — i.e. it INCLUDES the count field**.

Verified against the implementation:

- `readArray8` reads `size` (1 byte) and `count` (1 byte), then calls
  `compoundContentEnd($data, $position, $size, 1, 'Array8')`. The helper computes
  `declaredContent = size - countWidth`, so `size` is correctly treated as
  **size-then-count, size including the count field width**. ✅
- `readArray32` does the same with `countWidth = 4` after reading two `N` (big-endian uint32) fields. ✅ Big-endian `N` is correct per the AMQP 1.0 map/compound encoding.
- Header bounds checks mirror list8/list32 exactly (`$position + 1 >= strlen()` / `$position + 7 >= strlen()`). ✅
- `size < countWidth` (a size that doesn't even cover its own count field) is rejected by `compoundContentEnd`. ✅

## Depth-limit (#397) parity

- Every element is decoded with `depth + 1`, so nested arrays (including `array-of-array`, `array-containing-list/map`) are covered by the same recursion guard as lists and maps. ✅
- `testDecodeArrayRespectsMaxDepth` covers a 40-level nesting against the default max of 32. ✅
- A flat array is depth 1, so the comment's claim that #397 alone doesn't protect it is accurate.

## OOM-guard (#449) parity

- **array32**: two guards, identical in shape to `readList32`:
  1. `$count > $available` — malformed count cannot be satisfied by the content span (every element consumes ≥ 1 byte).
  2. `$count > MAX_COMPOUND_ELEMENTS (131072)` — catches honest large frames (e.g. 8 M nulls) before the loop allocates.
  Both fire before `$array = []` and the loop. ✅
- **array8**: no `MAX_COMPOUND_ELEMENTS` check, matching `readList8`'s approach — a uint8 count caps allocation at 255 elements, which is inherently safe. Acceptable parity; the loop's `$position >= $contentEnd` guard catches malformed counts. ✅
- `assertCompoundConsumed` closes the #453-style "lying size" desync hole for arrays too (a declared size larger than the elements actually present is rejected). ✅
- `testDecodeArray32HonestLargeFrameThrowsBeforeAllocating` mirrors the list32 #449 PoC, including a peak-memory assertion. ✅

## Bounds safety

- All reads go through existing bounded primitives (`ord`, `unpackIntAt`, `decodeValue`); the content window from `compoundContentEnd` bounds the element loop exactly as in list8/list32.
- No integer-overflow risk: `$position + 7` comparisons on PHP ints with a 32-bit-ish payload length are safe.
- Empty arrays (`size=1 count=0` / `size=4 count=0`) are handled and tested.

## Test quality (13 new tests)

Good coverage overall: happy paths for array8/array32, empty arrays, mixed element types, nested compounds inside arrays, array-of-arrays, malformed count (both variants), size mismatch, depth limit, OOM PoC, and an end-to-end `decodeMessage` body test. Two minor gaps noted below (boundary at exactly `MAX_COMPOUND_ELEMENTS`, and multi-byte elements under array32's available-bytes guard).

## Findings

See `findings-review.md`. No high-severity issues; nothing blocks merge.
