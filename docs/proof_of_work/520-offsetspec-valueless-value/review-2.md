# Review 2 — Issue #520: reject and omit values for value-less offset specs

Branch: `feature/issue-520-offsetspec-valueless-value`  
HEAD: `0403ba4` (`git rev-parse HEAD` confirmed)  
Reviewer: `review-critical` (adversarial, round 2)  
Diff reviewed: `git diff main...HEAD` (7 files: `CHANGELOG.md`,
`src/VO/OffsetSpec.php`, `tests/VO/OffsetSpecTest.php`, 4 proof-of-work docs).

## Gates (run on the current HEAD, all green)

| Gate | Command | Result |
|------|---------|--------|
| Unit | `./vendor/bin/phpunit --testsuite unit` | **OK (1134 tests, 8506 assertions)** |
| Static | `composer phpstan` (level 9) | **No errors** (271 files) |
| Style | `composer cs` (PHPCS PSR-12) | **Clean** (277 files) |
| Rector | `composer rector` (dry-run) | **OK, no changes** |
| Full lint | `composer lint` (phpcs+rector+phpstan+kb-lint+links) | **OK** |

## Wire-byte verification (scratch `php -r`, current code)

```
none                       len= 2 hex=0000
first                      len= 2 hex=0001
last                       len= 2 hex=0002
next                       len= 2 hex=0003
offset(42)                 len=10 hex=0004000000000000002a
offset(0)                  len=10 hex=00040000000000000000
timestamp(1700000000000)   len=10 hex=00050000018bcfe56800
timestamp(-1000)           len=10 hex=0005fffffffffffffc18
timestamp(PHP_INT_MIN)     len=10 hex=00058000000000000000
interval(3600)             len=10 hex=00060000000000000e10
offset(PHP_INT_MAX)        len=10 hex=00047fffffffffffffff
interval(PHP_INT_MAX)      len=10 hex=00067fffffffffffffff
offset(-1)                 throws InvalidArgumentException ("out of range for uint64")
interval(-1)               throws InvalidArgumentException ("out of range for uint64")
```

Constructor guards (all throw `InvalidArgumentException` as intended):

```
new O(none,0) / (first,0) / (last,0) / (next,0)      -> "does not accept a value"
new O(none,123) / (first,123) / (last,123) / (next,123) -> "does not accept a value"
new O(offset,null) / (timestamp,null) / (interval,null) -> "requires a value"
new O(999)                                             -> "Invalid offset spec type: 999"
```

`0` is correctly treated as a value (rejected for value-less types) and as a
valid offset (`offset(0)` → 10 bytes), so no truthiness regression. In-tree
callers: `grep -rn "new OffsetSpec" src examples tests` finds direct
construction **only** in `tests/VO/OffsetSpecTest.php`; every non-test caller
uses the `first`/`last`/`next`/`offset`/`timestamp`/`interval` factories, so the
stricter constructor breaks nothing.

---

## Round-1 finding verdicts

R1 statuses were verified against the current code, not taken on trust.

