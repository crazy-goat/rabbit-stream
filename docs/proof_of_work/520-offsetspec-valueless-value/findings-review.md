# Review findings — Issue #520

Append-only log. One entry per finding: `file:line`, description, severity,
status.

## Review 1

### R1-1 — `src/VO/OffsetSpec.php:116` (ctor `:34-70`)
`TYPE_INTERVAL` with a null value is still representable: `new OffsetSpec(TYPE_INTERVAL)` passes the constructor guards and `toStreamBuffer()` emits the 2-byte `0006` frame via the `$this->value === null` short-circuit. This is neither the value-less form (interval is not in `VALUELESS_TYPES`) nor the 10-byte interval form the code otherwise assumes, leaving `interval` as the sole type violating the binary value-carrying/value-less invariant established by this change. Deliberately scoped to #468, but the gap should be closed or explicitly pinned when #468 is resolved.
Severity: medium
Status: fixed — `TYPE_INTERVAL` added to the new positive `VALUE_TYPES` list, so the constructor now requires a value for interval; `testIntervalWithoutValueThrows` pins it.

### R1-2 — `src/VO/OffsetSpec.php:38-54`, `:56-63`, `:27-32`
The accepted-type list (7 constants), the requires-a-value pair (inline `OFFSET`/`TIMESTAMP`) and `VALUELESS_TYPES` (4 constants) are three independent literals that can drift. A new value-less type added to the accepted list but not `VALUELESS_TYPES` re-opens the #520 bug; the change also uses a negative list while the issue and the sibling `ConsumerUpdateReplyV1` fix use a positive ("only OFFSET/TIMESTAMP carry a value") rule, which is the safer default. Coder finding 2.
Severity: low
Status: fixed — named constants `ALL_TYPES`, `VALUELESS_TYPES` and a positive `VALUE_TYPES` allow-list; validation and serialization both key on the positive list.

### R1-3 — `src/VO/OffsetSpec.php:116`
The `in_array($this->type, VALUELESS_TYPES, true)` clause in the serializer is unreachable after the constructor invariant (value-less ⇒ `null`), so the `$this->value === null` short-circuit already returns. Intentional defense-in-depth, not a bug, but dead logic.
Severity: nit
Status: fixed — the serializer now uses the positive `VALUE_TYPES` check, which is the primary type-keyed rule the issue asked for rather than a redundant negative clause.

### R1-4 — `tests/VO/OffsetSpecTest.php:67-97`
Missing edge coverage: value-less types are only tested with value `123`, not `0` (the falsy case a future `if ($value)`/`empty()` refactor would regress); no test for `new OffsetSpec(TYPE_INTERVAL)`; `toArray()` not asserted for `none`. The four value-less types, interval regression and negative-timestamp round-trip are otherwise covered.
Severity: low
Status: fixed — added `testValueLessTypeRejectsZeroValue`, `testIntervalWithoutValueThrows`, `testToArrayForNone`.

### R1-5 — `CHANGELOG.md` `[Unreleased]`
No changelog entry for #520, while sibling bug fixes (#470, #392, #462, #474) all carry `### Fixed` entries under `[Unreleased]`. AGENTS.md schedules the changelog update post-merge, but the repo's PR pattern adds it in-branch; risk of omission.
Severity: low
Status: fixed — `### Fixed` entry added under `[Unreleased]`, mirroring the #470 entry.

### R1-6 — `src/Request/ConsumerUpdateReplyV1.php:49-51` (out of scope, coder finding 1)
`TYPE_TIMESTAMP` is encoded with `addUInt64()`, so a negative timestamp throws in `WriteBuffer::validateInt()` instead of encoding two's complement, unlike `OffsetSpec`'s `addInt64()`. Reachable via `StreamConnection::handleConsumerUpdate()` forwarding a handler's `OffsetSpec::timestamp(-1000)`.
Severity: low
Status: open — out of scope for #520; to be verified as a candidate for a follow-up issue in step 14.

### R1-7 — `src/Request/ConsumerUpdateReplyV1.php:29-39` + `:49-51` (out of scope, coder finding 2)
A non-zero offset supplied for a value-less type is stored but never serialized; `toArray()` reports `null` while the constructor keeps the value, so internal state and emitted/array views disagree.
Severity: low
Status: open — out of scope for #520; to be verified in step 14.

