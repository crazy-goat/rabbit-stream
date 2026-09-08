# Review Round 3 — #470

## VERDICT: CLEAN ✅

## Round-2 fix verification (confirmed)
- `src/Request/ConsumerUpdateReplyV1.php` imports and throws the **library** `CrazyGoat\RabbitStream\Exception\InvalidArgumentException` (which extends native `\InvalidArgumentException`, back-compat holds).
- `tests/Request/ConsumerUpdateReplyV1Test.php` expects the library class. No global `\InvalidArgumentException` throw remains in the branch diff.

## Dispositions
| Entry | Disposition |
|---|---|
| R1: bare-int `offsetType` (constructor validation) | FIXED — throws for 0–5 range violation via `OffsetSpec::TYPE_TIMESTAMP` bound |
| R1: `toArray()` offset for value-less types | FIXED — `null` unless type is OFFSET/TIMESTAMP |
| R1: `OffsetSpec.php:87-91` same-bug-class | NOT FIXED (out of scope, follow-up candidate) |
| R1: timestamp uint64 vs int64 | NOT FIXED (by design, follow-up candidate) |
| R2: global `\InvalidArgumentException` | FIXED — verified |

## QA results
- `composer cs` — pass
- `composer phpstan` (level 9) — OK, no errors (267 files)
- `composer rector` (dry-run) — clean, 0 changes
- `./vendor/bin/phpunit --testsuite unit` — OK (1077 tests, 8196 assertions)