| ID | Verdict | Evidence |
|----|---------|----------|
| **R1-1** — `TYPE_INTERVAL` null value still representable | **Fixed** | `TYPE_INTERVAL` is now in `VALUE_TYPES` (`src/VO/OffsetSpec.php:50-54`), so the requires-a-value guard at `:64-68` fires on `new OffsetSpec(TYPE_INTERVAL)`. Confirmed by `php -r` (throws `... type 6 requires a value`) and pinned by `testIntervalWithoutValueThrows` (`tests/VO/OffsetSpecTest.php:120-126`), which passes. |
| **R1-2** — three independent type literals can drift; negative list vs positive | **Partially fixed / still present as residual duplication (nit)** | The substantive risk is closed: `VALUE_TYPES` (`:50-54`) is a positive allow-list used by **both** the requires-value guard (`:64`) and serializer emission (`:124`), so a value-less type can no longer emit 8 bytes — the #520 wire defect cannot recur from drift. However the round-1 claim ("validation keys on the positive list") is only half true: the invalid-type check still keys on the third literal `ALL_TYPES` (`:22-31`, guard at `:60`), which must equal `VALUE_TYPES ∪ VALUELESS_TYPES`. Drift now fails *closed on the wire* (unknown type → 2 bytes) but would still silently accept-and-drop a stray value because `VALUELESS_TYPES` (`:38-43`) is a separate literal. Low/nit maintainability only — the accepted set could be derived from the two positive/negative lists (`if (!in_array(VALUE) && !in_array(VALUELESS)) throw`) and `ALL_TYPES` deleted. |
| **R1-3** — unreachable serializer clause is dead logic | **Still present (nit)** | The specific `VALUELESS_TYPES` clause is gone, but the guarded condition is `if ($this->value === null || !in_array($this->type, self::VALUE_TYPES, true))` (`:124`). Given the constructor invariants (value-less ⇒ `null`; `VALUE_TYPES` ⇒ non-null), the first disjunct is true for every value-less type and false for every value-carrying one, so the `!in_array(...VALUE_TYPES...)` disjunct can never change the result — still dead logic. The comment at `:121-123` ("rejected in the constructor and guarded by the type check below") reinforces the misreading. Functionally harmless defense-in-depth; the true type-keyed form would be `if (!in_array($this->type, self::VALUE_TYPES, true)) { return $buffer; }`. |
| **R1-4** — missing edge coverage | **Fixed** | `testValueLessTypeRejectsZeroValue` (`tests/VO/OffsetSpecTest.php:91-97`, zero-value case), `testIntervalWithoutValueThrows` (`:120-126`), `testToArrayForNone` (`:165-169`) all present. Full file: 30 tests, 56 assertions, OK. `0` is covered in both directions (rejected for value-less, emitted for offset). |
| **R1-5** — no changelog entry for #520 | **Fixed** | `CHANGELOG.md:18` adds a `### Fixed` bullet under `[Unreleased]`, mirroring the #470 entry and accurately stating that only `offset`/`timestamp`/`interval` carry a value. |
| **R1-6** — `ConsumerUpdateReplyV1` encodes `TYPE_TIMESTAMP` with `addUInt64` | **Still present (out of scope)** | `src/Request/ConsumerUpdateReplyV1.php:49-50` still calls `addUInt64($this->offset)` for both offset and timestamp. Reproduced: `new ConsumerUpdateReplyV1(0x0001, TYPE_TIMESTAMP, -1000)->toStreamBuffer()` throws `InvalidArgumentException: Value -1000 is out of range for uint64`. Reachable via `StreamConnection::handleConsumerUpdate()` forwarding a handler's `OffsetSpec::timestamp(-1000)`. Follow-up issue candidate; do not fix here. |
| **R1-7** — non-zero offset for value-less type stored but dropped | **Still present (out of scope)** | `ConsumerUpdateReplyV1` constructor (`:29-39`) only range-checks the type; `new ConsumerUpdateReplyV1(0x0001, TYPE_FIRST, 123)` reports `toArray()` `offset => null` while the constructor kept `123`, and the wire omits it (`bytes=...0001`). Same unrepresentable-state argument; follow-up candidate. |
| **R1-8** — unvalidated callback return shape in `handleConsumerUpdate` | **Still present (out of scope)** | `src/StreamConnection.php:1132` still does `[$offsetType, $offset] = ($this->consumerUpdateCallback)($query);`. The callback is registered via `onConsumerUpdate(callable)` (`:586-593`, docblock only "must return `[int, int]`"), so a short array yields `PHP Warning: Undefined array key 1` and a `null` offset before the `0-5` guard. Reproduced with a bare array destructure. Follow-up candidate. |
| **R1-9** — `none()` documented ConsumerUpdate-only but unenforced | **Still present (nit, out of scope)** | `src/VO/OffsetSpec.php:78-84`: docblock states "used only as a ConsumerUpdate reply value, never as a Subscribe offset specification", but `TYPE_NONE` is in `ALL_TYPES`/`VALUELESS_TYPES` and `SubscribeRequestV1` (`tests`/`src`) accepts any `OffsetSpec`. Documentation-level only. |

The round-1 "fixed" statuses for R1-1, R1-4 and R1-5 are **confirmed**. R1-2
is over-claimed (a third literal remains) and R1-3 was re-worded rather than
eliminated; both are nit-level. R1-6…R1-9 are correctly out of scope and remain
open for follow-up issues.

---

## NEW findings

1. **`docs/en/api-reference/value-objects.md:40` (and `:44`) | Public API
   reference is now factually wrong about the constructor this branch changed |
   low | Update it.** The `$value` row still reads: *"required (and enforced)
   for `TYPE_OFFSET` and `TYPE_TIMESTAMP`. `TYPE_INTERVAL` also needs a value at
   the protocol level, but that is **not yet enforced** (see issue #468)."* and
   the Throws list still names only `TYPE_OFFSET`/`TYPE_TIMESTAMP`. After
   `0403ba4`, `TYPE_INTERVAL` **is** enforced (`OffsetSpec.php:53`, `:64`), and
   the constructor additionally rejects a value for the value-less types
   (`:70`). The doc is the public contract for `OffsetSpec` and now contradicts
   the code users run; `composer lint`'s link checker cannot catch prose drift,
   so nothing in CI will flag it. Suggested fix: state that a value is required
   for `offset`/`timestamp`/`interval` and rejected for
   `none`/`first`/`last`/`next`, and extend the Throws list accordingly.
   (The sentence was introduced by `88d0ea5`, already on `main`, but this
   branch's `0403ba4` is what makes it false.)

No other new issues found. The core fix is correct, the strictness is
intentional and cannot be triggered by any in-tree caller, and the wire bytes
for all seven types match the protocol.
