# Findings — review round 1 — issue #383

One entry per finding. Severity: high / medium / low / nit. Status: open / fixed / not-a-finding.

---

## R1-1 — Missing truncated-chunk test on the consume path

- **File:** `tests/Client/OsirisChunkParserTest.php` (missing test)
- **What is wrong:** No test feeds a chunk truncated mid-entry (or mid-sub-batch-body)
  to assert that `DeserializationException` is thrown. The `ReadBuffer::ensureAvailable()`
  guards (`src/Buffer/ReadBuffer.php:13–22`) are the security backbone of the untrusted-
  data consume path, but they are only exercised indirectly (via header-validation tests
  that happen to underflow). For a priority:critical correctness fix on the untrusted-
  server-data path, a dedicated truncation test would close the evidence gap.
- **Severity:** low
- **Status:** fixed — added `testTruncatedSimpleEntryThrowsException` and
  `testTruncatedSubBatchBodyThrowsException` (13 tests, all green).
- **Smallest safe fix direction:** Add a test that builds a valid chunk header +
  partial entry body (e.g. `numEntries=1` with a simple entry claiming 100 bytes but
  only 10 present) and asserts `DeserializationException` (or `RuntimeException`) is
  thrown. Similarly for a sub-batch with `compressedSize` exceeding available bytes.

---

## R1-2 — Missing empty-sub-batch test (count=0)

- **File:** `tests/Client/OsirisChunkParserTest.php` (missing test)
- **What is wrong:** A sub-batch with `numRecords=0` is not tested. The parser handles
  it correctly (`src/Client/OsirisChunkParser.php:96` — inner loop runs 0 times, no
  entries added, `$currentOffset` unchanged), but this edge case is unverified. If a
  future refactor changes the inner-loop guard, this could silently break.
- **Severity:** low
- **Status:** fixed — added `testEmptySubBatchProducesNoEntries`.
- **Smallest safe fix direction:** Add `testEmptySubBatchProducesNoEntries` with
  `numEntries=1, numRecords=0, entries=[['type'=>'subbatch','codec'=>0,'entries'=>[]]]`,
  assert `assertCount(0, $entries)`.

---

## R1-3 — `@see` docblock references only PROTOCOL.adoc, not osiris_log.erl

- **File:** `src/Client/OsirisChunkParser.php:32`
- **What is wrong:** The `@see` tag points at the rabbitmq_stream `PROTOCOL.adoc` only.
  The `CHNK_USER` sub-batch entry layout is defined in
  `deps/osiris/src/osiris_log.erl`, not the adoc. The stale `@see` was the root cause
  of the original wrong docblock (which documented a 4-byte merged header). Without
  the osiris_log.erl reference, a future reader could re-introduce the same mistake.
- **Severity:** low
- **Status:** fixed — appended the osiris_log.erl URL to the `@see` tag.
- **Smallest safe fix direction:** Append the osiris_log.erl URL to the `@see` tag:
  `@see https://github.com/rabbitmq/rabbitmq-server/blob/main/deps/osiris/src/osiris_log.erl`

---

## R1-4 — Inaccurate arithmetic in coder findings-coder.md finding #3

- **File:** `docs/proof_of_work/383-osiris-subbatch-header/findings-coder.md` (finding #3)
- **What is wrong:** The coder claims `(0x88000000 >> 25) & 0x0F = 0` and that the old
  parser "silently ignored" codec 4 on the old fixture. This is arithmetically wrong:
  `(0x88000000 >> 25) & 0x0F = 0x44 & 0x0F = 4`. Verified by simulation — the old parser
  on the old fixture with codec=4 extracts `codec=4` and the guard **does** fire. The
  old fixture and old parser were self-consistent for all codecs 0–7; the bug was the
  wrong wire layout relative to real Osiris data, not internally inconsistent math.
  The regression test `testCompressedSubBatchZstdThrowsException` is still valid — it
  catches the bug because the **old parser on the new fixture** extracts `codec=0`
  from `0xC0000100` and throws an underflow instead of the expected `"codec: 4"` message.
  But the specific justification written in findings-coder.md is incorrect.
- **Severity:** nit
- **Status:** fixed — corrected the arithmetic in findings-coder.md obstacle #3;
  it now states the old fixture+parser were self-consistent for codecs 0–7 and the
  zstd regression test catches the bug via old-parser-on-new-fixture.
  (doc-only inaccuracy in a proof-of-work file; does not affect code or test validity)
- **Smallest safe fix direction:** Correct the explanation in findings-coder.md to
  state that the old fixture + old parser were self-consistent for codecs 0–7, and the
  test catches the bug via old-parser-on-new-fixture (which extracts codec=0, not 4).

---

## R1-5 — No unconsumed-trailing-bytes validation

- **File:** `src/Client/OsirisChunkParser.php` (after the entry loop, `:107`)
- **What is wrong:** After the `for ($i = 0; $i < $numEntries; $i++)` loop, the parser
  does not check whether all bytes in the data section were consumed. Trailing garbage
  or a miscounted `numEntries` would be silently ignored. This is permissive, not a
  security issue (bounded by buffer size), but it reduces corruption detection.
- **Severity:** nit
- **Status:** deliberately not fixed — adding trailing-bytes validation is a behavior
  change (it would throw on bytes the broker may legitimately emit in future chunk
  versions) and overlaps with coder finding #1 (numRecords cross-check), which is a
  separate hardening issue. Filed as a follow-up candidate in step 14, not patched here.
- **Smallest safe fix direction:** Optionally, after the loop, assert
  `$buffer->getPosition()` equals the expected end (header size + dataLength) and throw
  on mismatch. Low priority — relates to coder finding #1 (numRecords cross-check).

---

## Coder findings evaluation (from findings-coder.md)

| Coder # | Verdict | Issue-worthy? | Notes |
|---------|---------|---------------|-------|
| 1 — numRecords not cross-checked | **real** | yes (low/medium hardening follow-up) | Header numRecords read & discarded (`:53`); no integrity check. Not security (ReadBuffer bounds prevent amplification). |
| 2 — sub-batch body copy (2× memory) | **real, by-design/acceptable** | low, not now | `readBytes` substr-copies, then wraps in new ReadBuffer. Acceptable at current scale; revisit when compression lands. |
| 3 — @see docblock stale | **real** | yes (low, nice-to-have) | See R1-3 above. |
| 4 — no getUint24() | **not-a-finding (by-design)** | no | Deliberate non-abstraction; single call site. Correct per guidelines. |
| 5 — 32-bit PHP overflow | **real, pre-existing, out-of-scope** | maybe separate issue | `unpack('N')`/`unpack('J')` return float on 32-bit for large values. Pre-existing in ReadBuffer. Unrealistic target. |

---

## Summary

- **Blocking (high) findings:** 0
- **Medium findings:** 0
- **Low findings:** 3 (R1-1, R1-2, R1-3)
- **Nit findings:** 2 (R1-4, R1-5)
- **Verdict:** APPROVE — safe to merge. All low/nit findings are coverage/doc
  improvements that can be addressed in follow-up issues, not blockers.
