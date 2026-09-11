# Decision — Issue #520: reject and omit values for value-less offset specs

## Approach

`OffsetSpec` now knows which types are value-less. A private
`VALUELESS_TYPES` constant lists `TYPE_NONE`, `TYPE_FIRST`, `TYPE_LAST` and
`TYPE_NEXT`. The rule is keyed on the **type**, not on value presence:

1. **Constructor** — after the existing invalid-type and
   "offset/timestamp requires a value" checks, a non-null value on a
   value-less type throws `InvalidArgumentException` with the message
   `"Offset spec type <n> does not accept a value (value-less type)"`.
2. **`toStreamBuffer()`** — returns the 2-byte type field only when
   `value === null` **or** the type is in `VALUELESS_TYPES`. The 8-byte value
   is written only for the value-carrying types (`OFFSET`, `TIMESTAMP`,
   `INTERVAL`), preserving the existing int64-for-timestamp /
   uint64-otherwise encoding.

The constructor guard makes the malformed state unrepresentable; the
serializer guard is defense-in-depth for the same rule and mirrors
`ConsumerUpdateReplyV1::toStreamBuffer()` after #519.

## Deliberate scope choice: `TYPE_INTERVAL` stays value-carrying

`TYPE_INTERVAL` (0x0006) is **not** added to `VALUELESS_TYPES` and is left
untouched. It is a separate concern tracked by open issue #468; its wire
format (type + 8-byte uint64 interval) is unchanged, and
`OffsetSpec::interval($n)` keeps serializing its value. This issue is scoped to
the four types (`none`/`first`/`last`/`next`) that the protocol defines as
2-byte-only. Removing or changing interval behavior here would pre-empt #468.

## Rejected alternatives

- **Key only on the type (`TYPE_OFFSET`/`TYPE_TIMESTAMP`/`TYPE_INTERVAL` →
  valued), and drop the null check:** equivalent for the current type set, but
  the `value === null || ...` form keeps the `null` fast path and reads as
  "either no value, or a type that never has one". Chosen form is the one
  specified in the issue.
- **Silently ignore a supplied value for value-less types instead of
  throwing:** hides caller mistakes; a direct construction with a value is
  always a bug, so failing loudly is safer.
- **Move `A`/`TYPE_OFFSET/TIMESTAMP` check into a shared helper:** the two
  guards are small and local; extracting an enum is a wider refactor (see
  findings — raw-int API is a weak spot in `ConsumerUpdateReplyV1`).

## Test changes (`tests/VO/OffsetSpecTest.php`)

- Data provider `valueLessTypeProvider` over all four value-less types.
- `testValueLessTypeRejectsNonNullValue` — each value-less type with a
  non-null value throws, message contains `does not accept a value`.
- `testValueLessTypeSerializesTypeFieldOnly` — each value-less type serializes
  as exactly 2 bytes `pack('n', type)`.
- `testIntervalSerializesTypeAndUint64Value` — `interval(3600)` serializes as
  `pack('n', 0x0006) . pack('J', 3600)` (10 bytes), guarding #468.
- Existing `testToStreamBufferWithoutValue`, negative-timestamp tests and
  offset/timestamp-requires-value tests are unchanged and still pass.

## Uncertainties

- Whether RabbitMQ currently tolerates the extra 8 bytes for value-less types
  is irrelevant: no in-tree caller constructs those types with a value, so this
  is strictness/safety, not a live-broker behavior change.
- `toArray()` still emits the raw `type`/`value` pair; with the constructor
  guard, a value-less spec can only ever report `value => null`, so no change
  was needed there.
