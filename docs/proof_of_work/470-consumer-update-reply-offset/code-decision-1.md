# Decision — Issue #470: omit offset for value-less offset types

## Approach

`ConsumerUpdateReplyV1::toStreamBuffer()` now appends the `uint64` offset only
when `offsetType` is `4` (offset) or `5` (timestamp), matching the protocol
(only those offset specs carry a value) and the Java client behavior. Types
0–3 (`none`/`first`/`last`/`next`) serialize as just the 2-byte type field.

The sentinel types are referenced via `OffsetSpec::TYPE_OFFSET` /
`OffsetSpec::TYPE_TIMESTAMP` constants rather than raw literals, so the reply
and `OffsetSpec` stay in sync.

## Rejected alternatives

- **Change constructor to accept `OffsetSpec` (nullable value):** more
  type-safe, but a public API break; `StreamConnection` already validates the
  callback tuple (0–5) and constructs the reply. Out of scope for a wire bug.
- **Only omit when the value is `0`:** wrong — a real offset of `0` with type
  `4` is valid and must be written; the decision must key off the *type*.
- **Treat type `6` (interval) as valued:** the server's ConsumerUpdate reply
  contract only accepts 0–5 (`StreamConnection` already rejects 6), and the
  issue scopes the fix to 0–3 vs 4/5, so interval is not handled here.

## Test changes

- `tests/Request/ConsumerUpdateReplyV1Test.php`: `testSerializesCorrectly`
  moved to offsetType 4 (valued); added data-provider test that types 0–3
  serialize without the 8-byte value; added a timestamp (type 5) test.
- `tests/StreamConnectionTest.php::testDispatchConsumerUpdateWithoutCallbackSendsDefaultReply`
  — the default reply (type `none`) no longer carries the offset; replaced the
  `unpack('J', ...)` of bytes 12–20 with an assertion that the frame ends at
  12 bytes.

## Uncertainties

- Whether RabbitMQ tolerates (and always has tolerated) the extra 8 bytes for
  types 0–3 — the issue states it does, and the E2E suite passed before this
  fix, so the change is strictly safer, not load-bearing.
- `toArray()` still reports `offset` even for value-less types; kept as-is to
  avoid touching the debug/dump contract (out of scope).
