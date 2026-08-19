# Code decision 1 — OsirisChunkParser dataLength guard (issue #399)

**Issue:** #399
**Branch:** `feature/issue-399-osiris-datalength-guard`

## Problem

`src/Client/OsirisChunkParser.php:78-110` read `dataLength` and `trailerLength`
only to discard them (`:71-72`), then parsed entries from the rest of the
received buffer with no relation to the declared data section. Sub-batch inner
entries cost 4 bytes on the wire (a uint32 size prefix) but produce one
`ChunkEntry` object each, so a chunk inside the client's own 8 MB frame limit
(`StreamConnection::DEFAULT_MAX_FRAME_SIZE`) could expand into ~2,000,000
objects (~200 MB) — a ~30x amplification. Reproduced exactly: a 7,864,578-byte
chunk (`30 sub-batches × 65,535 zero-size records`) parses to **1,966,050
`ChunkEntry` objects, +202 MB** in 0.375 s.

## Approach taken

Four layered guards in `src/Client/OsirisChunkParser.php` (smallest correct
change, zero API breakage):

1. **`dataLength`/`trailerLength` validated against the received chunk.**
   `headerSize (48) + dataLength + trailerLength > strlen($chunkBytes)` →
   `DeserializationException`. `>` (not `!==`) so trailing/padding bytes are
   tolerated; the declared sections must fit, they need not exhaust the chunk.

2. **Entry loop bounded by `dataLength` via a sub-buffer.** After the header,
   parsing continues on `new ReadBuffer(substr($chunkBytes, 48, $dataLength))`
   — exactly the declared data section. Any entry that would spill into the
   trailer or past the received bytes fails with the `ReadBuffer` underflow
   guard (`DeserializationException`). This is the issue's criterion #2.

3. **Per-sub-batch plausibility check.** `numRecords * 4 > compressedSize` →
   `DeserializationException` (each record needs at least a 4-byte size
   prefix). Cheap reject for inconsistent sub-batches.

4. **Configurable entries-per-chunk ceiling** (criterion #3). Optional trailing
   parameter `parse(string $chunkBytes, int $maxEntriesPerChunk = 262144)` —
   BC-safe, mirrors the `$maxDepth` precedent from #397. Two enforcement
   points: an **up-front header check** (`numRecords > maxEntriesPerChunk`
   throws before any allocation — this is what makes the PoC a 0-byte no-op),
   and an **in-loop count check** (catches chunks whose header under-declares
   their sub-batch record counts; = the `entryCount >= max` guard at the top of
   both loop levels).

**Default value: 262 144.** Rationale: a compliant broker's deliver chunks stay
well below the frame limit (in practice ~1 MB and fewer than ~262 K records
even for all-empty messages); 262 144 entries cap the parser's transient memory
at ~30 MB even in the worst case, ~7.5x below the PoC's count. The parameter is
there for operators who negotiate very large frame sizes and need more.

## What I rejected and why

1. **Relying on the issue's suggested `compressedSize / 4` check alone.** It
   does **not** stop the PoC: each of the 30 sub-batches is individually
   wire-consistent (65 535 records × 4 bytes = 262 140 ≤ `compressedSize`).
   The amplification comes from *repeating* wire-consistent sub-batches, so
   only a total-entries ceiling (guard 4) actually bounds the memory. I kept
   the per-sub-batch check anyway as cheap defense-in-depth for lying headers.

2. **Bounding by the header `numEntries` only.** `numEntries` is uint16
   (≤ 65 535) and says nothing about inner records — the PoC needs only 30
   outer entries. Useless as a guard by itself (still used as the loop bound).

3. **Strict `!==` chunk-size equality.** `DeliverResponseV1` passes the chunk
   as `getRemainingBytes()` of the frame, so real chunks usually are exactly
   48 + data + trailer — but strict equality would break on any future frame
   layout with trailing fields. `>` gives the same security property (declared
   sections must fit) with forward tolerance.

4. **Threading the limit through `Consumer` as a constructor option.** The
   parser-level optional parameter is BC-safe; adding a `Consumer` option would
   change the public API surface and constructor for something the parser
   defaults already handle (Consumer uses the safe default). Noted as a
   follow-up in findings-coder.md instead.

5. **Static mutable configuration (setter for the default).** Global state,
   worse than a parameter.

## What I was unsure about

1. **Whether the header `numRecords` early check is safe against legit
   brokers.** Osiris semantics (verified in `osiris_log.erl`, `make_chunk`)
   define header `numRecords` as the exact total of record counts across all
   entries — the same sum the parser produces. So `numRecords > cap` rejects
   exactly the chunks that would exceed the cap anyway. E2E not runnable here
   (no Docker in this environment), but the E2E fixtures (1–3 messages) are
   orders of magnitude below the cap, and the check is consistent with how
   oss clients (Java/Go) treat `numRecords`.

2. **The default cap value.** Would 100 000 be safer for 128 MB limits? Yes,
   marginally, but it risks breaking a legitimately negotiated 8 MB frame_max
   with very small messages. 262 144 keeps the ~30 MB transient worst case and
   sits exactly at the physical record maximum of a 1 MB data section, which
   is the realistic broker chunk size. Documented in the constant's phpdoc.

3. **`substr()` copy of the data section.** `substr($chunkBytes, 48,
   $dataLength)` duplicates up to 8 MB transiently instead of parsing in place.
   Accepted for the smallest change; a bounded-view API on `ReadBuffer` would
   avoid it (see findings-coder.md).

4. **Message wording.** I used "entries" for the cap in user-facing messages
   and "records" for the header field, matching the PROTOCOL.adoc vocabulary.

## Validation

- PoC-scale chunk (30 × 65 535 zero-size records, 7,864,578 bytes): thrown in
  **0.001 s with a measured 0-byte `memory_get_usage(true)` delta**; a full
  parse with a raised cap costs +202 MB / 1,966,050 objects.
- `./vendor/bin/phpunit --testsuite unit` — 860 tests pass; the single "risky"
  test is the pre-existing no-assertion
  `StreamConnectionTest` case (untouched file).
- New tests (22 in `OsirisChunkParserTest`, 9 new): dataLength overflow,
  trailerLength overflow, entries outside the declared data section are not
  read, trailing bytes beyond declared sections are ignored, per-sub-batch
  count check, ceiling at limit (passes) / above limit (throws), param
  validation, and the amplification payload asserting bounded memory (< 64 MB
  against a 202 MB regression signal).
- `composer phpstan` (level 9), `composer cs`, `composer rector` (dry-run): all
  clean.
- E2E not run: pure parser/unit change, no wire-format change (per the task
  instructions; `tests/E2E/OsirisChunkParserE2ETest.php` fixtures are far
  below the new limits).
