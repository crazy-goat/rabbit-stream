# Review Round 2 — Issue #405 (AMQP decoder: drop per-value tuple allocation)

Branch: `feature/issue-405-amqp-decoder-alloc` (HEAD `5c2e727` — "fix(protocol): address round-1 review findings")

## Tooling results (run locally, round 2)

| Check | Result |
|---|---|
| `composer cs` (PHPCS PSR-12) | ✅ clean |
| `composer phpstan` (level 9) | ✅ 0 errors |
| `composer rector` (dry-run) | ✅ no suggestions |
| `./vendor/bin/phpunit --testsuite unit` | ✅ 1073 tests, 8196 assertions OK (3 new tests vs round 1) |

## Round-1 finding dispositions

### 1. (low) `decodeMessage()` inlined the described-type read — **fixed**

Evidence (`src/Client/AmqpDecoder.php:176-178`):

```php
// Read the described type (skip the 0x00 marker; depth 0 -> 1)
$position++;
['descriptor' => $descriptor, 'value' => $value] = self::readDescribedType($data, $position, 0, $maxDepth);
```

`decodeMessage()` now calls the single shared `readDescribedType()` (line 816-821),
which decodes descriptor and value at `$depth + 1`. Verified correctness of the
two details the round-1 finding worried about:

- **Marker skip**: `readDescribedType()` does *not* skip the 0x00 marker; the
  explicit `$position++` in `decodeMessage()` does it exactly once before the
  call. Inside `decodeValueInPlace()`, the 0x00 arm dispatches to
  `readDescribedType()` again — the match arm consumed the marker byte via its
  own `$position++` (lines 78-79), so a nested described type also skips exactly
  one marker. No double-skip, no missed-skip.
- **Depth parity**: `decodeMessage()` passes depth 0, so descriptor/value decode
  at depth 1 — identical to the old `readDescribedTypeWithPosition($data, $position, 0, …)`
  → `decodeValue(…, depth + 1)` chain. Compounds still recurse at `$depth + 1`
  from the reader level (e.g. list8 elements decode at the list's depth + 1),
  unchanged from round 1. The single 0x00-marker skip is now the *only* code
  path that does it outside `decodeValueInPlace()`, so there is no duplicated
  logic left to diverge.

The full unit suite (including the pre-existing decoder parity tests) is green.

### 2. (low) no direct `decodeValue()` BC-wrapper tests — **fixed**

New file `tests/Client/AmqpDecoderWrapperTest.php` (3 tests):

- `testWrapperReturnsTupleOfValueAndNewPosition` — tuple shape `[value, position]`
  for a top-level described value (`0xa1 0x03 "abc"` → `['abc', 5]`, `assertCount(2)`).
- `testWrapperStartsAtGivenPosition` — decoding starts at the supplied offset.
- `testWrapperLeavesPositionUnchangedOnException` — `$position` is still 2 after
  a `DeserializationException` on truncated data.

One observation, not a finding: the third test is trivially true by PHP semantics
— `decodeValue()` takes `$position` by value, so the caller's variable can never
change regardless of exceptions. It still pins the caller-visible contract the
round-1 finding asked for (the wrapper signature itself), so it serves its
purpose. The exception-behavior aspect that actually matters (internal readers
throw *before* advancing `$position`, so a caught exception leaves the cursor
consistent) is exercised implicitly by the suite.

### 3. (nit) missing `@return mixed` on `decodeValueInPlace()` — **fixed**

`src/Client/AmqpDecoder.php:58`: docblock now reads
`@return mixed the decoded value, with $position advanced past it`. Sibling
consistency restored.

### 4. (nit) unused defaults on `decodeValueInPlace()` — **fixed**

`src/Client/AmqpDecoder.php:60-65`: `$depth` and `$maxDepth` no longer declare
defaults; all call sites (match arms, list/map readers, `readDescribedType()`,
`decodeValue()`) pass them explicitly. An accidental depth-0 internal call is no
longer expressible without naming the arguments.

## Round-2 verification of `decodeMessage()` / `readDescribedType()` reuse

Summarized from disposition 1 above: single shared code path, marker skip
correct and singular, depth accounting identical to the pre-refactor behavior,
guards (`compoundContentEnd`, `assertCompoundConsumed`, #451 subtraction form,
#449 caps, #397 depth check) byte-for-byte unchanged. ✅

## Round-2 verification of the BC-wrapper test coverage

`tests/Client/AmqpDecoderWrapperTest.php` covers the wrapper contract asked for
in round 1: tuple shape, start-position handling, and the exception case. ✅

## New issues in the full diff (`git diff origin/main...HEAD`)

The diff touches `src/Client/AmqpDecoder.php` (236 lines changed), the new test
file, and docs/proof_of_work files. Checked:

- All readers converted to `int &$position` consistently; every guard throws
  before any `$position` mutation, so exception paths leave the cursor untouched
  — same observable behavior as the tuple version.
- `readUuid()` advances `$position` before the `sprintf`; `sprintf` cannot throw
  for these arguments, so no divergence.
- No match arm returns a tuple; constant arms (`0x40`–`0x45`) return plain values.
- `Platform::assertSixtyFourBitIntegers()` still fires once at depth 0 and once
  at `decodeMessage()` entry — no per-element cost regression, no behavior change.
- New test file: PSR-12 clean, namespaced per PSR-4, no issues.

**No new findings.**

## Verdict

**Clean.** All 4 round-1 findings are fixed with evidence; no new issues
introduced by the fix commit. Ready to merge from the review perspective.
