# Review 1 — Issue #413: Producer AMQP-encode round-trip

Branch: `feature/issue-413-producer-amqp-encode-roundtrip`
Diff: `git diff origin/main...HEAD` (1 commit: `85a3dc3`)

## Overall verdict

**Ship-able.** The change is correct, minimal, and well-targeted. The encoded
bytes round-trip exactly through the existing `AmqpDecoder::decodeMessage()`
(traced by hand and covered by the new unit test), the Producer public API is
signature-compatible, encoding happens exactly once per message with no
double-wrapping path, and all automated gates pass (PHPStan 9, PHPCS, Rector,
PHPUnit unit suite, kb-lint). No high or medium-severity defects found. Two
low-severity coverage/robustness gaps and three nits are listed below; none
block merge.

## Automated checks (all run from repo root)

| Check | Command | Result |
|-------|---------|--------|
| PHPStan 9 | `composer phpstan` | passed — 0 errors, 237 files |
| PHPCS (PSR-12) | `composer cs` | passed — 241 files, 0 violations |
| Rector dry-run | `composer rector` | passed — no changes |
| Unit tests | `./vendor/bin/phpunit --testsuite unit` | passed — 655 tests, 1410 assertions (1 pre-existing risky test) |
| New encoder test | `./vendor/bin/phpunit tests/Client/AmqpMessageEncoderTest.php` | passed — 5 tests, 6 assertions |
| Full lint (incl. kb-lint) | `composer lint` | passed |
| Staged files | `git diff --cached` | none — working tree clean |

## Wire-format correctness (primary focus area)

Encoder output for body `B`:
```
"\x00\x53\x75\xb0" . pack('N', strlen($B)) . $B
  ^      ^  ^   ^    ^                    ^
  |      |  |   |    big-endian uint32 len  payload
  |      |  |   vbin32 format code (0xb0)
  |      |  smallulong value = 0x75 = Data-section descriptor
  |      smallulong format code (0x53)
  described-type constructor marker
```

Hand-trace of `AmqpDecoder::decodeMessage()` on this input:
1. `ord($data[0]) === 0x00` → enters `readDescribedTypeWithPosition`.
2. Position→1; `decodeValue` reads `0x53` (smallulong) → `readUint8` → descriptor=`0x75`, position→3.
3. `decodeValue` reads `0xb0` (vbin32) → `readBinary32` reads `pack('N',len)` as big-endian uint32, returns `substr($data, 8, len)` as the value, position→`8+len`.
4. `switch (0x75)` → Data section; `is_string($value)` → appends to `sections['body']`.
5. Position equals `dataLength`; loop exits. `sections['body'] === $B`.

Round-trip verified for: non-empty text, empty body (`''` → sections body `''`,
**not** null — `testEmptyBodyWrapsWithZeroLength` asserts this), UTF-8 multibyte
with embedded null bytes (`testEncodedBodyDecodesBackToOriginalViaAmqpMessageDecoder`),
and 512 random binary bytes (`testBinaryBodyDecodesBackByteForByte`).

Empty-body path is correct because `decodeMessage` initialises `sections['body']`
to `''` and a zero-length vbin32 appends `''`; `AmqpMessageDecoder::decode` then
sees `is_scalar('') === true` and keeps `''`. `Message::getBody()` returns `''`
(string), matching the E2E assertion `assertSame('', $body)`.

## Producer API

- `send(string $message, ?float $timeout = null): void` — signature unchanged.
  Encode happens exactly once at `Producer.php:95` via
  `AmqpMessageEncoder::encodeDataSection($message)`. No other `new PublishedMessage`
  call site exists in `src/`.
- `sendBatch(array $messages, ?float $timeout = null): void` — signature unchanged.
  Encode happens exactly once per message inside the foreach at `Producer.php:113`.
  Empty-array early return (`Producer.php:108-110`) is unchanged and skips encoding.
- `@param`/`@return` PHPDoc on both `Producer` and `ProducerInterface` are
  consistent and document the plain-payload contract plus the low-level escape
  hatch. `string[]` typing preserved on `sendBatch`.
- No double-wrapping: the encoder does not recurse, and no path wraps an
  already-encoded result. The only double-wrap risk is user error (calling
  `encodeDataSection()` then passing the result to `send()`), which is
  explicitly warned about in `docs/en/guide/publishing.md:88` and
  `docs/en/api-reference/producer.md:103`.

## Security / length-prefix

- `pack('N', strlen($body))` is big-endian uint32 (network byte order). Verified
  with a PHP one-liner: `pack('N', 2147483648)` → `80000000`,
  `pack('N', 4294967295)` → `ffffffff`. The decoder's `readBinary32` corrects
  the signed return of `unpack('N')` for the high-bit range, so bodies in
  `[0, 2^32-1]` round-trip correctly.
- Binary-safe: null bytes preserved (verified), `strlen()` is byte semantics on
  PHP 8.x, `substr` in the decoder is byte-exact.
