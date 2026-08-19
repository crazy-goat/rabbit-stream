# Review round 1 — issue #399 (OsirisChunkParser dataLength guard, memory amplification)

**Branch:** `feature/issue-399-osiris-datalength-guard`
**Reviewer:** review-critical
**Date:** 2026-08-19
**Scope:** `src/Client/OsirisChunkParser.php`, `tests/Client/OsirisChunkParserTest.php`,
`docs/proof_of_work/399-osiris-datalength-guard/code-decision-1.md`, `.../findings-coder.md`
**E2E:** not run (per instructions — pure unit/parser change)

---

## 1. Overall verdict

**APPROVE WITH NITS** — the security fix is correct, layered, and its central claim
(memory bounded against the amplification payload) is verified empirically and
reproduced exactly. All four acceptance criteria from issue #399 are met. Four of
the five findings in this round concern residual risk and polish, not defects in the
fix; one finding (F1) asks for an E2E scale test or an explicit documented decision
about the default ceiling before merge. No correctness defect was found in the
parser itself.

## 2. Gate results (run locally on this branch)

| Gate | Result |
|---|---|
| `composer cs` (PHPCS PSR-12) | PASS — 242 files, no errors |
| `composer phpstan` (level 9) | PASS — 238 files, 0 errors |
| `composer rector` (dry-run) | PASS — no changes proposed |
| `./vendor/bin/phpunit --testsuite unit` | PASS — 860 tests, 2832 assertions, 1 risky (pre-existing no-assertion test in `tests/StreamConnectionTest.php:569`, untouched by this branch; matches the coder's report) |

## 3. Acceptance criteria — status

1. **`dataLength` validated against the actual chunk size — ✅ MET.**
   `headerSize(48) + dataLength + trailerLength > strlen($chunkBytes)` throws
   `DeserializationException` before any entry work (`OsirisChunkParser.php:117-129`).
   `>` (not `!==`) is deliberately tolerant of trailing bytes; this matches the wire:
   `DeliverResponseV1` hands the parser `getRemainingBytes()` of the frame. Tested by
   `testDataLengthExceedingChunkSizeThrowsException` and
   `testTrailerLengthExceedingChunkSizeThrowsException`, plus a tolerance test
   (`testTrailingBytesAfterDeclaredSectionsAreIgnored`). I verified overflow values
   too: `dataLength = 0xFFFFFFFF`, `trailerLength = 0xFFFFFFFF`, and both together are
   rejected up front with zero allocation (no 32-bit overflow reachable on 64-bit PHP:
   sums stay < 2^34, `substr` length never exceeds the 8 MB frame).

2. **Entry loop bounded by `dataLength`, not by the rest of the buffer — ✅ MET.**
   Parsing continues on `new ReadBuffer(substr($chunkBytes, $headerSize, $dataLength))`
   (`:133`). Bytes outside the declared data section are unreachable: I verified
   empirically that an entry physically present in the trailer section is **not**
   parsed, and that an entry appended after the declared section triggers the
   `ReadBuffer` underflow guard rather than being read. The sub-buffer bound also means
   `readBytes()` can never allocate beyond the declared data.

3. **Implausible entry counts rejected with `DeserializationException` — ✅ MET.**
   Three checks, all throwing the project's custom
   `CrazyGoat\RabbitStream\Exception\DeserializationException` (extends
   `RabbitStreamException` extends `\RuntimeException`, per DEC-002/#242):
   - up-front header check `numRecords > maxEntriesPerChunk` (`:108-114`),
   - per-sub-batch `numRecords * 4 > compressedSize` (`:169-175`),
   - in-loop ceiling at both loop levels (`:140-142`, `:181-183`).
   The per-sub-batch check is structurally exact: each inner record costs ≥ 4 bytes
   wire (uint32 size prefix + data), matching the real Osiris format
   (`<<Size:32, Data/binary>>`). Verified at the boundary: a sub-batch at exactly
   4 bytes/record parses; 5 records in 4 bytes throws.

4. **Unit test with the amplification payload asserting bounded memory — ✅ MET**
   (see §5, test T9).

## 4. Security analysis (adversarial probes, run against this branch)

I rebuilt the PoC and boundary payloads and ran them against the parser:

| Probe | Result |
|---|---|
| PoC payload: 7,864,578 B chunk, 30 × 65 535 zero-size records, header declares 1,966,050 records | **Rejected up-front in 0.0023 s, measured +0.0 MB** — matches the coder's claim exactly (0.001 s / 0-byte) |
| Exactly 262,144 records (1 MiB data, 4 B/record) at default cap | Parses OK, +34 MB transient (matches the documented ~30 MB worst case) |
| Same chunk at cap = 262,143 | Rejected up-front, +0.0 MB — off-by-one correct in both directions |
| Header-under-declaration attack: header `numRecords = 0`, sub-batches carry 1.9 M records | In-loop ceiling fires; +28 MB transient, ~0.05 s — **bounded**, no ceiling bypass |
| Same at cap = 100,000 | +0.0 MB — operators can tune down |
| `dataLength = trailerLength = 0xFFFFFFFF` | Rejected before any allocation |
| Zero-length / 5-byte / truncated chunks | `DeserializationException` (ReadBuffer underflow) |
| Simple-entry size claims past the data section | Underflow throw, no allocation past the declared section |
| Entry smuggled inside a nonzero trailer | Not parsed |

**Ceiling bypass analysis (criterion: can the cap be beaten?):** No. Both entry
production paths (simple entries, sub-batch inner entries) increment one shared
`$entryCount`, and the `>= maxEntriesPerChunk` guard runs at the top of **each**
iteration of **both** loops, before anything is allocated. The inner loop cannot
allocate more than `max − entryCount` records; the outer loop cannot fabricate inner
records (`numRecords` is uint16, ≤ 65 535, and each inner record consumes ≥ 4 bytes
of the bounded sub-buffer). No integer overflow is reachable: `entryCount` ≤ cap
(262,144) ≪ PHP_INT_MAX, and `numRecords * 4` ≤ 262,140. The header's `numRecords`
(≤ 2^32−1) is compared, not multiplied. CPU cost is bounded by the outer loop
(≤ 65 535 iterations × O(1)) plus the ceiling. The only residual: with a
header-under-declaring chunk the in-loop path allocates up to the cap (~28-34 MB)
before throwing — bounded, documented in the constant's phpdoc, and tunable via the
new parameter.

**Wire-format verification** (against RabbitMQ sources, current main):
- `osiris.hrl`: `MAGIC = 5` high nibble, `VERSION = 0` — the parser's magic/version
  checks match the format exactly.
- `osiris_log.erl parse_header`: 48-byte header = MagicVersion(8) + ChType(8) +
  NumEntries(16) + NumRecords(32) + Timestamp(64) + Epoch(64) + ChunkFirstOffset(64) +
  Crc(32) + DataSize(32) + TrailerSize(32) + FilterSize(8) + Reserved(24). The parser
  layout is byte-exact; `headerSize(48) + dataLength + trailerLength` is the full
  on-wire chunk size for the reachable path.
- `osiris_log.erl send_file` / `select_amount_to_send(user_data, ?CHNK_USER, ...) ->
  {FilterSize, DataSize}`: for user-data chunks the broker **skips the bloom filter
  bytes on the wire** (sends header + data only), so entries at offset 48 is correct
  for every deliver chunk this client can receive (`chunkType != 0` is rejected).
  This also means F2 (bloomSize) is latent risk, not a live bug.
- `rabbit_stream_reader.erl send_file_callback`: Deliver frame =
  Size:32 + Key:16 + Version:16 + SubId:8 + Chunk — matches `DeliverResponseV1`
  exactly. The server does **not** enforce `frame_max` on the Deliver send path
  (header + payload are two raw socket writes), so the client's 8 MB frame cap is the
  real outer bound on chunk size — relevant to F1.
- `numRecords` semantics: `parse_header` feeds `NumRecords` straight into the
  consumer offset bookkeeping (`C_OFFSET` counter), i.e. it is the exact total record
  count — the up-front cap check is semantically sound against compliant brokers.

**Regression check for legit 8 MB chunks:** the declared-sections-must-fit check and
the sub-buffer slicing are transparent for any chunk that is exactly
48 + dataLength + trailerLength (the norm on the wire — verified in
`osiris_log.erl`: `NextPos = Pos + ?HEADER_SIZE_B + FilterSize + DataSize +
TrailerSize`). E2E fixtures (1-3 messages, real broker chunks) exercise the parser
path through `Consumer`/`DeliverResponseV1` and stay orders of magnitude below the
cap. The one genuine residual-risk question is the **default cap** itself — see
finding F1.

## 5. Test suite strength

22 test methods in the file (13 pre-existing + 9 new — the coder's count is exact).
The 9 new tests:

- **T1** `testDataLengthExceedingChunkSizeThrowsException` — criterion 1; patches
  `dataLength` at the correct header offset (36), expects the specific exception class
  and message.
- **T2** `testTrailerLengthExceedingChunkSizeThrowsException` — criterion 1, trailer
  offset 40.
- **T3** `testEntriesOutsideDeclaredDataSectionAreNotParsed` — criterion 2; bumps
  `numEntries` and appends an entry after the declared section; asserts the parse
  throws rather than reading it.
- **T4** `testTrailingBytesAfterDeclaredSectionsAreIgnored` — pins the `>` tolerance.
- **T5** `testSubBatchDeclaringMoreRecordsThanDataThrowsException` — criterion 3,
  per-sub-batch; patches the sub-batch `numRecords` at byte 49 (48-byte header + 1
  entry-type byte — correct).
- **T6** `testMaxEntriesPerChunkGuardThrowsException` — exercises the **in-loop**
  ceiling (header passes at 5, sub-batch carries 10).
- **T7** `testMaxEntriesPerChunkExactlyAtLimitParses` — off-by-one, at-limit passes.
- **T8** `testMaxEntriesPerChunkBelowOneThrowsException` — parameter validation,
  project's `InvalidArgumentException` (matches Consumer's precedent).
- **T9** `testAmplificationPayloadRejectedWithBoundedMemory` — criterion 4. Assessed
  for flakiness: **robust, not trivially passable, not CI-fragile**.
  - The chunk is built *before* the `memory_get_usage(true)` baseline, so construction
    cost is excluded; the delta measures only the parse.
  - 64 MB threshold sits with ~2 orders of magnitude of headroom to the actual ~0 MB
    (fixed) and ~3x margin below the ~200 MB regression signal (unfixed). An
    environment-induced drift of a few MB cannot flip either side.
  - Cannot pass trivially: if the parse succeeds, `$this->fail()` fires; if it throws
    a non-`DeserializationException`, the test errors.
  - The `< 8 MB` length assertion pins that the payload is legal-sized (under the
    client's frame cap), so the test guards the actual attack surface.
  - Only gap: it covers the **up-front** rejection path (header `numRecords` > cap),
    not the in-loop path (header under-declares) — see F3. I verified the in-loop path
    independently (+28 MB against the same 64 MB threshold; it would pass, with ~2x
    margin — safe to add).

Legacy tests (`expectException(\RuntimeException::class)`) still pass because
`DeserializationException` is a `\RuntimeException` subclass; the new tests use the
precise class (see nit N4).

## 6. Findings — summary table

| # | Severity | Verdict |
|---|---|---|
| F1 | medium | Default cap has ~zero headroom over a theoretical compliant 1 MiB chunk; hard-fail mode; no scale E2E |
| F2 | low | `bloomSize` read but never validated (fail-loud guard missing) |
| F3 | low | In-loop ceiling memory bound documented but not pinned by a test |
| N1 | nit | `testEntriesOutsideDeclaredDataSectionAreNotParsed` name says "not parsed" but asserts a throw |
| N2 | nit | `uncompressedSize` read but not checked against `compressedSize` for codec 0 |
| N3 | nit | variable shadowing: header `$numRecords` reassigned in the sub-batch branch |
| N4 | nit | legacy tests assert the loose `\RuntimeException` where the specific class now applies |

Full detail (file:line, evidence, status = open) in `findings-review.md`.

## 7. Coder findings sanity-check (input for step 14)

1. **Consumer buffers a whole chunk at once** (`src/Client/Consumer.php:51-53`) —
   **REAL, worth an issue (medium).** `array_push($this->buffer, ...$messages)` after
   `AmqpMessageDecoder::decodeAll()` can transiently hold up to the new 262,144
   ceiling of `ChunkEntry` + decoded `Message` objects regardless of `maxBufferSize`
   (which only gates credits). Reachable: any legal chunk near the ceiling. Suggested
   fix (cap the push to free buffer capacity) is sound. The parser fix makes this
   bounded at ~30-200 MB worst case instead of unbounded — a follow-up issue, not a
   blocker, but the closest thing to the residual amplification path after #399.
2. **`ReadBuffer` bounded view / `substr` copy** — **REAL, low.** `substr($chunkBytes,
   48, $dataLength)` copies up to ~8 MB per parse (partial slices are copied, not
   shared). Bounded by the frame limit and reclaimed per parse; a window API on
   `ReadBuffer` would remove the transient. Reasonable enhancement issue.
3. **Truncated tests now fail at the size check** — **CONFIRMED empirically.** Both
   tests throw from "Chunk size mismatch: header + dataLength + trailerLength …"
   before any `ReadBuffer` underflow. Accurate characterization, no action.
4. **Plan doc header drift** (`docs/plans/iterations/04-osiris-chunk-parser.md:113-124`)
   — **CONFIRMED REAL** against `osiris_log.erl parse_header` and current
   PROTOCOL.adoc: the header is 48 bytes (not 52), the last field is `FilterSize:
   uint8` + `Reserved: uint24` (not `reserved uint32`), and magic is the high nibble
   `0x5` (not `0x00`). Docs-only; worth a small fix or a "superseded" note.
5. **`DeliverResponseV1` rest-of-frame chunk** — factual; tolerable by design (`>`
   check); forward-compat note only.

## 8. What I verified empirically (reproducible evidence)

- Amplification payload → rejected in 0.0023 s with +0.0 MB (`memory_get_usage(true)
  delta`), vs 202 MB / 1,966,050 objects without the fix (coder's number reproduced
  in this codebase).
- Boundary cases: 262,144 records at cap 262,144 → OK; at 262,143 → rejected;
  per-sub-batch at exactly 4 B/record → OK.
- Ceiling-bypass attempts (header `numRecords` lying low; entries hidden in the
  trailer; `0xFFFFFFFF` size fields; zero-length/truncated chunks) all fail bounded,
  before significant allocation, with `DeserializationException`.
- Wire layout claims above verified against RabbitMQ `osiris_log.erl`,
  `rabbit_stream_reader.erl`, `osiris.hrl` and PROTOCOL.adoc (current main).

## 9. Verdict for the main session

**APPROVE WITH NITS**

All four acceptance criteria are met; `composer cs` / `phpstan` (level 9) / `rector`
dry-run / unit suite are green; the security property is proven both by tests and by
my independent probes. Before merge, answer F1 (recommended: add the scale E2E
stress test — publish ~300k empty messages and consume them, or accept the
theoretical boundary as documented risk in CHANGELOG) and either fix or explicitly
decline the nits. Nothing in this round blocks merging on correctness grounds.