### R1-8 — `src/StreamConnection.php:1132` (out of scope, coder finding 3)
The global `consumerUpdateCallback` return value is destructured as `[$offsetType, $offset]` with no shape/type check. The callback is typed only as `callable`, so a malformed array yields "Undefined array key" warnings and null locals before the `0-5` guard.
Severity: low
Status: open — out of scope for #520; to be verified in step 14.

### R1-9 — `src/VO/OffsetSpec.php:73-75` (out of scope, coder finding 4)
`none()` is documented as ConsumerUpdate-only but the class cannot enforce it; `SubscribeRequestV1`/`ResolveOffsetSpecRequestV1` accept any `OffsetSpec`.
Severity: nit
Status: open — out of scope for #520; to be verified in step 14.

## Review 2

Round 2 re-verified every R1 finding against the current HEAD (`0403ba4`) and
hunted for new issues. Full detail in `review-2.md`. Gates all green:
unit 1134 tests / 8506 assertions, PHPStan level 9 clean, PHPCS clean, Rector
clean, `composer lint` clean. Wire bytes verified for all seven types.

### R1 verdicts (R2)

- **R1-1 — fixed.** `TYPE_INTERVAL` is in `VALUE_TYPES`
  (`src/VO/OffsetSpec.php:50-54`); guard `:64` throws on `new OffsetSpec(TYPE_INTERVAL)`;
  `testIntervalWithoutValueThrows` (`tests/VO/OffsetSpecTest.php:120`) passes.
- **R1-2 — partially fixed / still present as residual duplication (nit).**
  Positive `VALUE_TYPES` now drives both guard (`:64`) and serializer (`:124`),
  so the 8-byte-for-value-less wire defect cannot recur from drift. But the
  invalid-type check still keys on the third literal `ALL_TYPES` (`:22-31`,
  guard `:60`), and `VALUELESS_TYPES` (`:38-43`) remains separate, so a stray
  value could still be silently accepted-and-dropped under drift. The accepted
  set could be derived from the other two lists and `ALL_TYPES` deleted.
- **R1-3 — still present (nit).** The clause is now
  `!in_array($this->type, VALUE_TYPES)` (`:124`), still unreachable behind the
  leading `$this->value === null` disjunct given the constructor invariants.
  Comment at `:121-123` misleadingly credits the type check. True type-keyed
  form: `if (!in_array($this->type, self::VALUE_TYPES, true)) { return $buffer; }`.
- **R1-4 — fixed.** `testValueLessTypeRejectsZeroValue` (`:91`),
  `testIntervalWithoutValueThrows` (`:120`), `testToArrayForNone` (`:165`) all
  present; test file 30 tests / 56 assertions OK. `0` covered in both directions.
- **R1-5 — fixed.** `CHANGELOG.md:18` adds the `[Unreleased]` `### Fixed` bullet
  for #520, accurately naming `offset`/`timestamp`/`interval` as value-carrying.
- **R1-6 — still present (out of scope).** `ConsumerUpdateReplyV1.php:49-50`
  still `addUInt64()` for `TYPE_TIMESTAMP`; reproduced: offset `-1000` throws
  `Value -1000 is out of range for uint64`. Follow-up issue candidate.
- **R1-7 — still present (out of scope).**
  `new ConsumerUpdateReplyV1(0x0001, TYPE_FIRST, 123)` reports `offset => null`
  while the constructor keeps `123` and the wire omits it. Follow-up candidate.
- **R1-8 — still present (out of scope).** `src/StreamConnection.php:1132`
  still array-destructures the `onConsumerUpdate(callable)` return with no shape
  check; a short array emits `Undefined array key` warnings. Follow-up candidate.
- **R1-9 — still present (nit, out of scope).** `none()` docblock
  (`src/VO/OffsetSpec.php:78-84`) claims ConsumerUpdate-only use but the class
  cannot enforce it.

### R2 new finding

