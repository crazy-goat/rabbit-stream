# Review — Round 1 — AmqpDecoder recursion depth limit (issue #397)

**Reviewer:** review-critical (deep review agent)
**Branch:** `feature/issue-397-amqp-depth-limit`
**Commit:** `8bcc2f8`
**Date:** 2025-08-18

---

## Overall verdict: **approve** (with one out-of-scope security flag)

The fix correctly and completely addresses issue #397 (unbounded recursion in
`AmqpDecoder::decodeValue()`). Every recursive path is covered by a single
choke-point guard, the boundary semantics are correct (depth==32 ok,
depth==33 throws), the public API is preserved, the exception is catchable
via `RabbitStreamExceptionInterface`, and the PoC test proves bounded memory
(0 MB peak delta with `memory_limit=128M`). All automated checks pass
(PHPCS, PHPStan level 9, Rector dry-run, 649 unit tests).

One **medium** finding is out of scope for this PR but shares the same
attack surface: a flat (breadth) allocation OOM in `readList32`/`readMap32`
that the depth limit does not protect against. Flag it as a separate issue.

---

## Previous-round findings

`findings-review.md` did not exist before this round — this is round 1.
All findings below are new.

The coder self-reported four findings in `findings-coder.md`; their status:

| # | Coder finding | Status | Evidence |
|---|---|---|---|
| C1 | `decodeMessage` silently drops non-string Data (0x75) bodies | **Open, pre-existing, not a blocker** | `src/Client/AmqpDecoder.php:155` — `if (is_string($value))` gate. Not introduced by this change. Separate issue. |
| C2 | Compound readers don't verify element ends within declared size | **Open, pre-existing, not a blocker** | `readList8:496`, `readList32:525`, etc. — `$position > $endPosition` only checks start. Not introduced by this change. Separate issue. |
| C3 | Pre-existing risky test `StreamConnectionTest` | **Open, pre-existing, not a blocker** | `tests/StreamConnectionTest.php:567`. Unrelated to this PR. |
| C4 | Depth check ordering (end-of-data fires before depth at exhausted input) | **Not a real finding** | Harmless cosmetic; both are catchable `DeserializationException`. |

---

## Detailed review against the 8 check items

### 1. Correctness of depth accounting — every recursive path increments depth

**Result: PASS.** There is exactly one recursive entry point: `decodeValue()`.
The guard `if ($depth > $maxDepth) throw ...` sits at the top of
`decodeValue()` (line 30), before the format-code match. Every recursive
call passes `$depth + 1`. Enumerated:

| Path | Call site (line) | Depth arg | Covered |
|---|---|---|---|
| list8 element | `readList8:500` | `$depth + 1` | ✓ |
| list32 element | `readList32:529` | `$depth + 1` | ✓ |
| map8 key | `readMap8:553` | `$depth + 1` | ✓ |
| map8 value | `readMap8:557` | `$depth + 1` | ✓ |
| map32 key | `readMap32:588` | `$depth + 1` | ✓ |
| map32 value | `readMap32:592` | `$depth + 1` | ✓ |
| described descriptor (0x00 path) | `readDescribedType:605` | `$depth + 1` | ✓ |
| described value (0x00 path) | `readDescribedType:606` | `$depth + 1` | ✓ |
| described descriptor (decodeMessage path) | `readDescribedTypeWithPosition:621` | `$depth + 1` | ✓ |
| described value (decodeMessage path) | `readDescribedTypeWithPosition:624` | `$depth + 1` | ✓ |

The 0x00 format-code arm (line 81) calls `readDescribedType($depth, $maxDepth)`,
which increments to `$depth + 1` for both children. The `decodeMessage` while
loop (line 125) calls `readDescribedTypeWithPosition($data, $position, 0, $maxDepth)`,
starting each section at depth 0 (children at depth 1). No path bypasses the
guard. No other function in this class recurses (`parsePropertiesList` iterates
a flat, already-decoded array).

