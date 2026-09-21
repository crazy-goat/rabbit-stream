# Code decision — feat(producer): observability for dropped confirms (#522)

## Problem

Two paths discarded publish confirms with no signal:

1. `Producer::drainPendingConfirms()` (`src/Client/Producer.php`) — the
   bounded 2 s close() drain returned `false` on timeout and simply left the
   outstanding ids in `pendingConfirms`. `close()` then returned with
   `getPendingConfirms() > 0`, no log, no callback, no exception. An operator
   could not tell "the broker stopped confirming" from "everything drained".
2. `StreamConnection::handlePublishConfirm()` / `handlePublishError()`
   (`src/StreamConnection.php`) — the `isset($this->publisherCallbacks[$id])`
   guard dropped a frame for an unregistered (tombstoned) publisher id with
   zero output. The guard is intentional (#474 keeps the callback registered
   through the close exchange, then unregisters it), but the silence made a
   healthy tombstone race indistinguishable from a dispatch bug.

## Approach taken

**Both paths now emit a `warning` log line**, matching the existing logger
pattern in `StreamConnection` (`MetadataUpdate`, late replies, unsolicited
responses are all `warning`):

- drain timeout: `src/Client/Producer.php` `drainPendingConfirms()` logs the
  producer id, stream, drain timeout, number lost and the affected publishing
  ids. The message names the producer/stream so the line is actionable.
- unregistered id: `src/StreamConnection.php` `handlePublishConfirm()` /
  `handlePublishError()` log the publisher id, frame type
  (`PublishConfirm`/`PublishError`) and the publishing ids.

**Observability API: a cumulative counter, `Producer::getLostConfirmCount()`.**

The issue offered "a counter or an `onLostConfirms` callback or a method to
read the count". I chose the counter method because:

- it is the smallest non-breaking surface and mirrors the existing
  `getPendingConfirms(): int` introspection method;
- it needs no new constructor callback, no interface change, and no new
  `Connection::createProducer()` parameter;
- it is trivially testable (`$producer->getLostConfirmCount()`), where a
  callback would only be reachable through the discouraged direct
  instantiation or require widening `ProducerInterface` / `ConnectionInterface`
  (a BC break for external implementors, as #381 had to flag);
- the stranded ids remain visible through the existing `getPendingConfirms()`
  after close(), so the counter plus that method covers "how many" and "which".

`Producer` had no logger. `Connection` already owns one and passes it to
`StreamConnection`; `Producer` now takes an optional `?LoggerInterface $logger`
(default `NullLogger`) as the last constructor parameter and
`Connection::newProducer()` passes `$this->logger`. This keeps direct
`new Producer(...)` usage (tests, examples) working and non-noisy.

## Rejected alternatives

- **`onLostConfirms` callback only:** real-time, but not reachable through
  `Connection::createProducer()` without adding a parameter to it and to
  `ConnectionInterface` (BC break), and it is harder to assert in a unit test
  than a counter.
- **Adding `getLostConfirmCount()` to `ProducerInterface`:** forces every
  external implementor to add it. `isStale()` / `getRedeclareCount()` are
  already Producer-only introspection methods, so staying off the interface is
  the established pattern.
- **Reporting lost drain confirms through `onConfirm` as failed
  `ConfirmationStatus`:** would change confirm-dispatch semantics (the issue
  explicitly forbids that) and would fire after the application asked to
  close, double-reporting relative to `markStale()`.
- **`debug` instead of `warning`:** debug records are skipped entirely with
  `NullLogger`/a level-filtering logger, so the "healthy tombstone vs dispatch
  problem" distinction would vanish in the default configuration. Warning is
  the level already used for the neighbouring `MetadataUpdate` and
  late-reply-drop events.
- **A counter on `StreamConnection` for dropped frames:** the requirement's
  observability hook is about *lost confirms* at the producer level; the
  connection path only needs a clear log line (its own test asserts the log and
  that dispatch does not crash). Adding a second counter would be redundant
  state with no consumer.

## Semantics kept unchanged

- The `isset()` tombstone guard still drops the frame: no callback fires, the
  frame still counts as dispatched by `readLoop()`, and dispatch order is
  untouched.
- `drainUntilZero()` is shared with `waitForConfirms()`. The counter is
  incremented in `drainPendingConfirms()` only, so a `waitForConfirms()`
  timeout — which belongs to a still-open producer whose confirms can still
  arrive — does **not** count as lost.
- `close()` remains idempotent (`$this->closed` guard), so the counter cannot
  double-count.
- `pendingConfirms` is deliberately left populated after a timed-out drain:
  the existing #474 test asserts the stranded message stays pending, and
  removing it would be a behaviour change beyond logging/observability.

## Uncertainties / tradeoff

- The connection-path warning fires on every late frame after
  `unregisterPublisher()`. That is exactly the tombstone race #474 introduced,
  so it can be a little noisy at close time. It is bounded (one line per late
  frame) and is the price of visibility; a level-filtering logger can silence
  it. Noted for review.
- `getLostConfirmCount()` is only on `Producer`, not on `ProducerInterface`,
  so a caller holding the interface needs an `instanceof Producer` check —
  consistent with `isStale()` / `getRedeclareCount()`.
