# Review — Round 2 — AmqpDecoder recursion depth limit (issue #397)

**Reviewer:** review agent (round 2)
**Branch:** `feature/issue-397-amqp-depth-limit`
**Commits reviewed:** `8bcc2f8` (core fix, round 1), `a131038` (F4 fix, round 1 → 2)
**Date:** 2025-08-18

---

## Overall verdict: **approve**

Round 1 approved with 4 findings (F1–F4). The main session resolved F4
(the only actionable nit) and deferred F1/F2/F3 as out-of-scope or
pre-existing. Round 2 confirms:

- **F4 is correctly fixed** — the new test exercises the exact path,
  passes, and asserts the right exception/message/interface.
- **F1/F2/F3 deferrals are justified** — none are regressions, none are
  introduced by this PR, none block #397's acceptance criteria.
- **No new issues** introduced by the round-1 fix commit (`a131038`).
- **All automated checks pass** — PHPCS, PHPStan level 9, Rector dry-run,
  650 unit tests (1404 assertions, 1 pre-existing risky).

---

## Prior-round findings — status confirmation

### F4 — NIT — No test for decodeMessage with custom maxDepth → **FIXED**

**Evidence:** Commit `a131038` adds `testDecodeMessageHonorsCustomMaxDepth`
to `tests/Client/AmqpDecoderTest.php` (lines 471–484).

The test:
1. Constructs a message: `"\x00\x53\x76" . $this->buildNestedList8(5)` —
   a described type with descriptor 0x76 (AmqpValue section) and a 5-deep
   nested list8 body.
2. Calls `AmqpDecoder::decodeMessage($message, 3)` — the second arg is
   the custom `$maxDepth = 3`.
3. Expects `DeserializationException` with message containing
   `'AMQP recursion depth limit exceeded (max 3)'`.
4. Asserts `$e instanceof RabbitStreamExceptionInterface`.

**Depth trace (verified manually):**
- `decodeMessage($msg, 3)` → `readDescribedTypeWithPosition($data, $pos, 0, 3)`
- Descriptor (0x53→0x76) decoded at depth 1 — passes (1 ≤ 3).
- Value (outermost list8) enters `decodeValue` at depth 1 — passes (1 ≤ 3).
- Level 2 list at depth 2 — passes (2 ≤ 3).
- Level 3 list at depth 3 — passes (3 > 3 is false).
- Level 4 list at depth 4 — **throws** (4 > 3 is true).

