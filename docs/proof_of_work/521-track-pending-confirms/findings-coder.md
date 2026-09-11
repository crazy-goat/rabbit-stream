# Findings — #521 per-publishing-id confirm tracking

## Obstacles / surprises

- The issue's headline ("`markStale()` reports confirmed ids as failed") is not
  fully explained by duplicate-confirm drift alone. The old range was
  `publishingId - pendingConfirms … publishingId - 1`; drift only makes the
  counter too **small**, which *shrinks* the range and can only miss
  outstanding ids, never include a confirmed one. The double-report actually
  requires **out-of-order** confirms: e.g. ids 0–3, confirm 3 then 0, and the
  tail range `[2, 3]` re-reports the already-confirmed 3 while omitting 1. The
  regression test was rebuilt around an out-of-order confirm (plus a duplicate)
  so it pins both symptoms instead of a weaker one.
- The failure mode is only reproducible against the *old* implementation; both
  new tests were verified to fail against `main`'s `Producer.php` (via a
  temporary stash) before the fix.

## Bugs / weak spots noticed (with suggested fixes)

1. **`src/Client/Producer.php:369` `applyBackpressure()` is called before the
   new ids are registered in `sendBatch()`** — a single batch of N messages can
   push the outstanding count to `maxPendingConfirms - 1 + N`, so the cap is not
   a hard bound for batches (only per-frame). Out of scope for #521. Suggested
   fix: document the per-frame semantics, or split/reject a batch that would
   exceed the window.
2. **`src/Client/Producer.php:128` `markStale()` emits failed ids in
   set-insertion order** — the old code emitted ascending ids. Harmless (order
   was never contractual) but a behavioural change; `ksort($lost)` would
   restore ascending order if any caller depends on it.
3. **`src/Client/Producer.php:234` a late/duplicate `PublishError` after
   `markStale()` is now silently ignored** — intended (no double-report), but it
   also drops a genuinely different error code for an id already reported failed
   via the `MetadataUpdate` path. A debug-log would aid diagnosis. Related to
   the "silent drop for unregistered publisher ids" finding from #474
   (`src/StreamConnection.php` `handlePublishError()`), still untracked.
4. **`maxPendingConfirms: 0` memory tradeoff** — per-id tracking makes
   fire-and-forget bookkeeping O(outstanding) rather than O(1) (fixed
   in-scope by documenting, not by code).
