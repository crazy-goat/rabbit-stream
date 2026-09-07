# #403 Chunk CRC verification — implementation decisions

## Approach taken

`crc32()` (PHP's standard CRC-32, identical to `erlang:crc32`) is now computed
over exactly the `dataLength` bytes of the chunk's data section and compared
with the header's `chunkCrc` field. Mismatch throws
`DeserializationException` naming the chunk's first offset:

```
Chunk CRC mismatch at offset %d: computed 0x%08x but the chunk header declares 0x%08x
```

The check lives in `OsirisChunkParser::parseChunkHeader()`, *after* the
data-section bounds check, so the `substr()` used for CRC input can never read
past the received bytes and the error precedence matches the existing guards
(a truncated chunk still reports the size mismatch, not a CRC error).

### Toggle

Followed the existing pattern in this codebase: a named boolean constructor /
method parameter defaulting to `true`, threaded end-to-end like `maxDecodeDepth`
(#450 did):

- `OsirisChunkParser::parse()` / `parseEntries()` / `parseMessages()` gained
  `bool $verifyCrc = true` (threaded through the private `parseRaw()` /
  `parseRawViews()` / `parseChunkHeader()` cores).
- `Consumer::__construct()` gained `bool $verifyCrc = true`, passed to
  `parseMessages()` in the deliver callback.
- `Connection::createConsumer()` and `createSuperStreamConsumer()` gained
  `bool $verifyCrc = true` and forward it, so both the plain and super-stream
  consumer paths are covered.

Default is **on**, per the issue.

## What was rejected and why

- **A config/options object.** The library has no options class for consumers —
  everything is named constructor parameters (`initialCredit`,
  `maxDecodeDepth`, `creditWindowBytes`, ...). Introducing one for a single
  flag would be a bigger API change than the issue requires.
- **Verifying in `Consumer` instead of the parser.** `OsirisChunkParser` is the
  single place every chunk passes through (all three public entry points share
  `parseChunkHeader()`), and it is also a documented public API
  (`docs/en/advanced/osiris-chunk-format.md`). Verifying there covers
  `parse()`, `parseEntries()` and `parseMessages()` with one check and makes
  the acceptance criterion "CRC computed and compared for every chunk" true by
  construction.
- **Reading the CRC as signed (`getInt32`, the old code).** Erlang's
  `erlang:crc32` produces an unsigned 0..2^32-1 value stored as uint32; PHP's
  `crc32()` also returns unsigned. Changed to `getUint32()` so the comparison
  is exact. (Values above 2^31 would previously have arrived negative.)
- **Streaming/zero-copy CRC.** A copy-free CRC would need a hand-rolled
  table-based implementation; `crc32(substr(...))` costs one data-section copy
  per chunk. Accepted for now — noted as a hot-path cost in findings.
- **`hash('crc32b', ...)` instead of `crc32()`.** `crc32()` is the native,
  fastest builtin and computes the same CRC-32 (IEEE 802.3) as
  `erlang:crc32`. `hash('crc32b')` returns a hex string and is slower.

## Uncertainties

- **Does the broker's chunkCrc cover only the data section?** Per the osiris
  on-disk format the CRC covers the chunk's data section (not the header, not
  the trailer/bloom), and the E2E suite (not run here, per instructions)
  against a real broker is the definitive check that real Deliver frames pass
  verification. All unit tests with correctly-built chunks pass; the E2E
  OsirisChunkParser tests parse real broker chunks and would catch a
  scope/coversion mistake.
- **Performance of the copy.** The `substr()` of the data section is one extra
  full-chunk-sized copy per chunk on the consumer hot path when verification
  is on (the default). We deliberately re-removed full-chunk copies in #412/
  #484; a follow-up could add a rolling/windowed CRC or make the toggle's
  cost visible in `docs/en/advanced/performance-tuning.md`.
