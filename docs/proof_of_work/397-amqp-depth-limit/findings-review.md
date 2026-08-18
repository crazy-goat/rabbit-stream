# Findings — review — issue #397 (round 1)

`findings-review.md` did not exist before this round. All entries are new
for round 1. Status for each: **open** (no earlier round to resolve them).

---

## F1 — open — MEDIUM

- **File:line:** `src/Client/AmqpDecoder.php:525-530` (readList32),
  `:583-592` (readMap32)
- **What is wrong:** The loop bound `$count` is an attacker-supplied 32-bit
  unsigned int (up to 4 billion). Each successfully decoded element is
  appended to `$list[]` / `$map[]`. A ~7.6 MB payload (list32 with
  count=8M and 8M null bytes) builds an 8-million-entry PHP array
  (~576 MB overhead), causing an uncatchable fatal OOM with
  `memory_limit=128M`. The depth limit does not protect against this
  (nesting is depth=1). Same attack surface as #397 (untrusted publisher
  → Consumer::read() → AmqpMessageDecoder::decode() → decodeMessage()),
  different vector (breadth, not depth).
- **Severity:** medium
- **What happened to it:** open for round 1 (no earlier round)
- **Resolution (main session, round 1):** NOT FIXED in this PR — out of scope for
  #397. The depth fix is correct and complete for the recursion vector. This
  is a distinct breadth-OOM vector with its own fix direction (count cap /
  element budget). Filed as a separate GitHub issue at step 14.
- **Out of scope?** Yes — separate issue.
- **Automated check that could catch this:** No off-the-shelf static
  analyzer. A memory-limited fuzzer (random list32 counts with
  `memory_limit=128M`) would find it. Not worth adding to this PR; track
  as a separate issue.
- **Smallest safe fix direction:** Cap `$count` to
  `min($count, $endPosition - $position + 1)` (each element ≥ 1 byte) or
  enforce a total-element budget.

## F2 — open — LOW

- **File:line:** `src/Client/AmqpMessageDecoder.php:14`
- **What is wrong:** `AmqpMessageDecoder::decode()` calls
  `AmqpDecoder::decodeMessage($entry->getData())` without passing
  `$maxDepth`. The actual exposure path (Consumer::read() →
  AmqpMessageDecoder::decode()) always uses the default 32. An operator
  cannot configure the limit from the Consumer level without modifying
  AmqpMessageDecoder. The issue's "configurable" criterion is met at the
  AmqpDecoder level but not at the Consumer level.
- **Severity:** low
- **What happened to it:** open for round 1
- **Resolution (main session, round 1):** NOT FIXED in this PR — the issue's
  acceptance criterion ("configurable, default <= 32") is met at the
  AmqpDecoder level, which is where the limit lives. Threading $maxDepth
  through AmqpMessageDecoder/Consumer expands the public API surface
  (Consumer::read signature) beyond issue #397's scope and would need its own
  review. Default 32 is safe. Tracked as a separate enhancement issue at
  step 14.

## F3 — open — LOW

- **File:line:** `src/Client/AmqpDecoder.php` — readBinary32 (~line 295),
  readString32 (~line 320), readSymbol32 (~line 340)
- **What is wrong:** `$position + $length > strlen($data)` — if `$length`
  is near 4 GB (attacker-supplied uint32) and PHP is 32-bit,
  `$position + $length` can overflow to a negative int, bypassing the
  bounds check. On 64-bit PHP (the norm), no overflow. Pre-existing, not
  introduced by this change.
- **Severity:** low
- **What happened to it:** open for round 1
- **Resolution (main session, round 1):** NOT FIXED in this PR — pre-existing,
  not introduced by this change, and 64-bit PHP (the practical target for
  PHP 8.1+) is unaffected. Tracked as a separate issue at step 14.

## F4 — open — NIT

- **File:line:** `tests/Client/AmqpDecoderTest.php` (new test class section)
- **What is wrong:** No test for `decodeMessage($data, $maxDepth)` with a
  non-default limit. `testDecodeValueHonorsCustomMaxDepth` tests
  `decodeValue` with custom maxDepth but not `decodeMessage`. The
  parameter is transitively tested (passes through to decodeValue), but a
  direct test would guard against future refactors.
- **Severity:** nit
- **What happened to it:** open for round 1
- **Resolution (main session, round 1):** FIXED — added
  `testDecodeMessageHonorsCustomMaxDepth` calling `decodeMessage($msg, 3)`
  with a 5-deep nested body, asserting DeserializationException with the
  'max 3' message and the RabbitStreamExceptionInterface type.

---

## Coder self-reported findings (from findings-coder.md) — status

| # | Finding | Severity | Status | Evidence |
|---|---|---|---|---|
| C1 | decodeMessage silently drops non-string Data (0x75) bodies | low | open, pre-existing, not a blocker | `src/Client/AmqpDecoder.php:155` — `if (is_string($value))` gate. Not introduced by this change. |
| C2 | Compound readers don't verify element ends within declared size | low | open, pre-existing, not a blocker | `readList8:496`, `readList32:525`, etc. — `$position > $endPosition` only checks start. Not introduced. |
| C3 | Pre-existing risky test StreamConnectionTest | nit | open, pre-existing, not a blocker | `tests/StreamConnectionTest.php:567`. Unrelated. |
| C4 | Depth check ordering (end-of-data before depth at exhausted input) | nit | not a real finding | Harmless cosmetic; both are catchable DeserializationException. |
