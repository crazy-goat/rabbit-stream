# Review round 3 — issue #399 (OsirisChunkParser dataLength guard, memory amplification) — convergence round

**Branch:** `feature/issue-399-osiris-datalength-guard`
**Reviewer:** review-critical
**Date:** 2026-08-19
**Scope:** commit `0214936` (round-2→round-3 delta) + convergence check of the whole fix
**E2E:** not run (per instructions)

---

## 1. Overall verdict

**APPROVE (clean) — no open findings remain.**

The round-2→round-3 delta contains exactly the N5 fix (two docblock lines in
`src/Client/OsirisChunkParser.php`) plus the committed round-2 review records. No
code logic changed since the round-2 state, which was fully verified against
broker sources and mutation tests. All four acceptance criteria of issue #399
remain met; all findings from rounds 1 and 2 are fixed or resolved; the final
fresh-eyes pass found nothing new.

## 2. Per-finding status list (round-3 re-verification)

| Finding | Round-3 status | Evidence (this round) |
|---|---|---|
| F1 — default cap headroom (medium) | **fixed** (documented decision) | Docblock rationale unchanged and still accurate; config test present; CHANGELOG note remains a step-8 carry-over for the main session, not an open code finding |
| F2 — bloomSize unvalidated (low) | **not a real finding as suggested** (evidence verified) | Wire-contract documentation in the class docblock (`:28-35`) and at read sites (`:114-115`); the real latent bug it concealed (round-1 `trailerLength` fit check) was fixed in `384d37b` and stays fixed |
| F3 — in-loop ceiling memory untested (low) | **fixed** | `testInLoopCeilingKeepsMemoryBounded` present and passing (#863 suite green) |
| N1 — test name (nit) | **fixed** | `testEntryBeyondDeclaredDataSectionThrows` with rewritten comment |
| N2 — uncompressedSize unchecked (nit) | **fixed** | Capacity check mirroring broker's `check_message_count_fits_uncompressed_size`; deliberate non-equality documented and broker-verified |
| N3 — variable shadowing (nit) | **fixed** | `$subBatchRecords` at `:178,189,197,209` |
| N4 — loose `\RuntimeException` (nit) | **fixed** | 7 expectations tightened to `DeserializationException::class` |
| N5 — docblock bloomSize/reserved mislabel (nit, round 2) | **fixed** — verified in the file, not just in the annotation | `OsirisChunkParser.php:24-26` now reads: `4 bytes - trailerLength … (bytes are omitted from Deliver frames)`, `1 byte - bloomSize: on-disk size of the bloom filter section (bytes are omitted from Deliver frames)`, `3 bytes - reserved (alignment to 4 bytes)`. Matches the read sites (`getUint8() // bloomSize` at `:115`, `readBytes(3) // reserved` at `:116`), the 48-byte wire layout (FilterSize uint8 at offset 44, Reserved uint24 at 45-47 per `osiris_log.erl parse_header`), and the prose at `:28-35`. Total 1+1+2+4+8+8+8+4+4+4+1+3 = 48 ✓ |
| Note — `chunk_selector=all`/`data` wire shape | **closed** (by design, documented) | Unchanged; unreachable from this client |

## 3. Round-2→round-3 delta check

`git diff 384d37b..HEAD` (commit `0214936`):
- `src/Client/OsirisChunkParser.php`: **+2/−2 lines, docblock only** — the N5 fix
  shown verbatim in §2. Zero logic changes; every byte of parser behavior is
  identical to the round-2-reviewed state.
- `docs/proof_of_work/.../review-2.md` (new) and `findings-review.md`
  (annotations): the round-2 record, committed by the main session as required by
  the workflow.

Nothing else. No new code, no new behavior, no new API surface.

## 4. Convergence scan (fresh eyes, whole fix)

Re-checked the complete final state of `OsirisChunkParser.php` and
`OsirisChunkParserTest.php` for anything the two previous rounds could
plausibly have missed:

- **Security properties (unchanged since round-2 verification):** entry parsing
  strictly bounded to the declared data section (`substr($chunkBytes, 48,
  $dataLength)`); `dataLength` validated against received bytes up front
  (`headerSize + dataLength > chunkSize` → throw, `0xFFFFFFFF` values included);
  three-layer record-count defense (up-front header check, per-sub-batch
  `records * 4 > size` for both `compressedSize` and `uncompressedSize`, in-loop
  ceiling at both loop levels); no ceiling bypass possible (shared counter,
  checked before every allocation, no reachable integer overflow: `numEntries`
  and `subBatchRecords` uint16, `entryCount` ≤ 262 144); all failures throw the
  project's `DeserializationException` / `InvalidArgumentException` per the #242
  hierarchy (DEC-002).
- **Wire contract (re-verified in rounds 1–2 against osiris/rabbitmq current
  main):** 48-byte header, magic high-nibble 5, user-chunk Deliver = header +
  data only (bloom and trailer bytes omitted server-side), header `numRecords` =
  exact record total. The parser, its tests, and (now) its docblock all agree.
- **Acceptance criteria of #399 — all four still met:** (1) `dataLength`
  validated vs actual chunk size — tested; (2) entry loop bounded by
  `dataLength` — tested (including the beyond-declared-section throw and
  trailer-omitted wire shape); (3) implausible counts rejected with
  `DeserializationException` — tested at all three layers; (4) amplification
  payload test asserting bounded memory — `testAmplificationPayloadRejectedWithBoundedMemory`
  (up-front path, ~0 MB) and `testInLoopCeilingKeepsMemoryBounded` (in-loop path,
  < 64 MB vs ~28 MB actual).
- **Tests:** 25 tests / 1088 assertions in the parser test file; the two
  memory tests and the wire-contract test were mutation-verified in round 2
  (fail against the pre-fix parser). Memory assertions use the
  baseline-before-parse pattern with ≥ 2x margin — CI-robust.
- **Types/style:** PHPStan level 9 clean, PHPCS PSR-12 clean, Rector clean.

Nothing new found. The file has converged.

## 5. Residual items (process, not findings — main session)

1. **CHANGELOG** — add the `[Unreleased] > Changed` entry (parser rejects
   > 262 144 records per chunk; `parse()` gained `maxEntriesPerChunk`) at
   workflow step 8. The rationale already lives in the code docblock.
2. **Step-14 candidates** (verified real in rounds 1–2, still open): C1
   (Consumer buffers whole chunks regardless of `maxBufferSize`,
   `src/Client/Consumer.php:51-53` — follow-up issue, medium) and C4 (plan-doc
   header drift, `docs/plans/iterations/04-osiris-chunk-parser.md:113-124` —
   docs issue, low). KB candidate: verify the transmit path when reviewing
   wire-format parsers (on-disk ≠ wire).

## 6. Gates (re-run this round)

| Gate | Result |
|---|---|
| `composer cs` | PASS |
| `composer phpstan` (level 9) | PASS — no errors |
| `composer rector` (dry-run) | PASS — no changes |
| `./vendor/bin/phpunit --testsuite unit` | PASS — 863 tests, 2837 assertions, 1 risky (pre-existing `StreamConnectionTest.php:569`, untouched) |

## 7. Verdict for the main session

**APPROVE (clean) — no open findings remain.**

All findings from rounds 1 and 2 (F1–F3, N1–N5) are fixed or resolved with
verified evidence; the round-3 delta introduced nothing new; all gates are green;
the security fix satisfies every acceptance criterion of issue #399. The branch is
ready for the workflow's remaining steps (CHANGELOG at step 8, PR at step 9).
Commit this round's files (`review-3.md`, the `findings-review.md` annotation) as
`docs: record review round 3 for #399`.
