# Code Decision 1 — Issue #413: AMQP-encode on producer send

## Approach taken

Auto-encode on the producer side (maintainer decision, executed as instructed):

- New `src/Client/AmqpMessageEncoder.php` with `encodeDataSection(string $body): string`
  producing `"\x00\x53\x75\xb0" . pack('N', strlen($body)) . $body` —
  the exact bytes `AmqpDecoder::decodeMessage()` already parses (described
  type: `0x00` marker, `0x53 0x75` smallulong descriptor = Data section 0x75,
  `0xb0` vbin32 + big-endian length + payload). Also added `encode()` alias.
- `Producer::send()` / `sendBatch()` wrap each payload before building
  `PublishedMessage`. Public signatures unchanged (`string $message, ?float $timeout`).
- Consumer + `AmqpDecoder` untouched — the producer now emits what the
  decoder already expects, so round trips work with zero decoder changes.
- E2E round-trip tests dropped their private `\x00\x53\x75\xb0` helpers and
  now pass plain bodies (Producer wraps them). `AmqpMessageDecoderE2ETest`
  keeps its hand-built multi-section frames (Properties + Data etc.) since
  those are decoder wire-fixtures `Producer` cannot produce; only its
  `buildAmqpDataMessage()` now delegates to the encoder.
- Docs updated: README Quick Start note, `docs/en/guide/publishing.md`
  ("Message Encoding" subsection incl. raw-bytes escape hatch), API reference
  notes, example comments, CHANGELOG entry.

## Rejected alternatives

1. **Consumer-side graceful fallback** (return raw body when not AMQP-framed):
   rejected — hides protocol errors, makes the wire format ambiguous to
   consumers, and pushes the asymmetry onto every reader. The issue itself
   framed this as a maintainer call; the maintainer chose auto-encode.
2. **Documentation-only fix**: rejected — the acceptance criteria require the
   Quick Start to actually run, which needs a code change.
3. **Expose an explicit `ProduceOptions`/encoding parameter on `send()`**:
   rejected — larger API surface; the issue's decision allowed auto-encode
   with a raw escape hatch, which the low-level API + `AmqpMessageEncoder`
   already provides.
4. **`decodeRawFallback()` helper**: not implemented — consumer stays as-is
   per the decision, so a fallback helper has no consumer to serve.

## Uncertainties

- Binary-safety of `strlen()` is guaranteed by PHP's byte semantics; the
  length prefix is the AMQP vbin32 size, so null bytes and binary payloads
  round-trip (verified by `MessageContentTest` which still passes).
- Empty body `''` encodes to a zero-length vbin32; `AmqpDecoder::decodeMessage()`
  handles it (unit-tested).
- E2E could not be run if docker is unavailable in this environment — noted
  in findings-coder.md.
