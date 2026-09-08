# Findings Review — #474 (appended across rounds)

## Round 1

- `src/Client/Producer.php:405-415` | duplicate drain loop vs `waitForConfirms()` | nit | **FIXED** — extracted shared `drainUntilZero(float $timeout): bool`; `waitForConfirms()` throws on false, `drainPendingConfirms()` ignores the result.
- `src/Client/Producer.php:408-414` | silent drain timeout, no test for timeout path | low | **FIXED (test)** — added `testCloseGivesUpAfterDrainTimeoutWhenBrokerNeverConfirms`. Logging/observability hook deliberately not added: logger plumbing is out of scope here; candidate for a follow-up issue.
- `tests/Client/ProducerTest.php:975-1017` | foreign-publisher confirm during drain burns the 2 s deadline | low | **NOT FIXED (by design)** — bounded drain is safe; optimising per-publisher filtering belongs to the readLoop dispatch layer, noted in findings-coder.md as a candidate follow-up.
