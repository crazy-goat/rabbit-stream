# Review — issue #393, round 2

- **Branch:** `feature/issue-393-readbuffer-uint64-guard`
- **Commit under review:** `ea782754b9f9aadaaec715be59d3aeaa5e4969a4` (`docs(buffer): document uint64 overflow guard and pin PHP_INT_MAX+1 boundary (#393)`)
- **Round-1 baseline:** `e1340fb`
- **Diff reviewed:** `git diff main...HEAD` (8 files: `src/Buffer/ReadBuffer.php`, `tests/Buffer/ReadBufferTest.php`, `CHANGELOG.md`, `docs/en/api-reference/read-buffer.md`, plus 4 proof-of-work docs)
- **Working tree:** clean (`git status --short` empty)

## Gates (run on the current HEAD, all green)

| Gate | Command | Result |
|------|---------|--------|
| Style | `composer cs` (PHPCS PSR-12) | **Clean** — `277 / 277 (100%)`, exit 0 |
| Static | `composer phpstan` (level 9) | **No errors** — 271 files, exit 0 |
| Rector | `composer rector` (dry-run) | **OK, no changes** — exit 0 |
| Unit | `./vendor/bin/phpunit --testsuite unit` | **OK (1151 tests, 8542 assertions)**, exit 0 |
| Full lint | `composer lint` (phpcs + rector + phpstan + kb-lint + link check) | **OK**, exit 0 |

Raw unit result: `OK (1151 tests, 8542 assertions)`. Round 1 saw `1150/8540`; the
`+1` test / `+2` assertions are exactly the new `PHP_INT_MAX + 1` case (REV-393-3),
so that test is genuinely executed, not skipped.

---

## Round-1 finding verdicts

Verified against the current code, not taken on trust.

| ID | Severity | Round-1 status | Round-2 verdict |
|----|----------|---------------|-----------------|
| REV-393-1 | medium | open | **Fixed** — docs updated in `ea78275` |
| REV-393-2 | medium | deferred | **Still present — deferral defensible, but follow-up is not yet tracked** |
| REV-393-3 | low | open | **Fixed** — `PHP_INT_MAX + 1` test added |
| REV-393-4 | low | open | **Fixed** — CHANGELOG `#393` entry added |
| REV-393-5 | low | informational | **Still present, informational** — no action for #393 |
| REV-393-6 | nit | informational | **Still present (pre-existing)** — must not change in #393 |

### REV-393-1 — stale `getUint64()` docs: **Fixed**

`docs/en/api-reference/read-buffer.md` now documents the new throw at both places
round 1 flagged:

- `:105` — new bullet under `getUint64()`'s **Throws**: "If the value exceeds
  `PHP_INT_MAX` … the message includes the raw bytes (`uint64 value 0x... at
  position N exceeds PHP_INT_MAX`)".
- `:402` — new bullet in the shared **Error Handling** condition list
  ("uint64 out of range").
- `:410` — new line in the **Error Message Format** block:
  `uint64 value 0x{raw bytes} at position {position} exceeds PHP_INT_MAX`.

The documented format matches the code's `sprintf` in
`src/Buffer/ReadBuffer.php:117` byte-for-byte in structure, and the `Returns: 0 to
PHP_INT_MAX` line (`:101`) is now actually true. No remaining doc/code mismatch
found in `docs/en/` (checked the other `DeserializationException` enumerations via
`grep`; none of them describes `getUint64`'s throw conditions, and the
`binary-serialization.md` uint64 range row is consistent).

### REV-393-2 — `PublishConfirmResponseV1` bypasses the guard: **Still present; deferral defensible**

`src/Response/PublishConfirmResponseV1.php:59` is unchanged — ids are still read
with `unpack('J*', $buffer->readBytes($count * 8))` and never pass through
`getUint64()`, so ids `>= 2^63` still decode negative and reach the confirm
callbacks. This is a real gap.

