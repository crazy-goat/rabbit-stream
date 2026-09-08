# Review Round 2 — #474

## QA results
- `composer cs` — OK
- `composer phpstan` (level 9) — OK
- `composer rector` (dry-run) — OK, no suggestions
- `./vendor/bin/phpunit --testsuite unit` — OK (1073 tests, 8203 assertions)

## VERDICT: clean — no new findings

## Round 1 fix verification
- **Shared `drainUntilZero()`** — present at `src/Client/Producer.php:414-425`, used by both `drainPendingConfirms()` (line 408) and `waitForConfirms()` (line 441). The remaining loop in `applyBackpressure` (lines 344-356) has a different condition plus a throw, so leaving it unshared is right.
- **`testCloseGivesUpAfterDrainTimeoutWhenBrokerNeverConfirms`** — present at `tests/Client/ProducerTest.php:1064-1081`, passes.

Non-blocking stylistic notes (not counted as findings): the one-line `drainPendingConfirms()` wrapper is kept deliberately as self-documentation; unchecked `readMessage()` result in `close()` is pre-existing and out of scope.

## Disposition of findings-review.md entries
1. Duplicate drain loop (nit) — **FIXED** (evidence above).
2. Silent drain timeout, no timeout-path test (low) — **FIXED (test)**; logging hook deferred to follow-up.
3. Foreign-publisher confirm burns drain deadline (low) — **NOT FIXED (by design)**, bounded and safe; documented follow-up.
