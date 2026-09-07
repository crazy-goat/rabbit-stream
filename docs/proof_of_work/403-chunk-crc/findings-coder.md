# #403 Chunk CRC — coder findings

## Obstacles

- **Test chunk builders wrote `chunkCrc = 0`.** Three private helpers build
  raw chunks by hand and hardcoded a zero CRC
  (`tests/Client/OsirisChunkParserTest.php:624`, `:561`,
  `tests/Client/ConsumerTest.php:626`). With verification default-on, every
  test using them would have failed. Fixed by making the helpers emit
  `crc32($dataSection)`, which also means the whole existing suite now
  exercises the happy path of the new check for free.
- **Two tests deliberately corrupt chunks after building them**
  (`testSubBatchDeclaringMoreRecordsThanDataThrowsException`,
  `testSubBatchUncompressedSizeCannotHoldRecordsThrowsException` in
  `tests/Client/OsirisChunkParserTest.php:449` and `:476`). They now trip the
  CRC guard before reaching the sub-batch validation they exist to test.
  Fixed by passing `verifyCrc: false` with a comment explaining why the
  corruption is intentional — the alternative (recomputing the CRC after the
  mutation) would have hidden that the chunks are intentionally invalid.

## Surprises

- **The CRC guard's placement is load-bearing.** Because it runs after the
  data-section bounds check (`parseChunkHeader()`), truncation tests still
  report the size-mismatch error, and the two corruption tests above were the
  only collateral. Moving it earlier would change error precedence for
  existing tests and users.
- **`$chunkCrc` was read as *signed*** (`getInt32()` at the old
  `src/Client/OsirisChunkParser.php:310`). Any CRC above 0x7fffffff would have
  arrived negative — harmless while discarded, but a trap for the new
  comparison. Changed to `getUint32()`.

## Bugs / weak spots noticed (out of scope)

1. **One extra full-chunk copy on the consumer hot path**
   (`src/Client/OsirisChunkParser.php`, the `crc32(substr(...))` in
   `parseChunkHeader()`). #412/#484 carefully removed full-chunk copies; CRC
   verification reintroduces one when enabled (the default). Suggested fix: a
   small table-based CRC-32 that walks the string window in chunks (e.g.
   64 KiB `substr` slices), or measure first and document the cost in
   `docs/en/advanced/performance-tuning.md`.
2. **`Consumer` config is a growing positional-parameter list**
   (`src/Client/Consumer.php:141`, 16 params; `Connection::createConsumer()`
   mirrors it). `verifyCrc` was added following the established pattern, but
   the pattern itself is near its limit — an options/DTO object would prevent
   the next flag from being appended blind. Same for
   `createSuperStreamConsumer()` which re-lists a subset.
3. **`Connection::createConsumer()` positional trap**
   (`src/Client/Connection.php:496`): most calls use named arguments
   internally, but the public signature invites positional calls; anyone
   passing `$maxDecodeDepth` positionally in the 11th slot now silently gets
   `verifyCrc` semantics shifted. A options object (see 2) fixes this too.
4. **E2E coverage of CRC on real traffic is not asserted.** The E2E parser
   tests (`tests/E2E/OsirisChunkParserE2ETest.php`) parse real broker chunks;
   they would fail loudly if broker CRCs were out of scope of the data
   section — but there is no explicit "broker chunks verify" assertion or
   comment recording that expectation. Suggested fix: add a one-line comment /
   assertion there when E2E next runs.
5. **`ReadBuffer::getUint64()` sign-wrap comment** (`src/Buffer/ReadBuffer.php:100`):
   tracked as #393, unrelated here, but the same "fits in 64-bit int" reasoning
   documented for `getUint32()` applies; leaving the pointer for whoever picks
   that issue up.

6. **Round-1 fix note: string-offset corruption must target an in-range byte**
   (`tests/Client/ConsumerTest.php`). The existing CRC tests corrupt `$chunk[60]`,
   which only works for chunks longer than 61 bytes (the 'Hello World' fixture is
   61). With `buildOneEntryChunk('X')` the chunk is 57 bytes, and PHP string
   offset assignment past the end silently pads with spaces — the "corruption"
   is a no-op and the test fails confusingly. `testVerifyCrcFalsePropagatesToParserAndDefaultVerifies`
   therefore corrupts byte 52 (the single entry body). If more CRC tests are
   added, prefer `substr_replace` or assert the chunk is actually modified.
