# Review Round 1 — #470

## Automated checks
- `composer phpstan` (level 9): OK
- `composer cs` (PHPCS PSR-12): OK
- `composer rector` (dry-run): OK, no suggested changes
- `./vendor/bin/phpunit --testsuite unit`: OK (1075 tests / 8194 assertions)

## VERDICT: clean

Wire format verified against PROTOCOL.adoc: `ConsumerUpdateResponse` key `0x801a`,
`OffsetType => uint16 // 0 (none), 1 (first), 2 (last), 3 (next), 4 (offset), 5 (timestamp)`,
`Offset => uint64 (for offset) | int64 (for timestamp)` — only types 4/5 carry an 8-byte value.

## Findings

1. `src/Request/ConsumerUpdateReplyV1.php:30,43` | Constructor takes a bare `int $offsetType`; types outside 0–5 serialize a protocol violation. Pre-existing API shape, not introduced by this commit. Suggested follow-up: validate 0–5 in the constructor. | **low** | none of the current automated checks; needs a constructor guard
2. `src/Request/ConsumerUpdateReplyV1.php:57` | `toArray()` reports a non-zero `offset` for value-less types 0–3, no longer reflecting the wire data after this change. | **nit** | none (debug-contract consistency)
3. `src/VO/OffsetSpec.php:87-91` (adjacent, unchanged) | `OffsetSpec::toStreamBuffer()` keys value emission on `value !== null`, not on type — same bug class as #470. Worth a follow-up issue. | **low** | none; needs a targeted unit test
4. `src/Request/ConsumerUpdateReplyV1.php:44` | Spec types timestamp offset as `int64` (can be negative); `addUInt64`/`pack('J')` used. Practically irrelevant for epoch-ms; pre-existing behaviour shared with Subscribe/OffsetSpec. | **nit** | none (spec-level nuance)

## Disposition of findings-coder.md entries

1. `toArray()` always reports offset — **still present** → review finding 2.
2. Raw-int constructor without validation — **still present** → review finding 1.
3. `OffsetSpec::toStreamBuffer()` value-keyed on null — **still present** (out of scope) → review finding 3.
4. Silent serialization of invalid offset types — **still present** → review finding 1.
