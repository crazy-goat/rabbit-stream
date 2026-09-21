# Code decision 2 — #381 review round 1 fixes

Follows `code-decision-1.md`. Addresses the findings in `review-1.md` /
`findings-review.md`.

## R1-1 — pre-3.11 fallback (medium): broker version gate

`Connection::create()` already held the `PeerPropertiesResponseV1` and discarded
it. It now reads the `version` peer property through the new
`brokerSupportsCommandVersions()` and only sends `ExchangeCommandVersions` when
the broker reports RabbitMQ >= 3.11:

- RabbitMQ reports e.g. `"4.1.2"`. The gate splits on `.`, requires a numeric
  major and minor (`"3.11"` and `"3.11.0"` both pass), and compares
  `major > 3 || (major === 3 && minor >= 11)`.
- A **missing or unparseable** version is treated as "not supported" and the
  exchange is skipped. That mirrors the reference Go client
  (`availableFeatures.SetVersion()` + `Is311OrMore()`), where an empty/invalid
  version also skips the exchange. It is the safe direction: never send `0x001b`
  to a broker whose age we cannot establish.
- When skipped, `setCommandVersions([])` is still called, so the connection is
  in the documented v1-baseline state and `create()` succeeds.

The gate is confirmed against a real RabbitMQ 4 broker: the E2E
`testCreateNegotiatesCommandVersionsAgainstTheBroker` still sees a non-empty
negotiated map, which is only possible if the `version` property parsed as
>= 3.11.

### Server-initiated `Close` in reply to `0x001b`

The reviewer asked that a `Close` reply not leave a half-dead connection. This
was already handled by the existing infrastructure and needed no change:
`0x0016` is in `StreamConnection::SERVER_PUSH_KEYS`, so `readResponse()`
dispatches it to `handleServerClose()` (echoes the Close response, then
`close()`), and then throws `ConnectionException("Connection closed by
server")`. `negotiateCommandVersions()` catches only `ProtocolException |
DeserializationException | TimeoutException`, so the `ConnectionException`
propagates and `create()` fails loudly instead of returning a connection the
broker is tearing down. The version gate means a real RabbitMQ < 3.11 never even
gets there.

## R1-2 — uncorrelated handshake read (low): abandoned-correlation discard

The reviewer suggested using `StreamConnection::request()`. That alone does
**not** fix the reported desync: `request()` abandons its correlation id when it
times out, and the stale reply is then returned by the next *uncorrelated*
`readMessage()` — which is what every `Connection` management method
(`createStream()`, `deleteStream()`, ...) uses. `request()` only protects later
*correlated* reads.

The actual fix is targeted:

- `StreamConnection::abandonCorrelation(int $correlationId)` records an id whose
  request timed out. `negotiateCommandVersions()` calls it only on
  `TimeoutException` (a protocol rejection / malformed frame means the reply was
  already consumed, so there is nothing in flight).
- `readResponse()` now discards (and logs at warning level) any response whose
  correlation id was abandoned, before either returning it to an uncorrelated
  caller or parking it. So the late frame is neither misattributed nor left in
  `pendingResponses` for the call after that.

Pinned by `StreamConnectionTest::testLateReplyForAnAbandonedCorrelationIdIsDiscarded`
and `ConnectionHandshakeTest::testCreateAbandonsTheExchangeCorrelationIdWhenItTimesOut`.

`abandonCorrelation()` is public (the high-level `Connection` has to call it) and
is documented as an internal wiring seam, consistent with
`setCommandVersions()`/`setMaxFrameSize()` (see R1-9).

## R1-3 — drift guard (low)

`ConnectionHandshakeTest::testClientCommandVersionsCoversEveryClientInitiatedRequestClass`
reflects over `src/Request/*.php`, and for every `KeyVersionInterface` class
whose key is a request-direction key and not a handshake command, asserts the key
is advertised from v1 with `maxVersion >= getVersion()`. A new request class or a
version bump (`PublishRequestV3`) now fails the suite instead of silently not
being advertised. Extra advertised server-initiated keys remain allowed, since
those have no request class.

## R1-4 — 5 s timeout (low)

Fixed by the R1-1 gate: the only broker that would silently ignore the frame and
pay the full 5 s is one older than the command, and that broker is now skipped.
The constant stays 5.0 s as the bound for a *supporting* broker that stalls; it
is not exposed as a configuration knob (no evidence a caller needs to tune it).
Residual, recorded for honesty: a non-RabbitMQ broker that advertises a >= 3.11
version string but does not implement the command would still pay the 5 s.

## R1-5 — interface BC (low)

Added a BC note to the `[Unreleased]` CHANGELOG entry naming both added
`ConnectionInterface` methods.

## R1-6 — doc drift (low)

Updated `guide/connection-lifecycle.md` (6-step handshake, new step-6 section,
high-level and low-level examples, imports), `guide/architecture-overview.md`
(sequence diagram + numbered list) and
`protocol/connection-management-commands.md` (Deliver `maxVersion: 1`).

## R1-7 — `fromArray()` `?? 0` (nit)

`ExchangeCommandVersionsResponseV1::fromArray()` now uses
`TypeCast::toInt($data['correlationId'] ?? 0)`, matching
`PeerPropertiesResponseV1`.

## R1-8/R1-9/R1-10 — not fixed

R1-8 (`PublishRequestV2` validation) is pre-existing #404 and out of scope.
R1-9 (`setCommandVersions()` public setter) is accepted as an existing wiring-seam
pattern; the new `abandonCorrelation()` follows it. R1-10 (duplicate E2E) is
accepted — both tests are meaningful.

## R1-11 — missing edge tests (low)

Added tests for a valid `0x801b` with an empty command list, duplicate/repeated
keys (last-wins), a malformed `min > max` range, and the late-reply desync.

## Uncertainty

- The version gate is exact for RabbitMQ (the only broker the project targets)
  because RabbitMQ always reports a `version` peer property. For a hypothetical
  non-RabbitMQ broker the gate is conservative: an absent version skips the
  exchange (safe) and an advertised >= 3.11 without support would pay the 5 s
  timeout (R1-4 residual).
- `abandonCorrelation()` only fires on a timeout. If a future caller abandons a
  correlated request for another reason, it must call the method itself.
