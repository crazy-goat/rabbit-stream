# Review round 1 — ReadBuffer negative-length hardening (issue #384)

**Reviewer:** review-critical
**Branch:** `feature/issue-384-readbuffer-negative-length`
**Commit:** `2f1ac3e`
**Files reviewed:** `src/Buffer/ReadBuffer.php`, `tests/Buffer/ReadBufferTest.php`

This is a CRITICAL review: the diff touches wire-format / buffer parsing code
that is pre-auth reachable (`SaslHandshakeResponseV1::getStringArray()`,
`PeerPropertiesResponseV1::getObjectArray()`). The change is a security
hardening against a remote-OOM / position-backward defect.

---

## Overall verdict

**APPROVE.** The fix is correct, complete, and minimal. All three acceptance
criteria are met with evidence. Every automated gate is green. The five new
tests are strong (each fails if its corresponding guard is removed). No
high or medium findings are open. Two low/nit items are recorded as
non-blocking follow-ups.

---

## Acceptance criteria (from `gh issue view 384`)

### [MET] Negative length other than -1 throws `DeserializationException` (getString AND getBytes)

- `getString()` (`src/Buffer/ReadBuffer.php:101-106`): after the `=== -1`
  null sentinel, `if ($len < 0) throw new DeserializationException(...)`.
  Guard is placed **after** the `=== -1` check, so the null sentinel still
  returns `null` (verified by `testGetStringNull`, still green).
- `getBytes()` (`src/Buffer/ReadBuffer.php:208-213`): identical pattern with
  `$size < 0` after the `=== -1` sentinel (`testGetBytesNull` still green).
- Typed exception confirmed: `DeserializationException extends
  RabbitStreamException extends \RuntimeException` (verified in
  `src/Exception/`). Probe: `$e instanceof \RuntimeException` === true.
- Tests: `testGetStringWithNegativeLengthThrows` (0xFFFE → -2),
  `testGetBytesWithNegativeLengthThrows` (0xFFFFFFFE → -2). Both assert the
  typed exception and the exact message substring.

### [MET] Array count validated against remaining bytes before the loop (getStringArray AND getObjectArray)

- `getStringArray()` (`src/Buffer/ReadBuffer.php:181-193`): after
  `getUint32()`, `$remaining = strlen($this->buffer) - $this->position`;
  `if ($arrayLength * 2 > $remaining) throw ...` **before** the loop.
- `getObjectArray()` (`src/Buffer/ReadBuffer.php:151-163`): same with
  `$arrayLength > $remaining` (1 byte per element minimum).
- Both guards throw **before** the `for` loop, as required.
- Tests: `testGetStringArrayWithCountLargerThanRemainingThrows` (count 1000,
  5 bytes left), `testGetObjectArrayWithCountLargerThanRemainingThrows`
  (count 1000, 10 bytes left). Both assert the array-guard message.

### [MET] Unit test: `0xFFFFFFFF` count + `0xFFFE` length terminates with typed exception, not OOM

- `testGetStringArrayWithHugeCountAndNegativeLengthThrows`: buffer
  `pack('N', 0xFFFFFFFF) . pack('n', 0xFFFE) . 'ABCDEF'`.
- Executed via PHP probe: throws `DeserializationException` with
  `memDelta=0` (no memory amplification). Message:
  `Invalid string array count 4294967295 at position 4: need at least
  8589934590 bytes, but only 8 available`.
- The pre-fix code would have looped ~4 billion zero-net-byte iterations →
  OOM. The test pins the array guard's specific message, so it fails if that
  guard is removed (see check 8).

---

## The 10 checks

### 1. Acceptance criteria — all met (see above).

### 2. The `-1` sentinel path still works — CONFIRMED.

In both `getString` and `getBytes`, the `$len < 0` / `$size < 0` guard is
placed **after** the `=== -1` early return. `testGetStringNull`
(`"\xFF\xFF"` → null) and `testGetBytesNull` (`"\xFF\xFF\xFF\xFF"` → null)
both pass. Sentinel round-trip intact.

### 3. Position arithmetic in error messages — CONFIRMED CORRECT (not off-by-one).

- `getString` reports `$this->position - 2`: after `getInt16()` advances
  position by 2, `position - 2` is the offset where the corrupt length
  field **started**. Probe at non-zero position (field at offset 2) produced
  `position 2`. Correct.
