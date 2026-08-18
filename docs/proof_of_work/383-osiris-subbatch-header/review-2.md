# Review round 2 — Osiris sub-batch header parse fix (issue #383)

**Branch:** `feature/issue-383-osiris-subbatch-header`
**Reviewer:** review-critical agent (round 2)
**Date:** 2025-08-18
**Scope:** `src/Client/OsirisChunkParser.php`, `tests/Client/OsirisChunkParserTest.php`
**Commits reviewed:** `11b3c5f` (original fix) + `c5c2c5f` (round-1 fixes)

---

## Overall verdict

**APPROVE — no blocking findings. The branch is ready to merge.**

All three QA gates pass clean:
- `composer cs` (PHPCS PSR-12): 241 files, 0 violations
- `composer phpstan` (PHPStan level 9): 237 files, 0 errors
- `./vendor/bin/phpunit --testsuite unit --filter OsirisChunkParserTest`: 13 tests, 1067 assertions, all pass

All five round-1 findings are accounted for: 4 fixed, 1 deliberately deferred with
documented rationale. One new nit-level observation about test design (R2-1) — not
blocking.

---

## 1. Per-finding status for R1-1 through R1-5

### R1-1 — Truncated-chunk test — **FIXED**

Two tests added in commit `c5c2c5f`:

**`testTruncatedSimpleEntryThrowsException`** (test file lines 187–201):
- Builds a valid chunk with 1 simple entry (`'Hello World'`, 11 bytes), then
  truncates to `52 + 6 = 58` bytes (header 48 + 4-byte entry header + 6 body bytes).
- Truncation offset verified correct: header = 48 bytes (1+1+2+4+8+8+8+4+4+4+1+3),
  simple-entry header = 4 bytes (`getUint8` + `getUint16` + `getUint8`), leaving
  position 52 with 6 bytes of the claimed 11-byte body.
- Parser reads entry header successfully at position 52, then `readBytes(11)` calls
  `ensureAvailable(11)` with only 6 bytes available → throws `DeserializationException`.
- Asserts `\RuntimeException::class` ✓ (DeserializationException → RabbitStreamException
  → RuntimeException, verified by reading `src/Exception/`).
- **Would it FAIL if the guard were removed?** YES — verified by simulation: without
  `ensureAvailable`, `substr($chunk, 52, 11)` on a 58-byte string silently returns 6
  bytes, no exception thrown, test expects exception → FAIL. The test uniquely
  exercises the `readBytes` → `ensureAvailable` guard. ✓

**`testTruncatedSubBatchBodyThrowsException`** (test file lines 203–226):
- Builds a valid chunk with 1 sub-batch (2 inner records, `compressedSize=34`), then
  truncates to `59 + 2 = 61` bytes (header 48 + sub-batch header 11 + 2 body bytes).
- Truncation offset verified correct: sub-batch header = 1+2+4+4 = 11 bytes
  (`getUint8` + `getUint16` + `getUint32` + `getUint32`), leaving position 59 with
  2 bytes of the claimed 34-byte body.
- Parser reads sub-batch header successfully at position 59, then `readBytes(34)`
  calls `ensureAvailable(34)` with only 2 bytes available → throws.
- Asserts `\RuntimeException::class` ✓.
- **Would it FAIL if the guard were removed?** NO — see R2-1 below for the nuance.
  The `ensureAvailable` guard IS the first point of failure (fires before the inner
  buffer is created), so the test does exercise it in practice. But if it were
  removed, a secondary defense (`unpack('N', $shortString)` returns `false` →
  `getUint32` throws "Failed to unpack") would still cause the test to pass.

### R1-2 — Empty sub-batch test — **FIXED**

**`testEmptySubBatchProducesNoEntries`** (test file lines 228–243):
- Creates a sub-batch with `numRecords=0` and `entries=[]`.
- `innerData` is empty → `uncompressedSize=0`, `compressedSize=0`.
- `readBytes(0)` returns `''` (ensureAvailable(0) passes since `0 > available` is
  false when `available >= 0`). Inner loop runs 0 times. Returns `[]`.
- Asserts `assertSame([], $entries)` — strict identity check (not just `assertCount`).
  ✓
- Passes (part of the 13-test suite, all green). ✓

### R1-3 — `@see` docblock — **FIXED**

- `src/Client/OsirisChunkParser.php:32` now contains:
  `@see https://github.com/rabbitmq/rabbitmq-server/blob/main/deps/osiris/src/osiris_log.erl`
  appended below the existing PROTOCOL.adoc `@see`. ✓
- Added in commit `c5c2c5f`. ✓

### R1-4 — Arithmetic correction in findings-coder.md — **FIXED**

- `findings-coder.md` obstacle #3 corrected in commit `c5c2c5f` (24-line diff).
- Old (wrong) text: `(0x88000000 >> 25) & 0x0F = 0` → "guard never fired."
- New (correct) text: `(0x88000000 >> 25) & 0x0F = 4` (not 0) → old fixture + old
  parser were self-consistent for codecs 0–7.
- Arithmetic verified by PHP simulation:
  `0x88000001 >> 25 = 0x44; 0x44 & 0x0F = 4`. ✓
