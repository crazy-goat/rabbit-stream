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

---

## Round 2 confirmation (2025-08-18)

Reviewer: review agent (round 2). Commit reviewed: `a131038` (F4 fix).

### F4 — FIXED — confirmed

The new test `testDecodeMessageHonorsCustomMaxDepth` in
`tests/Client/AmqpDecoderTest.php:471–484` correctly:
- Calls `AmqpDecoder::decodeMessage($message, 3)` with a non-default
  `$maxDepth = 3`.
- Uses a 5-deep nested list8 body (`buildNestedList8(5)`) carried in an
  AmqpValue section (descriptor 0x76).
- Asserts `DeserializationException` is thrown.
- Asserts message contains `'AMQP recursion depth limit exceeded (max 3)'`.
- Asserts `$e instanceof RabbitStreamExceptionInterface`.

Manual depth trace confirms the exception fires at depth 4 (4 > 3 is
true), producing the "max 3" message. Test passes (1 test, 2 assertions).
**Status: fixed — no further action.**

### F1 — still present — MEDIUM — confirmed justified deferral

`readList32` (`src/Client/AmqpDecoder.php:517`) and `readMap32` (`:575`)
still use uncapped attacker-supplied 32-bit `$count`. Not introduced by
this PR, not a regression. Distinct vector (breadth, not depth). Out of
scope for #397. **Status: still present, out of scope, separate issue.**

### F2 — still present — LOW — confirmed justified deferral

`AmqpMessageDecoder::decode()` (`src/Client/AmqpMessageDecoder.php:14`)
still calls `decodeMessage()` without `$maxDepth`. The #397 acceptance
criterion (configurable at AmqpDecoder level, default ≤ 32) is met.
Threading through Consumer expands public API beyond scope. Not a
regression. **Status: still present, out of scope, separate enhancement.**

### F3 — still present — LOW — confirmed justified deferral

`readBinary32`/`readString32`/`readSymbol32`
(`src/Client/AmqpDecoder.php:397,414,428`) still use
`$position + $length > strlen($data)`. Pre-existing, not introduced by
this PR. 64-bit PHP (the norm) is unaffected. **Status: still present,
pre-existing, separate issue.**

### New issues from round-1 fix commit (a131038)

**None.** The commit modifies only `tests/Client/AmqpDecoderTest.php`
(+15 lines) and two docs files. No `src/` changes. All 650 unit tests
pass (up from 649), no existing tests broken.

### Automated checks (round 2)

| Command | Result |
|---|---|
| `composer cs` | passed |
| `composer phpstan` (level 9) | passed |
| `composer rector` (dry-run) | passed |
| `./vendor/bin/phpunit --testsuite unit` | passed (650 tests, 1404 assertions, 1 pre-existing risky) |
