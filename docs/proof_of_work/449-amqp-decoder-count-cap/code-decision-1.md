# Code decision 1 — AmqpDecoder element-count cap (issue #449)

**Issue:** #449
**Branch:** `feature/issue-449-amqp-decoder-count-cap`

## Problem

`src/Client/AmqpDecoder.php` `readList32()` and `readMap32()` loop
`for ($i = 0; $i < $count; $i++)` using the attacker-supplied 32-bit `<count>`
field directly as the bound, appending each decoded element to a PHP array.
This is a **breadth** amplification vector, distinct from the **depth** vector
fixed in #397 — a flat list is depth 1, so the #397 recursion guard never
fires. Two shapes:

- **Malformed count vs. size**: a frame declares a 32-bit `count` larger than
  what its own `size` field says is present (e.g. `size=5`, `count=8 388 608`).
  Today this is only caught *lazily*: the in-loop `position > endPosition` /
  end-of-data checks fire after 1–2 wasted `decodeValue()` calls and throw a
  generic `Unexpected end of data`, not a clear "count exceeds available"
  error. No OOM here (only 1–2 buckets are allocated), but the rejection is
  late and the diagnostic is poor.
- **Honest large frame** (the real OOM): an 8 MB frame — within
  `DEFAULT_MAX_FRAME_SIZE` (8 MiB, enforced in `StreamConnection::readFrame`)
  — carries a *truthful* `count` of 8 M null elements with 8 M content bytes.
  The loop runs to completion, appending 8 M array buckets (~128–256 MB of
  zval/Bucket storage) → **uncatchable** `Allowed memory size exhausted` fatal
  at `memory_limit=128M`. This is the headline PoC in the issue.

Reachable from an untrusted publisher via `Consumer::read()` →
`AmqpMessageDecoder::decodeAll()` → `decode()` →
`AmqpDecoder::decodeMessage()` → `decodeValue()` → `readList32`/`readMap32`.

## Approach taken

Two complementary guards in both `readList32` and `readMap32`, placed *before*
the allocation loop so no array bucket is ever allocated for an oversized
count:

### Guard 1 — available-bytes cap (the issue's suggested fix)

Caps `$count` to the bytes available in the compound's content span. Every
AMQP element is at least 1 byte (its format code), so a `count` larger than
the available bytes is provably malformed and can never be satisfied.

```php
$available = $endPosition - $position + 1;
if ($count > $available) {
    throw new DeserializationException(sprintf(
        'List32 count %d exceeds available bytes %d',
        $count,
        $available
    ));
}
```

This rejects the **malformed count vs. size** shape immediately and with a
clear diagnostic, before any allocation — an improvement over the lazy, generic
`Unexpected end of data` the existing in-loop checks produce.

### Guard 2 — per-compound element cap (closes the honest-large-frame OOM)

