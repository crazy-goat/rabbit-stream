# Review round 1 — Osiris sub-batch header parse fix (issue #383)

**Branch:** `feature/issue-383-osiris-subbatch-header`
**Reviewer:** review-critical agent (round 1)
**Date:** 2025-08-18
**Scope:** `src/Client/OsirisChunkParser.php`, `tests/Client/OsirisChunkParserTest.php`

---

## Overall verdict

**APPROVE — no blocking findings. The fix is correct and safe to merge.**

The core protocol wire-format fix is verified byte-equivalent for simple entries and
correct for the real Osiris 1+2+4+4 sub-batch layout. All three QA gates pass clean
(PHPStan level 9, PHPCS PSR-12, PHPUnit — 10 tests / 1064 assertions). The
untrusted-data consume path is bounded by `ReadBuffer::ensureAvailable()` on every
read, so truncation fails loud (throws `DeserializationException`) rather than
producing silent garbage or an unbounded loop. The two new regression tests are
proven to fail against the old parser on the new fixture (verified by full
end-to-end simulation, not just hand-computed math).

Four non-blocking coverage gaps and one inaccurate justification in the coder's
findings doc are noted below. None block merge.

---

## 1. Protocol wire-format correctness

### 1a. Simple-entry length reconstruction — byte-equivalent ✓

**Claim to verify:** `(($entryType & 0x7F) << 24) | ($buffer->getUint16() << 8) | $buffer->getUint8()`
is byte-equivalent to the old `$header & 0x7FFFFFFF` where `$header = $buffer->getUint32()`.

**Evidence (file:line):** `src/Client/OsirisChunkParser.php:82`

Old code read 4 bytes `[b0, b1, b2, b3]` as a big-endian uint32:
`(b0<<24)|(b1<<16)|(b2<<8)|b3`, then masked `& 0x7FFFFFFF` → `(b0 & 0x7F)<<24 | b1<<16 | b2<<8 | b3`.

New code reads `b0` via `getUint8()`, then `b1,b2` via `getUint16()`, then `b3` via `getUint8()`:
`(b0 & 0x7F)<<24 | (b1<<8 | b2)<<8 | b3` = `(b0 & 0x7F)<<24 | b1<<16 | b2<<8 | b3`.

These are identical. Verified by simulation across 4 cases including the max boundary
`0x7FFFFFFF` (both produce `2147483647`) and a mid-range value `0x4000000B`. Full
end-to-end simulation: old parser on a new-fixture simple entry (`"Hello World"`,
offset 100) → returns 1 entry with correct data and offset → **PASS**.

**Discriminator bit:** old `($header & 0x80000000) !== 0` tests bit 31 of the uint32
= bit 7 of byte 0. New `($entryType & 0x80) !== 0` tests bit 7 of byte 0 directly.
Same bit, same semantics. ✓

**Overflow:** max reconstructed value `0x7FFFFFFF = 2147483647` fits in a PHP int on
both 64-bit and 32-bit (it is exactly `INT32_MAX`). No overflow. ✓

### 1b. Sub-batch codec extraction — correct ✓

**Evidence (file:line):** `src/Client/OsirisChunkParser.php:86`

`$codec = ($entryType >> 4) & 0x07` extracts bits 6–4 of byte 0, which is the 3-bit
`Cmp` field per the Osiris `CHNK_USER` layout. Verified by simulation for all codecs
1–7: each produces `byte0 = 0x80 | (codec << 4)` and the extraction recovers the
original codec exactly.

**Mask 0x07 vs max codec:** `Cmp` is defined as a 3-bit field, so max value is 7.
The mask `0x07` covers all 3 bits. No codec > 7 is representable on the wire. The
guard `if ($codec !== 0)` fires for all non-zero codecs (1=gzip, 2=snappy, 3=lz4,
4=zstd, 5–7=reserved/future). ✓

Old code used `($header >> 25) & 0x0F` which extracted bits 28–25 of the uint32 —
this merged `Cmp` (bits 6–4 of byte 0 = bits 30–28) with `Rsvd` (bits 3–0 of byte 0
= bits 27–24) and was wrong for real data.

### 1c. Sub-batch field order — correct ✓

**Evidence (file:line):** `src/Client/OsirisChunkParser.php:92–95`

```
$numRecords = $buffer->getUint16();    // bytes 1-2: count (uint16 big-endian)
$buffer->getUint32();                  // bytes 3-6: uncompressedSize (read, discarded)
$compressedSize = $buffer->getUint32(); // bytes 7-10: compressedSize
$subBatchData = $buffer->readBytes($compressedSize); // body
```

