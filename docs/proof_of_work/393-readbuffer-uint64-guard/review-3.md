# Review — issue #393, round 3 (final expected)

- **Branch:** `feature/issue-393-readbuffer-uint64-guard`
- **Commit under review:** `ea85651` (`test(buffer): cover windowed-buffer uint64 overflow message (#393)`)
- **Round-2 baseline:** `ea78275`
- **Branch diff:** `git diff main...HEAD` — 9 files, `+832/-7`. Branch is exactly
  three commits on top of `main` (merge-base `cfe2b2e`): the fix (`e1340fb`), the
  docs/boundary follow-up (`ea78275`) and the windowed-message test (`ea85651`).
  Nothing else is in scope.
- **Working tree:** clean at the reviewed commit `ea85651`. This round writes only
  this review file and the `findings-review.md` status update; source, tests and
  product docs are untouched.

## Gates (run on the current HEAD, all green)

| Gate | Command | Result |
|------|---------|--------|
| Style | `composer cs` (PHPCS PSR-12) | **Clean** — `277 / 277 (100%)`, exit 0 |
| Static | `composer phpstan` (level 9) | **No errors** — 271 files, exit 0 |
| Rector | `composer rector` (dry-run) | **OK, no changes** — exit 0 |
| Unit | `./vendor/bin/phpunit --testsuite unit` | **OK (1152 tests, 8545 assertions)**, exit 0 |
| Full lint | `composer lint` | **OK**, exit 0 |

Raw unit result: `OK (1152 tests, 8545 assertions)`. Round 2 saw
`1151/8542`; the `+1` test / `+3` assertions are exactly the new
`testGetUint64AbovePhpIntMaxInWindowedBufferReportsWindowPosition`
(two `assertStringContainsString` + one `assertSame`), so REV-393-7's test is
genuinely executed, not skipped.

---

## Adjudication of open findings

| ID | Severity | R1 | R2 | **R3 verdict** |
|----|----------|----|----|----------------|
| REV-393-1 | medium | open | fixed | **Fixed** (still) — docs accurate at `:105`, `:402`, `:410` |
| REV-393-2 | medium | deferred | deferred | **Still present; deferred, follow-up still untracked** |
| REV-393-3 | low | open | fixed | **Fixed** (still) — threshold test present and passing |
| REV-393-4 | low | open | fixed | **Fixed** (still) — CHANGELOG `#393` entry present |
| REV-393-5 | low | informational | informational | **Still present; informational — no #393 action** |
| REV-393-6 | nit | informational | informational | **Still present (pre-existing); informational — must not change** |
| REV-393-7 | low | — | informational/open | **Fixed** in `ea85651` — windowed-buffer message now regression-tested |

### REV-393-1 — stale `getUint64()` docs: **Fixed (verified again)**

`docs/en/api-reference/read-buffer.md` still carries all three round-2 additions
(Throws bullet at `:105`, Error Handling condition at `:402`, message-format line
at `:410`) and they match `src/Buffer/ReadBuffer.php:114-122` byte-for-byte in
structure. No doc/code drift introduced by `ea85651` (it is test-only). Verified
via `git diff main...HEAD -- docs/`.

### REV-393-2 — `PublishConfirmResponseV1` bypasses the guard: **Still present; deferral defensible, follow-up still untracked**

`src/Response/PublishConfirmResponseV1.php:59` is unchanged:
`unpack('J*', $buffer->readBytes($count * 8))` still steps around
`ReadBuffer::getUint64()`, so publishing ids `>= 2^63` still decode negative and
reach the confirm callbacks. This is a real gap in the frame #393's report named,
but it is **out of the issue's file/acceptance scope** (the issue's criteria and
"Fix" section target `ReadBuffer::getUint64()` only; the bypass arrived with the
later #411 performance fast path). The coder disclosed it in
`code-decision-1.md` §Uncertainties and `findings-coder.md` §1. **Do not expand
#393 to cover it.**

