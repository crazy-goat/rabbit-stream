# Findings — review — issue #399 (round 1)

One entry per finding: `file:line` | what is wrong | severity | what happened to it.
Severities: high / medium / low / nit. Status is "open" for every entry this round
(round 1, nothing to re-check yet). Nothing is ever deleted from this file.

---

## New findings (round 1)

### F1 — Default ceiling has zero headroom over the theoretical max of a compliant 1 MiB chunk; failure is a hard exception
- `src/Client/OsirisChunkParser.php:53` (DEFAULT_MAX_ENTRIES_PER_CHUNK = 262144) with `:108-114` (up-front reject)
- What is wrong: 262 144 is exactly `1 MiB / 4 B` — the structural minimum wire cost
  per record (uint32 size prefix). I verified against RabbitMQ sources that the
  server does **not** enforce `frame_max` on Deliver frames
  (`rabbit_stream_reader.erl send_file_callback`/`send_chunks` — header + chunk are
  two raw socket writes), so the broker's chunk-flush batching is the only cap on
  chunk size. A chunk flushed at/after ~1 MiB of data containing only near-empty
  records could legitimately declare 262 145+ records → hard
  `DeserializationException` → propagates through the `Consumer` subscribe callback
  → `readLoop` dies. Boundary verified empirically: 262 144 records parse at the
  default cap, 262 145 do not. Practical likelihood is low (real AMQP records cost
  ≥ ~6 bytes incl. the prefix, so ≥ ~1.6 MB of data is needed to reach the cap; E2E
  fixtures are 1-3 messages and cannot disprove it), but the failure mode is fatal
  rather than graceful, and no E2E test exercises the parser near the cap.
- Suggested fix (choose one): (a) add an E2E stress test — publish
  ~300 000 empty-body messages to a stream and consume them, proving a compliant
  broker stays below the cap; (b) raise the default to a value with genuine headroom
  (e.g. 524 288 or 1 048 576 — at the cost of a weaker worst-case memory bound);
  (c) keep 262 144 and document the boundary in CHANGELOG with upgrade notes.
- Automated check that could have caught this: none locally (E2E only).
- Status: **open**

### F2 — `bloomSize` read but never validated
- `src/Client/OsirisChunkParser.php:101` (field read) with `:133` (slice at exactly 48)
- What is wrong: the chunk header carries `bloomSize` (uint8, aka FilterSize) and the
  parser reads it only to discard it. Verified in `osiris_log.erl` that for user-data
  chunks the broker skips the bloom bytes on the wire
  (`select_amount_to_send(user_data, ?CHNK_USER, _) -> {FilterSize, DataSize}`), so
  entries genuinely start at offset 48 today (E2E passes on real chunks). But if a
  future broker version transmits nonzero bloom data for user chunks (or a different
  chunk selector reaches this parser), the parser silently misparses entries and
  offsets with garbage instead of failing loud. A one-line guard
  (`bloomSize !== 0` → `DeserializationException`) makes the 48-byte assumption
  explicit and future-proof. No memory impact either way (slice length is
  `dataLength`).
- Suggested fix: reject nonzero `bloomSize` in the header (or skip
  `bloomSize` bytes and slice at `48 + bloomSize`) with a test.
- Automated check: none (broker-behavior dependent); unit test with a crafted header.
- Status: **open**

### F3 — In-loop ceiling memory bound is documented but not pinned by a test
- `tests/Client/OsirisChunkParserTest.php:429-455` (`testAmplificationPayloadRejectedWithBoundedMemory`)
- What is wrong: T9 covers only the up-front rejection path (header `numRecords` >
  cap, +0.0 MB). The parser's own phpdoc promises "~30 MB" for the worst-case path
  (header under-declares, in-loop ceiling fires) — I measured +28 MB — but no test
  pins it. If a future change moves or weakens the in-loop guard, nothing fails.
- Suggested fix: add a test with header `numRecords = 0` and 30 × 65 535-record
  sub-batches asserting `< 64 MB` (verified safe: ~28 MB actual, ~2x margin) and a
  `DeserializationException`.
- Automated check: none (memory assertion is inherently a unit test).
- Status: **open**

