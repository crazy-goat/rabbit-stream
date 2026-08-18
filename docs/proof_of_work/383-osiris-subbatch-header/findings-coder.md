# Findings — coder — issue #383

## Obstacles / surprises during implementation

1. **The old parser and old fixture were *mutually consistent* for small
   counts — the collision that hid the bug.** Under the old wrong layout
   (`0x80000000 | (codec << 25) | count`), any count ≤ 0xFFFF decoded
   "correctly" because the old parser read the same uint32 back with the
   same bit math. The bug only manifests against **real** Osiris chunks,
   where the uint32 spanning bytes 0–3 mixes byte 0 (header) with the
   count field and the high bytes of uncompressedSize. That explains why
   the pre-existing `testParseUncompressedSubBatch` (3 records) passed:
   fixture and parser shared the same wrong model.

2. **The 512-count regression test is only meaningful with the new
   fixture.** I verified by simulation that the *old parser* on the *new
   fixture* extracts `codec=0, count=0` from `0x80020000` and then walks
   into the size fields → buffer underflow / garbage. So the test would
   have caught the original bug, as required.

3. **Codec=4 (zstd) and the regression test.** The issue claimed the old
   bit math silently ignored codec 4. That is **not** arithmetically
   accurate for the *old fixture*: `0x80000000 | (4 << 25) = 0x88000000`
   and `(0x88000000 >> 25) & 0x0F = 4` (not 0), so on the old
   self-consistent fixture the guard *did* fire for codec 4. The real
   defect is the **wrong wire layout** relative to real Osiris chunks,
   not internally inconsistent math. The new regression test
   `testCompressedSubBatchZstdThrowsException` still catches the bug,
   but via a different path: the **old parser on the new fixture**
   reads byte 0 = `0x80 | (4 << 4) = 0xC0` as part of a uint32
   (`0xC0000100` for count=1), extracts `codec = (0xC0000100 >> 25) &
   0x0F = 0`, skips the guard, and underflows reading the body — so it
   throws, but with the wrong exception. With the new parser,
   `codec = (0xC0 >> 4) & 0x07 = 4` → the guard fires with the expected
   `'codec: 4'` message. (Corrected during review round 1 — see
   `findings-review.md` R1-4.)

4. **`DeserializationException` is a `RuntimeException`** (via
   `RabbitStreamException`), so the new tests keep the pre-existing
   `expectException(\RuntimeException::class)` pattern — no change
   needed to exception expectations.

## Discovered bugs / places to improve (including outside scope)

### 1. Chunk-header `numRecords` is never cross-checked against parsed entries

- **Where:** `src/Client/OsirisChunkParser.php:53` (header read) vs the
  entry loops (lines ~92–113)
- **What:** the 48-byte header carries `numRecords` (total records
  across all entries) and the parser just skips it (`$buffer->getUint32()`
  on line 53). For simple entries the body length implicitly dictates 1
  record per entry, but for sub-batches the real record count comes only
  from the sub-batch uint16. A corrupt/malicious chunk could claim
  `numRecords` wildly different from what is parsed; the value is read
  and discarded.
- **Suggested fix:** count records while parsing and, after the entry
  loop, compare with the header `numRecords`; throw a
  `DeserializationException` on mismatch. Optional hardening — the
  inner `ReadBuffer` underflow guards already prevent memory
  amplification, so this is integrity-checking, not security.

### 2. Sub-batch body is copied out of the parent buffer — doubling memory for large sub-batches

- **Where:** `src/Client/OsirisChunkParser.php:102-104`
- **What:** `readBytes($compressedSize)` first runs `ensureAvailable`
  (so truncation fails loudly — no amplification, contrary to a first
  read of this code) but then makes a full `substr` copy of the body,
  which is wrapped in a second `ReadBuffer`. For a chunk holding the
  whole broker message batch the data is transiently in memory twice.
  Acceptable at today's scale, but if sub-batch chunks get large
  (compression ships later), parsing inner records directly from the
  parent buffer with an explicit byte range would avoid the copy.
- **Suggested fix:** later — when decompression lands — consider a
  windowed reader (offset + length over the parent string) instead of
  the copy; not worth it for the uncompressed path now.

### 3. Docblock `@see` points at PROTOCOL.adoc, but the sub-batch layout lives in osiris_log.erl

- **Where:** `src/Client/OsirisChunkParser.php:32`
- **What:** the rabbitmq_stream PROTOCOL.adoc describes the chunk
  framing but the `CHNK_USER` sub-batch entry layout is defined in
  `deps/osiris/src/osiris_log.erl`; the stale (wrong) docblock was the
  direct result of trusting the adoc link. Could add
  `https://github.com/rabbitmq/rabbitmq-server/blob/main/deps/osiris/src/osiris_log.erl`
  next to the adoc link to prevent regression.
- **Suggested fix:** append the osiris_log.erl link to the `@see`.

### 4. `ReadBuffer` lacks a `getUint24()`/`getUint24Uint8()` building block (style only)

- **Where:** `src/Buffer/ReadBuffer.php` (no 3-byte reader)
- **What:** the simple-entry length is assembled as
  `(($entryType & 0x7F) << 24) | (getUint16() << 8) | getUint8()` —
  correct, but a `getUint24()` (rejecting values where bit 23+ of the
  top byte is set would be caller's business) would read more naturally.
  Deliberately NOT added here: single call site, and the guideline is
  no abstractions for hypothetical futures.
- **Suggested fix:** add `getUint24(): int` when a second call site
  appears, not before.

### 5. `getInt64`/`getUint32` on 32-bit PHP would overflow

- **Where:** `src/Buffer/ReadBuffer.php:85-96, 126-137` (pre-existing)
- **What:** `unpack('J')`/`unpack('N')` return `int`; on 32-bit PHP
  values ≥ 0x80000000 silently become negative or float-converted.
  Composer requires only `php >= 8.1`, so 32-bit is technically in
  scope; unrelated to this issue but adjacent to the same byte-parsing
  code.
- **Suggested fix:** if 32-bit support matters, use `unpack('J')`
  guarded by `PHP_INT_SIZE === 8` or document 64-bit-only. Out of scope
  for #383.