- **R2-1 — `docs/en/api-reference/value-objects.md:40` (and `:44`) | low.**
  The public API reference still says the `TYPE_INTERVAL` value requirement "is
  not yet enforced (see issue #468)" and that only `TYPE_OFFSET`/`TYPE_TIMESTAMP`
  are enforced, but `0403ba4` enforces interval (`OffsetSpec.php:53`, `:64`) and
  rejects a value for value-less types (`:70`). Suggested fix: update the
  description and Throws list to match the code. No automated check catches
  prose drift.

Result: R2-1 (low, docs) is the only new issue. The core fix is correct and no
in-tree caller breaks.

## Fix pass after Review 2

- **R1-2 (residual duplication) — fixed.** `ALL_TYPES` is deleted; the
  invalid-type check now derives the accepted set from the two disjoint
  category lists: `!in_array($type, VALUELESS_TYPES, true) && !in_array($type,
  VALUE_TYPES, true)`. Every type is now classified in exactly one list, and
  both validation and serialization follow from those two.
- **R1-3 (serializer guard order) — fixed.** `toStreamBuffer()` now branches on
  the type first (`if (!in_array($this->type, self::VALUE_TYPES, true))`) and
  only then on the nullable value; the type is the primary emission rule. The
  null check remains solely to satisfy PHPStan at level 9 (it cannot infer the
  constructor invariant) and is commented as such.
- **R2-1 (docs drift) — fixed.** `docs/en/api-reference/value-objects.md`
  now states a value is required for `TYPE_OFFSET`/`TYPE_TIMESTAMP`/
  `TYPE_INTERVAL` and rejected for `TYPE_NONE`/`TYPE_FIRST`/`TYPE_LAST`/
  `TYPE_NEXT`, in both the parameter table and the Throws list.

Gates after the fix pass: unit 1134 tests OK; `composer lint`
(PHPCS + Rector dry-run + PHPStan level 9 + kb-lint + docs links) clean.

## Review 3

Round 3 re-verified every item against HEAD `f0a8d78`. Full detail in
`review-3.md`. Gates all green: unit 1134 tests / **8506** assertions, focused
`OffsetSpecTest` 30 tests / 56 assertions, PHPStan level 9 clean (271 files),
PHPCS clean (277 files), Rector dry-run clean, `composer lint` (incl. kb-lint
and `docs/en` link check) clean. Wire bytes re-verified for all seven types.

### R1/R2 verdicts (R3)

- **R1-1 — fixed.** `TYPE_INTERVAL` in `VALUE_TYPES`; `new OffsetSpec(TYPE_INTERVAL)` throws.
- **R1-2 — fixed.** `ALL_TYPES` deleted; invalid-type check is the union of the
  two disjoint lists (`!in_array(VALUELESS) && !in_array(VALUE)`). Drift fails
  closed; no stray value can be silently dropped.
- **R1-3 — fixed / not a real defect.** Type-first branch
  (`src/VO/OffsetSpec.php:110-113`); the residual null guard is unreachable at
  runtime but required by PHPStan level 9 (`?int` cannot be passed to
  `addInt64(int)`/`addUInt64(int)`). Documented in the comment.
- **R1-4 — fixed.** Zero/non-null value-less rejection, interval-null and
  `toArray()`-for-none coverage all present.
- **R1-5 — fixed.** `CHANGELOG.md:18` `[Unreleased]` `### Fixed` bullet.
- **R2-1 — fixed.** `docs/en/api-reference/value-objects.md:40` and `:44` now
  match the enforced constructor; no `#468` "not yet enforced" text remains in
  `docs/en`.
- **R1-6 — still present (out of scope).** `ConsumerUpdateReplyV1.php:49-50`
  `addUInt64()` for `TYPE_TIMESTAMP`; `-1000` throws. Accurately described.
- **R1-7 — still present (out of scope).** Non-zero offset for a value-less type
  retained but nulled in `toArray()` and omitted on the wire. Accurately described.
- **R1-8 — still present (out of scope).** `StreamConnection.php:1132` unvalidated
  callback destructure. Accurately described.
- **R1-9 — still present (nit, out of scope).** `none()` docblock not enforced.
  Accurately described (cited lines are a few off; substance correct).

### New findings (R3)

None in scope. Non-blocking record note: `findings-review.md:125` records the
post-fix gate as 8510 assertions; the actual count is 8506 (`f0a8d78` changed
no test file). The gate is green; only the recorded number is off.

Verdict: **Code looks good, no issues to fix.**
