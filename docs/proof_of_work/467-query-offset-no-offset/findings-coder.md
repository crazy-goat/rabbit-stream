# Findings — Issue #467

## Obstacles / surprises

1. **The issue's recommended mechanism does not survive the deserializer.**
   `ResponseBuilder::fromResponseBuffer()` treats a `null` parser result as a
   deserialization failure (`src/ResponseBuilder.php:56-58`), and the whole read
   path is typed `object` (`src/Serializer/BinarySerializerInterface.php`,
   `src/Serializer/PhpBinarySerializer.php:25`, `src/StreamConnection.php:1012`,
   `src/StreamConnection.php:1036`). A literal `null` from
   `QueryOffsetResponseV1::fromStreamBuffer()` would therefore surface as
   `DeserializationException`, not as "no offset". Solved by carrying the
   sentinel as `getOffset(): ?int` on the parsed response instead of returning
   `null` from the parser (full rationale in `code-decision-1.md`).

2. **PHPStan analysed the E2E suite too.** Widening `queryOffset()` to `?int`
   immediately failed `composer phpstan` at
   `tests/E2E/ConsumerTest.php:68` (`OffsetSpec::offset()` expects `int`,
   `int|null` given). Fixed by narrowing with `assertNotNull($stored)` there;
   the other E2E uses (`assertSame`/`assertNotSame`) needed no change.

3. **The old response test asserted only `\Exception`.** The malformed-frame case
   (`tests/Response/QueryOffsetResponseV1Test.php:35`) sent a response code with
   no trailing offset, which only worked because `assertResponseCodeOk()` threw
   before the offset read. The `NO_OFFSET` branch deliberately does not read the
   offset, and the non-OK branch still asserts before reading, so that frame
   shape stayed valid — the test was tightened to the concrete
   `ProtocolException` rather than changed in shape.

## Bugs / weak spots noticed

### Fixed as part of this issue

1. **`README.md:177` (before this change)** — the resume example did
   `OffsetSpec::offset($storedOffset + 1)`, but since #396 `queryOffset()`
   already returns the **next** offset to consume, so the example skipped one
   message on resume. Fixed to `OffsetSpec::offset($storedOffset)` (and made
   `null`-safe).

### Out of scope (not changed here)

1. **`docs/en/examples/error-handling-patterns.md` is built on a class that no
   longer exists.** It `use`s `CrazyGoat\RabbitStream\OffsetSpecification`
   (lines 300, 489) and calls `OffsetSpecification::stored()`/`::first()`, and
   drives `$connection->subscribe(...)` / `unsubscribe(...)` on
   `StreamConnection` — none of which exist in `src/` today (the VO is
   `CrazyGoat\RabbitStream\VO\OffsetSpec`, and subscriptions go through
   `Consumer`). Lines 325-353, 411, 625-663 and 710 are unrunnable as written.
   Suggested fix: rewrite the file around `Connection::createConsumer()` +
   `QueryOffsetRequestV1`/`QueryOffsetResponseV1`, or delete it and point at
   `docs/en/guide/error-handling.md`. Its `NO_OFFSET` handling (lines 294,
   339, 638) is about a low-level `subscribe()` throw that this client does not
   produce, so it was left untouched rather than half-migrated.

2. **`docs/en/api-reference/enums.md:171`** still describes `NO_OFFSET` as
   "No offset available" in the error-code table without noting it is a normal
   QueryOffset outcome. Minor; `docs/en/guide/error-handling.md:39` says
   "First-time consumer with no stored offset", which is clearer. Suggested fix:
   align the enum table wording with the error-handling guide.

3. **`QueryOffsetResponseV1::fromArray()` has no round-trip test for the null
   offset.** `tests/Response/FromArrayTest.php:134` only covers a real offset.
   Low risk (the object is only rebuilt from array data in tests/serializers),
   but a `['correlationId' => 1, 'offset' => null]` case would pin the new
   branch. Suggested fix: add one alongside the existing case.

4. **`SuperStreamConsumer::queryOffset()` carries no docblock** (unlike
   `Consumer::queryOffset()`), so the null semantics are only visible in the
   interface/API reference. Suggested fix: add a short docblock mirroring
   `Consumer::queryOffset()`; not required by any gate.

---

## Round 1 follow-up discoveries

1. **No docblock-reflection gate covers `SuperStreamConsumer` (or `Connection`'s
   client class).** `ConsumerDocblockTest`/`ConnectionDocblockTest` guard their
   classes, but `SuperStreamConsumer` has no equivalent, so the docblock added
   for Coder finding 4 / review R1-F4 can regress silently. Suggested follow-up:
   generalise the reflection gate over the client classes (or add
   `SuperStreamConsumerDocblockTest`). Out of scope for #467.

2. **`FromArrayInterface` declares `@param array<string, mixed>`.** Narrowing the
   override's docblock to a shape would be flagged as a redundant/looser
   override, so the `fromArray()` documentation stays a prose note plus the
   inherited `array<string, mixed>` tag. Recorded so a future contributor does
   not "fix" it into a shape that then breaks on extra keys.

3. **`ReadBuffer::getPosition()` is window-relative.** The full-frame assertion
   in the NO_OFFSET test uses `strlen($raw)` rather than the buffer's absolute
   offset because `ReadBuffer` is constructed with offset 0 there. If a future
   test wraps a sub-window, the assertion must use the window length, not the
   backing string length.
