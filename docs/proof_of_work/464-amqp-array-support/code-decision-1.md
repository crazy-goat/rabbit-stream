# Decision 1 — Approach for AMQP array support (#464)

## What was done

Added `readArray8()` (0xe0) and `readArray32()` (0xf0) to `src/Client/AmqpDecoder.php`,
registered as two new match arms in `decodeValue()`. Both follow the exact pattern of
the existing compound readers (`readList8`/`readList32`):

- array8: size (uint8, includes the count byte) + count (uint8) + element constructions
- array32: size (uint32) + count (uint32) + element constructions
- `compoundContentEnd()` / `assertCompoundConsumed()` reuse validates the declared size
- Elements are decoded with `decodeValue($data, $position, $depth + 1, $maxDepth)`, so
  the #397 recursion guard applies to nested arrays/lists/maps automatically
- array32 carries the #449 OOM guards (available-bytes check + `MAX_COMPOUND_ELEMENTS`
  cap) exactly as `readList32` does

## What was rejected and why

1. **Ignoring the size field and looping only on count** (the issue's suggested fix is
   silent on size). Rejected: the existing readers treat a size/count mismatch as
   malformed input; dropping the size check would let corrupted frames decode silently
   and diverge from the file's established pattern.
2. **Adding char (0x73) / decimal (0x74/0x84)** from the issue report. Rejected: the
   issue marks them "optionally … in a follow-up". They are scalar types with different
   semantics (char is a 4-byte Unicode codepoint, decimal is int32+octet scale) and
   deserve their own change/test matrix. The throwing default arm still reports them
   with a clear message.
3. **A separate "element constructor" abstraction** (AMQP arrays are homogeneous, and
   some encoders omit repeated constructors). Rejected: per the AMQP 1.0 spec each
   array element still carries its own constructor on the wire in the single-constructor
   encoding this decoder targets; scanning/consumed-position semantics stay simple and
   consistent with the list readers.

## Uncertainties

- **Homogeneous-constructor encoding:** the AMQP 1.0 spec allows an array to be encoded
  with a *single* constructor followed by packed element values (used by some
  implementations for efficiency). This decoder handles the per-element-constructor
  form. We found no evidence the Java/Go clients emit the single-constructor form for
  message bodies/annotations (they emit per-element constructors), and the issue's own
  repro bytes (`e0 02 01 41`) use the per-element form. If a producer using the packed
  form appears, this will need a follow-up.
- array8 size/count are single bytes, so the #449-style element-cap guard is
  unnecessary for array8 (max 255 elements); only array32 got the explicit caps.
