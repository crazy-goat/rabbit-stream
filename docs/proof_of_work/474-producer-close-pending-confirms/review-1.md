# Review Round 1 — #474

## Automated checks
- `composer phpstan` (level 9): OK, 0 errors
- `composer cs` (PHPCS PSR-12): OK (273 files)
- `composer rector` (dry-run): OK, no suggestions
- `./vendor/bin/phpunit --testsuite unit`: OK (1072 tests, 8200 assertions)

## VERDICT: clean (no blocking findings; 3 low/nit observations)

## Findings

1. **`src/Client/Producer.php:405-415`** | `drainPendingConfirms()` is a near-duplicate of the loop in `waitForConfirms()` (lines 429-436) — same deadline/`readLoop(maxFrames: 1, timeout: $remaining)` pattern, differing only in whether it throws afterwards. Extracting a shared private helper would keep the two in sync. | **nit** | none (pure refactoring; Rector does not flag duplicate loops)
2. **`src/Client/Producer.php:408-414`** | If the drain times out with `pendingConfirms > 0`, close() gives up silently — no log, no exception, `getPendingConfirms()` stays > 0 after close(). Documented behaviour, but no unit test for the timeout path (broker never confirms → close() returns after 2 s with pendingConfirms == 1). | **low** | a test with a `readLoop` mock that never invokes `onConfirm` would have covered it
3. **`tests/Client/ProducerTest.php:975-1017`** | The mock's `readLoop` callback validates ordering, not wire semantics: the real `readLoop` dispatches whatever frame arrives (foreign-publisher confirms, heartbeats) each still consuming a `maxFrames` slot. A foreign confirm during the drain burns the full 2 s deadline even though our confirms arrived earlier. Safe (bounded), suboptimal on shared connections. | **low** | none (design observation; only E2E on a shared connection could surface it)

## Disposition of findings-coder.md entries

1. **The fixed bug (unregister before exchange)** — **fixed** in this commit; verified by `testCloseConfirmsArrivingDuringDeletePublisherExchangeAreNotDropped`.
2. **`handlePublishConfirm/Error` silent drop for unknown publisher ids** — **still present, intentional** (tombstoned producers); the drain window is now the only legitimate source of drops, bounded and documented.
3. **`max(0, …)` absorbs over-confirmation** — **still present** (pre-existing, `Producer.php:196,204`); low impact; needs per-publishing-id tracking.
4. **`markStale()` double-reporting confirmed ids** — **still present** (pre-existing, `Producer.php:120`); benign.
5. **Sequence-recording mock absent** — **partially improved**; new tests assert ordering but not frame sequences.
