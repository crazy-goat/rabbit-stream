# Review round 2 — issue #399 (OsirisChunkParser dataLength guard, memory amplification)

**Branch:** `feature/issue-399-osiris-datalength-guard`
**Reviewer:** review-critical
**Date:** 2026-08-19
**Scope:** round-2 commit `384d37b` (`src/Client/OsirisChunkParser.php`,
`tests/Client/OsirisChunkParserTest.php`, proof-of-work files)
**E2E:** not run (per instructions)

---

## 1. Overall verdict

**APPROVE WITH NITS** — the round-2 changes are correct and materially improved
the fix. The coder discovered a **real latent regression in the round-1 code**
(the `trailerLength` fit check) by re-verifying the wire contract against broker
sources, fixed it, and pinned it with a mutation-proof test. All three broker-side
evidence claims (trailer omission, bloom-size semantics, uncompressedSize
validation) were independently re-verified by me against RabbitMQ/osiris sources
on current main and are accurate. Round-1 findings: F1 fixed by documented
decision, F2 refuted with correct evidence (not a real finding as suggested), F3
and N1–N4 fixed. Two residual nits: one new doc mislabel (N5), one process
carry-over (F1's CHANGELOG note must land at step 8).

## 2. Gate results (run locally on this branch)

| Gate | Result |
|---|---|
| `composer cs` (PHPCS PSR-12) | PASS |
| `composer phpstan` (level 9) | PASS — no errors |
| `composer rector` (dry-run) | PASS — no changes proposed |
| `./vendor/bin/phpunit --testsuite unit` | PASS — 863 tests, 2837 assertions, 1 risky (pre-existing `StreamConnectionTest.php:569`, untouched) |
| `./vendor/bin/phpunit tests/Client/OsirisChunkParserTest.php` | PASS — 25 tests, 1088 assertions |

## 3. Status per round-1 finding (evidence-based)

### F1 — default cap headroom — **FIXED (by documented decision)**
The docblock of `DEFAULT_MAX_ENTRIES_PER_CHUNK` (`OsirisChunkParser.php:47-62`)
now carries an accurate rationale, and the new configurability test pins it.
Verified the docblock's factual claims independently:
- "the server does not enforce `frame_max` on Deliver frames" — confirmed:
  `rabbit_stream_reader.erl` `send_chunks`/`send_file_callback` write header +
  payload as raw socket writes with no frame_max check.
- "262 144 is the theoretical record maximum of a 1 MiB data section at 4 B/record"
  — confirmed: `1 MiB / 4 = 262 144`, and a sub-batch inner record costs a uint32
  size prefix minimum.
- "real AMQP records cost at least ~6 bytes" — reasonable (4-byte prefix + ≥ 2-byte
  AMQP section), so reaching the cap needs a >1.5 MiB chunk — no current broker
  workload produces that.
- `testCustomMaxEntriesPerChunkAboveDefaultParsesLargerChunk` (280 000 records:
  rejected at default 262 144, parses cleanly with `maxEntriesPerChunk: 300000`)
  verifies the operator knob. Mutation check: passes on round-1 code too (the
  parameter existed since round 1) — it is a decision-pinning test, which is what
  F1 asked for.
- **Carry-over for the main session:** the CHANGELOG upgrade note (recommended
  `[Unreleased] > Changed`) must land at workflow step 8 — it is not in this
  branch. The accepted-risk boundary (a >1.5 MiB near-empty-record chunk from a
  non-conforming or future broker fails loud) is now documented.

### F2 — bloomSize unvalidated — **NOT A REAL FINDING AS SUGGESTED (evidence verified); the real defect under it was found and fixed**
Both suggested fixes (reject nonzero, skip bytes) are disproven by broker sources
I re-verified on current main:
- `osiris_bloom.erl` `to_binary/1` returns `<<>>` only for empty/only-unfiltered
  filter sets; any stream carrying messages with filter values produces a
  16–255-byte bloom. A nonzero `bloomSize` header field is therefore legitimate
  on real chunks.
- `osiris_log.erl` `select_amount_to_send(user_data, ?CHNK_USER, FilterSize,
  DataSize, _TrailerSize) -> {FilterSize, DataSize}` and `send_file/...` starting
  the sendfile at `Pos + ?HEADER_SIZE_B + ToSkip`: the bloom bytes are **skipped
  on the wire** for user-data chunks while the header field stays nonzero —
  entries genuinely start at byte 48 always.
- Reachability of the only other selector: `get_chunk_selector/1` defaults to
  `user_data` and accepts only `"all"`; this client never sends the
  `chunk_selector` property (verified: no reference in `src/`), so header + data
  is the only wire shape this parser can receive.
- Consequence: read-and-discard with documentation (added in the class docblock
  `OsirisChunkParser.php:28-35` and at the read sites `:114-115`) is the correct
  behavior.

**And the important part:** while disproving F2 the coder found that its
underlying instinct (wire ≠ on-disk) exposed a genuinely broken check from round
1 — **A1/fix, verified real and fixed** (see §5): `osiris_writer.erl
handle_batch:293` writes user chunks with tracking-delta trailers
(`osiris_log:write(Entries, ?CHNK_USER, Now, TrkData, Log1)`), so a chunk's
header can declare a nonzero `trailerLength` with those bytes absent from the
Deliver frame; round-1's `header + dataLength + trailerLength <= received`
check would have thrown `DeserializationException` on legitimate chunks from
exactly the documented workflows (named consumers, auto-commit, store-offset).
The round-2 data-section-only check `headerSize + dataLength > chunkSize`
(`:130-144`) is the correct wire contract, security properties unchanged
(entries still bounded by the declared data section; `dataLength` still bounded
by received bytes; trailerLength never sizes or bounds anything).

### F3 — in-loop ceiling memory bound — **FIXED**
`testInLoopCeilingKeepsMemoryBounded` patches header `numRecords` to 0 with
5 × 65 535-record sub-batches (327 675 records; data section 1 310 755 B), so the
up-front check cannot fire; asserts the in-loop message ("maximum allowed per
chunk") and a `memory_get_usage(true)` delta < 64 MB with the baseline taken
before the parse (CI-robust: same pattern as T9; ~28 MB actual vs 64 MB
threshold, ~2x margin). Mutation-verified: **fails** (via `$this->fail`) against
pre-round-1 code without the in-loop guard; passes against both round-1 and HEAD.

### N1 — test name — **FIXED** (`testEntryBeyondDeclaredDataSectionThrows`, comment rewritten)
### N2 — uncompressedSize — **FIXED** (capacity check, deliberate non-equality; see §4)
### N3 — variable shadowing — **FIXED** (`$subBatchRecords`, `:178,189,197,209`)
### N4 — loose `\RuntimeException` — **FIXED** (all 7 legacy expectations now
`DeserializationException::class` — verified at `OsirisChunkParserTest.php:93,103,113,123,167,187,206`)

## 4. New-finding checks (priority probes)

1. **Trailer/bloom fix against the wire contract — CORRECT.** Fit check is
   `headerSize(48) + dataLength <= strlen(chunkBytes)`; entries parse from
   `substr($chunkBytes, 48, $dataLength)`. For the user_data selector the wire
   chunk is exactly 48 + dataLength, so every legitimate shape passes; if a
   future broker appends fields, the `<=` tolerance ignores them without letting
   entries bleed anywhere (sub-buffer underflow throws). `dataLength =
   0xFFFFFFFF` still rejected up front (`48 + N > received`). Attack surface vs
   round 1: unchanged in the security-relevant direction — entries bounded by
   declared dataLength, counts capped, no integer arithmetic reachable
   (`numEntries` uint16, `subBatchRecords` uint16, `entryCount` ≤ cap).
   `testNonzeroTrailerLengthWithoutTrailerBytesParses` pins the contract:
   **mutation-verified to ERROR against the round-1 parser** (the round-1 check
   throws instead of parsing), i.e. the test genuinely protects the fix.
   Header size math re-checked: 1+1+2+4+8+8+8+4+4+4+1+3 = 48 bytes, matches
   `osiris_log.erl parse_header` and PROTOCOL.adoc; offsets in the new tests
   (dataLength at 36, trailerLength at 40, header numRecords at 4, sub-batch
   numRecords at 49, uncompressedSize at 51) are all correct.

2. **New tests' mutation sensitivity / CI robustness.** Both new memory/behavior
   tests genuinely discriminate (see §3 F1/F3 and mutation table below);
   `testSubBatchUncompressedSizeCannotHoldRecordsThrowsException` **fails on the
   round-1 parser** (no exception thrown without the check). The delta-based
   memory assertions keep the robust round-1 pattern (baseline before parse,
   payload built before baseline, 64 MB threshold with ~2x margin on the
   measured ~28 MB — measured value far from allocator noise).

3. **uncompressedSize check direction/semantics — CORRECT, mirrors the broker.**
   `subBatchRecords * 4 > uncompressedSize` → throw: same direction and threshold
   as the broker's publish-time `check_message_count_fits_uncompressed_size`
   (`MessageCount * 4 > UncompressedSize` → error; "every sub-entry carries at
   least its own 4-byte length prefix" — same comment the parser now carries).
   No overflow: `subBatchRecords ≤ 65 535`, product ≤ 262 140. Equality with
   `compressedSize` deliberately not enforced — verified correct: the broker's
   `validate_compressed_sub_batch/5` never compares `UncompressedSize` to
   `BatchSize` (only upper-bounds uncompressed size, requires
   `MessageCount * 4 <= UncompressedSize`, rejects empty batches only when
   compressed), so a codec-0 sub-batch with unequal sizes is storable and
   deliverable by a compliant broker; enforcing equality would reject legitimate
   data. The `compressedSize` check (bytes actually present, the
   security-relevant bound) remains and runs first.

4. **Gates:** all green (see table).

5. **New defects from the round-2 edits:** none in behavior. One doc mislabel —
   N5: the class docblock's header field list (`OsirisChunkParser.php:24-26`)
   still omits `bloomSize`: it lists "1 byte - reserved" (in fact the
   `bloomSize`/FilterSize byte, offset 44) and "3 bytes - padding (alignment to
   4 bytes)" (in fact the `reserved` uint24, offsets 45-47). Both neighboring
   code comments are correct; total byte count (48) is right. Doc-only.

## 5. Mutation-test table (run against round-1 parser `3839b11`, then restored)

| Test | vs round-1 parser | vs HEAD |
|---|---|---|
| `testNonzeroTrailerLengthWithoutTrailerBytesParses` | **ERROR** (round-1 fit check threw "Chunk size mismatch") — proves the round-1 regression was real and is pinned | PASS |
| `testSubBatchUncompressedSizeCannotHoldRecordsThrowsException` | **FAILURE** (parsed 5 records, no exception) — proves the new check is exercised | PASS |
| `testCustomMaxEntriesPerChunkAboveDefaultParsesLargerChunk` | PASS (pins round-1 configurability) | PASS |
| `testInLoopCeilingKeepsMemoryBounded` | PASS (pins round-1 guard) | PASS |
| `testTrailingBytesAfterDeclaredSectionsAreIgnored` | PASS (regression guard) | PASS |

Working tree restored byte-identical after the experiment (`git status` clean).

## 6. Coder addenda sanity checks (new this round)

- **A1 (round-1 trailerLength regression) — REAL, verified at source, fixed.**
  Evidence re-checked: `osiris_writer.erl handle_batch:293`; `select_amount_to_send`
  `{FilterSize, DataSize}` for user chunks; `send_file` starts at
  `Pos + ?HEADER_SIZE_B + ToSkip`; `send_file_callback` wraps `HeaderData +
  DataSize`; `get_chunk_selector/1` defaults to `user_data`. The stated trigger
  workflows (StoreOffset/auto-commit, named producers, SAC) match. This is a
  textbook escaped-round-1 defect — the replacement test is the right response.
  **Recommend keeping a KB-worthy lesson (proposed for the main session): when
  reviewing a wire-format parser, verify the transmit path, not just the on-disk
  layout — on-disk and wire can differ in the exact fields under review.**
- **A2 (broker validation weaker than the client's) — verified.** The broker
  never checks `MessageCount * 4 <= BatchSize` and never enforces
  `UncompressedSize == BatchSize`, so the client's `compressedSize` check is
  deliberately stricter on bytes-present — correct security-by-construction;
  the stricter check cannot reject genuinely consistent data (a real batch
  always has body ≥ 4 B per record).
- Round-1 candidates C1 (Consumer chunk buffering — still the top follow-up
  issue candidate), C4 (plan doc drift — still unfixed, docs-only, confirmed
  real in round 1) remain for step 14.

## 7. Residual items

1. **N5 (nit, new):** docblock field mislabel for bloomSize/reserved
   (`OsirisChunkParser.php:24-26`). One-line doc fix (swap the two labels,
   mention bloomSize) or accept as-is.
2. **Process (not a code finding):** F1's CHANGELOG upgrade note must be added
   by the main session (step 8) — the branch carries the docblock rationale, not
   the changelog entry.
3. **Accepted risk (documented):** the default cap boundary — a non-conforming
   or future broker delivering > 262 144 records per chunk fails loud; operators
   can raise the cap per call. Boundary verified empirically in round 1:
   262 144 parses, 262 145 throws.
4. **Residual theoretical note (documented now, unreachable today):** a broker
   configured with `chunk_selector=all`/`data` would transmit bloom+trailer
   bytes — this client never requests that selector, and the parser rejects
   non-user chunk types anyway.

## 8. Verdict for the main session

**APPROVE WITH NITS**

Round-1 findings: F1 fixed by documented decision (config test added; CHANGELOG
note pending at step 8), F2 refuted with verified evidence and **the real latent
bug it concealed (round-1 trailerLength check) found, fixed and pinned by a
mutation-verified test**, F3 and N1–N4 fixed. New in round 2: one doc-only nit
(N5). All gates green; my independent re-verification of the wire-format claims
against RabbitMQ/osiris current-main sources confirms the coder's analysis on
every point. Nothing blocks merge on correctness grounds; N5 is a one-line doc
sweep that can ride along or be accepted.
