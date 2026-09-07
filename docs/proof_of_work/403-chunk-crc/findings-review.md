# #403 Chunk CRC — findings review (reviewer)

Round 1 (2026-09-07). First review round — `findings-review.md` did not previously exist; no earlier findings to re-check. Entries below are new findings from review-1.md.

| # | Location | What is wrong | Severity | Status |
|---|---|---|---|---|
| 1 | `docs/en/advanced/osiris-chunk-format.md:35` | Chunk header Bytes 32-35 documented as "Reserved (int32)"; it is the `chunkCrc` field, now verified — doc outdated | low | open |
| 2 | `src/Client/Consumer.php:141-166` | `$verifyCrc` inserted before `$onClose` breaks positional callers passing `onClose` (nit; matches existing maxDecodeDepth pattern; options-object debt already tracked in findings-coder.md) | nit | open |
| 3 | `src/Client/OsirisChunkParser.php:370-384` | `crc32(substr(...))` adds one full data-section copy per chunk on the hot path when verification is on (default); acknowledged in code-decision-1.md | low | open |
| 4 | `tests/Client/OsirisChunkParserTest.php` / `tests/Client/ConsumerTest.php` | Missing test: empty-chunk (`dataLength = 0`) CRC path; no unit test that `Consumer::__construct(verifyCrc: false)` propagates to the parser | low | open |

No high/medium findings. Verdict: clean.
