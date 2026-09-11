# Findings Review — #521 (appended across rounds)

## Round 1

- `src/Client/Producer.php:69` | fire-and-forget per-id memory is unbounded | minor | **FIXED** — memory tradeoff documented in `docs/en/advanced/performance-tuning.md` ("Memory note") and `docs/en/api-reference/producer.md`; no code change (the cap still bounds the default configuration).
- `src/Client/Producer.php:234` | `onError` dedup branch untested | minor | **FIXED (test)** — added `testDuplicatePublishErrorIsReportedOnceAndLateErrorAfterConfirmIsIgnored`.
- `docs/proof_of_work/521-track-pending-confirms/` | proof-of-work files missing | minor (process) | **FIXED** — `findings-coder.md`, `findings-review.md`, `code-decision-1.md`, `review-1.md` added.
- `CHANGELOG.md` `[Unreleased]` | no #521 entry | nit | **FIXED** — `### Fixed` bullet added.
- `tests/Client/ProducerTest.php` | no batch duplicate-confirm coverage | nit | **FIXED (test)** — added `testBatchConfirmWithDuplicateIdRetiresEachIdOnce`.
- `src/Client/Producer.php:128` | `markStale()` now emits failed ids in set-insertion order, not ascending | nit | **NOT FIXED (by design)** — order was never contractual; `ksort()` is unnecessary. Recorded in `findings-coder.md`.
