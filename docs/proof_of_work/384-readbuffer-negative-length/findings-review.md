# Findings — review — issue #384

Round 1. One entry per finding. Severities: high, medium, low, nit.
All findings here are non-blocking follow-ups; no high/medium open.

### [low] `skip()` accepts negative byte counts — same backward-position defect class as #384
- file: `src/Buffer/ReadBuffer.php:242-246`
- what is wrong: `skip(int $bytes)` calls `ensureAvailable($bytes)` (a negative value always passes, `-N < available`) then `$this->position += $bytes`, moving position backward — identical defect shape to the #384 pre-fix `getString`/`getBytes`. Not attacker-reachable today: the only production caller is `src/Response/DeliverResponseV1.php:56` with the constant `skip(8)` (verified by grep). Out of scope for #384 (acceptance criteria do not mention it); recorded by the coder in `findings-coder.md` #1.
- severity: low
- status: open (follow-up issue recommended, NOT a blocker for this PR — not reachable today) — **disposition: follow-up issue.** Acceptable scope decision: the issue's acceptance criteria do not mention `skip()`, and the only caller passes a constant `8`. Will be filed as a follow-up GitHub issue in step 14 to close the negative-length defect class for the whole `ReadBuffer`.
- which automated check could have caught this: none — manual review (a static taint/overflow analysis tying caller-provided ints to `skip`/`readBytes` could catch it, but no such tool is configured here)

### [low] `readBytes()` accepts negative lengths — same backward-position defect class as #384
- file: `src/Buffer/ReadBuffer.php:248-254`
- what is wrong: `readBytes(int $length)` with a negative `$length` passes `ensureAvailable`, returns a near-full-buffer suffix via `substr($buffer, $position, -N)`, and moves `position` backward. Not attacker-reachable today: all callers pass constants or unsigned/masked values (`OsirisChunkParser.php:73` constant `3`; `:84` `$header & 0x7FFFFFFF` → sign bit masked, always ≥ 0; `:100`,`:105` `getUint32()` results → unsigned on 64-bit). Recorded by the coder in `findings-coder.md` #2.
- severity: low
- status: open (follow-up issue recommended, NOT a blocker — not reachable today) — **disposition: follow-up issue.** Same as finding 1: not reachable today (all callers pass constants or unsigned/masked values), out of scope for #384. Folded into the same follow-up issue as finding 1.
- which automated check could have caught this: none — manual review

### [nit] Missing tests for valid zero-length / zero-count paths
- file: `tests/Buffer/ReadBufferTest.php`
- what is wrong: no dedicated test for `getString` len=0, `getBytes` size=0, or `getStringArray` count=0 (`testGetObjectArrayEmpty` covers only the object-array count=0 case). These paths are pre-existing, untouched by the fix, and verified correct by probe (len=0 → `''` + position advance; count=0 → `[]` + advance past count). Adding them would guard against a future regression that breaks the valid empty-value path.
- severity: nit
- status: open (optional follow-up, non-blocking) — **disposition: FIXED in this PR.** Added `testGetStringEmpty`, `testGetBytesEmpty`, and `testGetStringArrayEmpty` mirroring the existing `testGetObjectArrayEmpty` style (assert value + position advance). Cheap coverage that pins the valid zero-length/zero-count paths so a future regression (e.g. someone "fixing" a guard to reject 0) is caught.
- which automated check could have caught this: none — test-coverage gap (no coverage gate is configured in this project per `docs/workflow.md`)

### [nit] Inconsistent position convention between scalar guards and array guards
- file: `src/Buffer/ReadBuffer.php:101-106,208-213` (scalar: `position - 2`/`position - 4`, field-start) vs `:151-163,181-193` (array: `$this->position`, post-count field)
- what is wrong: `getString`/`getBytes` negative-length errors report the position where the offending length field **started** (`position - N`), but `getStringArray`/`getObjectArray` count errors report the **current** position (after `getUint32` consumed the count field, i.e. the array-body start, not the count-field start). Two conventions in the same change. Diagnostically the array messages are still sufficient (they include the count value and "need at least N bytes"), so this is cosmetic. The scalar convention is documented as deliberate in `code-decision-1.md`; the array convention was not explicitly justified.
- severity: nit
- status: open (optional: use `position - 4` in the array guards for consistency, or document the convention) — **disposition: deliberately not fixed.** Aligning the array guards to report the count-field start would require saving the position before `getUint32()` (an extra line + local per guard) for purely cosmetic consistency. The current array messages already include the offending count value and the "need at least N bytes, but only M available" breakdown, which is sufficient for diagnosis. Not worth the extra code; the scalar `position - N` convention remains documented in `code-decision-1.md`.
- which automated check could have caught this: none — manual review (a message-format consistency lint is not realistic here)
