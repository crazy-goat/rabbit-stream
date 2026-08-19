# Findings — coder — issue #399

## Obstacles / surprises during implementation

1. **The issue's own suggested guard does not stop its own PoC.** The proposed
   `compressedSize / 4` check targets one sub-batch: 65 535 records × 4 bytes =
   262 140 ≤ `compressedSize` (262 144) — every sub-batch in the PoC is
   individually wire-consistent. The amplification comes from *repeating*
   consistent sub-batches 30 times. Only a total-entries ceiling defeats it;
   specifically the **header `numRecords` early check**, which rejects the PoC
   in 0.001 s with a measured 0-byte memory delta. Without that early check the
   in-loop ceiling alone would still allocate ~262 144 objects (~30 MB) before
   throwing — bounded, but not free. This is the single most important insight
   of the fix.

2. **Off-by-four in my first draft of the parser fix.** I captured
   `$headerSize = $buffer->getPosition()` right after reading `trailerLength`
   (position 44) instead of after the bloomSize + reserved bytes (position 48),
   which would have sliced the data section 4 bytes early and desynced every
   entry. Caught by reading the file over before testing; the unit suite plus
   the rebuild of the PoC payload confirm position 48 is correct.

3. **Test made the same kind of mistake: sub-batch `numRecords` offset.** My
   first version patched it at byte 65 (I mentally appended the whole data
   section instead of the entry header); the correct offset is 49 (48-byte
   chunk header + 1 entry-type byte). The first test run failed with the
   *early header check's* message instead — the run itself surfaced the wrong
   expectation (see 4).

4. **The ceiling guard fires before my chosen expectation in the test.** With
   `maxEntriesPerChunk: 5` and a header declaring 10 records, the up-front
   `numRecords` check throws first with a different message than the in-loop
   check. Re-crafted the test so the header declares 5 (passes the early
   check) while the sub-batch carries 10 — that now provably exercises the
   in-loop guard. Both paths are covered by their own tests.

5. **`memory_get_usage(true)` deltas can be negative** across tests sharing a
   process (the allocator's pool state), so the amplification test measures the
   delta inside its own try/catch around a freshly built payload and asserts
   `< 64 MB` — 0 bytes in practice, 202 MB without the fix, huge margin both
   ways.

## Discovered bugs / places to improve (including outside scope)

### 1. `Consumer` buffers an entire chunk's messages at once — residual amplification after this fix

- **Where:** `src/Client/Consumer.php:51-53` (subscribe callback).
- **What:** `array_push($this->buffer, ...$messages)` appends **all** entries
  of a chunk to the buffer; `maxBufferSize` only throttles via the per-chunk
  credit of 1 until `count($buffer) < maxBufferSize`. With the parser ceiling
  at 262 144, one legal chunk still transiently holds up to ~262 K
  `ChunkEntry` **plus** ~262 K decoded `Message` objects (~100–200 MB peak)
  regardless of `maxBufferSize`. The parser is now bounded; the consumer path
  is not.
- **Suggested fix:** push at most `maxBufferSize - count($buffer)` messages per
  chunk (drop the rest of the chunk or leave them for the next credit), or
  slice the chunk's decoded messages to the buffer's free capacity before
  `array_push`. Could also thread the parser's `maxEntriesPerChunk` through
  `Consumer` so operators can tune both together. Likely deserves its own
  issue.

### 2. `ReadBuffer` cannot represent a bounded view — data section is copied

- **Where:** `src/Buffer/ReadBuffer.php` (class), used by
  `src/Client/OsirisChunkParser.php:132`.
- **What:** bounding the entry loop to the data section required
  `substr($chunkBytes, 48, $dataLength)`, which duplicates up to ~8 MB per
  chunk transiently (PHP may share the buffer for full-length substrings, but
  a partial slice is a copy). Not a correctness problem, just peak-memory
  overhead on the exact path this issue makes hot.
- **Suggested fix:** add a read-only window/slice API to `ReadBuffer`
  (e.g. a constructor accepting an existing buffer + offset + length without
  copying), and let the parser use it. Low priority; the copy is bounded by
  the frame limit and reclaimed per parse.

### 3. Truncated-chunk tests now fail at a different, clearer check — behavior note, not a bug

- **Where:** `tests/Client/OsirisChunkParserTest.php:181-221` (`testTruncatedSimpleEntryThrowsException`, `testTruncatedSubBatchBodyThrowsException`).
- **What:** both still pass, but they now throw from the new "Chunk size
  mismatch: header + dataLength + trailerLength exceeds received bytes" check
  (the header's declared `dataLength` no longer fits the truncated bytes)
  rather than from a `ReadBuffer` underflow while reading the entry. Better
  diagnostics; worth knowing when reading stack traces.

### 4. Plan doc describes the chunk header incorrectly

- **Where:** `docs/plans/iterations/04-osiris-chunk-parser.md:113-124`.
- **What:** says the header is "52 bytes total", calls the last field
  "reserved (uint32)", and asserts `magicVersion` must be `0x00` — the actual
  wire format (PROTOCOL.adoc and the parser) is 48 bytes with bloomSize
  (uint8) + reserved (3 bytes), and magic is the high nibble `0x5` of the
  first byte.
- **Suggested fix:** correct the plan doc's header table or mark the
  iteration plan as historical/superseded. Doc-drift only; no code impact.

### 5. `DeliverResponseV1` exposes the chunk as the frame's remaining bytes

- **Where:** `src/Response/DeliverResponseV1.php:59`.
- **What:** `getRemainingBytes()` silently swallows any trailing bytes that a
  future protocol version might append after the chunk — acceptable and
  tolerable by design (the parser's `>` size check is forward-tolerant), but a
  future version bump of Deliver should re-parse the chunk explicitly rather
  than rely on "rest of frame". No action now.
