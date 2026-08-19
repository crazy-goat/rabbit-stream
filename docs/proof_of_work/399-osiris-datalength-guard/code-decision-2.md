# Code decision 2 — Round-1 findings (issue #399)

**Issue:** #399
**Branch:** `feature/issue-399-osiris-datalength-guard`
**Round:** 2 (coder) — addressing `findings-review.md` (F1–F3, N1–N4)

## The big one: the wire reality contradicts both the review's F2 suggestions and my own round-1 trailer check

Re-verifying the review's claims against RabbitMQ sources (current main) produced a
correction with real consequences:

- `osiris_log.erl` `select_amount_to_send(user_data, ?CHNK_USER, FilterSize,
  DataSize, _TrailerSize) -> {FilterSize, DataSize}` — the Deliver path skips the
  bloom bytes and does **not** send the trailer.
- `send_file/...` starts the sendfile at `Pos + ?HEADER_SIZE_B + ToSkip`, so those
  bytes are physically absent from the frame while the header still declares their
  on-disk sizes.
- `osiris_bloom.erl` `to_binary/1` returns `<<>>` only for empty / only-unfiltered
  filters; chunks containing filtered messages carry a nonzero `BloomSize` field.
- The trailer field is nonzero on real user chunks whenever tracking deltas exist
  (store offset, named producers, SAC) — bytes absent from Deliver frames.

Consequences:

1. **My round-1 fit check `header + dataLength + trailerLength <= received` was a
   latent bug**: it would reject legitimate chunks from streams with tracking
   (e.g. any `Consumer` using `StoreOffsetRequestV1`/autoCommit). Its test
   (`testTrailerLengthExceedingChunkSizeThrowsException`) pinned the wrong
   behavior. Fixed: the fit check is now data-section-only
   (`header + dataLength <= received`), and the old test is replaced by
   `testNonzeroTrailerLengthWithoutTrailerBytesParses`, which pins the actual wire
   contract.
2. **F2's suggested fixes are both wrong for the wire**: rejecting `bloomSize !== 0`
   breaks legitimate filtered-stream chunks; skipping `bloomSize` bytes misparses
   every one of them (the bytes are absent while the field is nonzero). The
   round-1 behavior — read and discard — is wire-correct; round 2 adds the
   documentation that explains why, in the class docblock and at the read sites.
   This is recorded as "not a real finding as suggested" in `findings-review.md`,
   with the evidence inline.

Lesson: when a review (or a coder) reasons about a wire format from the on-disk
format alone, verify the *transmit* path. The on-disk chunk layout
(header + bloom + data + trailer) and the deliver wire layout (header + data)
differ in exactly the two fields at issue.

## F1 — default ceiling: documented decision, no E2E stress

Per the task instruction, no 300k-message E2E stress fixture. Chosen combination
of the review's options (b)+(c): the rationale for 262 144 is now documented in
the constant's docblock (server does not enforce `frame_max` on Deliver; 262 144
is the theoretical record max of a 1 MiB data section at 4 B/record; real records
cost ≥ ~6 B, so the cap needs a >1.5 MiB chunk of near-empty records; failure is
loud and immediate; the cap is per-call configurable), and
`testCustomMaxEntriesPerChunkAboveDefaultParsesLargerChunk` proves a 280 000-record
chunk parses with a raised cap. A CHANGELOG upgrade note is handed to the main
session (coder does not touch CHANGELOG this round).

## N2 — uncompressedSize: capacity check, deliberately no equality

`rabbit_stream_utils.erl` (current main) `validate_compressed_sub_batch/5` shows
the broker itself does **not** require `UncompressedSize == BatchSize` for codec 0 —
it upper-bounds `UncompressedSize`, requires `MessageCount * 4 <= UncompressedSize`,
and rejects empty batches only when compressed. A codec-0 sub-batch with unequal
sizes is therefore storable and deliverable by a compliant broker. Enforcing
equality client-side would reject legitimate data; instead the parser mirrors the
broker's capacity check (`records * 4 > uncompressedSize` → throw) and documents
the deliberate non-equality. Check order: compressedSize (bytes present, the
security-relevant bound) first, uncompressedSize second — this also keeps the
existing compressedSize test's error message stable.

## What I was unsure about

1. **Whether to keep any trailerLength validation at all.** Considered rejecting
   `trailerLength` absurdly large (e.g. > chunk size) as a sanity check — discarded:
   the field is never used to size or bound anything (parsing is sliced by
   `dataLength` only), so a hostile value has no memory or correctness impact, and
   any threshold would be a magic number contradicting the "informational" contract
   the deliver path actually implements.
2. **N1 naming.** Chose `testEntryBeyondDeclaredDataSectionThrows` over
   `testExtraEntryOutsideDataSectionThrows` (reviewer's alternative) for the
   `...ThrowsException` suffix consistency with sibling tests; the body asserts an
   exception, and `Throws` mirrors the two truncated-chunk tests.
3. **F3 memory threshold.** Reused the reviewer-verified 64 MB / ~28 MB ratio from
   the same path; the payload is built before the baseline so delta measures only
   the parse.

## Validation

- `./vendor/bin/phpunit tests/Client/OsirisChunkParserTest.php` — 25 tests, 1088
  assertions, OK.
- `./vendor/bin/phpunit --testsuite unit` — 863 tests pass (3 new), 1 pre-existing
  risky (`StreamConnectionTest.php:569`, untouched).
- `composer cs`, `composer phpstan` (level 9), `composer rector` (dry-run): all
  clean.
- E2E not run (unit-level change, per instructions).