### N1 — Misleading test name: "are not parsed" but the test asserts a throw
- `tests/Client/OsirisChunkParserTest.php:310-331` (`testEntriesOutsideDeclaredDataSectionAreNotParsed`)
- What is wrong: the chunk is malformed (declares 2 entries, data section holds 1),
  so the expected and pinned behavior is a `DeserializationException` on the second
  entry — the entry is indeed not parsed, but the parse fails rather than skipping.
  The doc comment explains this, so it is only a naming nit; the pinned behavior is
  correct and desirable. Optionally rename to
  `testEntryBeyondDeclaredDataSectionThrows`/`testExtraEntryOutsideDataSectionThrows`.
- Status: **open**

### N2 — `uncompressedSize` read but never checked
- `src/Client/OsirisChunkParser.php:164`
- What is wrong: for codec 0 `uncompressedSize` must equal `compressedSize`; the
  parser reads the field and discards it. No security impact (inner parsing is
  bounded by the `compressedSize` sub-buffer), but a corrupt header (e.g.
  uncompressedSize huge) silently slips through. Failing loud would match the
  strictness of the new checks; alternatively drop the field read. Nit; no test
  required if deliberately by-design (the comment says "read but not needed").
- Status: **open**

### N3 — Variable shadowing: header `$numRecords` reassigned in the sub-batch branch
- `src/Client/OsirisChunkParser.php:94` vs `:163`
- What is wrong: the header's `$numRecords` (used by the up-front cap check) is
  overwritten by each sub-batch's per-entry record count in the loop. Correct in
  behavior (the header value is consumed before the loop; the inner value is
  re-read per iteration) and PHPStan level 9 is happy, but the reuse is confusing to
  readers — the two values have different semantics. Rename the inner one
  (e.g. `$subBatchRecords`).
- Status: **open**

### N4 — Legacy tests assert the loose `\RuntimeException` where the specific class now applies
- `tests/Client/OsirisChunkParserTest.php:93,103,112,123,167,187,206` (legacy tests)
- What is wrong: the seven pre-existing tests `expectException(\RuntimeException::class)`
  — they pass only because `DeserializationException` is a `\RuntimeException`
  subclass. They would also pass if the parser regressed to throwing an unrelated
  `RuntimeException`. Tighter: `DeserializationException::class` (as the 9 new tests
  already do). Optional consistency cleanup; no behavior change.
- Status: **open**

---

## Coder-finding sanity checks (round 1 — input for the main session, not new findings)

These are assertions about `findings-coder.md` candidates; status = verdict, not open/fixed.

### C1 — Consumer buffers an entire chunk's messages (coder finding #1)
- `src/Client/Consumer.php:51-53` — **REAL, reachable, worth a follow-up issue (medium).**
  `array_push($this->buffer, ...$messages)` pushes all decoded messages of a chunk
  regardless of `maxBufferSize` (which only throttles credits). After #399 the
  worst case is bounded (~262 K entries + ~262 K decoded `Message` objects,
  ~30-200 MB transient) but still ignores the buffer cap. The suggested push-to-free-
  capacity fix is sound. Recommendation: file as a separate `performance`/`security`
  issue (the parser fix is not complete protection for the consumer path).
- Status: verdict only (not an open finding against this PR).

### C2 — ReadBuffer bounded view / substr copy (coder finding #2)
- `src/Buffer/ReadBuffer.php` used at `src/Client/OsirisChunkParser.php:133` —
  **REAL, low.** A partial `substr` is a copy; up to ~8 MB transient per parse,
  reclaimed per parse, bounded by the frame limit. Enhancement candidate (window API
  on ReadBuffer), not a defect.
- Status: verdict only.

### C3 — Truncated tests now fail at the size check (coder finding #3)
- `tests/Client/OsirisChunkParserTest.php:183-223` — **CONFIRMED empirically.** Both
  truncated tests throw from the new "Chunk size mismatch" check before reaching the
  ReadBuffer underflow. Correct characterization; no action.
- Status: verdict only.

