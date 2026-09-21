# Findings — #522 dropped-confirm observability

## Obstacles

- `Producer` had no logger and `StreamConnection` exposes no `getLogger()`
  seam. The clean path was to thread `Connection`'s existing logger into
  `Producer` as an optional trailing constructor parameter; direct
  `new Producer(...)` call sites keep working with the `NullLogger` default.
- The first version of the new `StreamConnectionTest` assertions read
  `$warnings[0]` on a filtered array. `RecordingLogger::warningMessages()`
  preserved the original record keys, so with debug frame logging also enabled
  the warning lived at key 1 and `$warnings[0]` was `null`. Fixed by
  re-indexing the filtered list with `array_values()` in
  `tests/Util/RecordingLogger.php`.
- A `testDispatchPublishErrorIgnoresUnregisteredPublisher` already existed, so
  the new assertions were folded into it rather than adding a duplicate method
  (PHP fatals on a redeclared test method).

## Bugs / weak spots noticed (with suggested fixes)

1. **`src/Client/Producer.php` `drainPendingConfirms()` (the fixed bug)** —
   the bounded 2 s drain silently abandoned outstanding confirms. Now counted
   by `getLostConfirmCount()` and logged at warning level. The stranded ids
   stay in `pendingConfirms`, so `getPendingConfirms()` still reports them
   after `close()`.
2. **`src/StreamConnection.php` `handlePublishConfirm()` /
   `handlePublishError()` (the fixed bug)** — frames for unregistered publisher
   ids were dropped silently by the `isset()` guard. Now logged with publisher
   id + frame type + publishing ids. Out of scope, still open: the drain
   timeout itself is hard-coded (`Producer::CLOSE_CONFIRM_DRAIN_TIMEOUT = 2.0`)
   and cannot be tuned per producer; a slow/large in-flight batch can lose
   confirms that a longer drain would have collected. Suggested fix: expose it
   as an optional `Producer` constructor / `createProducer()` parameter, as
   #474's findings already suggested.
3. **`src/Client/Producer.php` `close()` / `drainUntilZero()`** — on a drain
   timeout the stranded ids are never retired from `pendingConfirms` and the
   callback is unregistered in `finally`, so the producer keeps a permanent
   pending count with no owner. Harmless while the object is discarded after
   close, but `getPendingConfirms()` misleadingly stays > 0. Suggested fix:
   clear the set after recording the loss (would need the #474 test updated).
   Left as-is here to avoid changing semantics beyond observability.
4. **`src/StreamConnection.php` `handlePublishConfirm()` (related, not
   changed)** — a confirm frame can carry thousands of publishing ids
   (`unpack('J*')`); the new warning context embeds the whole id list, so a
   late frame with a huge batch produces a very large log record. A count plus
   the first N ids would bound the log volume. Low impact (late frames are
   rare); flagged for review.
5. **`src/Client/Producer.php` `markStale()`** — still reports the still-
   outstanding ids as failed via `onConfirm`; those are *not* counted as "lost
   confirms" (correctly, since the app is told about them). Worth documenting
   the distinction explicitly, which `getLostConfirmCount()`'s docblock now
   does.

## Test / verification notes

- Unit: `./vendor/bin/phpunit --testsuite unit` → OK (1222 tests).
- Lint: `composer lint` (PHPCS PSR-12 + Rector dry-run + PHPStan level 9 +
  kb-lint + docs link check) → OK.
- E2E: Docker was available; `./run-e2e.sh` → OK (147 tests), covering the
  publish path.
