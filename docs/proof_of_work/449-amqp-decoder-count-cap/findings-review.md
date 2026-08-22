# Findings (review) — AmqpDecoder element-count cap (issue #449)

One entry per finding. Appended across rounds. Nothing is deleted — a finding
the coder believes fixed and the review still sees is a disagreement worth
keeping on the record.

---

## R1 — Missing boundary-at-element-cap test for map32
**Severity:** nit
**Round:** 1

- **Where:** `tests/Client/AmqpDecoderTest.php` — `testDecodeList32AtElementCapDecodes` exists (line 667) but there is no `testDecodeMap32AtElementCapDecodes` analogue.
- **What:** The list32 boundary-at-cap test verifies that exactly `MAX_COMPOUND_ELEMENTS` elements decode without error. The map32 path has the same guard but no corresponding boundary test — only the over-cap throw test (`testDecodeMap32HonestLargeFrameThrowsBeforeAllocating` with count=131073). Symmetry gap: the "exactly at cap → decodes" boundary is tested for list32 but not map32.
- **What happened to it:** **Fixed (round 1).** Added `testDecodeMap32AtElementCapDecodes` — a map32 with exactly 65536 uint16-keyed null-value pairs (count=131072=MAX_COMPOUND_ELEMENTS) that decodes without error. Uses uint16 (0x60) keys for uniqueness (smalluint wraps at 256, null key collapses to one entry).
- **Automated check that could catch this:** none — this is a test-coverage symmetry gap, not a code defect. A mutation test could detect it (mutating the map32 `>` to `>=` would make the boundary test fail), but there is no mutation testing in this project.
