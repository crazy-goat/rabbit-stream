# Findings — coder — issue #397

## Obstacles / surprises during implementation

1. **The PoC crashes exactly as described, and the fix makes it a no-op.**
   Reproduced before touching code: 6 MB of `\xc0\xff\x01` exhausted 1 GB
   (`memory_limit=1G` here) with an uncatchable fatal. After the fix the same
   payload throws `DeserializationException` at depth 33 with a measured
   `memory_get_usage(true)` delta of **0 bytes** — the guard fires before any
   significant allocation, so the crash window is gone entirely.

2. **PHPStan level 9 rejects the test helper's `chr()`.** My first version of
   `buildNestedList8()` used `chr($size)` with `$size = strlen($payload) + 1`
   — PHPStan correctly flags this as `int<2, max>` outside `chr()`'s
   `int<0, 255>` contract. The codebase's existing pattern is
   `chr($size & 0xFF)` (`tests/Client/AmqpDecoderMessageTest.php:77,96,136`);
   adopted it. Not a cast-to-silence: the test builder provably emits sizes
   ≤ 100, and the mask matches the file's established style.

3. **PHPCS line-length (120) on the widened `decodeValue()` signature.**
   The single-line signature was 133 chars. Wrapped to a multi-line
   declaration — then PHPCS's
   `Squiz.Functions.MultiLineFunctionDeclaration.NewlineBeforeOpenBrace`
   demanded `): array {` on the same line. Two lint iterations to satisfy
   both sniffs; also a reminder the pre-push `composer lint` gate is strict.

4. **My first "shallow regression" test failed for a pre-existing reason,**
   not my change: `decodeMessage()` only assigns `$sections['body']` for a
   Data section (0x75) when the value **is a string**; a nested list in a
   0x75 section leaves body as `''`, so my assertion compared `''` against a
   20-deep nested array. Switched the test to an AmqpValue section (0x76),
   which is the correct AMQP carrier for a structured body. See finding 1
   below — the silent drop deserves a decision.

## Discovered bugs / places to improve (including outside scope)

### 1. `decodeMessage()` silently drops non-string Data (0x75) bodies

- **Where:** `src/Client/AmqpDecoder.php:150-158`, `case 0x75` (Data section).
- **What:** `if (is_string($value)) { $sections['body'] = ... . $value; }` —
  any non-string value in a Data section (e.g. a nested list/map, which AMQP
  mid-tier producers have been known to emit) is silently discarded and the
  consumer gets `body = ''` with no error.
- **Suggested fix:** either throw `DeserializationException` for non-string
  Data payloads (strict — spec says Data is binary), or append
  `(string)$value`-style coercion with the other sections. At minimum, a
  comment documenting why non-strings are skipped.

### 2. Compound readers never verify an element ends inside the declared size

- **Where:** `src/Client/AmqpDecoder.php` — `readList8` (line 493), `readList32` (line 522), `readMap8` (line 545), `readMap32` (line 580) — the `$endPosition` guards.
- **What:** the `endPosition` checks only test that the **start** of each
  element is ≤ `endPosition` before reading; nothing verifies the element's
  **total consumption** stays within the declared `size`. The final element
  may overrun `endPosition` as long as it stays inside the enclosing string —
  readers only bound reads by `strlen($data)`. A lying `size` field is
  therefore silently accepted. Same class of issue the depth-guard fixes, but
  orthogonal: bounded memory, imprecise validation. Note the existing tests
  themselves ship sizes 2 bytes larger than spec-correct (`"\xc0\x09\x02..."`
  has 7 content bytes with size=9), so tightening the check to spec-exact
  would break those fixtures and needs a deliberate pass.
- **Suggested fix (low priority, separate issue):** after the element loop,
  `if ($position !== $endPosition + 1) throw` — with spec-correct fixture
  sizes. This makes truncation/oversized elements fail loudly.

### 3. Pre-existing risky test: `StreamConnectionTest::testDispatchMetadataUpdateWithoutCallbackDoesNotCrash`

- **Where:** `tests/StreamConnectionTest.php:567`.
- **What:** performs no assertions; PHPUnit flags it "risky" on every run
  (baseline before this issue, unrelated to the change).
- **Suggested fix:** add `$this->addToAssertionCount(1)` (or assert the
  callback was not invoked / no exception was thrown after the dispatch).

### 4. Depth check is compare-only on scalars — fine, but note the ordering

- **Where:** `src/Client/AmqpDecoder.php`, top of `decodeValue()`.
- **What:** the guard runs after the end-of-data check, so at extreme depth
  with exhausted input the caller sees "Unexpected end of data" rather than
  the depth message. Harmless (still a catchable `DeserializationException`),
  just worth knowing when reading stack traces. No change proposed.