**Deferral judgment: defensible.** The issue (#393) body names `PublishConfirm`
publishing ids among the consumers of the wrapped value, *but* its stated
acceptance criteria and its "## Fix" section are scoped entirely to
`ReadBuffer::getUint64()`:

> - [ ] A uint64 above `PHP_INT_MAX` throws a typed exception rather than returning a negative int
> - [ ] Unit test with `0xFFFFFFFFFFFFFFFF`

This PR satisfies both. The bypass lives in a different class, was introduced by
the later #411 performance fast-path work, and its fix (a `min($unpacked) < 0`
check, or a shared `getUint64Array()` helper) is an independent change on a
speed-sensitive path that deserves its own benchmarks and tests. The coder
disclosed it in `code-decision-1.md` §Uncertainties and `findings-coder.md` §1, so
there is no hidden assumption. **Do not expand #393 to cover it.**

**Condition on the deferral:** a follow-up issue must actually be filed. As of
this review, `gh issue list --search "PublishConfirm" --state all` returns no such
issue — the gap is currently untracked. The deferral is only safe if the main
session opens a dedicated issue (e.g. "`PublishConfirmResponseV1.unpack('J*')`
bypasses the `getUint64()` guard") before/at merge. I recommend it be linked from
#393's closing comment so the named frame is not lost.

### REV-393-3 — missing `PHP_INT_MAX + 1` boundary test: **Fixed**

`tests/Buffer/ReadBufferTest.php:327-335` adds
`testGetUint64WithPhpIntMaxPlusOneThrows`, feeding
`"\x80\x00\x00\x00\x00\x00\x00\x00"` and asserting
`DeserializationException` with `0x8000000000000000`. This is exactly the
smallest unrepresentable value and pins the `< 0` predicate's threshold from the
correct side (the existing `0xFF×8` case pins `-1`, and
`testGetUint64WithMaxRepresentableValue` at `:321-325` pins `PHP_INT_MAX`).
The unit count/assertion delta versus round 1 confirms it runs.

### REV-393-4 — missing CHANGELOG entry: **Fixed**

`CHANGELOG.md:15` adds the `#393` bullet at the top of `[Unreleased] → ### Fixed`,
in the established format (bold summary, `(#393)`, mechanism, impact). It states
the pre-fix wrap, the new typed rejection with the raw bytes + position, and that
`getInt64()` is unchanged. Accurate.

### REV-393-5 — discarded `OsirisChunkParser` epoch: **Still present, informational**

`src/Client/OsirisChunkParser.php:325` still does `$buffer->getUint64(); // epoch`.
It inherits the new guard, so a bogus high-bit epoch now aborts chunk parsing for
a field the parser discards, even though `chunkFirstOffset` (`:326`) is the field
that matters. Round 1 judged this acceptable (fail-closed on a malformed header,
consistent with the issue's hardening intent, and epochs are small in practice).
I agree — no action for #393. The only theoretical narrowing would be reading the
epoch via `getInt64()`; not warranted.

### REV-393-6 — pre-existing dead branch in `getInt64()`: **Still present (pre-existing), informational**

`src/Buffer/ReadBuffer.php:135` still compares `$data[1] >= 0x8000000000000000`,
where the literal is a float on 64-bit PHP, so the subtraction at `:136` never
runs. `unpack('J')` already yields the two's-complement signed value, so behavior
is correct; the branch is dead. The issue explicitly requires `getInt64()` to stay
unchanged; correctly untouched. Not a #393 action (candidate for a future cleanup,
not this PR).

---

## NEW findings

One new, non-blocking coverage nit; nothing high/medium.

| # | file:line | Severity | Summary |
|---|-----------|----------|---------|
| REV-393-7 | `tests/Buffer/ReadBufferTest.php:306-335` | low (informational) | Guard message on a windowed/sliced `ReadBuffer` (`offset != 0`) is not regression-tested; the `$this->offset + $this->position` arithmetic is correct but only verified manually. |

### REV-393-7 — no windowed-buffer test for the guard message (low, optional)

The throw path builds its raw bytes with
`bin2hex(substr($this->buffer, $this->offset + $this->position, 8))`
(`src/Buffer/ReadBuffer.php:118`), and the message reports `$this->position`
(relative to the window). Every new test uses a whole-buffer `ReadBuffer`
(`offset === 0`), so the `+ $this->offset` term is not executed by the suite at
all — it is pinned only by manual inspection.

I re-verified it empirically on the current HEAD; it is correct:

```
whole:    uint64 value 0x8000000000000000 at position 0 exceeds PHP_INT_MAX
windowed: uint64 value 0x8000000000000000 at position 0 exceeds PHP_INT_MAX  (ReadBuffer("AA BB 80 00 ...", 2, 8)) pos=0
max:      pack('J', PHP_INT_MAX) -> PHP_INT_MAX (no throw)
```

This is coverage polish only (no defect), and `offset != 0` message bytes were
already exercised by round 1's manual check and by other `ReadBuffer` window tests.
A one-line addition (construct with a 2-byte prefix and `offset = 2`) would close
it. Optional; not a merge blocker.

### No other new issues

- **PSR-12 / PHPStan 9 / Rector:** clean; the new `sprintf`/`bin2hex`/`substr`
  expression is fully typed and never unreachable. No lint rule was weakened
  (DEC-003 respected).
- **Exception type:** still `DeserializationException`, no bare `\Exception`
  (DEC-002 respected).
- **Protocol correctness:** the guard predicate is exact (`< 0` ⟺ `>= 2^63`);
  the cursor does not advance on rejection; the raw bytes are read from the same
  windowed location as the `unpack`. `getInt64()` remains two's-complement and
  `testGetInt64Negative` still asserts `-1`.
- **Caller safety:** re-confirmed the five `getUint64()` call sites
  (`QueryOffsetResponseV1`, `QueryPublisherSequenceResponseV1`,
  `ResolveOffsetSpecResponseV1`, `VO/PublishingError`, `OsirisChunkParser`) all
  treat the result as a non-negative offset/sequence; none relies on the wrapped
  negative. `NO_OFFSET` is a response code checked before the offset read.
- **KB proposal:** the coder's candidate entry (`unpack('J') is signed — guard
  every uint64 path`, tags `buffer, protocol, exceptions`) is appropriate and
  directly captures REV-393-2; leave it to the retro step (single writer) as the
  `docs/helpers/README.md` rules require. There is no `buffer` tag in the current
  index, so the retro step would introduce it, which is fine.

---

## Automated-check outputs (exact)

```
$ composer cs
............................................................  277 / 277 (100%)
Time: 1.61 secs; Memory: 40MB
(explicit exit 0)

$ composer phpstan
 [OK] No errors
(explicit exit 0)

$ composer rector
 [OK] Rector is done!
(explicit exit 0)

$ ./vendor/bin/phpunit --testsuite unit
OK (1151 tests, 8542 assertions)
Time: 00:06.470, Memory: 612.02 MB
(explicit exit 0)

$ composer lint
kb-lint: docs/helpers/faq.md — 6 entries, 111 lines (101 budgeted, limit 300)
kb-lint: docs/helpers/decisions.md — 4 entries, 67 lines (54 budgeted, limit 300)
kb-lint: OK — 10 entries, 0 warning(s), 0 stale
All relative links in docs/en resolve.
(explicit exit 0)
```

## Verdict

- **Blocking issues: none.** REV-393-1, -3 and -4 are fixed and verified.
- **Deferred (defensible):** REV-393-2 — real gap in
  `PublishConfirmResponseV1`, correctly out of #393's file/acceptance scope; must
  be filed as a separate follow-up issue (not yet tracked).
- **Informational, no action:** REV-393-5 (epoch inherits the guard),
  REV-393-6 (pre-existing dead branch in `getInt64()`), REV-393-7 (optional
  windowed-buffer message test).

The code is ready. Everything left open is either a defensibly-deferred follow-up
(REV-393-2, pending a filed issue) or explicitly informational/out-of-scope
(REV-393-5, REV-393-6, REV-393-7).