Matches the real layout: `1 byte header + uint16 count + uint32 uncompressed + uint32
compressed + body`. ✓

### 1d. Offset increment — per record, not per entry ✓

**Evidence (file:line):** `src/Client/OsirisChunkParser.php:84, 99`

Simple entry: `$currentOffset++` once (1 record per simple entry).
Sub-batch: `$currentOffset++` inside the inner `$j` loop (per record), and the outer
`else` branch has no entry-level increment. So a sub-batch with N records increments
offset N times. Mixed chunks produce correct sequential offsets.

Verified by `testMixedSimpleAndSubBatchEntries`: simple(1) + subbatch(2) + simple(1)
= 4 entries at offsets 200, 201, 202, 203. ✓

---

## 2. Untrusted-data / security angles

### 2a. No unbounded loop ✓

**Evidence:** `src/Client/OsirisChunkParser.php:80` (outer loop bounded by `$numEntries`
= uint16, max 65535), `:96` (inner loop bounded by `$numRecords` = uint16, max 65535).

Every loop iteration calls `getUint8/getUint16/getUint32/readBytes`, each of which
calls `ensureAvailable()` (`src/Buffer/ReadBuffer.php:13–22`) that throws
`DeserializationException` on underflow. A malicious chunk claiming 65535 inner records
in a 10-byte sub-batch body throws on the first `getUint32()` inside the inner loop.
No amplification, no infinite loop.

### 2b. No read past buffer ✓

**Evidence:** `src/Buffer/ReadBuffer.php:13–22` — `ensureAvailable(int $bytes)` compares
`$bytes > $available` and throws `DeserializationException` with position/available
detail before any `substr`. `readBytes()` (`:158–164`), `getUint8/16/32/64()`, and
`getInt16/32/64()` all call `ensureAvailable()` first.

`compressedSize` (uint32, max 4294967295) passed to `readBytes($compressedSize)` —
if the buffer doesn't have that many bytes, `ensureAvailable` throws. No overread.

### 2c. Integer overflow — safe on 64-bit PHP ✓

`$entrySize` max `0x7FFFFFFF` (see 1a). `compressedSize`/`innerSize` are uint32 values
returned as PHP `int`. On 64-bit PHP (the realistic target — `composer.json` requires
`php >= 8.1`, and all modern PHP installs are 64-bit), `unpack('N')` returns a proper
`int` in range 0–4294967295. No overflow in the bit assembly or the `readBytes(int)`
call.

On 32-bit PHP, `unpack('N')` for values ≥ `0x80000000` returns a float (PHP 7+ behavior)
which would be silently truncated — but this is pre-existing tech debt in `ReadBuffer`,
not introduced by this change. See coder finding #5 evaluation below.

### 2d. ReadBuffer throws on underflow (truncation fails loud) ✓

**Evidence:** `src/Buffer/ReadBuffer.php:13–22`. Confirmed by reading the source: every
public reader calls `ensureAvailable()` which throws `DeserializationException` (extends
`RabbitStreamException` extends `\RuntimeException`) before touching the buffer.

Full simulation confirmed: old parser on a truncated new-fixture sub-batch throws
`"underflow need=655360 avail=2559"` (512-entry case) and `"underflow need=12288
avail=47"` (3-entry case) — loud failures, not silent garbage.

---

## 3. Type correctness — PHPStan level 9 ✓

```
composer phpstan → [OK] No errors (237 files analysed)
```

---

## 4. Style — PSR-12 ✓

```
composer cs (phpcs --standard=phpcs.xml.dist) → 241 files, exit 0, no violations
```

---

## 5. Test coverage

### 5a. Fixture rebuild exercises the REAL layout ✓

**Evidence:** `tests/Client/OsirisChunkParserTest.php:234–235` — `createChunk()` now
emits `pack('C', 0x80 | (($codec & 0x07) << 4))` (1-byte header) + `pack('n', $count)`
(uint16) + two `pack('N', $uncompressedSize)` (uint32 uncompressed + uint32 compressed)
+ body. This matches the real Osiris 1+2+4+4 layout. The old fixture emitted
`pack('N', 0x80000000 | ($codec << 25) | $count)` (wrong 4-byte merged layout).

### 5b. 512-entry regression test — proves the fix ✓

**Evidence:** `tests/Client/OsirisChunkParserTest.php:137–161`
(`testSubBatchHeaderParsedAsOneBytePlusUint16`).

512 = `0x0200` has a non-zero high byte in the uint16 count field — this is the critical
discriminator. With the new fixture, byte 0 = `0x80`, bytes 1–2 = `0x02 0x00`.

