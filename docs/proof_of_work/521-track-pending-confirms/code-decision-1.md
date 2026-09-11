# Code decision — fix(producer): track pending confirms per publishing id (#521)

## Approach taken

`Producer` kept `private int $pendingConfirms` and maintained it with
`max(0, $pendingConfirms - count($publishingIds))`. That loses the identity of
the outstanding publishes:

- a duplicate `PublishConfirm` (broker retry, repeated frame) decrements the
  counter below the real number of outstanding publishes, so `waitForConfirms()`
  / `applyBackpressure()` can observe `0` while messages are still unconfirmed;
- `markStale()` cannot name the unconfirmed ids, so it synthesised the failed
  range `publishingId - pendingConfirms … publishingId - 1`. With out-of-order
  confirms that range re-reports already-confirmed ids as failed *and* misses
  genuinely outstanding ones.

The fix replaces the counter with a set keyed by publishing id:
`array<int, true> $pendingConfirms` (`src/Client/Producer.php:67`).

1. `send()`, `sendWithFilter()` and `sendBatch()` register the actual ids in the
   set, still only after a successful write (preserving #395).
2. `onConfirm` and `onError` retire ids via `isset()`/`unset()`; a frame for an
   id that is no longer outstanding (`continue`) is ignored, so duplicates and
   late frames are idempotent. The fatal-code detection in `onError` runs
   *before* the outstanding check, so a fatal `PUBLISHER_NOT_EXIST` /
   `STREAM_NOT_AVAILABLE` still marks the producer stale even when its id was
   already retired.
3. `markStale()` emits `array_keys($pendingConfirms)` — exactly the ids still
   awaiting a confirm — and clears the set.
4. `applyBackpressure()`, `drainUntilZero()`, `waitForConfirms()` and
   `getPendingConfirms()` use `count($pendingConfirms)`, so the public API
   (`getPendingConfirms(): int`, `waitForConfirms()`) is unchanged.

## Rejected alternatives

- **Keep the counter and add a "duplicate detect" guard:** a bare count cannot
  tell a duplicate confirm from a genuine one without also tracking which ids
  are outstanding, which is the per-id set.
- **Fixed-size ring buffer of outstanding ids:** bounds memory but silently
  loses ids once the window wraps, reintroducing exactly the "unknown
  unconfirmed id" problem `markStale()` needs to avoid.
- **Tombstone set of already-confirmed ids:** grows for the producer's whole
  lifetime and needs pruning; tracking the (small) outstanding set is bounded
  by `maxPendingConfirms` in every normal configuration.

## Uncertainties / tradeoff

- Memory is now O(outstanding) instead of O(1). Under a positive
  `maxPendingConfirms` this is bounded by the cap; with
  `maxPendingConfirms: 0` (unlimited/fire-and-forget) it is bounded only by
  what the caller drains. Documented under "Producer Flow Control"
  (`docs/en/advanced/performance-tuning.md`) and in
  `docs/en/api-reference/producer.md`; no separate issue raised.
- `markStale()` now emits ids in set-insertion order rather than ascending.
  Harmless — the order was never part of the contract — and noted in
  `findings-review.md`.
