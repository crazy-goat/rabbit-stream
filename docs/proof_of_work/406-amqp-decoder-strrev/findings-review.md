# Review findings — issue #406 (AmqpDecoder `strrev` → offset-form `unpack()`)

Format: `file:line | what is wrong | severity | what happened to it`

The changed behaviour in this branch (90 added test lines) has **no defects**.
The entries below are the pre-existing issues surfaced by the coder's
`findings-coder.md`, audited here, plus one nit about a comment. Nothing here
blocks the branch.

1. `src/Client/AmqpDecoder.php:378-386` | `readUint64()` returns `int(-1)` for an
   AMQP `ulong` (0x80) above `PHP_INT_MAX` (e.g. `0xFFFFFFFFFFFFFFFF`) because it
   uses `unpack('J')` with no `< 0` guard, while `ReadBuffer::getUint64()`
   (`src/Buffer/ReadBuffer.php:107-125`) throws `DeserializationException` for
   exactly that input (#393). Confirmed live: decoder → `int(-1)`, ReadBuffer →
   throw. Pre-existing, not introduced by this test-only branch. | medium
   (pre-existing) | open — recommend a follow-up issue; out of scope here.
2. `src/Client/AmqpDecoder.php:216-217` | Repeated `Data` (0x75) section
   concatenation reallocates the accumulated body on every section →
   O(n²) for pathological many-section payloads. Pre-existing. | low
   (pre-existing) | open — noted, not worth changing in this PR.
3. `src/Client/AmqpDecoder.php:289-310` | The `$result === false` branches in
   `unpackIntAt()`/`unpackFloatAt()` are effectively unreachable with the fixed
   literal formats and the preceding bounds guards. | nit | not-a-finding —
   harmless defensive code.
4. `src/Client/AmqpDecoder.php:393-395` | Comment says `'J'` "is already signed";
   more precisely it is an unsigned wire format whose bit pattern is
   reinterpreted as a native signed PHP int. Conclusion (no manual correction)
   is correct. | nit | not-a-finding — wording only.
5. `tests/Client/AmqpDecoderTest.php:263` | The new test's explanatory comment
   still contains the literal word `strrev`, so `grep -rn strrev tests/`
   returns a hit even though no executable `strrev` remains. Could confuse a
   future textual search. | nit | not-a-finding — descriptive comment only.

## Changed-test audit (the actual subject of the PR)

- `numericBoundaryProvider` / `testDecodeNumericBoundary`: all 27 cases use the
  correct AMQP format bytes (0x50/0x51/0x60/0x61/0x70/0x71/0x80/0x81/0x83/0x72/0x82)
  and the correct big-endian `pack()` codes (`C`/`c`/`n`/`N`/`J`/`G`/`E`);
  expectations match the decoder's signedness. `assertSame` strictness is safe
  (int expectations are ints on 64-bit; the float fixtures `-1.5`/`1.0` are
  exact). Whole-fixture consumption is asserted. The data provider is not
  self-fulfilling — endianness, sign-correction and cursor regressions all
  fail these cases. **No finding.**
- `testDecodeNumericAtNonZeroOffset`: genuinely decodes the second int32 at
  `$position === 5`, exercising `unpack($format, $data, $offset)`; position
  assertions 5 and 10 are correct. **No finding.**
- Coverage: min/max/negative across all signed widths (byte, short, int, long,
  timestamp), plus unsigned min/max where representable. Meets the issue's
  acceptance criteria. **No finding.**
