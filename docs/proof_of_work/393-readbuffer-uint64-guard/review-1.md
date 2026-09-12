# Review — issue #393, round 1

- **Branch:** `feature/issue-393-readbuffer-uint64-guard`
- **Commit under review:** `e1340fb` ("fix(buffer): reject uint64 values above PHP_INT_MAX in ReadBuffer::getUint64() (closes #393)")
- **Diff:** `git show HEAD` — `src/Buffer/ReadBuffer.php`, `tests/Buffer/ReadBufferTest.php`, plus the two pre-existing PoW files from the coder round.
- **Working tree:** clean at `e1340fb`.

## Prior review state

`docs/proof_of_work/393-readbuffer-uint64-guard/findings-review.md` **does not exist** — this is the first review round, so there are **no earlier findings to revisit**. Everything below is new.

## Verdict

The core change is correct, minimal, and well-scoped. The guard, cursor semantics, exception type, and caller compatibility all check out. **No high-severity findings, no blocking issues.** The remaining items are documentation/test-coverage gaps (medium/low) and one genuine out-of-scope gap that the coder already disclosed.

## Acceptance criteria (issue #393)

| Requirement | Status | Evidence |
|---|---|---|
| `getUint64()` throws for values above `PHP_INT_MAX` | ✅ | `src/Buffer/ReadBuffer.php:114-122` |
| Typed exception | ✅ | `DeserializationException` (a `RabbitStreamException`), not bare `\Exception` |
| Raw bytes included | ✅ | `bin2hex(substr(...,8))` in the message (`ReadBuffer.php:118`) |
| Unit test with `0xFFFFFFFFFFFFFFFF` | ✅ | `tests/Buffer/ReadBufferTest.php:306-319` |

## Correctness analysis

### Guard predicate is exact

On 64-bit PHP, `unpack('J')` returns the raw two's-complement bit pattern as a native
int. Values `0 … PHP_INT_MAX` (`2^63-1`) are `>= 0`; every value `>= 2^63` is
negative. Therefore `$data[1] < 0` is exactly "cannot be represented as a
non-negative offset integer", and it is placed after the `unpack() === false`
check (where `$data[1]` is guaranteed to exist) and **before** the cursor bump.
Verified empirically on PHP 8.5.10:

```
max       => 9223372036854775807 pos=8
max+1     => THROW uint64 value 0x8000000000000000 at position 0 exceeds PHP_INT_MAX | pos=0
all-ones  => THROW uint64 value 0xffffffffffffffff at position 0 exceeds PHP_INT_MAX | pos=0
windowed  => uint64 value 0x8000000000000000 at position 8 exceeds PHP_INT_MAX | pos=8
getInt64(ffx8) => -1
```

The windowed case confirms `substr($this->buffer, $this->offset + $this->position, 8)`
addresses the right 8 bytes for a `slice()`d buffer with a non-zero cursor (offset
`2` + position `8`). The `0x8000000000000000` message shows the raw bytes are
correct, not the decoded value.

### Cursor does not advance on throw

The guard throws before `$this->position += 8`, so a rejected read is
side-effect-free (`pos=0`/`pos=8` above, and the test asserts `getPosition() === 0`
at `ReadBufferTest.php:318`). Consistent with the #447 guards on `skip()` /
`readBytes()`.

### `getInt64()` is untouched and remains two's-complement

`getInt64()` (`ReadBuffer.php:127-139`) is unchanged by this commit and
`testGetInt64Negative` still asserts `-1` for `0xFF×8`
(`ReadBufferTest.php:351-355`). Requirement "must NOT be changed" is met.

### Message / exception quality

- Exception class: `DeserializationException` (`src/Exception/DeserializationException.php:7`), a `RabbitStreamException`. No bare `\Exception` — satisfies **DEC-002**.
- Shape: `'uint64 value 0x%s at position %d exceeds PHP_INT_MAX'` matches the sibling `… at position %d` convention used by `ensureAvailable`, `getString`, `getBytes`, `skip`, `readBytes`.
- Includes both the raw bytes and the cursor position, which is what a hostile-frame bug report needs.

### Caller audit (all of `src/` + `tests/`)

