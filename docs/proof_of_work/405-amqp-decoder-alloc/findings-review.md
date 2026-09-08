# Findings — Review Round 1 (#405)

1. `src/Client/AmqpDecoder.php:139-233` — **low** — open
   `decodeMessage()` now re-implements the described-type read inline (`$position++` +
   two `decodeValueInPlace()` calls at lines 175-178) instead of reusing
   `readDescribedType()`. The two code paths must be kept in sync manually (both currently
   decode descriptor/value at `depth + 1`). A future edit to one (e.g. changing depth
   accounting or marker handling) can silently diverge from the other. Suggested fix:
   have `decodeMessage()` call a shared helper (e.g. a variant of `readDescribedType()`
   that returns the pair, or pass depth 0 and let it skip the marker).

2. `tests/` — **low** — open
   No tests were added or updated in this diff. Parity is covered indirectly by the
   existing 1070-test suite (all green), but there is no direct test pinning the
   `decodeValue()` BC wrapper contract — specifically that it returns
   `[value, advancedPosition]` for a described type decoded at top level and that
   `$position` is left untouched when `DeserializationException` is thrown. If the
   wrapper is a supported public API (as the docblock claims), it deserves its own
   assertions so a future removal/rename is a conscious decision, not an accident.

3. `src/Client/AmqpDecoder.php:59-64` — **nit** — open
   `decodeValueInPlace()` is missing an explicit `@return mixed` docblock tag. PHPStan
   infers it correctly, but the sibling `decodeValue()` documents its return; consistency
   would help readers.

4. `src/Client/AmqpDecoder.php:59-64` — **nit** — open
   `decodeValueInPlace()` declares default values for `$depth` / `$maxDepth`, but every
   call site passes them explicitly. Dropping the defaults (or keeping them — cosmetic
   either way) would make it clear the parameters are always threaded; as-is it invites
   a future internal call that silently uses depth 0.

## Verified non-issues (checked, no finding)

- Bounds/truncation guards, #451 overflow-safe length checks, `compoundContentEnd()`,
  `assertCompoundConsumed()` — all byte-for-byte equivalent to the old tuple version.
- #397 recursion depth accounting unchanged, including the depth-1 descriptor/value decode
  in `decodeMessage()` and the single 0x00-marker skip.
- #449 OOM guards (`MAX_COMPOUND_ELEMENTS`, available-bytes cap, `assertEvenMapCount()`)
  untouched and still effective.
- `decodeValue()` BC wrapper is exactly equivalent to the old implementation, including
  exception behavior.
- All match arms return the plain value; none accidentally leaks the old tuple shape.
- `composer cs`, `composer phpstan`, `composer rector` (dry-run), unit suite: all clean.

## Round 1 dispositions (main session, after fixes)

1. (low) decodeMessage inlined described-type read — **fixed**: decodeMessage() now reuses readDescribedType() (marker skip kept explicit; parity verified by full unit suite).
2. (low) no direct decodeValue() BC-wrapper tests — **fixed**: added tests/Client/AmqpDecoderWrapperTest.php (tuple shape, start position, position untouched on exception).
3. (nit) missing @return mixed on decodeValueInPlace() — **fixed**: docblock tag added.
4. (nit) unused defaults on decodeValueInPlace() — **fixed**: defaults removed; all call sites pass depth explicitly.

## Round 2 dispositions (review subagent)

1. (low) decodeMessage inlined described-type read — **fixed** (verified round 2): decodeMessage() calls the shared readDescribedType() at depth 0; single explicit $position++ skips the 0x00 marker exactly once; descriptor/value decode at depth 1, identical to pre-refactor. No duplicated logic remains.
2. (low) no decodeValue() BC-wrapper tests — **fixed** (verified round 2): tests/Client/AmqpDecoderWrapperTest.php covers tuple shape, start position, and the exception case. Note: the position-untouched assertion is trivially true since $position is by-value, but it pins the public contract as intended.
3. (nit) missing @return mixed — **fixed** (verified round 2): docblock tag present at src/Client/AmqpDecoder.php:58.
4. (nit) unused defaults — **fixed** (verified round 2): defaults removed from decodeValueInPlace(); all call sites pass depth explicitly.

Round 2 tooling: composer cs ✅, composer phpstan (level 9) ✅, composer rector (dry-run) ✅, unit suite ✅ 1073 tests / 8196 assertions. No new findings — see review-2.md. Verdict: **clean**.