### C4 — Plan doc header drift (coder finding #4)
- `docs/plans/iterations/04-osiris-chunk-parser.md:113-124` — **CONFIRMED REAL.**
  Verified against `osiris_log.erl parse_header` and current PROTOCOL.adoc: header is
  48 bytes (not 52), last fields are `FilterSize: uint8` + `Reserved: uint24` (not
  `reserved uint32`), magic is high-nibble `0x5` (not `0x00`). Docs-only fix or
  "superseded" note. Worth a small `documentation` issue or a sweep commit.
- Status: verdict only.

### C5 — DeliverResponseV1 rest-of-frame chunk (coder finding #5)
- `src/Response/DeliverResponseV1.php:59` — **factual, by-design.** `getRemainingBytes()`
  swallows trailing bytes; the parser's `>` check tolerates them. Forward-compat note
  only; no action.
- Status: verdict only.

---

## Coder answers (round 1)

Per-finding resolution from the coder (round 2 of the workflow). Nothing above is
deleted; statuses below supersede the "open" markers from round 1.

### F1 — Default ceiling headroom — FIXED (documented decision + configurability test; no E2E stress, deliberately)

- **Resolution: fixed by decision (option (c) + (b) combined).** Per the task
  instruction, no E2E stress test is added: this is a unit-level parser change and
  a ~300k-message broker stress fixture is disproportionate for this PR. Instead:
  1. The decision is documented in
     `src/Client/OsirisChunkParser.php:47-62` (`DEFAULT_MAX_ENTRIES_PER_CHUNK`
     docblock): the server does not enforce `frame_max` on Deliver frames; the
     broker's chunk flush batching is the practical cap; 262 144 is the theoretical
     record maximum of a 1 MiB data section at the 4-byte minimum per-entry cost;
     real AMQP records cost ≥ ~6 bytes including the prefix, so reaching the cap
     needs a >1.5 MiB chunk of near-empty records — a workload no current broker
     produces; exceeding the cap means the chunk is hostile or pathological and
     failing loud is intended. The `parse()` `@param` docblock (`:65-70`) points to
     the configurability for workloads that genuinely need more.
  2. New test `testCustomMaxEntriesPerChunkAboveDefaultParsesLargerChunk`
     (`tests/Client/OsirisChunkParserTest.php`): a 280 000-record chunk is rejected
     at the default cap and parses cleanly with `maxEntriesPerChunk: 300000`,
     proving operators can raise the ceiling per call.
  3. Upgrade note for CHANGELOG is handed to the main session (step 8 of the
     workflow runs at PR time; coder does not touch CHANGELOG).
- Status: **fixed** (documented decision; configurable; no E2E by explicit task
  instruction).

### F2 — `bloomSize` read but never validated — NOT fixed as suggested; the finding's premise is refuted by broker sources (evidence below); the validation that IS correct was already in place

- **Resolution: deliberately not fixed in the suggested form; the correct form is
  "read and discard, with documentation".** Both reviewer options are wrong for the
  actual wire format, verified against RabbitMQ sources on current main:
  - **Reject `bloomSize !== 0` would reject legitimate chunks.** `osiris_bloom.erl`
    `to_binary/1` returns `<<>>` only for empty or only-unfiltered filters
    (BloomSize field = 0); any chunk containing messages with filter values (any
    stream where another client publishes filtered messages) gets a 16–255-byte
    bloom and a nonzero `BloomSize` field.
  - **Skip `bloomSize` bytes would misparse every such chunk.** The deliver path
    never transmits the bloom bytes: `osiris_log.erl` `select_amount_to_send(
    user_data, ?CHNK_USER, FilterSize, DataSize, _) -> {FilterSize, DataSize}`
    and `send_file/...` starts the sendfile at `Pos + ?HEADER_SIZE_B + ToSkip`,
    i.e. the bytes are skipped on the wire while the field value stays in the
    header.
  - So entries genuinely start at byte 48 even when `BloomSize > 0`, and the
    round-1 behavior (field read and discarded) is wire-correct. What round 2 adds:
    the class docblock (`OsirisChunkParser.php:28-35`), the reading comments
    (`:114-115`) and the size-check comment (`:130-134`) now document exactly why
    the field is informational (deliver = header + data only; on-disk sizes
    declared in the header are not transmitted).
  - Same wire evidence applies to `trailerLength`, which had ALREADY been included
    in the round-1 fit check — that was a **latent round-1 bug** (see findings-coder
    addition A1): a streaming `StoreOffsetRequestV1`/named-producer tracking delta
    makes user chunks carry a nonzero trailer field whose bytes are absent on the
    wire, so `header + data + trailer <= received` would have rejected legitimate
    chunks. Round 2 removes `trailerLength` from the fit check
    (`OsirisChunkParser.php:130-144`), keeps the data-section-only check
    `header + dataLength <= received`, and replaces the round-1 trailer test with
    `testNonzeroTrailerLengthWithoutTrailerBytesParses`.
