# 461 — findings

## Obstacles

- None serious. The main "obstacle" was discovering the issue is already fixed:
  `git log -L 469,490:src/Client/Consumer.php` shows `waitForMessages()` was
  introduced by #498 (`57b1528`) and reworked by #499 (`f89026e`), both already
  on `main`. The line numbers in the issue body (`src/Client/Consumer.php:82`,
  `:102`) no longer exist.

## Surprises

- The issue's example code block (`read()` draining `$this->buffer` directly)
  matches a much older revision of `Consumer.php`; the current implementation
  uses `unreadCount` accounting with `bufferHead` (#408) and `drain()`.
- Test coverage gap: #498 added `testReadKeepsWaitingWhileNonDeliverFramesArrive`
  (empty outcome) but no test asserted the *success* path — that a message
  delivered after unrelated frames is actually returned. This branch adds it.

## Bugs noticed (out of scope)

1. **Busy-loop risk on a lost subscription with a long deadline**
   `src/Client/Consumer.php:473-481` — while `resubscribeIfLost()` keeps failing
   (stream deleted), `waitForMessages()` calls `readLoop(maxFrames: 1,
   timeout: $slice)` in a tight loop with no sleep between iterations; each
   `readLoop()` call performs `socket_select()` so it is not a pure CPU spin,
   but every returned frame restarts the cycle. Acceptable, but a small
   `usleep` between failed resubscribe slices would be safer under frame storms.
   Suggested fix: `usleep(1000)` before the `continue` in the
   `!$this->resubscribeIfLost()` branch.

2. **`waitForMessages()` ignores `$timeout <= 0` passed by callers**
   `src/Client/Consumer.php:472` — `read(timeout: 0)` skips the loop (good),
   but a *negative* timeout also skips it, which is fine; however
   `testReadReturnsEmptyArrayOnTimeout` etc. rely on this implicitly. No action
   needed — noting only that the contract "0 = don't block" is undocumented in
   `ConsumerInterface`.

3. **`ConnectionTest`-level: `readLoop()` re-arms `$this->running = true` on
   every call** (`src/StreamConnection.php:838`). After `stop()`, the *next*
   `readLoop()` call resets the stop flag, so a `stop()` issued while a
   consumer is between `readLoop()` calls is silently lost. Suggested fix:
   only set `running = true` in an explicit `start()`/constructor, or document
   that `stop()` only affects the currently running loop.

4. **Duplicate mock setup boilerplate** in `tests/Client/ConsumerTest.php`
   (`makeConnection()` exists but most tests rebuild the same 4-line mock
   inline — e.g. the new tests follow the file's existing pattern for
   consistency; a `makeConnection(['readLoop' => ...])` variant would reduce
   noise file-wide).
