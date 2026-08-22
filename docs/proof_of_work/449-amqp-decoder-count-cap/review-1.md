# Review 1 — AmqpDecoder element-count cap (issue #449)

**Branch:** `feature/issue-449-amqp-decoder-count-cap`
**Reviewer:** main session (reviewer subagent hit its iteration limit; review done in main context)
**Commit:** `a366e30` (rebased onto current `origin/main`)

## Summary

The fix adds two complementary pre-loop guards to `readList32` and `readMap32`
in `src/Client/AmqpDecoder.php`, plus a `MAX_COMPOUND_ELEMENTS = 131072`
constant. Both guards throw `DeserializationException` **before** the allocation
loop, so no array bucket is ever allocated for an oversized count.

## Checklist

### 1. Protocol / wire-format correctness — PASS

- **Lying-count vector** (count >> available bytes): guard 1
  (`$count > $available`) fires immediately. Verified: the lying PoC
  (size=5, count=8388608, 1 content byte) throws
  "List32 count 8388608 exceeds available bytes 2" before the loop.
- **Honest-large-frame vector** (count == available == millions): guard 2
  (`$count > MAX_COMPOUND_ELEMENTS`) fires. Verified: the headline PoC
  (truthful size, count=131073, 131073 null bytes) throws
  "List32 count 131073 exceeds maximum compound elements 131072" before the
  loop. This is the vector the issue's *primary* suggested fix (guard 1 alone)
  does NOT stop — the coder correctly identified this discrepancy and added
  guard 2 (the "total-element budget" alternative the issue mentions).
- `MAX_COMPOUND_ELEMENTS = 131072` (128K): reasonable ceiling. Real AMQP 1.0
  messages (headers, properties, application-properties) have at most dozens of
  entries. 128K elements ≈ 2–4 MB array storage — well below any OOM threshold.
  No legitimate message should approach this.

### 2. Type correctness (PHPStan level 9) — PASS

`composer phpstan`: 0 errors. The `assert(is_array($value))` narrowing in
`testDecodeList32AtElementCapDecodes` is correct for PHPStan level 9.

### 3. PSR-12 (PHPCS) — PASS

`composer cs`: clean (242 files, 0 violations).

### 4. Test coverage — PASS (1 nit)

10 new tests cover:
- ✅ Lying-count throws before allocating (list32 + map32)
- ✅ Honest-large-frame throws before allocating (list32 + map32)
- ✅ Boundary: count within available bytes decodes (list32 + map32)
- ✅ Boundary: count exceeding available throws (list32 + map32)
- ✅ Boundary: exactly at element cap decodes (list32 only — see nit R1)
- ✅ Regression: valid payloads decode identically
- ✅ Exposure path: `decodeMessage()` with oversized body

Memory assertions use `memory_get_peak_usage(true)` with a 32 MB threshold
(matches the #397 depth-guard test style). 32 MB is well above baseline noise
(~6 MB page rounding) and well below the 576 MB amplification target — not
brittle.

### 5. Security — PASS

- Both guards placed **before** `$list = []` / `$map = []` and the `for` loop —
  no bucket allocated for oversized count. ✅
- Both `readList32` and `readMap32` covered with identical guard structure. ✅
- Exception type: `DeserializationException` (extends `RabbitStreamException`
  → `\RuntimeException`, implements `RabbitStreamExceptionInterface`). Catchable,
  never a fatal. ✅
- 8-bit readers (`readList8`/`readMap8`) correctly skipped — 8-bit count caps at
  255, cannot OOM. Rationale documented in `code-decision-1.md`. ✅

### 6. Edge cases — PASS

- **Nested compounds**: the per-compound cap bounds each level independently.
  A list of lists, each near 128K, is bounded by the depth guard (#397,
  `MAX_RECURSION_DEPTH = 32`) — at most 32 levels of nesting. Aggregate across
  a single path: 32 × 128K = 4M elements max, but each level's array is
  allocated and the inner ones are small (only the outermost holds 128K
  sub-lists, each sub-list holds 128K elements). Realistic worst case: the
  outermost list has 128K entries, each a 128K-element list = 128K × 128K =
  16G elements — but that requires 16G bytes on the wire (each element ≥1 byte),
  far exceeding the 8 MiB frame limit. So the frame-size ceiling + per-compound
  cap + depth guard together bound aggregate amplification. Acceptable.
- **`$available` off-by-one** (findings F2): the pre-existing
  `$endPosition = $position + $size - 4` is one byte past the real last content
  byte. This makes `$available` one byte looser than the true content length.
  Not made worse by the new guard — the `>` comparison is still tight enough.
  Documented in `findings-coder.md` F2. No action needed for this issue.

### 7. Rector dry-run — PASS

`composer lint` (which includes Rector dry-run + kb-lint): clean.

## Findings

See `findings-review.md`. Summary: **1 nit** (R1 — missing boundary-at-cap test
for map32). No high/medium/low findings. The fix is correct, complete, and
closes both OOM vectors described in issue #449.
