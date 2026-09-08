
## Round 1 dispositions (main session, after fixes)

1. (low) no direct toWire() unit tests — **fixed**: added tests/VO/PublishedMessageV2Test.php (wire bytes, empty values, negative id, too-long filter, invalid UTF-8).
2. (low) validation constants duplicated in three classes — **deliberately not fixed**: refactor (WireEncodeTrait) touches three files beyond this issue's minimal scope; tracked as follow-up in findings-coder.md.
3. (low) validation order (length before UTF-8) differs from WriteBuffer::addString() for doubly-invalid input — **not a real finding**: both orders reject the same inputs; only the exception identity for doubly-invalid data differs, which no caller or test relies on.
4. (nit) pack('J')+pack('n') vs pack('Jn') — **fixed**: now a single pack('Jn', ...), matching V1 style.
5. (nit) upper-bound publishingId check dead on 64-bit — **deliberately not fixed**: kept for 32-bit/parity with V1, as the review itself notes.

## Round 2 review (reviewer, see review-2.md)

1. still present? **fixed** — agreed; test file verified by hand (wire bytes, empty case = 14 bytes, negative id, 32768 filter, invalid UTF-8).
2. still present? **deliberately not fixed** — agreed; no new duplication added; WireEncodeTrait correctly deferred to findings-coder.md.
3. still present? **not a real finding** — agreed; same input set accepted/rejected, only exception identity for doubly-invalid data differs.
4. still present? **fixed** — agreed; single `pack('Jn', ...)` confirmed in src/VO/PublishedMessageV2.php.
5. still present? **deliberately not fixed** — agreed; live on 32-bit, parity with V1.

New issues: none blocking (two informational notes in review-2.md). Tooling: composer cs / phpstan / rector / unit tests all clean (1075 tests, 8195 assertions). Verdict: **clean**.