- Integer overflow: `pack('N', 4294967296)` wraps to `00000000` — silent
  corruption for bodies ≥ 4 GiB. See finding R1 (low). This exceeds the AMQP
  vbin32 maximum (2^32-1) anyway, so it is a protocol violation rather than a
  supported use case, but the failure is silent rather than loud.

## E2E classes (7 modified)

All private `amqp(string $body): string` helpers removed; every call site now
passes a plain string. Grep confirms zero remaining `$this->amqp(` / `function amqp(`
in `tests/E2E`. Assertions are unchanged and still compare against plain strings,
which is correct because the consumer strips the framing. The
`LargeMessageE2ETest` 100 KiB / 1 MiB cases now exercise the encoder on
realistic sizes. `AmqpMessageDecoderE2ETest::buildAmqpDataMessage()` now
delegates to `AmqpMessageEncoder::encodeDataSection()`; its two multi-section
fixture builders (`buildAmqpMessageWithProperties`, `buildAmqpMessageWithAppProperties`)
keep inline data-section construction because they concatenate a Data section
after a Properties/AppProperties section — see finding C3/nit.

## Docs

- `README.md` Quick Start: new paragraph explains plain-string contract + AMQP
  Data section + link to publishing guide. Consistent with examples.
- `docs/en/guide/publishing.md`: new "Message Encoding" subsection documents the
  exact wire format (`0x00 0x53 0x75 0xb0 <uint32 length> <payload>`), binary
  safety, and the raw-bytes escape hatch via `AmqpMessageEncoder`. Matches the
  encoder output byte-for-byte.
- `docs/en/api-reference/producer.md`: Notes for `send()` and `sendBatch()` now
  state the auto-wrap behavior and the double-wrap warning.
- `examples/producer.php` / `examples/consumer.php`: comments updated.
- `CHANGELOG.md`: entry under `[Unreleased] > Changed` is accurate and references
  `#413`.
- `docs/en/examples/basic-producer.md` / `basic-consumer.md` already use plain
  strings — no contradiction. Optional cross-link noted as nit (C4).

## Findings carried over from the coder round (findings-coder.md)

No prior `findings-review.md` existed. The coder's own findings-coder.md is the
only earlier round; its items are assessed in findings-review.md below. Summary:
C1 (decoder exception-message hint) and C2 (dead `readDescribedType`) are
out-of-scope pre-existing suggestions, not defects in this diff. C3 (inline E2E
constructions) is a nit, still present by design. C4 (example cross-links) is an
optional nit. C5 (risky `StreamConnectionTest` test) is pre-existing and
confirmed still present by the unit run — not introduced here.

## New findings (this round)

- **R1 (low)** — `AmqpMessageEncoder.php:28`: no guard against `strlen($body) >= 2^32`;
  `pack('N', ...)` silently wraps. Exceeds vbin32 max so it is a protocol
  violation, but the failure is silent. Smallest safe fix: throw when
  `strlen($body) > 0xFFFFFFFF`. Could not be caught by an automated check
  shipped here (PHPStan/PHPCS won't flag it); a dedicated unit test asserting
  the throw would.
- **R2 (low)** — `tests/Client/ProducerTest.php`: no unit test asserts that
  `Producer::send()`/`sendBatch()` actually emit an encoded body. A refactor
  that drops the `encodeDataSection()` call would pass the unit suite and only
  fail E2E (which needs Docker). The encoder itself and the full E2E path are
  covered; only the Producer→encoder wiring lacks a fast guard. Smallest safe
  fix: in `testSendBatchCreatesSingleRequestWithMultipleMessages` (or a new
  test), capture the `PublishedMessage` and assert its `message` equals
  `AmqpMessageEncoder::encodeDataSection('msg1')` etc. PHPUnit would catch a
  regression.
- **R3 (nit)** — `tests/E2E/AmqpMessageDecoderE2ETest.php:102,140`: two inline
  `"\x00\x53\x75\xb0" . pack('N', $length) . $body` constructions remain inside
  multi-section fixture builders. Byte-identical to the encoder output, so no
  drift risk; marginal DRY win only.

## Remaining risk areas checked clean

- Round-trip correctness (empty, text, UTF-8, null bytes, binary, ≥2 GiB
  high-bit length): verified by trace + unit tests.
- Signature/back-compat of `send()`/`sendBatch()` on `Producer` and
  `ProducerInterface`: unchanged.
- Double-wrapping within the library: none.
- Decoder-side changes: none (consumer untouched by design).
- PSR-12, PHPStan 9, Rector, kb-lint: clean.
- CHANGELOG/README/docs/examples consistency: consistent.
- Staged files / accidental commits: none.

## Not fully verified

- **E2E suite (`./run-e2e.sh`)** was not run — requires Docker/RabbitMQ, not
  available in this environment. The modified E2E classes were syntax-checked
  via the unit suite's autoload and by PHPCS; the wire-level correctness they
  exercise is independently covered by `AmqpMessageEncoderTest` (which
  round-trips through both `AmqpDecoder::decodeMessage()` and
  `AmqpMessageDecoder::decode()`). E2E must still be run before merge per the
  coder's own note.
```

---
