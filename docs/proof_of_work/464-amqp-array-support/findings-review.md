# Findings — Review Round 1 (#464)

1. **src/Client/AmqpDecoder.php:748-783** — `readArray32` duplicates the two #449 guard blocks (available-bytes check + MAX_COMPOUND_ELEMENTS check) and their long comments verbatim from `readList32` (~35 duplicated lines incl. comments). A future change to one guard (e.g. raising `MAX_COMPOUND_ELEMENTS` semantics) can easily miss the other. Consider extracting a small `assertCompoundCountGuard(int $count, int $available, string $what)` helper shared by list32/array32.
   Severity: low — Status: open

2. **src/Client/AmqpDecoder.php:740-760** — Inconsistent malformed-count error path between array8 and array32: array32 throws a specific pre-computed message ("Array32 count %d exceeds available bytes %d"), while array8 throws the generic in-loop "Array8 count exceeds available data". Functionally safe (uint8 count ≤ 255), but a divergence from list32/list8 parity — same issue exists for list8, so this is consistent with the existing code rather than a regression.
   Severity: nit — Status: open

3. **tests/Client/AmqpDecoderTest.php:990-1012** — `testDecodeArray32HonestLargeFrameThrowsBeforeAllocating` tests only `MAX_COMPOUND_ELEMENTS + 1`. A boundary test at exactly `131072` null elements (accepted, decodes successfully with peak memory still bounded) would pin down the inclusive boundary behaviour; cheap to add (~128 KB payload).
   Severity: low — Status: open

4. **tests/Client/AmqpDecoderTest.php:998-1000** — The "count exceeds available bytes" guard test (`testDecodeArray32CountExceedingAvailableThrows`) uses 1-byte null elements only. A case where declared count is satisfiable per-byte but elements are multi-byte (e.g. count larger than can be satisfied by str8 elements inside the span) would exercise that the guard is not just the loop guard firing. Low value but tightens coverage.
   Severity: nit — Status: open

5. **tests/Client/AmqpDecoderTest.php:1030-1036** — `testDecodeArrayRespectsMaxDepth` uses `chr($size & 0xFF)` which silently truncates if the accumulated size ever exceeded 255. Harmless today (depth limit fires at 32 long before sizes approach 255), but a comment noting the reliance, or an assertion that `strlen($data) <= 255`, would make the test self-documenting.
   Severity: nit — Status: open

No high- or medium-severity findings. Wire format, depth parity, OOM parity, bounds safety, PHPStan 9 and PSR-12 all verified clean.