### 2. Public API preserved

**Result: PASS.**
- `decodeValue(string, int)` → now `decodeValue(string, int, int $depth = 0, int $maxDepth = 32)`.
  All existing 2-arg callers work unchanged.
- `decodeMessage(string)` → now `decodeMessage(string, int $maxDepth = 32)`.
  All existing 1-arg callers work unchanged.
- Verified callers: `AmqpMessageDecoder::decode()` (1-arg), all
  `AmqpDecoderMessageTest` calls (1-arg), all pre-existing `AmqpDecoderTest`
  calls (2-arg). No breakage.

### 3. Limit default ≤ 32 and configurable

**Result: PASS.** `private const MAX_RECURSION_DEPTH = 32` (line 11). Both
`decodeValue` and `decodeMessage` accept an optional `$maxDepth` parameter
with this default. An operator can pass a custom limit without editing source.

### 4. Exception type is `DeserializationException` (catchable via `RabbitStreamExceptionInterface`)

**Result: PASS.** Hierarchy verified:
`DeserializationException` → `RabbitStreamException` → `\RuntimeException`
implements `RabbitStreamExceptionInterface` (extends `\Throwable`). The thrown
exception (line 31) is `DeserializationException`, not a generic `\Exception`.
The test asserts `assertInstanceOf(RabbitStreamExceptionInterface::class, $e)`.
Standalone PoC confirms `instanceof RabbitStreamExceptionInterface` is `true`.

### 5. Off-by-one: depth == limit decodes, depth == limit + 1 throws

**Result: PASS.** The guard uses `>` (not `>=`): `if ($depth > $maxDepth)`.
- `buildNestedList8(32)` → innermost null decoded at depth 32 → `32 > 32`
  is false → decodes. Test `testDecodeNestedListsAtDepthLimit` asserts the
  full nested value matches. ✓
- `buildNestedList8(33)` → innermost list at depth 33 → `33 > 32` is true
  → throws. Test `testDecodeNestedListsBeyondDepthLimitThrows` asserts the
  exception. ✓

Traced manually: 32 nested lists with null at center → the null's
`decodeValue(depth=32)` passes; 33 nested lists → the 33rd list's
`decodeValue(depth=33)` throws before reading the format code.

### 6. PoC test proves bounded memory

**Result: PASS.** `testDecodeDeeplyNestedPoCPayloadThrowsCatchableException`
uses the exact issue payload (`str_repeat("\xc0\xff\x01", 2_000_000)` = 6 MB),
catches `DeserializationException`, asserts the message, asserts
`RabbitStreamExceptionInterface`, and asserts peak memory delta < 32 MB.

Standalone verification with `memory_limit=128M`:
```
Exception: DeserializationException
Implements RabbitStreamExceptionInterface: yes
Message: AMQP recursion depth limit exceeded (max 32)
Peak memory delta: 0 MB
```
The guard fires at depth 33 (33 stack frames, 33 empty `$list = []` arrays)
before any significant allocation. The crash window is gone.

### 7. Other unbounded-recursion or unbounded-allocation paths

**Result: one MEDIUM finding (out of scope, separate issue).**

- **Unbounded recursion**: No other recursive path exists. The depth guard
  covers all compound/described types. ✓

- **Unbounded allocation (breadth)**: `readList32` (line 525) and
  `readMap32` (line 583) use an attacker-supplied 32-bit `count` (up to
  4 billion) as the loop bound. The `$position > $endPosition` check and
  `strlen($data)` bounds limit the number of *successfully decoded*
  elements, but each decoded element is appended to a PHP array
  (`$list[] = $value` at line 530). A ~7.6 MB payload of `\xd0` + 8M null
  bytes builds an 8-million-entry PHP array (~576 MB of array overhead),
  causing an uncatchable fatal OOM with `memory_limit=128M`. **Verified
  with a PoC.** This is the same attack surface as #397 (untrusted
  publisher → Consumer::read()) but a different vector (breadth, not
  depth). The depth limit does not protect against it. See finding F1.