The available-bytes guard alone does **not** stop the honest-large-frame OOM
(the issue's headline PoC): when `count` truthfully equals the available bytes
(8 M elements in an 8 MB frame), `count > available` is false and the loop
still runs to completion, allocating the multi-hundred-MB array. The issue
itself mentions the alternative: "or enforce a total-element budget across the
decode." A global threaded counter would be a larger, riskier change; a
per-compound cap achieves the same protection with a single constant and one
branch, and also bounds nested compounds (each level is independently capped).

```php
if ($count > self::MAX_COMPOUND_ELEMENTS) {
    throw new DeserializationException(sprintf(
        'List32 count %d exceeds maximum compound elements %d',
        $count,
        self::MAX_COMPOUND_ELEMENTS
    ));
}
```

`MAX_COMPOUND_ELEMENTS = 131072` (128 K). Rationale:

- An 8 M-element array is ~128–256 MB → fatal at `memory_limit=128M`. 128 K
  elements is ~2–4 MB of array storage — well below any OOM threshold.
- 128 K elements is generous for real AMQP 1.0 messages: application-properties
  maps and header lists are typically a few dozen entries; even pathological
  messages rarely exceed thousands.
- A flat list is depth 1, so the #397 `MAX_RECURSION_DEPTH = 32` guard does not
  apply. The element cap is the breadth analogue of the depth guard.

### Why `DeserializationException`

Per `docs/helpers/decisions.md` DEC-002, the library uses the custom exception
hierarchy from #242, not bare `\Exception`. `DeserializationException` extends
`RabbitStreamException` (`\RuntimeException`), which implements
`RabbitStreamExceptionInterface` — so callers can catch it by interface, and it
is a catchable `\RuntimeException`, never a fatal. The #397 tests assert the
same `RabbitStreamExceptionInterface` contract, which the new tests mirror.

### Why not also `readList8` / `readMap8`

The 8-bit readers (`0xc0`/`0xc1`) read `count` as `ord(...)` — capped at 255.
A 255-element array is ~hundreds of KB at worst, which cannot OOM at any sane
`memory_limit`. The available-bytes guard would be dead defensive code there,
and the element cap (131 072) is never reachable with an 8-bit count. Both
guards are skipped for the 8-bit readers, by design; the rationale is recorded
here rather than as inline comments, to avoid cluttering the 8-bit readers with
a non-local security note.

## What I rejected and why

- **A global total-element budget threaded through `decodeValue()`** (e.g. an
  `int &$elementBudget` passed to every reader, decremented per element). More
  general — it would bound the *aggregate* across all compounds in a message —
  but it is a larger, riskier change (new threaded state through every reader,
  new public-API surface or static state, and a policy number to pick). The
  per-compound cap closes the same OOM with a single constant and no signature
  changes, and nested compounds are independently bounded. A global budget can
  be added later if aggregate amplification across many small compounds becomes
  a concern.
- **Using the true content length `size - 4` instead of
  `$endPosition - $position + 1`.** More precise, but `$endPosition` is derived
  from the attacker-supplied `size`; if `size` is itself inflated, `size - 4`
  is still huge and the guard would not fire for the lying-count case — exactly
  what we must stop. Basing `available` on `$endPosition - $position + 1`
  reuses the same window the existing in-loop `position > endPosition` check
  already trusts, so the guard is consistent with the surrounding code and
  fires on the small-frame lying payload. (There is a pre-existing off-by-one
  in `$endPosition` — see findings — which makes `available` one byte looser
  than the true content; harmless for a `>` cap and documented.)
- **Capping with `>=` instead of `>`.** Would reject `count == available`,
  i.e. a list of all-1-byte elements that exactly fills the span — a legal,
  decodable payload. `>` is correct: `count` may equal the available bytes.
- **Lowering `DEFAULT_MAX_FRAME_SIZE`.** Would reduce the wire ceiling but not
  the amplification ratio (a smaller frame still amplifies ~70×), and it
  changes the negotiated frame size for all peers — a protocol-level change
  outside this issue's scope.

## Tests added

In `tests/Client/AmqpDecoderTest.php`, mirroring the #397 depth-guard style:

1. `testDecodeList32WithOversizedCountThrowsBeforeAllocating` — lying PoC
   (`size=5`, `count=8 388 608`, 1 content byte): asserts the catchable
   `DeserializationException` with the exact message, the
   `RabbitStreamExceptionInterface` contract, and that peak memory stays under
   32 MB (no allocation).
2. `testDecodeMap32WithOversizedCountThrowsBeforeAllocating` — same for map32.
3. `testDecodeList32CountWithinAvailableBytesStillDecodes` — boundary: a valid
   list32 whose `count` is within the window decodes to the right array/pos.
4. `testDecodeMap32CountWithinAvailableBytesStillDecodes` — boundary for map32.
5. `testDecodeList32CountExceedingAvailableThrows` / `testDecodeMap32…` — one
   past the available-bytes cap throws with the exact available-bytes count.
6. `testDecodeValidList32AndMap32StillDecodeIdentically` — regression: the
   pre-existing valid `testDecodeList32`/`testDecodeMap32` fixtures still
   decode byte-for-byte identically.
7. `testDecodeMessageWithOversizedList32BodyThrows` — the
   `Consumer::read()` → `decodeMessage()` exposure path via an AmqpValue
   (`0x76`) body.
8. `testDecodeList32HonestLargeFrameThrowsBeforeAllocating` — headline PoC:
   an *honest* list32 (`count=131 073`, truthful size, 131 073 null bytes)
   hits the element cap before the loop allocates; asserts the exact message
   and peak memory under 32 MB.
9. `testDecodeMap32HonestLargeFrameThrowsBeforeAllocating` — same for map32.
10. `testDecodeList32AtElementCapDecodes` — boundary: exactly
    `MAX_COMPOUND_ELEMENTS` (131 072) elements decodes without error.

## Verification

```
./vendor/bin/phpunit --testsuite unit   # 874 tests, OK (1 pre-existing risky, unrelated #454)
composer phpstan                        # level 9, 0 errors
composer cs                             # PSR-12, clean
composer lint                           # PHPCS + Rector + PHPStan + kb-lint, clean
```

## Uncertainties

- `MAX_COMPOUND_ELEMENTS = 131072` is a policy number. It is generous for real
  AMQP messages and well below any OOM threshold, but if a legitimate use case
  needs larger compounds, it would need to be raised (or made configurable, as
  #450 requests for the depth limit). 128 K is a safe default; no real message
  should approach it.
- `available` is one byte looser than the true content length due to the
  pre-existing `$endPosition` off-by-one (see findings F2). It does not weaken
  the cap for the malformed-count vector, but a future cleanup of that
  off-by-one would change the exact `available` number in the exception
  messages and in the boundary tests.
