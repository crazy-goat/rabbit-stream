# Review 3 — Issue #520: reject and omit values for value-less offset specs

Branch: `feature/issue-520-offsetspec-valueless-value`  
HEAD: `f0a8d78627368e4c59cf472140020eb5525823ed` (`git rev-parse HEAD`)  
Reviewer: `review-critical` (adversarial, round 3)  
Diff reviewed: `git diff main...HEAD` — `CHANGELOG.md`,
`docs/en/api-reference/value-objects.md`, `src/VO/OffsetSpec.php`,
`tests/VO/OffsetSpecTest.php`, plus the proof-of-work docs.

## Verdict

**Code looks good, no issues to fix.**

Every in-scope finding from rounds 1–2 is resolved on the current HEAD; the
four known out-of-scope follow-ups (R1-6…R1-9) are still present and accurately
described. No new in-scope defect was found. The wire bytes match the protocol
for all seven types, the constructor rejects the previously-representable
malformed states, and no in-tree caller breaks.

## Gates (run on current HEAD, all green)

| Gate | Command | Result |
|------|---------|--------|
| Unit (full) | `./vendor/bin/phpunit --testsuite unit` | **OK (1134 tests, 8506 assertions)** |
| Unit (focused) | `./vendor/bin/phpunit tests/VO/OffsetSpecTest.php` | **OK (30 tests, 56 assertions)** |
| Static | `composer phpstan` (level 9) | **No errors** (271 files) |
| Style | `composer cs` (PHPCS PSR-12) | **Clean** (277 files) |
| Rector | `composer rector` (dry-run) | **OK, no changes** (6 files) |
| Full lint | `composer lint` (phpcs + rector + phpstan + kb-lint + doc links) | **OK** — kb-lint 10 entries / 0 warnings / 0 stale; all relative `docs/en` links resolve |

## Wire-byte verification (`php -r`, current code)

```
none                       len= 2 hex=0000
first                      len= 2 hex=0001
last                       len= 2 hex=0002
next                       len= 2 hex=0003
offset(0)                  len=10 hex=00040000000000000000
offset(42)                 len=10 hex=0004000000000000002a
offset(PHP_INT_MAX)        len=10 hex=00047fffffffffffffff
timestamp(0)               len=10 hex=00050000000000000000
timestamp(-1000)           len=10 hex=0005fffffffffffffc18
timestamp(PHP_INT_MIN)     len=10 hex=00058000000000000000
interval(0)                len=10 hex=00060000000000000000
interval(3600)             len=10 hex=00060000000000000e10
interval(PHP_INT_MAX)      len=10 hex=00067fffffffffffffff
```

Constructor rejections (all throw `InvalidArgumentException`):

```
none + 123 / first + 0 / last + 123 / next + 0   -> "Offset spec type N does not accept a value (value-less type)"
offset null / timestamp null / interval null      -> "Offset spec type N requires a value (offset/timestamp/interval)"
999 / -1 / 7                                      -> "Invalid offset spec type: N"
```

`0` is a value, not "no value": rejected for value-less types, and correctly
emitted as 8 bytes for `offset(0)`/`interval(0)`. `toArray()` reports
`value => null` for all four value-less types and the real value otherwise.
`grep "new OffsetSpec(" src/ examples/ tests/` finds direct construction only
in `tests/VO/OffsetSpecTest.php`; all production callers use the factory
methods, so the stricter constructor is not triggered in-tree.

---

## Per-item re-check on current HEAD

- **R1-1 — fixed.** `TYPE_INTERVAL` is in `VALUE_TYPES`
  (`src/VO/OffsetSpec.php:44-48`); the requires-a-value guard
  (`:61-63`) fires on `new OffsetSpec(TYPE_INTERVAL)`, reproduced:
  `Offset spec type 6 requires a value (offset/timestamp/interval)`. Pinned by
  `testIntervalWithoutValueThrows` (`tests/VO/OffsetSpecTest.php:120-126`).
- **R1-2 — fixed.** `ALL_TYPES` is gone (`f0a8d78`). The invalid-type check is
  now `!in_array($type, VALUELESS_TYPES, true) && !in_array($type, VALUE_TYPES,
  true)` (`src/VO/OffsetSpec.php:56-59`). The two lists are disjoint and
  exhaustive over 0–6; every type is classified in exactly one list, so drift
  fails **closed** (unknown → invalid type; in both → both guards reject any
  value). A stray value can no longer be silently accepted-and-dropped:
  value-less + non-null throws (`:65-69`), value-carrying + null throws
  (`:61-63`). No third literal remains.
