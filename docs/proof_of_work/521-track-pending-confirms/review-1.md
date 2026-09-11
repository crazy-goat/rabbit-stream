# Review Round 1 — #521

## Automated checks
- `composer lint` (PHPCS PSR-12 + Rector dry-run + PHPStan level 9 + kb-lint + docs links): **PASS** — PHPCS 277/277 files, Rector `[OK]`, PHPStan level 9 no errors, `kb-lint OK — 10 entries, 0 warnings, 0 stale`, docs links resolve.
- `./vendor/bin/phpunit tests/Client/ProducerTest.php`: **OK (37 tests, 159 assertions)**
- `./vendor/bin/phpunit --testsuite unit`: **OK (1136 tests, 8517 assertions)**
- Empirical regression guard: both new tests fail against `main`'s `Producer.php` (`Failed asserting that 1 is identical to 2`), pass with the fix.

## VERDICT: no blocker; core logic correct

`onConfirm`/`onError` retire ids via `isset`/`unset` on the id-keyed set
(duplicates and late frames become no-ops), `markStale()` reports exactly
`array_keys()` of the remaining set, backpressure/drain/wait use `count()`, the
#395 "advance only after a successful write" ordering is preserved, and
`getPendingConfirms()` keeps its public signature/semantics.

## Findings

1. **`src/Client/Producer.php:69`** | unbounded memory in fire-and-forget mode
   (`maxPendingConfirms: 0`): every successfully-sent id stays in the set until
   drained. Bounded under the default cap; a documentation/tradeoff gap rather
   than a correctness bug. | **minor** | document the per-id memory tradeoff in
   `performance-tuning.md` / `api-reference/producer.md`.
2. **`src/Client/Producer.php:234`** | the new `onError` dedup branch is
   untested (duplicate `PublishError`, late error after confirm). | **minor** |
   add a test firing a duplicate error and an error for an already-confirmed id.
3. **`docs/proof_of_work/521-<slug>/`** | the four per-cycle proof-of-work files
   were missing. | **minor (process)** | add `findings-coder.md`,
   `findings-review.md`, `code-decision-1.md`, `review-1.md`.
4. **`CHANGELOG.md` `[Unreleased]`** | no #521 entry. | **nit** | add a `### Fixed`
   bullet (required before merge, per the repo workflow).
5. **`tests/Client/ProducerTest.php`** | no `sendBatch`/`sendWithFilter`
   duplicate-confirm coverage. | **nit** | feed a confirm frame with a duplicate
   id after `sendBatch()` and assert each id is reported once.