`grep getUint64` finds five production call sites plus `getInt64`:

| Caller | Field | Non-negative contract? |
|---|---|---|
| `src/Response/QueryOffsetResponseV1.php:36` | stored offset | ✅ response code checked first; NO_OFFSET (`0x13`) throws in `assertResponseCodeOk` before the read |
| `src/Response/QueryPublisherSequenceResponseV1.php:36` | publisher sequence | ✅ |
| `src/Response/ResolveOffsetSpecResponseV1.php:37` | resolved offset | ✅ |
| `src/VO/PublishingError.php:34` | publishing id | ✅ |
| `src/Client/OsirisChunkParser.php:326` | `chunkFirstOffset` | ✅ (see finding REV-393-5 re `:325` epoch) |

No caller relies on the old wrapped-negative return. The old `testGetUint64WithMaxValue`
that asserted `-1` was replaced, not left red. The only other `unpack('J')` in the
test suite (`tests/StreamConnectionTest.php:1290`) reads the write-side reply
payload directly and does not touch `ReadBuffer::getUint64()`.

## Findings

| # | file:line | Severity | Summary |
|---|---|---|---|
| REV-393-1 | `docs/en/api-reference/read-buffer.md:103-104` (and `:391-408`) | medium | Docs stale: `getUint64()` "Throws" lists only underflow; the new over-`PHP_INT_MAX` condition and its message format are missing. |
| REV-393-2 | `src/Response/PublishConfirmResponseV1.php:59` | medium (out of scope) | `unpack('J*')` bypasses the guard — publishing ids `>= 2^63` still wrap negative. Real gap, separate issue. |
| REV-393-3 | `tests/Buffer/ReadBufferTest.php:321-325` | low | Missing boundary test for the smallest unrepresentable value `PHP_INT_MAX + 1` (`0x8000000000000000`); exact predicate threshold not pinned. |
| REV-393-4 | `CHANGELOG.md:14` (`[Unreleased]` → `### Fixed`) | low | No `#393` entry. Main session owns step 8; flagged because it is absent. |
| REV-393-5 | `src/Client/OsirisChunkParser.php:325` | low | Discarded `epoch` now read through the guarded `getUint64()`; a high-bit epoch aborts chunk parsing for a field the parser ignores. Defensible, informational. |
| REV-393-6 | `src/Buffer/ReadBuffer.php:135` | nit (pre-existing) | `getInt64()`'s `>= 0x8000000000000000` branch is dead (literal is a float, comparison never true) — functionally harmless, explicitly out of scope, must not be changed here. |

### REV-393-1 — stale API docs (medium)

`docs/en/api-reference/read-buffer.md` documents `getUint64()` as throwing only on
buffer underflow:

```
103: **Throws:**
104: - `DeserializationException` - If buffer underflow (less than 8 bytes available)
```

and the shared Error Handling section (`:393-408`) lists the message formats without
the new one. After this commit the method also throws
`uint64 value 0x… at position … exceeds PHP_INT_MAX`. The "Returns: range 0 to
PHP_INT_MAX" line (`:101`) was already written for the intended contract and is now
actually true. This is a genuine doc/code mismatch introduced by the commit. The
coder's `findings-coder.md` §2 already identified it and deliberately deferred it "to
keep the diff minimal"; a docs-only follow-up (or folding it into this PR) is cheap.
No automated check catches this — `composer lint` runs `bin/check-docs-links.php`
(links only), not prose accuracy.

### REV-393-2 — `PublishConfirmResponseV1` still wraps (medium, separate issue)

`src/Response/PublishConfirmResponseV1.php:59` reads all ids with
`unpack('J*', $buffer->readBytes($count * 8))` and never calls `getUint64()`, so a
publishing id `>= 2^63` still comes back negative and is handed to confirm callbacks.
This is a **real gap**, and it is the frame the #393 report named, so #393's stated
blast radius is not fully closed by this commit. It is nevertheless **out of scope for
this issue**: it is a different file/API, touches the #411 performance fast path, and
the fix (a `min($unpacked) < 0` check or a shared `getUint64Array()` helper) is an
independent change. Recommend filing a follow-up and keeping #393 scoped as
implemented. The coder disclosed this in `code-decision-1.md` §Uncertainties and
`findings-coder.md` §1, so no hidden assumption.

