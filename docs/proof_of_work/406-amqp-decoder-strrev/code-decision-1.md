# Code decision 1 — issue #406 (AmqpDecoder `strrev(substr())` → `unpack()` offset form)

## Situation found before writing any code

The production change described in the issue had **already landed on `main`**
before this branch was cut. Commit `25b4507` ("perf: harden consumer/producer hot
path for 1 KB messages …", PR #493, closes #492) removed every `strrev(substr())`
from `src/Client/AmqpDecoder.php` and introduced the offset-form `unpack()` plus
big-endian format codes:

- `unpackIntAt('n'|'N'|'J', $data, $position, …)` for the integer widths
- `unpackFloatAt('G'|'E', $data, $position, …)` for float/double
- manual sign correction for the 16/32-bit types only (mirrors
  `ReadBuffer.php:160-186`)

The `unpackInt` → `safeUnpack` → `is_scalar` chain the issue also mentions is
gone too: the decoder now has `unpackIntAt()`/`unpackFloatAt()` and no
`safeUnpack` at all. Verified with `grep -rn "strrev" src/ tests/` → no matches
and `git log -S "strrev" -- src/Client/AmqpDecoder.php` shows the removal in
`25b4507`.

So the performance half of the issue was already satisfied on `main`; only the
second acceptance criterion (numeric unit-test coverage including negatives and
boundary widths) was still open in practice.

## What I did

Added boundary-value coverage to `tests/Client/AmqpDecoderTest.php`:

- `testDecodeNumericBoundary` + `numericBoundaryProvider` — one case per end of
  every fixed-width numeric format that the decoder exposes: ubyte/byte,
  ushort/short, uint/int, ulong/long, timestamp, float and double. Each case
  builds the wire bytes with `pack()` (big-endian `n`/`N`/`J`/`G`/`E`) and
  asserts both the decoded value and that the whole fixture was consumed.
- `testDecodeNumericAtNonZeroOffset` — decodes two back-to-back int32 values,
  the second at a non-zero offset, to pin the `unpack($format, $data, $offset)`
  behaviour that replaced the `substr()`/`strrev()` slicing.

No production file was changed. The acceptance criteria are met by the existing
implementation plus this coverage.

## Alternatives considered and rejected

1. **Re-introduce `strrev` and "fix" it again for a visible diff.** Rejected:
   pointless churn that would make the history lie and risks regressing a
   working, already-reviewed optimisation.
2. **Inline `unpack()` directly into each reader to drop `unpackIntAt()`.**
   Rejected: the issue's concrete complaint was the `safeUnpack`/`is_scalar`
   layers, which are already gone. Inlining would duplicate the `false`-check
   ten times for no measurable gain and hurt readability.
3. **A source-text regression test asserting `strrev` is absent.**
   Rejected: brittle (comments/strings could trip it) and it tests the source
   rather than behaviour; the boundary tests pin the behaviour that matters.
4. **Fix the `ulong` > `PHP_INT_MAX` integer overflow while here.** Rejected as
   out of scope and a behaviour change: the instructions say preserve exact
   behaviour. Reported as a finding instead (see `findings-coder.md`).

## Uncertainty

- `ulong` (format `0x80`) still silently wraps values above `PHP_INT_MAX` to a
  negative int because it shares `unpack('J')` with `long`. This is a real
  discrepancy with `ReadBuffer::getUint64()` (which rejects via #393) and should
  probably be a follow-up issue, but it is deliberately left unchanged here.
- The tests assume a 64-bit PHP build (guarded by
  `Platform::assertSixtyFourBitIntegers()`), which is already the library's
  stated platform requirement.
