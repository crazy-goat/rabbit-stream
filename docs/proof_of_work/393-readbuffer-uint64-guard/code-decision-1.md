# Decision — Issue #393: reject uint64 above PHP_INT_MAX in `ReadBuffer::getUint64()`

## Approach

`getUint64()` now detects the signed wrap and throws. On 64-bit PHP,
`unpack('J')` returns the raw two's-complement bit pattern as a native int, so
every value `>= 2^63` comes back **negative** (`0xFFFFFFFFFFFFFFFF` → `-1`,
`0x8000000000000000` → `PHP_INT_MIN`). `PHP_INT_MAX` is `2^63 - 1`, therefore
the exact predicate for "cannot be represented" is `$data[1] < 0`.

The guard is placed **after** the existing `unpack(...) === false` check and
**before** `$this->position += 8`, so a rejected read does not advance the
cursor (matching the #447 guards on `skip()` / `readBytes()`). The thrown
`DeserializationException` message carries both the raw 8 bytes and the
position, using the same `sprintf(... at position %d ...)` shape as the
sibling errors:

```
uint64 value 0xffffffffffffffff at position 0 exceeds PHP_INT_MAX
```

The raw bytes are recovered with
`bin2hex(substr($this->buffer, $this->offset + $this->position, 8))` — the
same windowed read the unpack itself uses, so it is correct for `slice()`d and
windowed buffers too, not just whole-buffer ones.

`getInt64()` is deliberately untouched. It is *supposed* to return the signed
interpretation (`-1` for `0xFFFFFFFFFFFFFFFF`); that is its contract and its
existing tests assert it.

## Docblock

The old comment said "values above PHP_INT_MAX wrap to negative (tracked
separately as #393)" — i.e. it documented the bug and deferred it. It now
states the values are rejected.

## Rejected alternatives

- **Clamp / saturate to `PHP_INT_MAX` and continue.** Silently turns a corrupt
  frame into a plausible-looking offset. Worse than failing; offsets are
  equality-compared and fed to `OffsetSpec`.
- **Return a string / GMP-ish big integer.** The return type is `int` and every
  caller stores the result in an `int` field (`QueryOffsetResponseV1::offset`,
  `PublishingError`, the chunk parser locals). Changing the type would ripple
  through the whole codebase for a case the protocol cannot legitimately
  produce; the issue asks for a guard, not a representation change.
- **Guard inside `getInt64()` as well / share one code path.** `getInt64()`
  must keep two's-complement behavior, so the two methods genuinely differ and
  the duplication (8 lines) is cheaper than an extra parameter to a hot scalar
  getter.
- **Including `PHP_INT_MAX` in the message.** It is a platform constant whose
  decimal value is not useful in a parse error; the offending raw value is.
  The format string stays short (≤ 80 chars at this nesting depth) to satisfy
  the repo's PSR-12 line-length gate.

## Test changes (`tests/Buffer/ReadBufferTest.php`)

- Replaced `testGetUint64WithMaxValue` (which asserted the buggy `-1`) with:
  - `testGetUint64WithValueAbovePhpIntMaxThrows` — `0xFF×8` throws
    `DeserializationException`; the message contains
    `0xffffffffffffffff` and `position 0`, and `getPosition()` is still `0`
    afterwards (no cursor movement on rejection).
  - `testGetUint64WithMaxRepresentableValue` — `pack('J', PHP_INT_MAX)`
    (`0x7FFFFFFFFFFFFFFF`) round-trips to `PHP_INT_MAX`.
- `testGetInt64Negative` (existing) still asserts `-1` for the same bytes,
  pinning that the change is scoped to the unsigned reader.

## Caller audit

`grep getUint64` over `src/` and `tests/`: the only test that relied on the
wrapped value was the one above. All production callers
(`QueryOffsetResponseV1`, `QueryPublisherSequenceResponseV1`,
`ResolveOffsetSpecResponseV1`, `VO/PublishingError`, `OsirisChunkParser`) treat
the result as a non-negative offset/sequence, so rejecting the wrap is
strictly safer. See `findings-coder.md` for the one caller that bypasses this
method entirely (`PublishConfirmResponseV1`).

## Uncertainties

- Real stream offsets never approach `2^63`, so this is hostile-frame
  hardening, not a live-broker behavior change.
- `PublishConfirmResponseV1::fromStreamBuffer()` reads ids with a single
  `unpack('J*')` (a #411 performance optimization) and therefore does **not**
  go through this guard. Flagged as a finding; fixing it is a separate,
  performance-sensitive change and out of this issue's scope.
