# Review Round 2 — #470

## QA results
- `composer cs` — OK
- `composer phpstan` (level 9) — OK
- `composer rector` (dry-run) — OK
- `./vendor/bin/phpunit --testsuite unit` — OK (1077 tests, 8196 assertions)

## VERDICT: findings (1 low, fixed in-round)

1. `src/Request/ConsumerUpdateReplyV1.php:34` | Round-1 fix threw the global `\InvalidArgumentException`, but the codebase convention (since #242) is the custom `CrazyGoat\RabbitStream\Exception\InvalidArgumentException` (cf. `src/VO/OffsetSpec.php:41`). A `catch` on the library class would miss it. | **low** | grep-style lint forbidding global `\InvalidArgumentException` in `src/`
   → **FIXED**: now throws the library exception; test updated; all checks green.

No other new issues. Wire format, `toArray()` null handling, and the 12-byte default-reply assertion all confirmed correct.

## Disposition of findings-review.md entries
1. Constructor validation (round 1, low) — **FIXED**, still fixed; exception class corrected this round.
2. `toArray()` offset for value-less types (round 1, nit) — **FIXED**, still fixed.
3. `OffsetSpec.php:87-91` value keyed on `value !== null` (round 1, low) — **NOT FIXED (out of scope)**, confirmed still present; follow-up candidate.
4. Timestamp as uint64 vs spec int64 (round 1, nit) — **NOT FIXED (by design)**, confirmed still present; follow-up candidate.
