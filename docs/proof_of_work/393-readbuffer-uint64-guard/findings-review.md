# Findings — review — issue #393 (round 1)

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
- **Status:** open

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
- **Status:** deferred (separate follow-up issue recommended)

## REV-393-3 — missing `PHP_INT_MAX + 1` boundary test

- **file:line:** `tests/Buffer/ReadBufferTest.php:321-325`
- **What is wrong:** `testGetUint64WithMaxRepresentableValue` covers
  `PHP_INT_MAX` (must not throw) and the throw test uses `0xFF×8`, but the
  exact first unrepresentable value, `PHP_INT_MAX + 1` = `0x8000000000000000`
  (`PHP_INT_MIN`) is not exercised. Since `< 0` is precisely the
  `PHP_INT_MAX`/`PHP_INT_MAX+1` split, a one-line case would pin it. The
  all-ones case already pins the `-1` end, so this is coverage polish.
- **Severity:** low
- **Status:** open

## REV-393-4 — missing CHANGELOG entry

- **file:line:** `CHANGELOG.md:14` (`[Unreleased]` → `### Fixed`)
- **What is wrong:** No `#393` entry was added. `AGENTS.md` "After Merging a
  Feature Branch" step 3 requires it; the task notes the main session handles
  step 8, so this is flagged only so it is not lost.
- **Severity:** low
- **Status:** open (expected to be handled by the main session at merge)

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
- **Status:** informational

## REV-393-6 — pre-existing dead branch in `getInt64()`

- **file:line:** `src/Buffer/ReadBuffer.php:135`
- **What is wrong:** `$data[1] >= 0x8000000000000000` compares an int against a
  float literal, so the subtraction never runs. `unpack('J')` already returns
  the two's-complement signed value, so behavior is correct; the branch is dead.
  Pre-existing, and the issue explicitly requires `getInt64()` to stay
  unchanged.
- **Severity:** nit
- **Status:** informational (do not change as part of #393)

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
- `composer cs`, `composer phpstan`, `composer rector` (dry-run), and
  `./vendor/bin/phpunit --testsuite unit` all pass on the clean tree.
