# Findings — review — issue #393

## Round 3 (commit `ea85651`) — final expected

Reviewer round 3 for `feature/issue-393-readbuffer-uint64-guard` at
`ea85651df1f34a35747936a5e45d61f853017a30` ("test(buffer): cover windowed-buffer uint64 overflow message
(#393)"). Branch is exactly three commits over `main` (merge-base `cfe2b2e`):
`e1340fb` (fix), `ea78275` (docs + boundary test), `ea85651` (windowed test).
Full analysis in `review-3.md`.

Round-3 adjudication:

| ID | R2 status | **R3 status** |
|----|-----------|---------------|
| REV-393-1 | fixed | **fixed (re-verified)** |
| REV-393-2 | deferred | **still present; deferred — follow-up STILL untracked** |
| REV-393-3 | fixed | **fixed (re-verified)** |
| REV-393-4 | fixed | **fixed (re-verified)** |
| REV-393-5 | informational | **still present; informational — no action** |
| REV-393-6 | informational | **still present (pre-existing); informational — no action** |
| REV-393-7 | informational | **fixed in `ea85651`** |

New issue `REV-393-7` (windowed-buffer message coverage) is **fixed** by
`testGetUint64AbovePhpIntMaxInWindowedBufferReportsWindowPosition`
(`tests/Buffer/ReadBufferTest.php:337-350`). The test is a genuine regression
guard: a dropped window offset would read `0xaabb800000000000` and fail the
`0x8000000000000000` assertion, and an absolute position would fail
`position 0`. The unit suite went `1151/8542 → 1152/8545` (`+1` test, `+3`
assertions), confirming it runs.

Gates on HEAD `ea85651`: `composer cs` (`277 / 277 (100%)`, exit 0),
`composer phpstan` (`[OK] No errors`, exit 0), `composer rector` (dry-run,
`[OK] Rector is done!`, exit 0), `./vendor/bin/phpunit --testsuite unit`
(`OK (1152 tests, 8545 assertions)`, exit 0) and `composer lint` (all sub-gates
OK, exit 0). No new actionable issue found. Code is ready; all remaining items
are deferred/informational (REV-393-2 follow-up, REV-393-5, REV-393-6).

## Round 2 (commit `ea78275`)

Reviewer round 2 for `feature/issue-393-readbuffer-uint64-guard` at
`ea782754b9f9aadaaec715be59d3aeaa5e4969a4`. Round-1 findings are re-adjudicated
below with the round-2 status inline; full analysis in `review-2.md`. New issue
`REV-393-7` was added. Gates on HEAD: `composer cs`, `composer phpstan`,
`composer rector` (dry-run), `./vendor/bin/phpunit --testsuite unit`
(`OK (1151 tests, 8542 assertions)`) and `composer lint` all pass, exit 0.

## Round 1 (commit `e1340fb`)

Reviewer round 1 for `feature/issue-393-readbuffer-uint64-guard` at `e1340fb`.
No earlier `findings-review.md` existed, so there are no prior findings to
revisit. One entry per finding below; full analysis in `review-1.md`.

Status values: `open` (needs action), `deferred` (agreed out of scope),
`informational` (no action required).

---

## REV-393-1 — stale `getUint64()` docs

- **file:line:** `docs/en/api-reference/read-buffer.md:103-104` (also `:391-408`)
- **What is wrong:** The `getUint64()` "Throws" list still says only "buffer
  underflow (less than 8 bytes available)". After this commit the method also
  throws `DeserializationException` when the raw value exceeds `PHP_INT_MAX`
  (`uint64 value 0x… at position … exceeds PHP_INT_MAX`). The shared Error
  Handling section and its message-format list were not updated either. The
  "Returns: 0 to PHP_INT_MAX" line (`:101`) is now accurate.
- **Severity:** medium
- **Status:** **fixed (round 2), re-confirmed fixed (round 3)** —
  `docs/en/api-reference/read-buffer.md:105` (Throws bullet), `:402` (Error
  Handling condition) and `:410` (message-format block) document the
  over-`PHP_INT_MAX` throw; the documented format matches
  `src/Buffer/ReadBuffer.php:114-122` and the `Returns` line is accurate.
  `ea85651` is test-only, so no new doc drift.

## REV-393-2 — `PublishConfirmResponseV1` bypasses the guard

- **file:line:** `src/Response/PublishConfirmResponseV1.php:59`
- **What is wrong:** Ids are read with `unpack('J*', $buffer->readBytes($count * 8))`
  and never go through `getUint64()`, so publishing ids `>= 2^63` still wrap to
  negative and reach the confirm callbacks. This is the exact frame the #393
  report named, so #393's stated blast radius is not fully closed. It is a
  different file/API on the #411 performance fast path, so it belongs in a
  separate issue. The coder disclosed it in `code-decision-1.md` and
  `findings-coder.md` §1.
- **Severity:** medium
- **Status:** **deferred (round 2), still present / still untracked (round 3)**.
  Code unchanged at `src/Response/PublishConfirmResponseV1.php:59`.
  #393's acceptance criteria and "Fix" section are scoped to
  `ReadBuffer::getUint64()`, so expanding this PR is not warranted; the coder
  disclosed it. Condition: the main session must file a dedicated follow-up issue
  so the gap named by the issue is not lost. Round 3 re-check:
  `gh issue list --search "PublishConfirm" --state all` still returns only #393
  (no dedicated follow-up issue). This is the one item that must not be lost at
  merge — file the issue and link it from #393's closing comment.

## REV-393-3 — missing `PHP_INT_MAX + 1` boundary test

- **file:line:** `tests/Buffer/ReadBufferTest.php:321-325`
- **What is wrong:** `testGetUint64WithMaxRepresentableValue` covers
  `PHP_INT_MAX` (must not throw) and the throw test uses `0xFF×8`, but the
  exact first unrepresentable value, `PHP_INT_MAX + 1` = `0x8000000000000000`
  (`PHP_INT_MIN`) is not exercised. Since `< 0` is precisely the
  `PHP_INT_MAX`/`PHP_INT_MAX+1` split, a one-line case would pin it. The
  all-ones case already pins the `-1` end, so this is coverage polish.
- **Severity:** low
- **Status:** **fixed (round 2), re-confirmed fixed (round 3)** —
  `tests/Buffer/ReadBufferTest.php:327-335`
  (`testGetUint64WithPhpIntMaxPlusOneThrows`) feeds
  `"\x80\x00\x00\x00\x00\x00\x00\x00"` (`PHP_INT_MAX + 1` =
  `0x8000000000000000`) and asserts `DeserializationException` with
  `0x8000000000000000`. Still passing on `ea85651`; together with the
  `PHP_INT_MAX` and `0xFF×8` cases it pins the `< 0` predicate on both sides.

## REV-393-4 — missing CHANGELOG entry

- **file:line:** `CHANGELOG.md:14` (`[Unreleased]` → `### Fixed`)
- **What is wrong:** No `#393` entry was added. `AGENTS.md` "After Merging a
  Feature Branch" step 3 requires it; the task notes the main session handles
  step 8, so this is flagged only so it is not lost.
- **Severity:** low
- **Status:** **fixed (round 2), re-confirmed fixed (round 3)** —
  `CHANGELOG.md:15` has the `#393` bullet at the top of
  `[Unreleased] → ### Fixed`, accurately stating the pre-fix wrap, the new typed
  rejection with raw bytes + position, and that `getInt64()` is unchanged.

## REV-393-5 — discarded chunk `epoch` can now reject a frame

- **file:line:** `src/Client/OsirisChunkParser.php:325`
- **What is wrong:** `$buffer->getUint64(); // epoch` reads and discards the
  epoch, so it now inherits the guard: a bogus high-bit epoch aborts chunk
  parsing even though `chunkFirstOffset` (`:326`) is the field that matters.
  The epoch is a small running number in practice and failing closed on a
  malformed header matches the issue's hardening intent, so this is
  informational. If a narrower failure surface is wanted, read it with
  `getInt64()` instead.
- **Severity:** low
- **Status:** informational (round 2: still present; `OsirisChunkParser.php:325`
  unchanged in round 3, no action for #393)

## REV-393-6 — pre-existing dead branch in `getInt64()`

- **file:line:** `src/Buffer/ReadBuffer.php:135`
- **What is wrong:** `$data[1] >= 0x8000000000000000` compares an int against a
  float literal, so the subtraction never runs. `unpack('J')` already returns
  the two's-complement signed value, so behavior is correct; the branch is dead.
  Pre-existing, and the issue explicitly requires `getInt64()` to stay
  unchanged.
- **Severity:** nit
- **Status:** **still present, informational (round 2 and round 3)** —
  `ReadBuffer.php:135` unchanged (dead `>= 0x8000000000000000` branch, float
  literal/64-bit). The issue requires `getInt64()` to stay unchanged; correctly
  untouched. Not a #393 action.

## REV-393-7 — no windowed-buffer test for the guard message (new in round 2)

- **file:line:** `tests/Buffer/ReadBufferTest.php:306-335`
- **What is wrong:** The guard's raw-byte message uses
  `bin2hex(substr($this->buffer, $this->offset + $this->position, 8))`
  (`src/Buffer/ReadBuffer.php:118`), but every new test constructs a whole-buffer
  `ReadBuffer` (`offset === 0`), so the `+ $this->offset` term is never exercised
  by the suite. Re-verified empirically on HEAD: a windowed
  `ReadBuffer("\xAA\xBB\x80\x00\x00\x00\x00\x00\x00\x00", 2, 8)` throws with the
  correct bytes (`0x8000000000000000`) and `position 0`, so this is a coverage
  gap, not a defect.
- **Severity:** low (informational)
- **Status:** **fixed (round 3, `ea85651`)** —
  `tests/Buffer/ReadBufferTest.php:337-350`
  (`testGetUint64AbovePhpIntMaxInWindowedBufferReportsWindowPosition`) constructs
  `new ReadBuffer("\xAA\xBB\x80\x00\x00\x00\x00\x00\x00\x00", 2, 8)` and asserts
  the message contains `0x8000000000000000` and `position 0`, plus
  `getPosition() === 0`. A dropped `$this->offset` would read
  `0xaabb800000000000` and fail; an absolute position would report `2` and fail.
  Genuine regression guard; unit suite `1151/8542 → 1152/8545`.

---

## Not findings (confirmed correct)

- Guard predicate `$data[1] < 0` is exact for the `2^63` wrap; verified against
  `PHP_INT_MAX`, `PHP_INT_MAX+1`, and `0xFF×8`.
- Cursor does not advance on throw (guard precedes `position += 8`); verified on
  a non-zero-position windowed buffer.
- Correct exception class (`DeserializationException`), no bare `\Exception`
  (DEC-002), message shape consistent with siblings.
- `getInt64()` unchanged and still two's-complement; `testGetInt64Negative`
  still asserts `-1`.
- Test genuinely fails against pre-fix code: pre-fix returned `-1`, so
  `$this->fail()` raises `AssertionFailedError`, which the
  `catch (DeserializationException)` does not swallow.
- All `src/` callers treat the result as a non-negative offset/sequence; none
  relies on the wrapped value. NO_OFFSET is a response code, checked before the
  `getUint64()` read, so no sentinel is lost.
- `composer cs`, `composer phpstan`, `composer rector` (dry-run),
  `./vendor/bin/phpunit --testsuite unit` and `composer lint` all pass on the
  clean tree at `ea78275` (`OK (1151 tests, 8542 assertions)`, exit 0 each).
