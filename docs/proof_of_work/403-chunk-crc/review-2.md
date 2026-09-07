# #403 Chunk CRC — Review Round 2 (2026-09-07)

Reviewer: read-only code review of `feature/issue-403-chunk-crc` in
`/Users/piotr.halas/work/rabbit-stream-wt-403` (commits `0ff5132`, `a50e19b`, `e22b2da`).

## Automated checks (all run in the worktree)

| Check | Result |
|---|---|
| `composer cs` (PHPCS PSR-12) | ✅ clean |
| `composer phpstan` (level 9) | ✅ 0 errors (267/267) |
| `composer rector` (dry-run) | ✅ no suggestions |
| `./vendor/bin/phpunit --testsuite unit` | ✅ OK (1070 tests, 8190 assertions) |
| `git diff main...HEAD` | reviewed in full (11 files) |

## Re-check of round-1 findings

| # | Round-1 finding | Status | Evidence |
|---|---|---|---|
| 1 | Docs said Bytes 32-35 were "Reserved (int32)" | **fixed** | `docs/en/advanced/osiris-chunk-format.md:33` now reads "Chunk CRC (uint32, CRC-32 of the data section)" and a new "Chunk CRC (Bytes 32-35)" section documents erlang:crc32 equivalence and the `verifyCrc` toggle. Grep of `docs/en` shows no other stale references. |
| 2 | `$verifyCrc` inserted before `$onClose` broke positional callers | **fixed** | `src/Client/Consumer.php:160-162`: `?callable $onClose = null` now precedes `private readonly bool $verifyCrc = true`. Both have defaults; named-argument callers unaffected. |
| 3 | `crc32(substr(...))` copies the data section per chunk | **not a real finding (deliberate, documented)** | Round 1 accepted it with rationale in `code-decision-1.md` and `findings-review.md` #3: the parser reads entries via an absolute cursor with no intermediate slice to hand `crc32()`; the transient copy is refcount-shared from the frame buffer. The code comment at `OsirisChunkParser.php:370-373` matches that rationale. Operators can opt out via `verifyCrc: false`. |
| 4 | Missing empty-chunk CRC test and `verifyCrc: false` propagation test | **fixed** | `OsirisChunkParserTest::testEmptyChunkCrcVerifiesOverEmptyDataSection` (0-entry chunk, `crc32('') = 0`, passes with verification on) and `ConsumerTest::testVerifyCrcFalsePropagatesToParserAndDefaultVerifies` (corrupted chunk rejected by default, accepted as 1 buffered message with `verifyCrc: false`) both exist and pass. |

All four round-1 findings are resolved or dispositioned.

## Protocol / wire correctness

- **CRC scope is correct.** Cross-checked against the reference Go client
  (`rabbitmq-stream-go-client` `server_frame.go handleDeliver`): it computes
  `crc32.ChecksumIEEE` over exactly the `dataLength` bytes following the header
  and compares with the header's `crc` field — identical to
  `crc32(substr($chunkBytes, $headerSize, $dataLength))` at
  `OsirisChunkParser.php:375`. CRC-32 IEEE == `erlang:crc32` == PHP `crc32()`.
- **Error precedence is correct.** The CRC check runs after the
  header + dataLength bounds check (`OsirisChunkParser.php:353-361`), so the
  `substr()` can never read past received bytes, and before entry parsing, so a
  corrupt chunk fails fast before any entry is yielded. The
  `numRecords > maxEntriesPerChunk` guard still precedes it.
- **Offset reporting is correct.** The exception message reports the
  *stream* offset (`chunkFirstOffset`), not a buffer position — consistent with
  what an operator can act on; the test asserts `offset 42`.
- **`getUint32()` (not `getInt32()`) now reads chunkCrc** — correct, the field
  is unsigned on the wire.
- **Generators**: CRC verification lives in `parseChunkHeader()`, shared by
  `parseRaw()` and `parseRawViews()`, so `parse()`, `parseEntries()` and
  `parseMessages()` all verify — covered by `testParseMessagesAlsoVerifiesChunkCrc`.
- **Toggle threading is complete**: `Consumer::__construct` →
  `Consumer.php:397` (deliver callback) → `parseMessages(verifyCrc: ...`;
  `Connection::subscribe` (`Connection.php:508,533`) and
  `Connection::subscribeToSuperStream` (`Connection.php:602,619`) both pass it
  through with named arguments. No call site missed.

## Round-2 findings (new)

1. **`.DS_Store` binary modification committed on the branch** —
   `git diff main...HEAD` includes `.DS_Store` (Bin 6148 → 6148) in commit
   `0ff5132`, unrelated to #403. The file is *already tracked on `main`*
   (last touched by commit `473c1fe`), so the existing `.gitignore` entry does
   not help; every macOS session re-dirties it. **Severity: low** (repo
   hygiene only; no source impact). Fix on this branch: `git rm --cached
   .DS_Store` in a cleanup commit (or amend); longer term, remove the tracked
   blob from `main`.
   **Which automated check could have caught it:** none of
   cs/phpstan/rector/phpunit look at the diff — a trivial CI/pre-push step
   such as `git diff --name-only main...HEAD | grep -E '(^|/)\.DS_Store$' &&
   exit 1` (or a generic "no binary/junk files in diff" lint) would have.

2. **32-bit PHP portability of the CRC comparison (nit)** —
   `OsirisChunkParser.php:376` compares `crc32(...)` (signed int on 32-bit
   PHP) with `!==` against the header's unsigned uint32; on a 32-bit build a
   valid chunk could report a false CRC mismatch. The library effectively
   assumes 64-bit PHP (see the `ReadBuffer` comment at line 39 about
   getUint32/getUint64 returning floats), so this is a nit, not a bug on any
   supported target. Not blocking.

No correctness, security, PSR-12, PHPStan, docs-accuracy or test-coverage
issues found this round. The two new tests from round 1 are present, meaningful
(deliver-callback path exercised through a mocked `StreamConnection`, not just
parser-level), and pass.

## Verdict

**needs fixes** — only for finding 1 (strip the unrelated `.DS_Store` change
from the branch before merge/PR). Source code itself: clean.