- New text correctly explains the zstd test catches the bug via old-parser-on-new-
  fixture (extracts `codec=0` from `0xC0000100`, skips guard, underflows). ✓
- The corrected explanation is arithmetically accurate and logically sound. ✓

### R1-5 — Trailing-bytes validation — **DELIBERATELY NOT FIXED (reasonable)**

- Status recorded in `findings-review.md` R1-5 with explicit rationale:
  "adding trailing-bytes validation is a behavior change (it would throw on bytes
  the broker may legitimately emit in future chunk versions) and overlaps with coder
  finding #1 (numRecords cross-check), which is a separate hardening issue. Filed as
  a follow-up candidate, not patched here."
- This is a reasonable deferral: the change is a behavior change (not a bug fix),
  severity is nit, and it overlaps with a separate hardening issue. ✓
- The parser remains bounded by ReadBuffer guards, so there is no security impact
  from the missing check. ✓

---

## 2. New findings

### R2-1 — Sub-batch truncation test does not uniquely exercise the ensureAvailable guard

- **Severity:** nit
- **File:** `tests/Client/OsirisChunkParserTest.php:203–226`
  (`testTruncatedSubBatchBodyThrowsException`)
- **What:** The test truncates the sub-batch body to 2 bytes (out of 34 claimed).
  The `readBytes(34)` → `ensureAvailable(34)` guard fires first, so the test does
  exercise it in practice. However, if `ensureAvailable` were removed from
  `readBytes`, the test would **still pass**: `substr` would silently return 2 bytes,
  the inner `ReadBuffer` would be 2 bytes long, and the inner `getUint32()` would
  throw because `unpack('N', $twoByteString)` returns `false` (PHP warning + false
  return), triggering the `if ($data === false) throw ...` check. Verified by
  simulation.
- **Impact:** The test does not **uniquely** prove the `readBytes` `ensureAvailable`
  guard works — it relies on a secondary defense (the `unpack`-false check in
  `getUint32`). The simple-entry truncation test (`testTruncatedSimpleEntryThrows
  Exception`) **does** uniquely test the guard (it would fail without it, since
  there is no inner buffer and `substr` would silently return short data with no
  further read to catch it). The sub-batch test is still valid and useful — it
  proves truncation fails loud — it just doesn't isolate the `readBytes` guard.
- **Smallest safe fix direction:** Optionally, add a sub-batch truncation test where
  the body has enough bytes for the inner `getUint32` to succeed but not enough for
  the inner `readBytes(innerSize)`, so the outer `readBytes` guard is the only thing
  preventing silent garbage. Or document the observation. Not blocking — the
  simple-entry test already provides unique coverage of the `readBytes` guard.

---

## 3. Remaining risk areas checked

| Area | Status |
|------|--------|
| Round-1 fixes introduced no code regression | ✓ Parser unchanged from 11b3c5f; c5c2c5f only added @see + tests |
| Truncation offsets correct (48 + entry/sub-batch header) | ✓ Verified: simple=48+4+6=58, sub-batch=48+11+2=61 |
| Exception type is RuntimeException (base of DeserializationException) | ✓ Verified via src/Exception/ hierarchy |
| Empty sub-batch asserts strict `assertSame([], ...)` | ✓ Line 242 |
| @see URL present in docblock | ✓ Line 32 |
| Arithmetic in findings-coder.md obstacle #3 correct | ✓ Verified: (0x88000000 >> 25) & 0x0F = 4 |
| R1-5 deferral documented with rationale | ✓ findings-review.md R1-5 |
| No staged files | ✓ `git status` clean |
| PHPCS PSR-12 | ✓ 241 files, 0 violations |
| PHPStan level 9 | ✓ 237 files, 0 errors |
| PHPUnit OsirisChunkParserTest | ✓ 13 tests, 1067 assertions, all pass |

### Areas checked clean (no new issues found)

- Simple-entry byte-equivalence (old vs new) — unchanged from R1, still correct.
- Sub-batch 1+2+4+4 field order — unchanged, still correct.
- Codec extraction `(entryType >> 4) & 0x07` — unchanged, still correct.
- Offset increment per-record — unchanged, still correct.
- Fixture/parser consistency — fixture emits 1-byte header + uint16 count + 2×uint32;
  parser reads the same. ✓
- Inner record framing (uint32 length + bytes) — unchanged, consistent between
  fixture and parser. ✓
- No unbounded loop — outer loop bounded by uint16 numEntries, inner by uint16
  numRecords, both with ReadBuffer underflow guards. ✓
- Integer overflow — max entrySize 0x7FFFFFFF fits in 64-bit int. ✓

### Areas not fully verified (unchanged from R1)

- Inner-record framing correctness vs real `osiris_log.erl` — kept as-is per task
  prescription. Low residual risk.
- 32-bit PHP overflow in ReadBuffer — pre-existing, out of scope.
- No real RabbitMQ E2E test — excluded from scope (unit-only fix).

---

## 4. Verdict

**APPROVE — ready to merge.** No blocking findings. All round-1 findings are
resolved (4 fixed, 1 deliberately deferred with rationale). One new nit (R2-1)
about test isolation — non-blocking, cosmetic test-design observation. The core
protocol fix, test coverage, and documentation are all sound.
