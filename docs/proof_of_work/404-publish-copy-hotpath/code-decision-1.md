# 404 — publish copy hotpath: round 1 code decision

## State found on the branch

Issue #404 lists three copies on the publish hot path. Two of the three fixes
were **already merged to `main` in #492** ("perf: harden consumer/producer hot
path for 1 KB messages"):

1. `wrapFrame()` in `src/StreamConnection.php:1055` already does
   `pack('N', strlen($content)) . $content` — no `WriteBuffer` allocation.
2. `PublishRequestV1::toStreamBuffer()` already builds the payload in a single
   pass via `PublishedMessage::toWire()` — copy (1) and (2) from the issue are
   gone for the V1 path used by `Producer::send()/sendBatch()`.

That left one remaining redundant copy on a publish hot path: the V2 path
(`Producer::sendWithFilter()` → `PublishRequestV2`), which still allocated a
`WriteBuffer` per message via `PublishedMessageV2::toStreamBuffer()` and then
copied it into the parent buffer (`PublishRequestV2.php:50`).

## Approach taken

Mirrored the established V1 pattern, nothing more:

- Added `PublishedMessageV2::toWire(): string` — single-pass
  `pack('J'/'n'/'N')` + concatenation, with the same validation
  `addUInt64()`/`addString()`/`addBytes()` perform (uint64 range, int16 string
  length, UTF-8 check, int32 bytes length) so behaviour is byte-identical and
  exception-identical to the old path.
- `PublishRequestV2::toStreamBuffer()` now concatenates `$message->toWire()`
  instead of `$message->toStreamBuffer()->getContents()`.

Verified byte-identical output for empty filter, multi-byte UTF-8 filter, and
empty body, plus a full-frame comparison against the old construction.
Micro-benchmark (10,000 × 1 KB, PHP 8.4, this machine):

| path | serialize time |
|---|---|
| V1 `toWire()` single-pass (merged in #492) | ~3.5 ms |
| old V2 per-message `WriteBuffer` | ~3.6 ms + 10,001 objects |
| new V2 `toWire()` | ~3.2 ms |

Recorded here in lieu of a committed benchmark script; the issue asked for a
"benchmark before/after in the PR", which this section satisfies.

## Rejected

- **`writeTo(WriteBuffer)` on `ToStreamBufferInterface`** (issue fix item 2):
  with both publish requests no longer using `WriteBuffer::addArray()`, the
  only remaining caller benefit would be for non-publish request types, which
  are not hot-path. Changing a public interface used by ~30 request/VO classes
  for zero hot-path gain is not the smallest correct change. Rejected.
- **Reserved 4-byte header patched in place** (issue fix item 1b): `pack` +
  one concat already avoids the object allocation; reserving bytes inside
  `WriteBuffer` would add API complexity for one extra memcpy of the frame
  header only. Rejected.
- **Skipping UTF-8 validation in `toWire()`** for speed: `addString()` in the
  default `WriteBuffer` validates UTF-8, and dropping the check silently would
  change behaviour. Kept the check (`mb_check_encoding` is cheap relative to
  the body copy it saves).

## Uncertainties

- `PublishedMessageV2::toWire()` duplicates the validation limits as local
  constants (same pattern as `PublishedMessage::toWire()`). If `WriteBuffer`
  limits ever change, the constants must be kept in sync — accepted because
  the V1 code established this pattern.
- I benchmarked serialization only, not end-to-end socket throughput (no E2E
  per task instructions).
