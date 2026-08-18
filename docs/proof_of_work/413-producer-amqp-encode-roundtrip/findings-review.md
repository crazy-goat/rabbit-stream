
```markdown
# Findings — review (issue #413)

One entry per finding. "Earlier round" = `findings-coder.md` (no prior
`findings-review.md` existed). Severity: high / medium / low / nit.

## Earlier-round findings (from findings-coder.md)

### C1 — src/Client/AmqpDecoder.php:101-107 — decoder exception message lacks hint
- What is wrong (coder's claim): `decodeMessage()` throws
  `DeserializationException` on non-AMQP payloads with no hint that the cause
  might be a raw payload published without framing.
- Severity: nit (coder marked it as a suggestion, not a bug).
- What happened to it: **not a real finding for this diff.** `AmqpDecoder` is
  not touched by this change (diff confirms zero lines changed there). It is a
  pre-existing UX suggestion. The new `Producer::send()` auto-encode means
  well-formed high-API publishes no longer hit this path; the low-level API
  remains the documented escape hatch. Out of scope for #413.
- Automated check that could catch it: none (message wording is human-readable
  only; no test asserts the message text).

### C2 — src/Client/AmqpDecoder.php readDescribedType() — dead/duplicated code
- What is wrong (coder's claim): `readDescribedType()` returns a keyed
  `['descriptor'=>…,'value'=>…]` array that is only consumed by tests;
  `decodeMessage()` uses the separate `readDescribedTypeWithPosition()`.
- Severity: nit.
- What happened to it: **not a real finding for this diff.** Pre-existing,
  untouched by this change. Out of scope for #413.
- Automated check that could catch it: PHPStan dead-code ruleset (not enabled
  here) or a custom test asserting the return shape is unused.

### C3 — tests/E2E/AmqpMessageDecoderE2ETest.php:102,140 — inline data-section construction
- What is wrong: two `"\x00\x53\x75\xb0" . pack('N', $length) . $body`
  constructions remain inside multi-section fixture builders instead of using
  `AmqpMessageEncoder::encodeDataSection()`.
- Severity: nit.
- What happened to it: **still present, by design.** These are embedded in
  Properties+Data and AppProperties+Data fixtures that `Producer::send()`
  cannot produce; the Data-section fragment is byte-identical to the encoder
  output, so there is no behavioral drift risk. Marginal DRY win only.
- Automated check that could catch it: none (a custom grep-based sniff for the
  literal byte pattern could flag it, but none is configured).

### C4 — docs/en/examples/basic-producer.md, basic-consumer.md — missing cross-link
- What is wrong (coder's claim): examples use plain strings but do not
  cross-link to the new "Message Encoding" section.
- Severity: nit.
- What happened to it: **still present, optional.** The examples are
  correct as-is (plain strings both ways); the cross-link is a discoverability
  nicety. README and publishing guide already cover the topic.
- Automated check that could catch it: a link-checker / doc linter (not the
  configured `kb-lint`, which only covers `docs/helpers/`).

### C5 — tests/StreamConnectionTest.php:567 — risky test (no assertions)
- What is wrong (coder's claim): `testDispatchMetadataUpdateWithoutCallbackDoesNotCrash`
  is flagged risky by PHPUnit.
- Severity: nit.
- What happened to it: **still present, pre-existing.** Confirmed by the unit
  run: "There was 1 risky test … StreamConnectionTest.php:567". Not introduced
  by this diff (file not in the changed set). Smallest fix (out of scope here):
  add `$this->expectNotToPerformAssertions()`.
- Automated check that could catch it: PHPUnit's risky-test detection (already
  reports it).

## New findings (this review)

### R1 — src/Client/AmqpMessageEncoder.php:28 — silent overflow for ≥4 GiB bodies
- What is wrong: `pack('N', strlen($body))` wraps modulo 2^32 when
  `strlen($body) >= 4294967296`. Verified by PHP one-liner:
  `pack('N', 4294967296)` → `00000000`. A body ≥ 4 GiB would be written with a
  length prefix of `(len mod 2^32)`, so the decoder would read the wrong number
  of bytes (or fail) — silently corrupted framing rather than a thrown error.
- Severity: low. The AMQP vbin32 format itself maxes at 2^32-1 bytes, so a
  body this large is already a protocol violation; PHP `memory_limit` and the
  negotiated `frameMax` make it unreachable in practice. The issue is that the
  failure mode is silent instead of loud.
- Impact: theoretical data corruption on absurdly large payloads; no realistic
  path given normal config.
- Smallest safe fix direction: at the top of `encodeDataSection()`,
  `if (strlen($body) > 0xFFFFFFFF) throw new \LengthException(...)` (or the
  project's exception base). Pair with a unit test asserting the throw.
- Automated check that could catch it: none of PHPStan/PHPCS/Rector flag this;
  a dedicated unit test would.

### R2 — tests/Client/ProducerTest.php — no unit guard on Producer→encoder wiring
- What is wrong: `ProducerTest::testSendBatchCreatesSingleRequestWithMultipleMessages`
  captures the `PublishRequestV1` but asserts only `count === 3`, not that each
  `PublishedMessage::message` is the encoded form. A future refactor that
  removes the `AmqpMessageEncoder::encodeDataSection()` call from
  `Producer::send()`/`sendBatch()` would pass the entire unit suite and only
  fail E2E (which requires Docker and is not in the unit gate).
- Severity: low. The current code is correct (verified by reading
  `Producer.php:95,113`); this is a test-coverage gap, not a defect.
- Impact: regression-detection latency — a broken encode could land if E2E is
  skipped. The encoder itself and the full round-trip are covered; only the
  wiring between `Producer` and the encoder lacks a fast unit-level assertion.
- Smallest safe fix direction: extend the existing test (or add one) to assert
  `$messages[0]['message'] === AmqpMessageEncoder::encodeDataSection('msg1')`
  on the captured request. PHPUnit catches the regression in the unit suite.
- Automated check that could catch it: the proposed unit test (PHPUnit).

### R3 — tests/E2E/AmqpMessageDecoderE2ETest.php:102,140 — inline data-section bytes
- What is wrong: same as C3; listed once under earlier round. No separate
  defect.
- Severity: nit.
- What happened to it: see C3 above (still present, by design, byte-identical).

---

## Round 1 fixes (applied by main session after round 1)

### R1 — FIXED
`src/Client/AmqpMessageEncoder.php`: added a `LengthException` guard when
`strlen($body) > 0xFFFFFFFF`, so an over-vbin32-limit body throws loudly
instead of silently corrupting the length prefix via `pack('N', ...)` wrap.
`testEncodeDataSectionAcceptsBodyAtVbin32Limit` documents the boundary.

### R2 — FIXED
`tests/Client/ProducerTest.php::testSendBatchCreatesSingleRequestWithMultipleMessages`:
now asserts each captured `PublishedMessage::data` equals
`AmqpMessageEncoder::encodeDataSection(<input>)`, so dropping the encode call
from `Producer::send()`/`sendBatch()` fails the unit suite (not only the
Docker-gated E2E suite).

```

---

```acceptance-report
{
  "criteriaSatisfied": [
    {
      "id": "criterion-1",
      "status": "satisfied",
