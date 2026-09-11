# Findings — Issue #520

## Obstacles / surprises

1. **The constructor had to become stricter, not just the serializer.** The
   most obvious fix (only change `toStreamBuffer()`) would leave
   `new OffsetSpec(TYPE_FIRST, 123)` constructible and silently discard the
   value. Two guards are now in place: the constructor throws (unrepresentable
   bug), and the serializer still keys on the type (defense-in-depth,
   consistent with `ConsumerUpdateReplyV1`).
2. **The invalid-type check duplicates the type list.**
   `src/VO/OffsetSpec.php:38-51` hardcodes all seven type constants in an
   `in_array`, while the new `VALUELESS_TYPES` constant lists four of them.
   Not a blocker, just a third place to update when a type is added.

## Bugs / weak spots noticed (out of scope)

1. **`ConsumerUpdateReplyV1` encodes `TYPE_TIMESTAMP` with `addUInt64()`**
   (`src/Request/ConsumerUpdateReplyV1.php:49-51`). `OffsetSpec` deliberately
   uses `addInt64()` for timestamps so pre-1970 values encode correctly
   (`src/VO/OffsetSpec.php:124-125`). A consumer-update handler that returns
   `OffsetSpec::timestamp(-1000)` reaches `StreamConnection.php:1129-1145`,
   which forwards the raw int; `ConsumerUpdateReplyV1::toStreamBuffer()` then
   calls `addUInt64(-1000)`, and `WriteBuffer::validateInt()`
   (`src/Buffer/WriteBuffer.php:126`) throws
   `InvalidArgumentException` instead of encoding two's complement.
   Suggested fix: mirror `OffsetSpec` — `addInt64()` when
   `offsetType === OffsetSpec::TYPE_TIMESTAMP`, `addUInt64()` otherwise.
2. **`ConsumerUpdateReplyV1` accepts any offset value for value-less types.**
   Its constructor (`src/Request/ConsumerUpdateReplyV1.php:29-39`) validates
   only that the type is 0–5; `toArray()` already nulls the offset for types
   0–3, but the serializer ignores a non-zero offset for those types rather
   than rejecting it. Low impact (offset is never written), but the same
   unrepresentable-state argument from #520 applies. Suggested fix: reject a
   non-zero offset for types 0–3, or accept an `OffsetSpec`.
3. **`StreamConnection::handleConsumerUpdate()` validates the type but not the
   value as an integer pair.** `src/StreamConnection.php:1129` does
   `[$offsetType, $offset] = [$offsetSpec->getType(), $offsetSpec->getValue() ?? 0]`,
   which is fine, but line 1132 unpacks an arbitrary array returned by the
   callback with no shape check before the 0–5 guard at line 1135. A callback
   returning a short array would emit PHP warnings / undefined offsets.
   Suggested fix: validate `is_int()`/`array_key_exists` or type the callback
   to return `OffsetSpec|null`.
4. **`OffsetSpec::none()` is documented as "ConsumerUpdate reply only" but the
   type is also accepted anywhere an `OffsetSpec` is** (e.g. Subscribe). This
   is documentation-level only; a dedicated enum/VO split would make it
   unrepresentable. Out of scope.