- Status: **not a real finding as suggested** (both suggested fixes would break
  legitimate chunks; evidence above); **real latent bug found next to it and fixed**
  (trailerLength in the round-1 fit check).

### F3 — In-loop ceiling memory bound not pinned by a test — FIXED

- **Resolution: fixed.** New test `testInLoopCeilingKeepsMemoryBounded`
  (`tests/Client/OsirisChunkParserTest.php`): header `numRecords` patched to 0 with
  5 × 65 535-record sub-batches (327 675 records) so the up-front check cannot fire;
  asserts `DeserializationException` with the in-loop message ("maximum allowed per
  chunk") and a `memory_get_usage(true)` delta < 64 MB (the reviewer measured ~28 MB
  for this exact path; the same 64 MB threshold and baseline-before-parse pattern as
  the round-1 T9 test, robust for CI).
- Status: **fixed**

### N1 — Misleading test name — FIXED

- **Resolution: fixed.** `testEntriesOutsideDeclaredDataSectionAreNotParsed` renamed
  to `testEntryBeyondDeclaredDataSectionThrows`; the doc comment now states the
  pinned behavior (bounded sub-buffer must throw when an entry sits beyond the
  declared data section).
- Status: **fixed**

### N2 — `uncompressedSize` read but never checked — FIXED (validated with capacity check; equality deliberately not enforced — evidence)

- **Resolution: fixed with a capacity check, deliberately without equality.** The
  field is now captured (`OsirisChunkParser.php:179`) and checked:
  `subBatchRecords * 4 > uncompressedSize` → `DeserializationException`
  (`:197-204`), mirroring the broker's own publish-time validation
  (`rabbit_stream_utils.erl` `check_message_count_fits_uncompressed_size`, current
  main). **Equality with `compressedSize` is deliberately NOT enforced** because the
  broker does not enforce it either: `validate_compressed_sub_batch/5` in
  `rabbit_stream_utils.erl` (current main) only upper-bounds `UncompressedSize`
  (`max_uncompressed_sub_entry_batch_size`), requires `MessageCount * 4 <=
  UncompressedSize`, and rejects empty batches only when compressed; codec-0
  sub-batches with `UncompressedSize != BatchSize` are accepted, stored
  (`process_entry0` writes the two lengths independently in `osiris_log.erl`) and
  delivered. Enforcing equality would reject legitimate broker data. This rationale
  is documented in the parser comment (`:181-186`); the compressedSize check
  remains the security-relevant bound (bytes actually present). New test
  `testSubBatchUncompressedSizeCannotHoldRecordsThrowsException`.
- Status: **fixed** (capacity validation + documented non-equality)

### N3 — Variable shadowing — FIXED

- **Resolution: fixed.** Inner `$numRecords` renamed to `$subBatchRecords`
  (`OsirisChunkParser.php:178,189,197,209`); the header `$numRecords`
  (`:108`) is no longer reassigned.
- Status: **fixed**

### N4 — Loose `\RuntimeException` in legacy tests — FIXED

- **Resolution: fixed.** All 7 legacy expectations in
  `tests/Client/OsirisChunkParserTest.php` changed from
  `expectException(\RuntimeException::class)` to
  `expectException(DeserializationException::class)`; the suite passes (the custom
  exception extends `RabbitStreamException` extends `\RuntimeException`, so the
  tightened assertions are still satisfied by every error path the parser throws).
- Status: **fixed**
