# Review Round 1 — Issue #405 (AMQP decoder: drop per-value tuple allocation)

Branch: `feature/issue-405-amqp-decoder-alloc`
Scope: `src/Client/AmqpDecoder.php` (only source file changed)

## Tooling results (run locally)

| Check | Result |
|---|---|
| `composer cs` (PHPCS PSR-12) | ✅ clean |
| `composer phpstan` (level 9) | ✅ 0 errors |
| `composer rector` (dry-run) | ✅ no suggestions |
| `./vendor/bin/phpunit --testsuite unit` | ✅ 1070 tests, 8190 assertions OK |

## Change summary

The diff converts every private reader from the `[$value, $position]` tuple API to an
in-place API: `int &$position` by reference, plain value returned. `decodeValueInPlace()`
becomes the internal workhorse; the public `decodeValue()` is kept as a thin BC wrapper
that calls `decodeValueInPlace()` and re-wraps into the tuple. The dead-code tuple variant
of `readDescribedType()` was removed and `readDescribedTypeWithPosition()` was folded into
a single `readDescribedType()` returning `array{descriptor, value}`.

## Behavioral parity analysis

Verified each concern from the review brief:

1. **Bounds / truncation.** Every reader keeps its original guard (`$position + N >= strlen($data)`,
   `$length > strlen($data) - $position` with the #451 subtraction form, `compoundContentEnd()`,
   `assertCompoundConsumed()`). The order "read value → advance position" vs old
   "compute both → return" is equivalent because a guard throw leaves `$position` untouched in
   both versions. `readUuid` advances before the `sprintf`, but `sprintf` cannot throw here.
2. **Nesting depth (#397).** The depth check (`$depth > $maxDepth`) is unchanged and the depth
   plumbing is preserved exactly: compounds and described types still recurse at `$depth + 1`;
   `decodeMessage()` decodes descriptor/value at depth 1, matching the old
   `readDescribedTypeWithPosition($data, $position, 0, …)` → `decodeValue(…, depth+1)` chain.
   The 0x00 marker skip moved from `readDescribedTypeWithPosition()` into `decodeMessage()`
   (`$position++` at line 176) — semantically identical, and the match arm in
   `decodeValueInPlace()` still relies on the match-level `$position++` for nested described
   types, so no double-skip / no missed-skip.
3. **OOM guards (#449).** `MAX_COMPOUND_ELEMENTS`, the available-bytes cap, and
   `assertEvenMapCount()` are all untouched; the loops now append
   `decodeValueInPlace()` directly but the guard logic and loop bounds are byte-for-byte the old ones.
4. **decodeValue() BC wrapper.** `[$value, $position]` with `$position` advanced by reference is
   exactly the old contract, including the untouched `$position` on exception (callers could
   never observe it). All external callers (`Consumer`, `OsirisChunkParser`, `Message`, tests)
   go through the public API and see identical results.
5. **Match-arm values.** The constant arms (`0x40 => null` … `0x45 => []`) are direct
   translations; no arm accidentally returns the tuple shape (checked all 40+ arms).

PHPStan level 9 confirms return types: readers now declare real scalar/array types instead of
`array{0: x, 1: int}` tuples, which is a type-safety improvement.

## Findings

No high or medium issues found. See `findings-review.md` for the full numbered list
(2 low, 2 nits — none blocking).
