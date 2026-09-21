# Code decision 2 — bound the publishing-id context in dropped-confirm warnings (#522, review round 1)

Addresses review round 1, finding **F1** (Medium). F2/F3 dispositions are in
[findings-review.md](findings-review.md).

## Problem

The #522 observability change put the **complete** list of affected publishing
ids into the PSR-3 context at three sites:

- `StreamConnection::handlePublishConfirm()` unregistered-id warning
- `StreamConnection::handlePublishError()` unregistered-id warning
- `Producer::drainPendingConfirms()` drain-timeout warning

Those lists are not bounded by anything the logger can control:

- a late `PublishConfirm`/`PublishError` is at most `DEFAULT_MAX_FRAME_SIZE`
  (8 MiB) and carries 8 bytes per id (~1,000,000 ids in one frame);
- the drain-timeout list is bounded by `maxPendingConfirms` only when
  backpressure is enabled — with `maxPendingConfirms: 0` (documented
  fire-and-forget mode) `pendingConfirms` is unbounded.

The issue only asked to log "publisher id and frame type". Emitting the full
list turns one warning into a log-volume / memory hazard for exactly the
operators who enabled logging to diagnose a dropped-confirm incident.

## Decision

Log the **count** plus a **bounded prefix** of the ids, never the full list.

- New public constant `StreamConnection::MAX_LOGGED_PUBLISHING_IDS = 10`.
  It lives on `StreamConnection` because that class owns the
  `DEFAULT_MAX_FRAME_SIZE` that motivates the bound; `Producer` reuses the same
  constant so the two log sites cannot drift apart.
- `StreamConnection`:
  - context always carries `publishingIdCount` (the true total) and
    `publishingIds` (`array_slice(..., 0, MAX_LOGGED_PUBLISHING_IDS)`);
  - the message gains a truncation suffix only when the total exceeds the
    bound: ` (N publishing ids in total; only the first 10 are logged)`;
  - `handlePublishError()` slices the `PublishingError` objects **before**
    mapping them to ids, so no intermediate 1M-element int array is built.
- `Producer::drainPendingConfirms()`: context keeps the existing `lostCount`
  and gains a sliced `publishingIds`; the message gains the same truncation
  suffix when `lostCount > MAX_LOGGED_PUBLISHING_IDS`.

The bound is `10`: enough to recognise the shape of a dropped batch (contiguous
ids, first id, id stride) without letting a single record scale with the frame.

## Alternatives rejected

- **Keep the full list, document the risk.** Rejected: the default `NullLogger`
  hides it, so it only bites deployments that enabled logging, which is the
  worst possible failure mode for a diagnostic feature.
- **Log only the count, drop the prefix entirely.** The count alone does not let
  an operator confirm the ids belong to the producer being closed (e.g. that
  the first stranded id matches the last publish). The prefix preserves that.
- **Bound by byte size instead of id count.** More code, no practical gain for
  8-byte ids.
- **Put the bound in a new shared helper/class.** Overkill for one integer; a
  public constant on the class that already defines the frame cap is the
  smallest change that keeps both sites consistent.

## Semantics kept unchanged

- Dispatch is untouched: the tombstone guard still drops the frame, registered
  callbacks still fire, `readLoop()` still counts the frame as dispatched.
- The drain still runs once, `close()` stays idempotent, and the counter is
  still cumulative.
- No log **level** or message **prefix** changed, so existing substring
  assertions (`unregistered publisher id`, `PublishError`, `drain timeout`,
  `unconfirmed`) still hold.

## Tests added

- `StreamConnectionTest::testUnregisteredPublishConfirmWarningContextIsBounded`
- `StreamConnectionTest::testUnregisteredPublishErrorWarningContextIsBounded`
- `ProducerTest::testDrainTimeoutWarningContextIsBounded`

Each feeds a batch larger than the bound and asserts `publishingIdCount` /
`lostCount` equals the true total, `publishingIds` has exactly
`MAX_LOGGED_PUBLISHING_IDS` entries, and the message says `only the first`.
`tests/Util/RecordingLogger.php` gains `warningContexts()` to read the PSR-3
context.
