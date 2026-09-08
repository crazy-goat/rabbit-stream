# Review round 2 — #464 AMQP array support

Branch: `feature/issue-464-amqp-array-support` @ `6a087bd` ("fix(protocol): address round-1 review findings")
Scope reviewed: `git diff origin/main...HEAD` (src/Client/AmqpDecoder.php, tests/Client/AmqpDecoderTest.php, docs/proof_of_work/*).

## Round-1 finding dispositions

| # | Round-1 finding | Round-2 status |
|---|-----------------|----------------|
| 1 | Duplicated count/size guard blocks in readList32/readMap32/readArray32 | **Fixed (verified behavior-identical).** Extracted to `assertCompoundCount(int $count, int $available, string $what)`. Evidence: with `$what` = `List32` / `Map32` the formatted messages are byte-for-byte identical to the pre-refactor strings (`'%s count %d exceeds available bytes %d'`, `'%s count %d exceeds maximum compound elements %d'`), and check order (available-bytes first, then `MAX_COMPOUND_ELEMENTS`) is unchanged. Reuse verified in all three call sites (`readList32`, `readMap32`, and the new `readArray32`). Existing List32/Map32 guard tests still pass unchanged. |
| 2 | "array8 differs from list8" claim | **Confirmed not a real finding.** `readArray8` mirrors `readList8` structurally (same header layout, `compoundContentEnd` window, per-element guard, `assertCompoundConsumed`). No change needed. |
| 3 | Missing inclusive boundary test at MAX_COMPOUND_ELEMENTS | **Fixed (verified correct & deterministic).** `testDecodeArray32AtElementCapDecodes` uses count = 131072 exactly, fixture built with `pack('N', ...)` (no `chr()` truncation risk), asserts full decode, correct final position (`strlen($payload)`), and bounded memory. Fully deterministic. |
| 4 | Multi-byte-element overrun case missing | **Fixed (verified).** `testDecodeArray32MultiByteElementsOverrunStillThrows`: size=8, count=3, 4 content bytes with two 2-byte smalluints. The up-front guard correctly does not fire (3 ≤ 4); the per-element loop guard throws `Array32 count exceeds available data` on the third iteration. Deterministic; documents which guard catches which shape of overrun. |
| 5 | `chr($size & 0xFF)` silent wrap in fixtures | **Fixed (verified).** Both `buildNestedList8()` and `testDecodeArrayRespectsMaxDepth()` now throw `\LogicException` when the computed size exceeds 0xFF instead of masking. |

## Verification of assertCompoundCount() extraction

- Message formats and argument order identical to the removed inline blocks (see table above); no test expectation changed.
- Call order inside readers unchanged: header parse → `compoundContentEnd` → `assertCompoundCount` → loop → `assertCompoundConsumed`.
- All three 32-bit compound readers (List32, Map32, Array32) route through the helper; the 8-bit readers (List8, Array8) correctly do not (1-byte counts are inherently ≤ 255 and the per-element guard covers overruns).
- New boundary/multi-byte tests are deterministic: fixtures built with `pack()`/`str_repeat()`, no timing, no environment dependence. One minor observation: the `memory_get_peak_usage(true) - $baseline` assertions assume process peak was not already elevated by earlier tests (peak is monotonic process-wide). Currently green and stable under this suite ordering, so noted as informational only — not an open finding.

## QA results

- `composer cs` — clean (273/273)
- `composer phpstan` — clean (level 9, no errors)
- `composer rector` (dry-run) — no suggested changes
- `./vendor/bin/phpunit --testsuite unit` — OK (1085 tests, 8222 assertions)

## New issues found in round 2

None. Checked specifically:
- array8/array32 header bounds checks (`$position + 1 >= strlen`, `$position + 7 >= strlen`) are correct for their 2- and 8-byte headers.
- array8/array32 sizes include the count field; `compoundContentEnd(..., $countWidth)` subtracts it correctly; size-mismatch handling matches list8/list32 semantics.
- `0xe0`/`0xf0` dispatch entries do not collide with existing format codes.
- Depth accounting (`$depth + 1` for elements) matches list/map behavior; `testDecodeArrayRespectsMaxDepth` covers it.

## Verdict

**Clean** — no open findings. Ready to merge.