- **R1-3 — fixed / not a real defect.** `toStreamBuffer()` branches on the type
  first (`src/VO/OffsetSpec.php:110-113`: `if (!in_array($this->type,
  self::VALUE_TYPES, true)) { return $buffer; }`) and only then on the nullable
  value (`:115-118`). The residual `$this->value === null` guard is
  unreachable at runtime given the constructor invariant, but it is **required
  by PHPStan level 9**: the promoted property is `?int` and PHPStan does not
  propagate the constructor invariant to `addInt64()`/`addUInt64()` (which take
  `int`). Removing it would fail `composer phpstan`. The accompanying comment
  accurately states this, so the guard is justified static-analysis narrowing,
  not dead misleading logic.
- **R1-4 — fixed.** `testValueLessTypeRejectsNonNullValue` (123),
  `testValueLessTypeRejectsZeroValue` (0), `testValueLessTypeSerializesTypeFieldOnly`
  (all four types, exact `pack('n', $type)`), `testIntervalSerializesTypeAndUint64Value`,
  `testIntervalWithoutValueThrows`, `testToArrayForNone` all present; focused
  file 30 tests / 56 assertions OK.
- **R1-5 — fixed.** `CHANGELOG.md:18` has the `[Unreleased]` `### Fixed` bullet
  for #520, accurately stating only `offset`/`timestamp`/`interval` carry a
  value and that the constructor rejects/requires accordingly.
- **R2-1 — fixed.** `docs/en/api-reference/value-objects.md:40` now reads
  "required (and enforced) for `TYPE_OFFSET`, `TYPE_TIMESTAMP` and
  `TYPE_INTERVAL`, and rejected (and enforced) for the value-less types
  `TYPE_NONE`, `TYPE_FIRST`, `TYPE_LAST` and `TYPE_NEXT`"; the Throws list at
  `:44` matches. No "not yet enforced (see issue #468)" text remains anywhere in
  `docs/en`.
- **R1-6 — still present (out of scope), accurately described.**
  `src/Request/ConsumerUpdateReplyV1.php:49-50` still calls
  `addUInt64($this->offset)` for `TYPE_TIMESTAMP`; reproduced:
  `new ConsumerUpdateReplyV1(0x0001, TYPE_TIMESTAMP, -1000)->toStreamBuffer()`
  throws `InvalidArgumentException: Value -1000 is out of range for uint64`.
  Follow-up candidate, not this PR.
- **R1-7 — still present (out of scope), accurately described.**
  `new ConsumerUpdateReplyV1(0x0001, TYPE_FIRST, 123)` reports
  `toArray() => {"offset":null}` while retaining `123`; wire emits
  `...0001` only. Follow-up candidate.
- **R1-8 — still present (out of scope), accurately described.**
  `src/StreamConnection.php:1132` still destructures the `callable` return with
  no shape check before the `0-5` guard at `:1135`. Follow-up candidate.
- **R1-9 — still present (nit, out of scope), accurately described.**
  `src/VO/OffsetSpec.php:68-72` documents `none()` as ConsumerUpdate-only, but
  the class cannot enforce it (`SubscribeRequestV1`/`ResolveOffsetSpecRequestV1`
  accept any `OffsetSpec`). The finding cites `:73-75` (the method, not the
  docblock) — a harmless off-by-a-few line reference; substance is correct.

## New in-scope findings

None. The core fix is correct, the strictness is intentional and unreachable
from in-tree callers, and every malformed construction now fails loudly.

### Non-blocking record note (not an in-scope code finding)

`findings-review.md:125` states the post-fix gate count as "1134 tests / 8510
assertions". The actual count on `f0a8d78` is **8506** assertions (same as
`0403ba4`; `f0a8d78` changed no test files — verified with
`git show f0a8d78 --stat`). The gate itself is green; only the recorded number
is an off-by-4 transcription. It does not affect the code or the review
verdict, so it is not raised as a finding to fix.

## Convergence

Change is correct and all in-scope findings (R1-1…R1-5, R2-1) are resolved.
Verdict: **Code looks good, no issues to fix.**
