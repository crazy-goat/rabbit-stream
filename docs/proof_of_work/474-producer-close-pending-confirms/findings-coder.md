# Findings — #474 producer close / pending confirms

## Obstacles

- `vendor/` was absent in the worktree; `composer install` was needed before
  running PHPUnit (the task command list assumes an installed worktree).
- PHPStan level 9 flagged `$registeredCallbacks['onConfirm']` as a possibly
  missing offset inside a test closure (`offsetAccess.notFound`);
  `assertNotNull()` before use fixed it.

## Bugs / weak spots noticed (with suggested fixes)

1. **`src/Client/Producer.php` `close()` (the fixed bug)** — unregistering
   the publisher callback before the `DeletePublisher` exchange dropped
   in-flight confirms. Fixed here by keeping callbacks registered through
   the exchange and adding a bounded drain. Out of scope but related: the
   drain timeout is hard-coded (`CLOSE_CONFIRM_DRAIN_TIMEOUT = 2.0`); if it
   proves too short for large in-flight batches it should become a
   constructor option.
2. **`src/StreamConnection.php:942-969`** — `handlePublishConfirm()` /
   `handlePublishError()` silently *ignore* frames for unregistered
   publisher ids (`isset()` guard). For frames that are not a late confirm
   from a closed producer (e.g. a genuinely unknown publisher id), silence
   hides protocol problems; a debug log or counter would help. The guard is
   still needed for tombstoned producers, so no change made.
3. **`src/Client/Producer.php:191`** —
   `$this->pendingConfirms = max(0, $this->pendingConfirms - count($publishingIds))`
   silently absorbs over-confirmation; a duplicate confirm frame would make
   the counter drift upward over time (never underflow). Low impact, but
   tracking per-publishing-id state would be exact. Suggested fix: log when
   the clamp actually clamps.
4. **`src/Client/Producer.php` `markStale()`** — reports lost confirms as
   failed for ids `publishingId - lost .. publishingId - 1`. If a mix of
   confirmed and unconfirmed messages existed, already-confirmed ids are
   reported as failed too (double-reporting to the app). Rare and benign
   (app treats it as "may resend"), noted for future per-id tracking.
5. **`tests/Client/ProducerTest.php`** — many tests rely on
   `$connection->expects($this->any())->method('sendMessage')` mocks that
   never assert the request type; a `DeletePublisher` sent at the wrong time
   would go unnoticed there. The two new regression tests close the most
   important gap; a mock that records the frame *sequence*
   (sendMessage/readMessage/unregister ordering) would guard more.
