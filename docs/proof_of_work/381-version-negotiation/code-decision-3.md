# Code decision 3 — #381 review round 2 fixes

Follows `code-decision-1.md` and `code-decision-2.md`. Addresses the round-2
findings in `review-2.md` / `findings-review.md` (R2-1..R2-5).

## R2-1 (high) — advertise only multi-version commands

### Problem

`clientCommandVersions()` advertised every command the library implements. The
round-1 version gate decided only *whether* to send `ExchangeCommandVersions`,
never *what* to put in it. RabbitMQ's stream reader runs each advertised key
through `rabbit_stream_core:parse_command_id/1`, which has **no catch-all
clause**: a key the release never defined terminates the connection process with
`error:function_clause` (repeatedly, until `reached_max_restart_intensity`).
`CREATE_SUPER_STREAM`/`DELETE_SUPER_STREAM` (`0x001d`/`0x001e`) crash 3.11–3.12;
`RESOLVE_OFFSET_SPEC` (`0x001f`) crashes 3.13–4.2. Only 4.3+ tolerates the list,
so the single floating CI image (`rabbitmq:4-management`, 4.3.6) was blind to it.

### Fix

`clientCommandVersions()` now returns exactly the commands the client implements
more than one version of — currently only `Publish` (v1–v2):

```php
return [
    new CommandVersion(KeyEnum::PUBLISH->value, 1, 2),
];
```

Rationale:

- A v1-only command needs no advertisement — the v1 baseline already covers it —
  so dropping it loses nothing.
- The keys that crash old brokers are all v1-only today, so the rule removes
  them by construction rather than by an ever-growing deny list.
- `Publish` (`0x0002`) has existed since the first stream protocol, so every
  broker the 3.11 gate admits knows the key. Advertising a v1–v2 range to 3.11 /
  3.12 is safe: the broker answers with 1–1 and the client stays on v1.

The drift guard (R2-2) makes the rule self-enforcing: the advertised set must
equal the set of client-initiated keys whose highest implemented `getVersion()`
is > 1, with min 1 and max equal to that highest version.

### Version fact correction

`Publish` v2 (per-message filter values) was introduced in **RabbitMQ 3.13**, not
3.11. The old `clientCommandVersions()` docblock said 3.11, and
`testCreateNegotiatesCommandVersionsAgainstTheBroker` asserted v2 unconditionally,
so it failed against real 3.11/3.12 brokers. Both fixed: the E2E now asserts the
high-level `supportsCommandVersion(PUBLISH, 2)` matches the broker-reported
range, which keeps it valid on every supported broker.

## R2-2 (nit) — drift guard equality

The guard in `ConnectionHandshakeTest` was rewritten as described above. It
scans `src/Request/*.php`, skips handshake keys and response-direction keys, and
computes `implementedMax[key]`. It then asserts the advertised key set equals
`array_keys(array_filter($implementedMax, fn ($v) => $v > 1))`, each min is 1,
and each max `assertSame`s (not `assertGreaterThanOrEqual`) `implementedMax[$key]`.

## R2-3 (low) — bound `abandonedCorrelationIds`

Three changes:

1. **Only abandon on a read-side timeout.** `negotiateCommandVersions()` now
   tracks a `$sent` flag set after `sendMessage()` returns; the `TimeoutException`
   catch abandons only when `$sent`. A write-side timeout never put a frame on
   the wire, so no reply can arrive and an abandoned entry could never be
   cleared.
2. **Cap the set.** `MAX_ABANDONED_CORRELATION_IDS = 64`, oldest-first eviction
   via `array_key_first()`. Correlation ids are monotonic, so a bound is the only
   hard guarantee; 64 is far above the one id a real handshake can abandon, so
   eviction is unreachable in practice and cannot reintroduce the R1-2 desync.
3. **Clear on `close()`.** A closed connection can never read the late reply, so
   the ids are dead state; clearing prevents a reused/reset instance from
   carrying them.

`abandonCorrelation()` stays public (the high-level `Connection` calls it) and
remains the documented wiring-seam pattern accepted in R1-9.

## R2-4 (nit) — abandon test pins the wire id

The test's `sendMessage` mock now mutates the request's correlation id to 42,
mirroring what the real `StreamConnection::sendMessage()` does, and asserts
`abandonCorrelation(42)`. Previously the request kept the trait default 0, so the
test could not distinguish "abandon called" from "abandon called with the right
id".

## R2-5 (low) — docs

R2-1 restores the "no exception and no failure" contract, so the fallback wording
is now true. The docs were additionally made consistent with the new advertised
set (see `findings-review.md` for the file list): only Publish is advertised, and
`sendWithFilter()`'s `ProtocolException` / 3.13+ requirement is documented.

## Uncertainties / tradeoffs

- **The "multi-version ⇒ safe to advertise" rule is an invariant, not a proof.**
  It holds for Publish because the key predates 3.11. If a future multi-version
  command is introduced for a key a supported broker does not know, the drift
  guard would force advertising it. Mitigation: the guard's expected set is
  derived from request classes, so adding such a class is an explicit,
  reviewable change; a comment in `clientCommandVersions()` records the rule and
  the crash mechanism. A stricter future option is a per-broker-version allow
  list, but that needs a new source of truth and was not warranted for one
  command.
- **`Publish` v2 on 3.11/3.12 is advertised but never negotiated.** That is
  intentional and free: the broker answers 1–1 and `Producer::sendWithFilter()`
  throws for a non-null filter value on those brokers.
- **The abandoned-id cap is best-effort at the boundary.** Evicting an id at 64
  entries could, in a pathological caller that abandons >64 in-flight requests,
  let the oldest late reply desync a read. Not reachable from library code (one
  handshake abandon per connection) and far safer than unbounded growth.
- **Version matrix breadth.** Verified 3.11.28, 3.12.14, 3.13.7, 4.0.9, 4.1.8,
  4.2.9, 4.3.6 (all locally present via Docker). Pre-3.11 (3.10) is gated and
  out of scope here; it was verified by the round-2 reviewer as skip/OK.
