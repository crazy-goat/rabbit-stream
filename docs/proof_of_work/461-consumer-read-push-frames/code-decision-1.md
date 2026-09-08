# 461 — Consumer::read()/readOne() vs unrelated server-push frames

## Approach taken

On inspection, the fix demanded by issue #461 is **already implemented and merged
on `main`** by PR #498 ("fix(consumer): read() waits for a real delivery;
request() skips unsolicited Credit responses", commit `57b1528`).

`src/Client/Consumer.php` no longer calls `readLoop(maxFrames: 1, ...)` directly
from `read()`/`readOne()` (the lines cited in the issue, old :82/:102). Both now
delegate to a private `waitForMessages(float $timeout)` (Consumer.php:469)
which loops:

```
$deadline = microtime(true) + $timeout;
while (!$this->hasUnread() && $timeout > 0) {
    if (resubscribeIfLost() fails) { service socket in slices; continue; }
    if ($this->connection->readLoop(maxFrames: 1, timeout: $timeout) === 0) {
        return;              // 0 dispatched = timeout / stop / disconnect
    }
    $timeout = $deadline - microtime(true);
}
```

So an unrelated server-push frame (heartbeat, PublishConfirm, Credit, Deliver
of *another* subscription) returning `1` from `readLoop()` does **not** end the
wait — the loop recomputes the remaining budget against the original deadline
and keeps reading. Only a real delivery (buffer non-empty) or a genuinely
expired deadline ends the wait. This gives the requested semantics without
adding a per-frame filter to `StreamConnection::readLoop()` — the consumer-side
loop is the simpler, sufficient mechanism, and unrelated frames are still
dispatched to their registered callbacks.

## What was rejected and why

- **Adding a frame filter / "only Deliver for this subscription counts toward
  maxFrames" parameter to `StreamConnection::readLoop()`** — rejected: it would
  grow the connection API for a need fully met by the existing deadline loop,
  and would need to special-case Deliver routing by subscription id inside the
  connection layer, mixing responsibilities.
- **Skipping the frame-count budget check (`=== 0`) and relying purely on
  `microtime()`** — already the case in effect; the `=== 0` check is kept only
  as a fast exit when the socket is idle/stop()/disconnected, avoiding a spin.

## Work done in this branch

Since the code fix was already in place, the smallest correct change is
regression coverage of the exact scenarios from the issue, which were missing:
existing tests only covered the *empty* outcome. Added to
`tests/Client/ConsumerTest.php`:

1. `testReadReturnsMessageArrivingAfterNonDeliverFrames` — two unrelated frames
   dispatched first, then a Deliver buffers a message: `read()` returns the
   message, and confirms 3 `readLoop()` calls were needed.
2. `testReadOneKeepsWaitingWhileNonDeliverFramesArrive` — `readOne()` mirrors
   `read()`: unrelated frames alone do not end the wait before the deadline.
3. `testReadOneReturnsMessageArrivingAfterNonDeliverFrames` — success path for
   `readOne()` after one unrelated frame.

## Uncertainties

- Issue #461 may have been intended to be closed by #498 (or #499 which touched
  the same loop); if the maintainers consider it already resolved, this branch
  is purely the missing test coverage + documentation.
- The `readLoop() === 0` early-exit also fires on `stop()`/disconnect, which
  means `read()` can return `[]` before the caller's deadline in those cases —
  intentional and documented in `waitForMessages()`'s docblock.