The exception fires at depth 4 with "max 3" in the message, exactly as
asserted. The test comment ("Section value enters at depth 1, so depth 4
is reached and the limit of 3 is exceeded") is accurate.

**Test execution:**
```
./vendor/bin/phpunit --filter testDecodeMessageHonorsCustomMaxDepth
OK (1 test, 2 assertions)
```

**Verdict: correctly fixed.** The test directly exercises
`decodeMessage($msg, $maxDepth)` with a non-default limit, asserts the
exception type, message content, and interface — all three assertions
are meaningful and correct.

### F1 — MEDIUM — Breadth OOM in readList32/readMap32 → **still present, justified deferral**

**Evidence:** `src/Client/AmqpDecoder.php:517` (readList32) and `:575`
(readMap32) still use an uncapped attacker-supplied 32-bit `$count` as
the loop bound. No cap was added in either commit.

**Justification for deferral:** This is a distinct attack vector (breadth
allocation, not recursion depth). The depth limit added by #397 does not
and should not protect against it — the nesting is depth=1, well under 32.
The fix direction (count cap / element budget) is independent of the
recursion guard. Not introduced by this PR, not a regression, not a
blocker for #397's acceptance criteria ("enforce recursion depth limit,
default ≤ 32, configurable, catchable exception"). Filing as a separate
issue is appropriate.

**Verdict: still present, out of scope, justified.**

### F2 — LOW — $maxDepth not threaded from AmqpMessageDecoder/Consumer → **still present, justified deferral**

**Evidence:** `src/Client/AmqpMessageDecoder.php:14` still calls
`AmqpDecoder::decodeMessage($entry->getData())` without passing
`$maxDepth`. The default (32) is always used in the production path.

**Justification for deferral:** Issue #397's acceptance criterion
("configurable, default ≤ 32") is met at the `AmqpDecoder` level — the
parameter exists and accepts a custom value. Threading it through
`AmqpMessageDecoder::decode()` and `Consumer::read()` would expand the
public API surface (new constructor params or method signatures) beyond
#397's scope. The default (32) is safe for all current callers. Not a
regression, not introduced by this PR, not a blocker.

**Verdict: still present, out of scope, justified.**

### F3 — LOW — 32-bit integer overflow in readBinary32/readString32/readSymbol32 → **still present, justified deferral**

**Evidence:** `src/Client/AmqpDecoder.php` lines 397, 414, 428, 445, 459,
476 still use the pattern `$position + $length > strlen($data)`. On 32-bit
PHP, `$position + $length` can overflow to a negative int when `$length`
is near 4 GB (attacker-supplied uint32), bypassing the bounds check.

**Justification for deferral:** Pre-existing code, not introduced by this
PR. PHP 8.1+ on 32-bit is extremely rare (64-bit is the norm and the
practical target). On 64-bit PHP, 64-bit integers cannot overflow here.
Not a regression, not a blocker for #397.

**Verdict: still present, pre-existing, justified.**

---

## Coder self-reported findings — status (unchanged from round 1)

| # | Finding | Severity | Round 2 status | Evidence |
|---|---|---|---|---|
| C1 | decodeMessage silently drops non-string Data (0x75) bodies | low | still present, pre-existing, not a blocker | `src/Client/AmqpDecoder.php:155` — `if (is_string($value))` gate. Not introduced by this change. |
| C2 | Compound readers don't verify element ends within declared size | low | still present, pre-existing, not a blocker | `readList8:497`, `readList32:526`, etc. — `$position > $endPosition` only checks start. Not introduced. |
| C3 | Pre-existing risky test StreamConnectionTest | nit | still present, pre-existing, not a blocker | `tests/StreamConnectionTest.php:567`. Unrelated. Confirmed: same risky test in round 2 run. |
| C4 | Depth check ordering (end-of-data before depth at exhausted input) | nit | not a real finding | Harmless cosmetic; both are catchable DeserializationException. |

---

## New issues introduced by the round-1 fix commit (a131038)

**None.**

The fix commit (`a131038`) changes exactly 3 files:
- `tests/Client/AmqpDecoderTest.php` (+15 lines — the new test method)
- `docs/proof_of_work/397-amqp-depth-limit/findings-review.md` (new doc)
- `docs/proof_of_work/397-amqp-depth-limit/review-1.md` (new doc)

No `src/` files were touched. The test method is syntactically and
semantically correct (verified by execution and manual trace). No
existing tests were modified or broken (test count increased from 649
to 650, all pass).

---

## Automated checks run (round 2)

| Command | Result | Notes |
|---|---|---|
| `composer cs` | passed | 239 sniff files, no violations |
| `composer phpstan` (level 9) | passed | 235 files, no errors |
| `composer rector` (dry-run) | passed | 2 files, no changes |
| `./vendor/bin/phpunit --testsuite unit` | passed | 650 tests, 1404 assertions, 1 pre-existing risky |
| `--filter testDecodeMessageHonorsCustomMaxDepth` | passed | 1 test, 2 assertions |

---

## High-risk areas re-verified clean

| Area | Status | Notes |
|---|---|---|
| F4 test exercises the right path | ✓ clean | decodeMessage($msg, 3) → depth 4 → throws "max 3" |
| F4 test asserts exception type + interface | ✓ clean | DeserializationException + RabbitStreamExceptionInterface |
| No src/ changes in fix commit | ✓ clean | Only test + docs files modified |
| Existing tests unbroken | ✓ clean | 650 pass (up from 649), same 1 pre-existing risky |
| PHPCS / PHPStan / Rector | ✓ clean | All pass |
| F1/F2/F3 not regressions | ✓ clean | All pre-existing or out-of-scope, confirmed still present |