### REV-393-3 — missing exact-boundary test (low)

`testGetUint64WithMaxRepresentableValue` pins `PHP_INT_MAX` (must **not** throw) and
`testGetUint64WithValueAbovePhpIntMaxThrows` uses `0xFF×8`. The exact first
unrepresentable value, `PHP_INT_MAX + 1` = `0x8000000000000000`, is not tested. The
predicate `< 0` is exactly the `PHP_INT_MAX`/`PHP_INT_MAX+1` split, so a one-line test
(`"\x80\x00\x00\x00\x00\x00\x00\x00"`) would pin it. Note the all-ones case already
pins the `-1` end (a wrong `< -1` predicate would fail it), so this is coverage
polish, not a correctness hole. PHPUnit is the catchable check.

### REV-393-4 — CHANGELOG entry missing (low)

`CHANGELOG.md` `[Unreleased]` has `### Changed` (`:9`) and `### Fixed` (`:14`) but no
`#393` entry. Per `AGENTS.md` "After Merging a Feature Branch" (step 3) and the task
note that the main session handles step 8, this is expected to be added at merge time;
flagging it only so it is not lost. No automated check.

### REV-393-5 — `OsirisChunkParser` epoch (low, informational)

`src/Client/OsirisChunkParser.php:325` calls `$buffer->getUint64(); // epoch` and
throws the result away. It now inherits the guard, so a hostile/bogus high-bit epoch
aborts chunk parsing even though `chunkFirstOffset` (`:326`) is the field that
matters. In practice the epoch is a small running number, so this is not a live-broker
behavior change, and failing closed on a malformed header is consistent with the
issue's hardening intent. If a narrower failure surface is ever wanted, reading the
epoch with `getInt64()` (it is discarded anyway) keeps only `chunkFirstOffset` able to
reject the chunk. No action required for #393.

### REV-393-6 — pre-existing dead branch in `getInt64()` (nit)

`ReadBuffer.php:135` compares `$data[1] >= 0x8000000000000000`; on 64-bit PHP that
literal is a float (`9.223372036854775808E18`) and no native int is `>=` it, so the
subtraction never runs — `unpack('J')` already returns the two's-complement signed
value. This is pre-existing and **must not be changed as part of #393** (the issue
explicitly says `getInt64()` stays as-is). Noted for a future cleanup issue only.

## Documented-decision / convention compliance

- **DEC-002 (custom exception hierarchy):** ✅ throws `DeserializationException`; no bare `\Exception`.
- **DEC-003 (lint gate is never lowered):** ✅ no linter config, level, or rule was touched; all gates pass.
- **DEC-001 / DEC-004 (naming, branch):** ✅ N/A for naming; branch is `feature/issue-393-readbuffer-uint64-guard`, matching `feature/issue-NNN-<slug>`.
- **FAQ-002 / FAQ-002 server-push keys, FAQ-006 wire-vs-disk:** ✅ N/A, no framing/push changes. `getInt64()` unchanged.
- No documented decision is violated.

## Automated checks (run on the clean tree at `e1340fb`)

```
$ composer cs
 277 / 277 (100%)
Time: 1.79 secs; Memory: 40MB
(exit 0)

$ composer phpstan
 [OK] No errors
(exit 0)

$ composer rector
 [OK] Rector is done!
(exit 0)

$ ./vendor/bin/phpunit --testsuite unit
OK (1150 tests, 8540 assertions)
Time: 00:06.592, Memory: 562.02 MB
(exit 0)
```

PHPCS PSR-12, PHPStan level 9, and Rector dry-run are all clean; no finding above is
catchable by these gates except REV-393-3 (PHPUnit would run the missing case if
added).

## Progress / verdict

- Blocking issues: **none**.
- Non-blocking to fix before merge (recommended): REV-393-1 (docs), REV-393-4 (CHANGELOG at merge).
- Follow-up issue: REV-393-2.
- Optional polish: REV-393-3.
- No earlier review round existed; this file is the baseline for any round 2.