- `getBytes` reports `$this->position - 4`: after `getInt32()` advances by
  4, `position - 4` is the field start. Probe (field at offset 2) produced
  `position 2`. Correct.
- Tests assert `position 0` for buffers where the field starts at offset 0.
  Matches.

Note (deliberate, documented in `code-decision-1.md`): `getString`/`getBytes`
report the **field-start** position, while `ensureAvailable` reports the
**next-read** position. The two conventions differ; both are internally
consistent and the choice is defensible (the negative-length error points at
the corrupt bytes). Not a finding.

### 4. Array bound correctness — CONFIRMED SOUND.

- `getStringArray` `* 2`: every string element is `int16 prefix (2 bytes) +
  payload (≥ 0)`. A null element is exactly 2 bytes (`\xFF\xFF`). Minimum
  per element = 2, so `count * 2 > remaining` rejects only **impossible**
  arrays. Necessary condition, never falsely rejects valid input. ✓
- `getObjectArray` `* 1`: the method is generic over
  `class-string<FromStreamBufferInterface>`; the only provable lower bound
  is 1 byte (a 0-byte consumer would infinite-loop and is itself a bug).
  `count > remaining` rejects only impossible arrays. `* 2` would wrongly
  reject a hypothetical 1-byte element class. Asymmetry is correct. ✓
  (Current concrete class `KeyValue` consumes ≥ 4 bytes, so the bound is
  loose but safe.)
- **Overflow:** `$arrayLength` is a `getUint32()` result, max 4294967295.
  `4294967295 * 2 = 8589934590 < PHP_INT_MAX (9.2E18)` on 64-bit PHP →
  stays `int`, no float coercion. Probe confirmed the message renders
  `8589934590` (integer formatting, not scientific notation). The project
  targets 64-bit (`getInt64`/`getUint64` use `'J'` which needs 64-bit for
  int results); 32-bit is not a supported target. No misbehavior. ✓

### 5. Edge cases not covered by tests — checked, all behave correctly.

Probe results (post-fix `ReadBuffer`):
- `getString` len=0 (`\x00\x00`) → returns `''`, position advances 2. ✓
  No dedicated test (pre-existing behavior, untouched by guard).
- `getBytes` size=0 (`\x00\x00\x00\x00`) → returns `''`, position advances 4.
  ✓ No dedicated test (pre-existing, untouched).
- `getStringArray` count=0 (`\x00\x00\x00\x00`) → returns `[]`, position
  advances 4. Guard `0*2 > remaining` → `0 > remaining` is false → proceeds.
  ✓ No dedicated test (pre-existing; `testGetObjectArrayEmpty` covers the
  object variant only).
- Exact issue attack vector (count=0xFFFFFFFF + len=0xFFFE) → throws with
  memDelta=0. Covered by
  `testGetStringArrayWithHugeCountAndNegativeLengthThrows`. ✓

Recorded as a low/nit finding (optional follow-up): add tests for the valid
zero-length / zero-count paths. Not a blocker — these paths are not modified
by the fix and are trivially correct.

### 6. `skip()` and `readBytes()` deliberately unguarded — scope decision ACCEPTABLE (follow-up issue).

The coder noted the same defect class in `skip()` (`:242-246`) and
`readBytes()` (`:248-254`) as out-of-scope findings #1/#2. Verified
reachability:
- `skip()`: only production caller is `src/Response/DeliverResponseV1.php:56`
  with the constant `skip(8)`. Not attacker-derived.
- `readBytes()`: callers in `src/Client/OsirisChunkParser.php` pass either a
  constant (`readBytes(3)`), a sign-bit-masked value (`$header & 0x7FFFFFFF`
  → always ≥ 0), or an unsigned `getUint32()` result (always ≥ 0 on 64-bit).
  `StreamConnection::readBytes()` is an unrelated private socket reader, not
  the buffer method.

Not attacker-reachable today. The issue's acceptance criteria do not mention
them. **Recommendation: follow-up issue** (one-line guard each,
defense-in-depth, closes the defect class for the whole `ReadBuffer`). Not a
blocker for this PR. (Informs workflow step 14.)

### 7. `ensureAvailable()` interaction — CONFIRMED CORRECT ordering.

