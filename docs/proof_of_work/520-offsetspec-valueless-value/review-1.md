# Review 1 — Issue #520: reject and omit values for value-less offset specs

Branch: `feature/issue-520-offsetspec-valueless-value`
Reviewer: `review-critical` (adversarial, round 1)
Scope reviewed: `src/VO/OffsetSpec.php`, `tests/VO/OffsetSpecTest.php`,
`docs/proof_of_work/520-offsetspec-valueless-value/code-decision-1.md`,
`findings-coder.md`, plus the surrounding call sites and the sibling
`ConsumerUpdateReplyV1` fix (#470/#519).

## Verdict

The core fix is **correct**. For every value-less type
(`none`/`first`/`last`/`next`) the constructor now rejects a non-null value and
the serializer emits exactly the 2-byte type field; for `offset`/`timestamp` it
emits 2 + 8 bytes with the right signedness (uint64 / int64 two's complement);
for `interval` it keeps the pre-existing 10-byte encoding. No caller in `src/`,
`examples/` or `tests/` constructs a value-less spec with a value, so the
stricter constructor breaks nothing in-tree. All automated gates pass.

There are **no high-severity findings**. There is one medium invariant gap
(`TYPE_INTERVAL` with a null value is still representable and silently emits a
2-byte frame) and several low/nit items, most of which are pre-existing or the
out-of-scope issues the coder already recorded.

## Verification performed

- Wire format, confirmed by direct serialization:
  - `none`/`first`/`last`/`next` → 2 bytes `pack('n', type)`.
  - `offset(42)` → `0x0004` + 8-byte big-endian uint64.
  - `timestamp(-1000)` → `0x0005` + two's complement (`0005800000000000` ...
    verified via `pack('J', -1000)` and a `ReadBuffer::getInt64()` round-trip).
  - `timestamp(PHP_INT_MIN)` → `00058000000000000000`.
  - `interval(3600)` → `0x0006` + uint64 (10 bytes). `interval(-5)` and
    `offset(-5)` throw `InvalidArgumentException` from
    `WriteBuffer::validateInt()` (uint64 range) — correct, offsets/interval are
    unsigned.
  - `new OffsetSpec(TYPE_FIRST, 0)` and `new OffsetSpec(TYPE_NONE, 0)` **throw**
    (`$value !== null` is the check, so `0` is correctly rejected, not treated
    as "no value").
- Constructor invariants: all seven types accepted; the four value-less types
  reject any non-null value; `offset`/`timestamp` require a value.
- Backwards compatibility: `grep -rn "new OffsetSpec("` over `src`, `tests`,
  `examples` finds direct construction only inside `OffsetSpecTest`; all other
  callers use the `first()`/`last()`/`next()`/`offset()`/`timestamp()` factories.
- Docs: no doc/PHPDoc claims the old (value-emitted-when-present) behavior. The
  `toStreamBuffer()` comment was correctly updated to call out the
  int64-for-timestamp rule and that value-less types short-circuit.
- Automated gates (all pass):
  - `./vendor/bin/phpunit --testsuite unit` → OK (1128 tests, 8495 assertions).
  - `composer phpstan` (level 9) → No errors.
  - `composer cs` (PHPCS PSR-12) → clean.
  - `composer rector` (dry-run) → clean.
  - `composer lint` (incl. `kb-lint`, `check-docs-links`) → clean.

## Decision on `TYPE_INTERVAL` (required by the brief)

**Leaving `TYPE_INTERVAL` value-carrying is the right call for this PR, but it
is a deliberate divergence from the issue text and leaves a real invariant
gap.** Issue #520 says "only TYPE_OFFSET/TYPE_TIMESTAMP carry a value"; taken
literally that would also make `0x0006` value-less and silently drop the value
passed to `OffsetSpec::interval($n)` — regressing the one behavior #468 is
trying to decide. The coder's scope choice is therefore defensible: changing
interval here would pre-empt #468. The cost is the inconsistency captured in
Finding 1 (interval is value-carrying yet value-optional) and the negative-vs-
positive list style of Finding 2. Harmless for live traffic today (interval is
out-of-spec anyway per #468 and no in-tree caller builds a value-less interval),
so it is **not blocking**, but it should be resolved together with #468 rather
than left implicit.

---

## Findings

1. `src/VO/OffsetSpec.php:116` (with constructor `:34-70`) | **`TYPE_INTERVAL`
   with a null value is still representable and silently emits a 2-byte frame.**
   `new OffsetSpec(TYPE_INTERVAL)` passes both constructor guards (interval is
   neither value-less nor in the requires-a-value pair) and `toStreamBuffer()`
   then hits the `$this->value === null` short-circuit, producing `0006` — a
   frame that is neither the 2-byte form of a value-less type (interval is not
   in `VALUELESS_TYPES`) nor the 10-byte type+value interval form the rest of
   the code assumes. With the constructor now enforcing "value-less ⇒ null" and
   "offset/timestamp ⇒ non-null", interval is the one type that violates the
   binary value-carrying/value-less model. | **medium** | Either require a value
   for `TYPE_INTERVAL` as well (add it to the positive requires-value check) so
   the invalid state is unrepresentable, or — if #468 will remove interval —
   add a test pinning the current behavior and a comment. | Check that could
   catch it: none today (PHPStan/tests pass); a `valueLessTypeProvider`-style
   test for `new OffsetSpec(TYPE_INTERVAL)` would. |

2. `src/VO/OffsetSpec.php:38-54`, `:56-63`, `:27-32` | **The type set is
   duplicated three times and the change uses a negative list while the issue
   and the sibling fix use a positive one.** The accepted-type `in_array`
   (7 constants), the requires-a-value pair (`OFFSET`/`TIMESTAMP`) and
   `VALUELESS_TYPES` (4 constants) are independent literals. A future value-less
   type added to the accepted list but forgotten in `VALUELESS_TYPES` would
   re-open exactly the bug class this issue fixes (8-byte value emitted for a
   value-less type); a new value-carrying type added but forgotten in the
   requires-a-value pair would default to value-optional. The issue text and
   `ConsumerUpdateReplyV1::toStreamBuffer()` (#519) both phrase the rule
   positively ("only OFFSET/TIMESTAMP carry a value"), which is the safer
   default. | **low** | Derive one canonical list (e.g. build the accepted list
   from `VALUELESS_TYPES` + the valued set, or use a `match`/backed enum), or
   switch to the positive value-carrying allow-list to match the issue and the
   sibling. | Check that could catch it: none automated; a per-type
   serialization test only covers types that already exist. Coder already noted
   this as finding 2.

3. `src/VO/OffsetSpec.php:116` | **The `in_array($this->type, VALUELESS_TYPES)`
   half of the serializer guard is unreachable.** After the constructor change,
   a value-less type can only hold `null`, so the `$this->value === null`
   short-circuit already returns early. The extra clause is intentional
   defense-in-depth and mirrors `ConsumerUpdateReplyV1`, so this is not a bug,
   but it is dead logic that could mislead a future reader into thinking a
   value-less type can still carry a value here. | **nit** | Keep as
   defense-in-depth (documented in `code-decision-1.md`) or drop it; either is
   fine. | Check that could catch it: none; static analysis will not flag an
   always-true branch like this.

4. `tests/VO/OffsetSpecTest.php:67-97` | **Missing edge coverage.** The
   value-less provider exercises a non-null value of `123` only; `0` (the
   falsy case, which a future refactor to `if ($value)`/`empty()` would
   regress) is not covered. There is also no test for the `TYPE_INTERVAL`
   null-value case from Finding 1, and `toArray()` is only asserted for
   `first`/`offset` (not `none`). The four value-less types, the interval
   regression, and the negative-timestamp round-trip are otherwise well
   covered. | **low** | Add `0` to the rejected-value data set (or a dedicated
   `testValueLessTypeRejectsZeroValue`), add an interval-null test once Finding
   1 is resolved, and optionally a `TYPE_NONE` `toArray()` assertion. | Check
   that could catch it: PHPUnit coverage report.

5. `CHANGELOG.md` `[Unreleased]` | **No changelog entry for #520.** The sibling
   fixes on `main` (`#470`, `#392`, `#462`, `#474`) all have `### Fixed` entries
   under `[Unreleased]`, added on their feature branches. This branch adds none,
   so unless it is added post-merge it will be lost (the `#470` entry and this
   fix are the same defect class and would read well together). AGENTS.md puts
   the changelog update after merge, but the repo's actual PR pattern is to
   include it. | **low** (process/docs) | Add a `### Fixed` bullet mirroring
   the `#470` entry, e.g. "`OffsetSpec` emitted an 8-byte value for value-less
   offset types when constructed directly (#520)". | Check that could catch it:
   none (`kb-lint`/`check-docs-links` do not inspect `CHANGELOG.md`).

6. `src/Request/ConsumerUpdateReplyV1.php:49-51` | **Out-of-scope, verified
   real:** `TYPE_TIMESTAMP` is encoded with `addUInt64()`, so a negative
   timestamp throws in `WriteBuffer::validateInt()` (min 0) instead of encoding
   two's complement. `OffsetSpec` deliberately uses `addInt64()` for the same
   type, and `StreamConnection::handleConsumerUpdate()` forwards the raw int
   from a handler, so `OffsetSpec::timestamp(-1000)` returned by a callback
   reaches this path and throws. Positive timestamps are byte-identical either
   way, so only pre-1970 values break. | **low** | Mirror `OffsetSpec`:
   `addInt64()` when `offsetType === OffsetSpec::TYPE_TIMESTAMP`,
   `addUInt64()` otherwise. | Check that could catch it: a unit test
   constructing `ConsumerUpdateReplyV1` with `TYPE_TIMESTAMP` and a negative
   offset.

7. `src/Request/ConsumerUpdateReplyV1.php:29-39` + `:49-51` | **Out-of-scope,
   verified real:** a non-zero offset supplied for a value-less type is stored
   but never serialized (`toArray()` reports `null`, the wire omits it), so the
   internal state and the emitted/array views disagree. Low impact (no bytes are
   wrong, offset is simply dropped), but it is the same
   "unrepresentable-state" argument this issue applies to `OffsetSpec`. |
   **low** | Reject a non-zero offset for types 0–3 in the constructor, or
   accept an `OffsetSpec` instead of the raw `(type, offset)` pair. | Check that
   could catch it: a unit test constructing the reply with a value-less type and
   a non-zero offset.

8. `src/StreamConnection.php:1132` | **Out-of-scope, verified real:** the global
   `consumerUpdateCallback` return value is destructured as
   `[$offsetType, $offset]` with no shape/type check. The callback is typed only
   as `callable` (docblock says it "must return `[int, int]`"), so a malformed
   array produces "Undefined array key" warnings and null locals before the
   `0-5` type guard. Only reachable with a misbehaving callback; the
   per-subscription handler path is safe (it takes an `OffsetSpec`). | **low** |
   Validate the returned array shape (`array_key_exists`/`is_int`) or type the
   callback to return `OffsetSpec|null` like the per-subscription handler. |
   Check that could catch it: PHPStan cannot, because `callable` is untyped; a
   test invoking the callback with a short array would.

9. `src/VO/OffsetSpec.php:73-75` | **Out-of-scope nit:** `none()` is documented
   as "used only as a ConsumerUpdate reply value, never as a Subscribe offset
   specification", but the class imposes no such restriction and
   `SubscribeRequestV1`/`ResolveOffsetSpecRequestV1` accept any `OffsetSpec`. |
   **nit** | Documentation-only; splitting the value-less types into a separate
   VO/enum would make it unrepresentable (as the coder suggested). | Check that
   could catch it: none.

### Note on the coder's findings

The coder's out-of-scope findings 1–4 are all **real** as written (findings 6–9
above; finding 4 maps to Finding 9). Findings 6 and 7 are worth a follow-up
issue; 8 and 9 are lower priority. None should be fixed inside this PR.
