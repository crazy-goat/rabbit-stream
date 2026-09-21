# Decision — Issue #467: treat QueryOffset `NO_OFFSET` (0x13) as a normal outcome

## Approach

`NO_OFFSET` is represented as a `QueryOffsetResponseV1` whose
`getOffset()` returns `null`, instead of a `ProtocolException`:

- `src/Response/QueryOffsetResponseV1.php` — `fromStreamBuffer()` now reads the
  response code first; when it is `ResponseCodeEnum::NO_OFFSET->value` it returns
  a response with `offset = null` (correlation id still parsed). Any **other**
  non-OK code still goes through `assertResponseCodeOk()` and throws
  `ProtocolException`. `getOffset()` widened to `?int` and `fromArray()` accepts a
  missing/`null` offset (`offset ?? null`).
- `Connection::queryOffset(): ?int`, `Consumer::queryOffset(): ?int`,
  `SuperStreamConsumer::queryOffset(): ?int` propagate the `null`; the three
  interfaces were widened accordingly (`ConnectionInterface`,
  `ConsumerInterface`, `SuperStreamConsumerInterface`).
- `Consumer::defaultConsumerUpdateHandler()` dropped the
  `catch (ProtocolException) { if NO_OFFSET ... }` branch: a `null` offset now
  resumes at the consumer's initial `OffsetSpec`. Other `ProtocolException`s
  propagate exactly as before.

`ProtocolException::getResponseCode()` is untouched, so a caller that still wants
to distinguish broker codes keeps that ability for all *other* codes.

## Rejected alternatives

1. **Return `null` from `QueryOffsetResponseV1::fromStreamBuffer()` (the issue's
   literal suggestion).** `ResponseBuilder::fromResponseBuffer()`
   (`src/ResponseBuilder.php:56-58`) converts a `null` parser result into a
   `DeserializationException`, and the serializer chain is typed `object`
   (`BinarySerializerInterface::deserialize(): object`,
   `StreamConnection::readMessage()/request(): object`,
   `Connection::queryOffset()` reading via `readMessage()`). The `null` could
   therefore never reach the caller without widening those public signatures and
   auditing every `readMessage()`/`request()` call site. A sentinel *inside* the
   response object keeps the deserializer contract (`object`) intact and is the
   smallest change that makes `readMessage()` itself return the "no offset"
   answer. The issue itself allows choosing "a dedicated result".
2. **Return `-1` / `0` as the sentinel.** Ambiguous with a real offset and
   makes the first-run branch fragile (`!== -1`). `null` is unambiguous and is
   already the project's "graceful absence" idiom.
3. **Keep throwing and document `getResponseCode()`.** Rejected: the issue's
   whole point is that the README/Javadocs-style first-run resume must not
   throw; a documented throw leaves the bug in place for every existing caller.
4. **Add `hasOffset(): bool` and keep `getOffset(): int`.** Works, but leaves a
   meaningless `0` reachable from `getOffset()` and adds a second accessor to
   keep in sync. `?int` is the direct representation.
5. **Read the trailing `uint64` offset even on `NO_OFFSET`.** Not needed (the
   value is ignored) and would make a truncated `NO_OFFSET` frame fail in the
   parser instead of being treated as "nothing stored". The client deliberately
   stops after the response code for the `NO_OFFSET` branch.

## Test changes

- `tests/Response/QueryOffsetResponseV1Test.php` — added
  `testNoOffsetParsesAsNullOffset` (`0x0013` → `null`, correlation id kept);
  tightened `testThrowsOnErrorResponseCode` from `\Exception` to the concrete
  `ProtocolException` with the `0x0002` message, pinning that non-`NO_OFFSET`
  codes still throw.
- `tests/Client/ConnectionTest.php` — added
  `testQueryOffsetReturnsNullForNoOffset`.
- `tests/Client/ConsumerTest.php` — added `testQueryOffsetReturnsNullForNoOffset`
  and `testSingleActiveConsumerResumesAtInitialOffsetWhenNoOffsetStored`
  (drives the private `defaultConsumerUpdateHandler` via the existing
  `invokeConsumerUpdate` helper).
- `tests/E2E/StoreOffsetQueryOffsetE2ETest.php` — `NO_OFFSET` case now asserts a
  `QueryOffsetResponseV1` with `null` offset instead of `ProtocolException`
  `0x0013`.
- `tests/E2E/ConsumerTest.php` — the two "no offset stored" tests assert `null`
  instead of an exception; the resume test now narrows the `?int` with
  `assertNotNull` before `OffsetSpec::offset()`.

## Uncertainties

- **Literal-null vs. null-offset**: the issue text recommends `fromStreamBuffer()`
  returning `null`. I chose the response-carries-`null` form for the reason in
  rejected alternative 1. If review insists on the literal-null API, the
  blast radius is the whole `readMessage()/request()/deserialize()` chain
  (`object` → `?object`) and every call site — a much larger, riskier change than
  this issue warrants.
- **Frame shape on `NO_OFFSET`**: the Erlang reader sends
  `{?RESPONSE_CODE_NO_OFFSET, 0}` so the offset field is present in practice, but
  this change does not depend on it.
- **Single-active-consumer resume semantics**: with `null`, activation resumes at
  the consumer's initial `OffsetSpec`. That matches the previous NO_OFFSET branch
  exactly, so no behaviour change there — only the detection mechanism moved.