The `$len < 0` / `$size < 0` guard throws **before** `ensureAvailable($len)`.
For a negative length, `ensureAvailable` computes `available = strlen -
position` and tests `$len > $available` → `negative > non-negative` → false
→ would pass. So `ensureAvailable` cannot catch a negative length; the guard
catches exactly what `ensureAvailable` can't. Ordering is correct.

### 8. Test quality — STRONG; no weak tests.

All five new tests use the project's existing `try { ... $this->fail() } catch
(DeserializationException $e) { assertStringContainsString(...) }` pattern
(consistent with `testUnderflowMessageContainsPosition`). Each asserts a
message substring unique to its guard:

- `testGetStringWithNegativeLengthThrows`: without the `getString` guard,
  `getString` returns a suffix and does not throw → `$this->fail()` fires →
  test fails. Pins the `getString` guard. ✓
- `testGetBytesWithNegativeLengthThrows`: same for `getBytes`. ✓
- `testGetStringArrayWithHugeCountAndNegativeLengthThrows`: asserts
  `'Invalid string array count 4294967295'`. If the **array** guard were
  removed but the `getString` guard kept, the first `getString` would throw
  `'Invalid string length -2'` (different message) → assertion fails. If both
  removed → OOM/crash. So the test specifically pins the **array** guard, not
  merely "something throws". ✓
- `testGetStringArrayWithCountLargerThanRemainingThrows`: without the array
  guard, the loop runs 2 iterations then `ensureAvailable` throws
  `'Buffer underflow...'` (different message) → assertion fails. ✓
- `testGetObjectArrayWithCountLargerThanRemainingThrows`: same logic. ✓

No test passes vacuously; each is a genuine regression guard. Message
substrings match the code's actual output (verified by probe).

### 9. Protocol correctness — CONFIRMED.

Strings are int16-length-prefixed with `-1` = null; bytes are int32-length
prefixed with `-1` = null (AGENTS.md "Protocol Reference"). The fix only adds
a negative-length rejection **after** the sentinel check; valid null (`-1`)
and valid non-negative lengths still round-trip. Array counts are `uint32`
(`getUint32`), unsigned. No protocol-layout change. Backward compatible: the
only behavior change is that malformed (negative non-sentinel) input now
throws instead of returning a garbage suffix / OOM — no correct caller is
affected. Existing `\RuntimeException`-based underflow tests still pass
because `DeserializationException` IS-A `\RuntimeException`.

### 10. Style — CLEAN.

`composer cs` (PHPCS PSR-12) green, `composer phpstan` (level 9) green,
`composer rector` (dry-run) green, `composer lint` (full: PHPCS + Rector +
PHPStan + kb-lint) green. `DeserializationException` import already present
in `ReadBuffer.php` (used by `ensureAvailable`); test-side import added, no
unused imports. Type declarations intact. Line lengths within the repo's
PHPCS threshold (the coder noted rewording to fit; gate confirms).

---

## Commands run (all green)

| Command | Result |
| --- | --- |
| `git show 2f1ac3e --stat` | 4 files, +345 (2 src/test + 2 pow docs) |
| `./vendor/bin/phpunit tests/Buffer/ReadBufferTest.php` | OK, 51 tests, 99 assertions |
| `./vendor/bin/phpunit --testsuite unit` | OK, 640 tests, 1384 assertions, 1 pre-existing Risky (`StreamConnectionTest:567`, unrelated) |
| `composer phpstan` | [OK] No errors (235 files) |
| `composer cs` | 239 files, no violations |
| `composer rector` | [OK] Rector is done (dry-run, no changes) |
| `composer lint` | green (PHPCS + Rector + PHPStan + kb-lint: 9 entries OK) |

The coder claimed all green — **confirmed**. The single `Risky: 1` is
pre-existing on `main` (documented in `findings-coder.md` #4), unrelated to
this change.

---

## Conclusion

The hardening is correct and complete for its stated scope. The two pre-loop
array guards plus the two negative-length guards close the remote-OOM vector
described in #384, including the exact attack vector (count=0xFFFFFFFF +
len=0xFFFE), verified to throw with zero memory amplification. No high or
medium findings. Recommend merging after the two low/nit follow-ups are
tracked (see `findings-review.md`).