**Deferral condition — still unmet:** `gh issue list --search "PublishConfirm"
--state all` on this HEAD returns only #393 itself (plus unrelated closed
issues); there is no dedicated follow-up issue for the bypass. The deferral is
safe only if the main session files one (e.g. "`PublishConfirmResponseV1.unpack('J*')`
bypasses the `getUint64()` guard") before/at merge and links it from #393's
closing comment. This remains the one item that must not be lost.

### REV-393-3 — missing `PHP_INT_MAX + 1` boundary test: **Fixed (verified again)**

`tests/Buffer/ReadBufferTest.php:327-335`
(`testGetUint64WithPhpIntMaxPlusOneThrows`) feeds
`"\x80\x00\x00\x00\x00\x00\x00\x00"` (`0x8000000000000000` = `PHP_INT_MAX + 1`)
and asserts `DeserializationException` carrying `0x8000000000000000`. Together
with `testGetUint64WithMaxRepresentableValue` (`PHP_INT_MAX`, no throw) and
`testGetUint64WithValueAbovePhpIntMaxThrows` (`0xFF×8`), the `< 0` predicate is
pinned on both sides of the threshold.

### REV-393-4 — missing CHANGELOG entry: **Fixed (verified again)**

`CHANGELOG.md:15` still has the `#393` bullet at the top of
`[Unreleased] → ### Fixed`, in the established format, accurately describing the
pre-fix wrap, the new typed rejection with raw bytes + position, and that
`getInt64()` is unchanged.

### REV-393-5 — discarded `OsirisChunkParser` epoch can now reject a frame: **Still present; informational**

`src/Client/OsirisChunkParser.php:325` still reads and discards the epoch through
the guarded `getUint64()`, so a bogus high-bit epoch aborts chunk parsing for a
field the parser ignores. Epochs are small running numbers in practice and
failing closed on a malformed header matches the issue's hardening intent.
Reading it via `getInt64()` would narrow the failure surface but is not warranted
for #393. **No action.**

### REV-393-6 — pre-existing dead branch in `getInt64()`: **Still present; informational**

`src/Buffer/ReadBuffer.php:135` still compares `$data[1] >= 0x8000000000000000`
(a float literal on 64-bit PHP), so the subtraction at `:136` never runs;
`unpack('J')` already yields the two's-complement value, so behavior is correct.
The issue explicitly requires `getInt64()` to stay unchanged. **No #393 action**
(candidate for a future cleanup only).

### REV-393-7 — no windowed-buffer test for the guard message: **Fixed in `ea85651`**

`tests/Buffer/ReadBufferTest.php:337-350`
(`testGetUint64AbovePhpIntMaxInWindowedBufferReportsWindowPosition`) constructs
`new ReadBuffer("\xAA\xBB\x80\x00\x00\x00\x00\x00\x00\x00", 2, 8)` (offset `2`,
window length `8`) and asserts the thrown message contains
`0x8000000000000000` **and** `position 0`, plus `getPosition() === 0` afterwards.

This exercises exactly what round 2 flagged:

- `bin2hex(substr($this->buffer, $this->offset + $this->position, 8))` must add
  the window offset. A hypothetical `substr($this->buffer, $this->position, 8)`
  (offset dropped) would read `\xAA\xBB\x80\x00\x00\x00\x00\x00` →
  `0xaabb800000000000`, which does **not** contain `0x8000000000000000`, so the
  assertion would fail. The test is a genuine regression guard, not a tautology.
- The message must report the **window-relative** position (`0`), not the
  absolute one (`2`); a `$this->offset + $this->position` regression would fail
  the `position 0` assertion.
- Cursor non-advance on rejection is re-asserted.

Re-verified empirically on HEAD:

```
$ php -r 'ReadBuffer("\xAA\xBB\x80\x00...", 2, 8)->getUint64()'
DeserializationException: uint64 value 0x8000000000000000 at position 0 exceeds PHP_INT_MAX
pos=0
max (pack('J', PHP_INT_MAX)) => 9223372036854775807 pos=8
```

The suite delta (`1151→1152` tests, `8542→8545` assertions) confirms it runs.
**No remaining coverage gap for this finding.**

---

## New issues found in round 3

**None.** Full diff re-read (`src/Buffer/ReadBuffer.php`,
`tests/Buffer/ReadBufferTest.php`, `CHANGELOG.md`,
`docs/en/api-reference/read-buffer.md`, PoW docs) plus the round-3 test:

- **Guard predicate** `$data[1] < 0` remains exact for the `2^63` wrap and runs
  after the `unpack() === false` check and before the cursor bump.
- **Raw-bytes read** is bounded by the preceding `ensureAvailable(8)`, so
  `substr(..., 8)` always returns exactly 8 bytes; the offset term is now
  covered by the suite (REV-393-7).
- **Exception type** is still `DeserializationException`, no bare `\Exception`
  (DEC-002 respected); the message shape matches siblings.
- **`getInt64()`** untouched and still two's-complement; `testGetInt64Negative`
  still asserts `-1`.
- **Caller safety:** the five `getUint64()` call sites
  (`QueryOffsetResponseV1`, `QueryPublisherSequenceResponseV1`,
  `ResolveOffsetSpecResponseV1`, `VO/PublishingError`, `OsirisChunkParser`)
  still treat the result as a non-negative offset/sequence; `NO_OFFSET` is a
  response code checked before the read.
- **Lint gates** (PHPCS PSR-12, PHPStan level 9, Rector dry-run, kb-lint, link
  check) are all clean; no rule/level/config was weakened (DEC-003 respected).
- The coder's candidate KB entry (`unpack('J') is signed — guard every uint64
  path`) remains appropriate and is left to the retro step (single-writer rule
  in `docs/helpers/README.md`). It is not a #393 finding.

---

## Automated-check outputs (exact)

```
$ composer cs
............................................................  60 / 277 (22%)
............................................................ 120 / 277 (43%)
............................................................ 180 / 277 (65%)
............................................................ 240 / 277 (87%)
.....................................                        277 / 277 (100%)


Time: 1.77 secs; Memory: 40MB
(exit 0)

$ composer phpstan
 [OK] No errors
(271 files, exit 0)

$ composer rector
 [OK] Rector is done!
(exit 0)

$ ./vendor/bin/phpunit --testsuite unit
OK (1152 tests, 8545 assertions)
Time: 00:06.522, Memory: 568.02 MB
(exit 0)

$ composer lint
............................................................  277 / 277 (100%)


 [OK] Rector is done!

 [OK] No errors

kb-lint: root /Users/piotr.halas/work/rabbit-stream
kb-lint: docs/helpers/faq.md — 6 entries, 111 lines (101 budgeted, limit 300)
kb-lint: docs/helpers/decisions.md — 4 entries, 67 lines (54 budgeted, limit 300)
kb-lint: OK — 10 entries, 0 warning(s), 0 stale
All relative links in docs/en resolve.
(exit 0)
```

## Verdict

- **Blocking issues: none.**
- REV-393-1, -3, -4 fixed and re-verified; REV-393-7 **fixed in `ea85651`**.
- **Deferred:** REV-393-2 (real gap in `PublishConfirmResponseV1`, correctly out
  of #393's file/acceptance scope; the required follow-up issue is still not
  filed — the main session must file it at/before merge).
- **Informational, no action:** REV-393-5 (epoch inherits the guard) and
  REV-393-6 (pre-existing dead branch in `getInt64()`); plus the coder's KB
  proposal left to the retro step.

**Code looks good, no issues to fix.**

Remaining non-#393 items, listed so they are not lost: REV-393-2 follow-up
(PublishConfirm `unpack('J*')` bypass — still untracked), REV-393-5
(`OsirisChunkParser` epoch), REV-393-6 (dead `getInt64()` branch). All are
deferred/informational and none is a merge blocker for this branch.