- **readBinary32/readString32/readSymbol32 with 4 GB length**: The
  `$position + $length > strlen($data)` check (lines ~300, ~325, ~345)
  bounds the `substr` to the actual data length on 64-bit PHP. On 32-bit
  PHP, `$position + $length` could overflow to a negative int, bypassing
  the check — but PHP 8.1+ on 32-bit is rare, and this is pre-existing.
  See finding F3 (low).

### 8. Test coverage

**Result: PASS (with one nit).**

| Required coverage | Test | Present |
|---|---|---|
| PoC payload (catchable, bounded memory) | `testDecodeDeeplyNestedPoCPayloadThrowsCatchableException` | ✓ |
| Boundary: 32 ok | `testDecodeNestedListsAtDepthLimit` | ✓ |
| Boundary: 33 throws | `testDecodeNestedListsBeyondDepthLimitThrows` | ✓ |
| `decodeMessage` exposure path | `testDecodeMessageWithDeeplyNestedBodyThrows` | ✓ |
| Custom `maxDepth` | `testDecodeValueHonorsCustomMaxDepth` | ✓ (decodeValue only) |
| Shallow regression | `testDecodeMessageWithShallowNestedBodyStillDecodes` | ✓ |

**Nit (F4):** No test for `decodeMessage($data, $maxDepth)` with a custom
`maxDepth`. The parameter is transitively tested (it passes through to
`decodeValue`), but a direct test would be more robust.

---

## Findings

### F1 — MEDIUM — Flat-allocation (breadth) OOM in readList32/readMap32
- **File:** `src/Client/AmqpDecoder.php:525-530` (readList32), `:583-592` (readMap32)
- **What:** The loop bound `$count` is an attacker-supplied 32-bit unsigned
  int (up to 4 billion). Each successfully decoded element is appended to a
  PHP array (`$list[] = $value`). A ~7.6 MB payload (list32 with count=8M
  and 8M null elements) builds an 8-million-entry array, causing an
  uncatchable fatal OOM with `memory_limit=128M`. The depth limit does not
  protect against this — the nesting is depth=1, well under 32.
- **Impact:** Same attack surface as #397 (untrusted publisher →
  Consumer::read() → AmqpMessageDecoder::decode() → decodeMessage()).
  Uncatchable fatal kills the worker process.
- **Evidence:** PoC run with `memory_limit=128M`:
  `PHP Fatal error: Allowed memory size of 134217728 bytes exhausted
  ... in src/Client/AmqpDecoder.php on line 530`
- **Out of scope?** Yes — this is breadth, not depth. The fix for #397 is
  correct and complete for its scope. This should be a separate issue.
- **Smallest safe fix direction:** Cap `$count` to a maximum proportional
  to remaining data (e.g., `$count = min($count, $endPosition - $position + 1)`
  since each element consumes ≥ 1 byte) or enforce a total-element budget.
- **Automated check that could catch this:** No off-the-shelf static analyzer.
  A memory-limited fuzzer (e.g., injecting random list32 counts with
  `memory_limit=128M`) would find it. A custom PHPStan rule tracking
  attacker-controlled loop bounds would also catch it. Not worth adding to
  this PR; track as a separate issue.

### F2 — LOW — Configurability not threaded to AmqpMessageDecoder / Consumer
- **File:** `src/Client/AmqpMessageDecoder.php:14`
- **What:** `AmqpMessageDecoder::decode()` calls
  `AmqpDecoder::decodeMessage($entry->getData())` without passing
  `$maxDepth`. The actual exposure path (`Consumer::read()` →
  `AmqpMessageDecoder::decode()`) always uses the default 32. An operator
  cannot configure the limit from the Consumer level without modifying
  `AmqpMessageDecoder`.
