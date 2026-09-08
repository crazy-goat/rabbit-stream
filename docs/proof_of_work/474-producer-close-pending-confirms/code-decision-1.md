# Code decision — fix(producer): keep confirm callbacks until in-flight confirms settle (#474)

## Approach taken

`Producer::close()` in `src/Client/Producer.php` used to call
`unregisterPublisher()` **before** sending `DeletePublisher` and reading its
response. Any `PublishConfirm`/`PublishError` frames for messages published
before `close()` were then dropped by the `isset()` guard in
`StreamConnection::handlePublishConfirm()` / `handlePublishError()`, leaving
`$pendingConfirms` stuck above 0 forever.

The fix keeps the confirm callback registered until after the
`DeletePublisher` exchange completes:

1. Send `DeletePublisher`, `readMessage()` — confirms racing with the
   Delete response are dispatched to the still-registered callback by
   `readMessage()`'s transparent server-push handling.
2. `drainPendingConfirms()` — a bounded loop (new constant
   `CLOSE_CONFIRM_DRAIN_TIMEOUT = 2.0s`) calling
   `readLoop(maxFrames: 1, timeout: $remaining)` until `$pendingConfirms`
   reaches 0, so in-flight confirms are counted instead of dropped.
3. `unregisterPublisher()` + `unregisterMetadataUpdateHandler()` moved into
   the existing `finally` block, preserving the old guarantee that handlers
   are unregistered even when the Delete exchange throws, and that the id is
   still released via `onClose` (issue #388 behaviour unchanged).

No wire-level changes, so E2E was not run.

## Rejected alternatives

- **Tombstone counter in `StreamConnection`** (keep a per-publisher counter
  after unregistering, log/flush late confirms): touches the low-level
  connection layer, adds new state, and the callbacks would still not fire —
  the user's `onConfirm` callback would miss late confirms. Rejected as too
  invasive for the same outcome.
- **Fail/warn in `close()` when `pendingConfirms > 0`**: the issue lists it
  as an option, but it turns a fixable situation into an error; the broker
  does confirm in-flight messages during/after the Delete exchange, so
  draining is strictly better.
- **Unbounded drain**: could hang `close()` forever on a broker that never
  confirms (e.g. after a stream deletion we did not see). The drain is
  bounded at 2 s; after that close() gives up and the late confirms are lost
  (documented on the method).

## Uncertainties

- `CLOSE_CONFIRM_DRAIN_TIMEOUT = 2.0` is a judgement call; it is not
  configurable. If real-world brokers need longer to confirm a deep
  in-flight queue, this should become a constructor option later.
- A pathological interleaving is theoretically possible: `pendingConfirms`
  reaching 0, then the broker sending a confirm for an *older* id we already
  counted (duplicate confirm) — that only decrements to 0 via
  `max(0, ...)`, so no underflow, but the callback would report an
  out-of-band `ConfirmationStatus`. Pre-existing behaviour, unchanged.