**Would it have FAILED against old parser + old fixture?** No — old fixture + old
parser were self-consistent (coder finding #1 is correct on this point). The old
fixture stored count in the low 16 bits of a uint32, so count=512 decoded correctly
under the old math. This is exactly why the bug was hidden.

**Would it have FAILED against old parser + NEW fixture?** YES — verified by full
end-to-end simulation. Old parser reads `getUint32()` = `0x80020000`, extracts
`count = 0x80020000 & 0xFFFF = 0`, then reads garbage `uncompressedSize = 655360` and
`compressedSize = 655360`, then `readBytes(655360)` throws `"underflow need=655360
avail=2559"`. The test expects 512 entries → **FAIL** (throws instead).

The test is a valid regression test: it proves the new parser correctly handles the
real layout where count is a separate uint16 field.

### 5c. zstd codec=4 regression test — proves the fix ✓

**Evidence:** `tests/Client/OsirisChunkParserTest.php:163–178`
(`testCompressedSubBatchZstdThrowsException`).

New fixture byte 0 = `0x80 | (4 << 4) = 0xC0`. New parser: `codec = (0xC0 >> 4) & 0x07
= 4` → guard fires → throws `"Compressed sub-batches not supported yet (codec: 4)"`.

**Would old parser + new fixture throw the expected message?** NO — verified by
simulation. Old parser reads `getUint32()` = `0xC0000100`, extracts `codec =
(0xC0000100 >> 25) & 0x0F = 0` → guard does NOT fire → proceeds to read garbage sizes
→ throws `"underflow need=2048 avail=7"` instead. The test expects `"codec: 4"` →
**FAIL** (wrong exception message). Valid regression test. ✓

### 5d. Existing tests still meaningful ✓

`testParseUncompressedSubBatch` (3 records) with the new fixture: old parser extracts
`count = 0x80000300 & 0xFFFF = 0x0300 = 768` (garbage) → tries to read 768 inner records
→ underflow. New parser: `count = getUint16() = 3` → correct. The fixture rebuild made
this test exercise the real layout and it would catch the bug against the old parser.

`testCompressedSubBatchThrowsException` (codec=1) with new fixture: byte 0 = `0x90`.
New parser: `codec = (0x90 >> 4) & 0x07 = 1` → fires. Old parser on new fixture: header
= `0x90000100`, `codec = (0x90000100 >> 25) & 0x0F = 0` → does NOT fire → underflow
instead. Test still valid.

### 5e. Coverage gaps (non-blocking)

1. **No truncated-chunk test.** The `ReadBuffer::ensureAvailable()` guards are the
   security backbone of this path, but no test explicitly feeds a chunk truncated
   mid-entry to assert `DeserializationException` is thrown. The existing
   `testInvalidMagicThrowsException` / `testUnsupportedChunkVersionThrowsException`
   test header validation, not body truncation. **Severity: low** (for a
   security-critical path, a dedicated truncation test would be good hygiene).

2. **No empty-sub-batch test (count=0).** Parser handles it correctly (inner loop
   runs 0 times, no entries added, offset unchanged) but it is untested.
   **Severity: low.**

3. **No max-uint16-count test (65535).** The 512 test already proves the uint16 path
   (non-zero high byte), so 65535 would be redundant. **Severity: nit.**

4. **No zero-length simple-entry test.** `readBytes(0)` returns `''` correctly
   (`ensureAvailable(0)` passes since `0 > available` is false when `available >= 0`).
   Untested. **Severity: nit.**

5. **No unconsumed-trailing-bytes check.** The parser does not verify all bytes were
   consumed after the entry loop. Trailing garbage is silently ignored. This is
   permissive, not a security issue (bounded by buffer size). **Severity: nit.**

---

## 6. Docs

### 6a. Class docblock — updated to real layout ✓

**Evidence:** `src/Client/OsirisChunkParser.php:29` — docblock now reads:
"Sub-batch entry: 1-byte header (bit 7 = 1, codec in bits 6-4) + numRecords (uint16)
+ uncompressedSize (uint32) + compressedSize (uint32) + sub-batch data". Matches the
real 1+2+4+4 layout. ✓

### 6b. `@see` still references only PROTOCOL.adoc

**Evidence:** `src/Client/OsirisChunkParser.php:32` — `@see` points at
`PROTOCOL.adoc` only. The sub-batch `CHNK_USER` layout is defined in
`deps/osiris/src/osiris_log.erl`, not the adoc. The stale `@see` was the root cause of
the original wrong docblock (per coder finding #3). Adding the osiris_log.erl link
would prevent regression. **Severity: low** (doc accuracy, not blocking).

---

## 7. Coder findings evaluation (from findings-coder.md)

| # | Finding | Verdict | Issue-worthy? |
|---|---------|---------|---------------|
| 1 | Chunk-header `numRecords` never cross-checked against parsed entries | **REAL** — header `numRecords` (uint32) is read and discarded (`:53`). No integrity cross-check. Not a security issue (ReadBuffer bounds prevent amplification), but a corruption-detection gap. | Yes — low/medium hardening follow-up issue |
| 2 | Sub-batch body copied → transient memory doubling | **REAL, by-design/acceptable** — `readBytes($compressedSize)` (`:95`) substr-copies the body, then wraps in a second `ReadBuffer` (`:97`). Transient 2× memory for large sub-batches. Acceptable at current scale; revisit when compression lands. | Low priority, not now |
| 3 | `@see` docblock points at PROTOCOL.adoc, not osiris_log.erl | **REAL** — see 6b above. | Yes — low, nice-to-have |
| 4 | `ReadBuffer` lacks `getUint24()` | **NOT-A-FINDING (by-design)** — coder deliberately did not add a single-use abstraction. Correct per "no hypothetical abstractions" guideline. | No |
| 5 | `getInt64`/`getUint32` overflow on 32-bit PHP | **REAL, pre-existing, out-of-scope** — `unpack('N')`/`unpack('J')` return float on 32-bit PHP for large values. Pre-existing in `ReadBuffer`, not introduced here. Composer requires only `php >= 8.1` so 32-bit is technically in scope but unrealistic. | Maybe — separate hardening issue, not #383 |

### Inaccuracy in coder finding #3 (prose, not code)

The coder claims: "For the old fixture with codec 4 that's `0x80000000 | (4 << 25) =
0x88000000` → `(0x88000000 >> 25) & 0x0F = 0` → guard never fired."

This arithmetic is **wrong**. `(0x88000000 >> 25) & 0x0F = 0x44 & 0x0F = 4`, not 0.
Verified by simulation: old parser on old fixture with codec=4 extracts `codec=4` and
the guard **does** fire. The old fixture and old parser were self-consistent for all
codecs 0–7 (the bug was the wrong wire layout, not internally inconsistent math).

The coder's conclusion is still correct in spirit (the regression test
`testCompressedSubBatchZstdThrowsException` is valid) — but the specific justification
given is inaccurate. The test catches the bug because the **old parser on the new
fixture** extracts `codec=0` from `0xC0000100` (not the old fixture). **Severity: nit**
(doc-only inaccuracy in a proof-of-work file, does not affect code or test validity).

---

## 8. Remaining risk areas checked clean

| Area | Status |
|------|--------|
| Simple-entry byte-equivalence | ✓ Verified by simulation (4 cases + boundary) |
| Sub-batch 1+2+4+4 field order | ✓ Matches Osiris layout |
| Codec mask 0x07 covers all 3-bit Cmp values | ✓ Verified for codecs 1–7 |
| Discriminator bit (old bit 31 = new bit 7) | ✓ Same bit, same semantics |
| Offset increment per-record not per-entry | ✓ Verified by mixed-chunk test |
| Unbounded loop / amplification | ✓ Bounded by uint16 counts + ReadBuffer underflow guards |
| Read past buffer | ✓ ensureAvailable on every read |
| Integer overflow (64-bit) | ✓ Max 0x7FFFFFFF fits in int |
| PHPStan level 9 | ✓ Clean |
| PHPCS PSR-12 | ✓ Clean |
| PHPUnit (10 tests, 1064 assertions) | ✓ All pass |
| Regression tests fail against old parser | ✓ Verified by full end-to-end simulation |

## 9. Residual risks (not fully verified)

- **Inner-record framing for codec 0 (uint32 length + bytes)** was kept as-is per the
  task prescription. The coder did not re-derive it from `osiris_log.erl`. If the
  framing is wrong, uncompressed sub-batches would misparse — but this is unchanged
  from the pre-existing code and outside the scope of this fix. Low residual risk.
- **32-bit PHP overflow** (coder finding #5) — not verified on a 32-bit build. All
  tests pass on 64-bit PHP 8.5.9. Pre-existing, out of scope.
- **No real RabbitMQ E2E test** of this path — unit tests use self-built fixtures
  matching the documented layout. An E2E test against a real broker would provide
  end-to-end confidence but was explicitly excluded from scope (pure unit fix).