- **Impact:** The issue's "configurable" criterion is met at the
  `AmqpDecoder` level but not at the `Consumer` level. The default (32) is
  safe, so this is a design limitation, not a security bug.
- **Smallest safe fix direction:** Add an optional `int $maxDepth = 32`
  param to `AmqpMessageDecoder::decode()`/`decodeAll()` and thread it to
  `Consumer` if operator configurability is desired. Not a blocker for #397.
- **Automated check:** None. Design review.

### F3 — LOW — Potential integer overflow in readBinary32/readString32 on 32-bit PHP
- **File:** `src/Client/AmqpDecoder.php` — `readBinary32` (~line 295),
  `readString32` (~line 320), `readSymbol32` (~line 340)
- **What:** `$position + $length > strlen($data)` — if `$length` is near
  4 GB (attacker-supplied uint32) and PHP is 32-bit, `$position + $length`
  can overflow to a negative int, bypassing the bounds check.
- **Impact:** On 64-bit PHP (the norm), no issue — 64-bit ints don't
  overflow. On 32-bit PHP 8.1+ (rare), the check could be bypassed.
  Pre-existing, not introduced by this change.
- **Smallest safe fix direction:** Compare `$length > strlen($data) - $position`
  instead of `$position + $length > strlen($data)` to avoid the addition.
- **Automated check:** PHPStan on a 32-bit target could flag this, but the
  project targets 64-bit. An integer-range tracking analyzer would catch it.

### F4 — NIT — No test for decodeMessage with custom maxDepth
- **File:** `tests/Client/AmqpDecoderTest.php`
- **What:** `testDecodeValueHonorsCustomMaxDepth` tests `decodeValue` with
  a custom `$maxDepth`, but no test exercises `decodeMessage($data, $maxDepth)`
  with a non-default limit. The parameter is transitively tested (it passes
  through to `decodeValue`), but a direct test would guard against future
  refactors that break the pass-through.
- **Smallest safe fix direction:** Add a test calling
  `decodeMessage($message, 3)` with a 5-deep nested body and asserting the
  exception.
- **Automated check:** A parameter-coverage tool could flag the untested
  argument, but no standard CI tool does this.

---

## High-risk areas checked clean

| Area | Status | Notes |
|---|---|---|
| Every recursive path increments depth | ✓ clean | All 10 call sites verified (see item 1) |
| No recursive path bypasses the guard | ✓ clean | Single choke-point in decodeValue |
| Public API backward compatible | ✓ clean | Optional params with safe defaults |
| Exception is catchable (not fatal) | ✓ clean | DeserializationException → RabbitStreamExceptionInterface |
| Off-by-one boundary | ✓ clean | depth==32 ok, depth==33 throws (verified by trace + tests) |
| PoC payload bounded memory | ✓ clean | 0 MB peak delta with 128M limit |
| decodeMessage exposure path covered | ✓ clean | readDescribedTypeWithPosition passes depth+1 |
| Described type descriptor and value both incremented | ✓ clean | Both get $depth+1 in both readers |
| Map key and value both incremented | ✓ clean | Both get $depth+1 in readMap8 and readMap32 |
| Shallow messages still decode | ✓ clean | 20-level nested body decodes correctly |
| PHPCS / PHPStan / Rector / PHPUnit | ✓ clean | All pass (649 tests, 1 pre-existing risky) |

---

## Automated checks run

| Command | Result |
|---|---|
| `composer cs` | passed |
| `composer phpstan` (level 9) | passed |
| `composer rector` (dry-run) | passed |
| `./vendor/bin/phpunit --testsuite unit` | passed (649 tests, 1402 assertions, 1 pre-existing risky) |
| Standalone PoC with `memory_limit=128M` | passed (catchable exception, 0 MB peak delta) |
| Flat-allocation PoC with `memory_limit=128M` | **fatal OOM** (finding F1, out of scope) |
