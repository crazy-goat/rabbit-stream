# Code decision 1 — Osiris sub-batch header parse fix (issue #383)

**Issue:** #383
**Branch:** `feature/issue-383-osiris-subbatch-header`

## What was asked

`OsirisChunkParser` parsed sub-batch entries (CHNK_USER) as a single
4-byte uint32 header (bit 31 = T, bits 28–25 = codec, lower 16 bits =
record count). The real Osiris layout (`deps/osiris/src/osiris_log.erl`)
is:

    byte 0:   T(1 bit) | Cmp(3 bits) | Rsvd(4 bits)   → 1 byte
    bytes 1-2: Number of records                      → uint16 big-endian
    bytes 3-6: Uncompressed Length                    → uint32
    bytes 7-10: Length (compressed size)              → uint32
    then:      Body (Length bytes)

Simple entries (bit 7 of byte 0 = 0) were specified as correct and must
not change behavior.

## Approach taken

### Parser (`src/Client/OsirisChunkParser.php`)

Loop restructured to read the entry-type discriminator on the **first
byte**:

1. `$entryType = $buffer->getUint8();` then test `($entryType & 0x80) !== 0`.

2. **Simple entry:** reassemble the 31-bit body length from the already
   read high byte plus the remaining 3 bytes of the 4-byte header.
   `ReadBuffer` has no `getUint24`, so I assembled the low 24 bits from
   `getUint16()` + `getUint8()`:
   `$entrySize = (($entryType & 0x7F) << 24) | ($buffer->getUint16() << 8) | $buffer->getUint8();`.
   Shift precedence: `<<` binds tighter than `|`, but explicit parens
   keep it unambiguous. Max value 0x7FFFFFFF — fits a 64-bit int (and
   even a 32-bit signed int), so no overflow issue.

3. **Sub-batch entry:** `$codec = ($entryType >> 4) & 0x07;` (bits 6–4),
   keep the `codec !== 0` guard **before** reading the rest (fail fast,
   mirrors the old code which also threw before consuming the size
   fields), then `$numRecords = $buffer->getUint16();`, skip
   uncompressedSize (`getUint32()`), read `$compressedSize = getUint32()`,
   `readBytes($compressedSize)`, and iterate `$numRecords` inner records
   (uint32 length + bytes) exactly as before.

4. Class docblock rewritten to document the real 1+2+4+4 layout
   (it previously documented the wrong 4-byte layout, which misled
   anyone reading the file).

### Test fixture (`tests/Client/OsirisChunkParserTest.php`)

`createChunk()` rebuilt to emit the real wire format:

```php
$dataSection .= pack('C', 0x80 | (($codec & 0x07) << 4)); // T=1, codec bits 6-4
$dataSection .= pack('n', $count);                        // numRecords uint16
$dataSection .= pack('N', $uncompressedSize);
$dataSection .= pack('N', $uncompressedSize);             // compressed == uncompressed for codec 0
$dataSection .= $innerData;
```

## What I rejected

- **Keeping the `getUint32()` pre-read and then re-decoding.** Reading
  the 4-byte uint32 first is exactly the original bug: the discriminator
  is bit 7 of **byte 0**, and a pre-read uint32 merges the next fields
  (count and uncompressed-size high bits) into the "header" value. Any
  decode path starting from a single uint32 is inherently wrong — for
  count=512 the merged value is `0x80020000`, from which the old math
  extracted `codec=0, count=0` (verified by simulation before editing).
  The only correct restructure is read-byte → decide → consume the
  remaining fields per branch.

- **Adding `getUint24()` to `ReadBuffer`** to assemble the simple-entry
  length. Tempting for readability, but it's a one-off use — the
  `getUint16() << 8 | getUint8()` assembly is 1 line and adding public
  API for a single call site violates the "no hypothetical abstractions"
  guideline. (Noted as a possible future cleanup in findings-coder.md.)

- **Moving the `codec !== 0` guard after reading `numRecords`.** The
  guard's position doesn't change observable behavior (we throw either
  way before touching the body), but throwing first keeps the
  "reject unsupported" path minimal and matches the pre-existing code
  structure.

## Uncertain / assumptions

- **Inner-record framing for codec 0 (uint32 length + bytes)** was kept
  exactly as the pre-existing code did it — the task explicitly
  prescribed it. I did not re-derive it from osiris_log.erl; if
  RabbitMQ ever emits a *compressed* sub-batch this framing changes
  (that's why the guard exists).
- **`numRecords` vs the chunk-header `numRecords` field** — distinct
  fields; the fixture sets both independently and the parser uses the
  sub-batch's own uint16, as the layout requires. A mismatch between
  the two is possible on the wire; the parser is deliberately permissive
  and does not cross-check them (see findings-coder.md).
- No wire-level behavior changes: simple entries byte-for-byte identical
  output; only the sub-batch decode path changed shape.
