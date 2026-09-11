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
