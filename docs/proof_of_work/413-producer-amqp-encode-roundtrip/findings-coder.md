# Findings — coder (issue #413)

## Obstacles / surprises

1. **The `amqp()` helpers were not only in ConsumerTest** — 7 E2E classes
   duplicated the `\x00\x53\x75\xb0` pattern (the issue said "repeated in 7
   test classes"; the literal search found 8 files including
   `tests/E2E/AmqpMessageDecoderE2ETest.php` and
   `tests/Client/AmqpMessageDecoderTest.php`). The decoder-focused ones build
   multi-section wire fixtures (Properties + Data) that `Producer::send()`
   cannot produce, so they were intentionally left hand-built; only the
   data-section-only builder was switched to `AmqpMessageEncoder`.

2. **The E2E tests that asserted plain-string equality were subtly coupled
   to the old behavior**: they sent pre-wrapped bytes and asserted against
   plain strings (the decoder strips the framing). After the change they send
   plain strings and assert plain strings — same assertions, no changes
   needed beyond removing the helpers.

3. **README/examples were already written for the post-fix behavior**
   (plain strings both ways) — the docs work was mostly adding an explicit
   note explaining the wire format, not rewriting examples.

## Discovered bugs / places to improve (incl. out of scope)

1. `src/Client/AmqpDecoder.php:101-107` (`decodeMessage`): throws
   `DeserializationException` on any non-AMQP payload. This is now by-design
   (issue #413 resolution), but the exception message gives no hint about
   *why* a payload might be raw (e.g. old messages published before this
   change). Suggested fix: extend the message with a hint like "if this is a
   raw payload published without AMQP framing, publish via
   `Producer::send()` (it now encodes) or wrap with
   `AmqpMessageEncoder::encodeDataSection()`".

2. `src/Client/AmqpDecoder.php` `readDescribedType()` (line ~340): returns
   `['descriptor' => ..., 'value' => ...]` keyed arrays; the "described value"
   returned by `decodeValue(0x00)` uses the same array shape as a map. It is
   only consumed by tests today, and `decodeMessage()` uses the separate
   `readDescribedTypeWithPosition()` — dead/semi-duplicated code. Suggested
   fix: remove `readDescribedType()` if nothing external relies on its shape,
   or document the dual contract.

3. `tests/E2E/AmqpMessageDecoderE2ETest.php:102,140`: two near-identical
   inline data-section constructions remain (raw bytes) — could use
   `AmqpMessageEncoder::encodeDataSection()`, but they are embedded in
   multi-section fixtures, so the win is marginal. Low priority.

4. `docs/en/examples/basic-producer.md` / `basic-consumer.md`: still fine
   (they show plain strings), but they contain no mention of the AMQP Data
   section wrapping; a reader coming from the issue might want a cross-link
   to `docs/en/guide/publishing.md#message-encoding`. Optional.

5. Pre-existing (not touched): `tests/StreamConnectionTest.php:567`
   (`testDispatchMetadataUpdateWithoutCallbackDoesNotCrash`) is flagged
   risky by PHPUnit ("did not perform any assertions") — it relies on
   `expectNotToPerformAssertions` or similar not being present. Suggested
   fix: add `$this->expectNotToPerformAssertions()`.

## Validation notes

- Unit suite: 655 tests, 1410 assertions, OK (1 pre-existing risky test).
- New `tests/Client/AmqpMessageEncoderTest.php`: 5 tests, 6 assertions, OK.
- E2E: `./run-e2e.sh` not run yet at time of writing — depends on docker
  availability in the environment; must be run before merge (wire-level
  change). The changed E2E tests were syntax-linted.
