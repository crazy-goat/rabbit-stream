# Code decision — feat(connection): send ExchangeCommandVersions and negotiate command versions (#381)

## Approach taken

`ExchangeCommandVersions` (`0x001b`) already had a request, a response, a
`ResponseBuilder` dispatch and unit tests, but nothing sent it. This change adds
the missing handshake step and wires the result into `Producer`.

### Handshake

`Connection::create()` gained step 7, after `Open` completes and after the
outgoing frame cap is applied:

```php
$streamConnection->setCommandVersions(
    self::negotiateCommandVersions($streamConnection, $logger)
);
```

`negotiateCommandVersions()` sends an `ExchangeCommandVersionsRequestV1` built
from `clientCommandVersions()` and reads the reply under a 5 s bound. It is
advisory:

- `ProtocolException` (a non-OK response code is asserted while the reply is
  deserialized), `DeserializationException`, `TimeoutException`, and any reply
  that is not an `ExchangeCommandVersionsResponseV1` are caught and logged at
  warning level, and the map is left empty;
- `ConnectionException` is **not** caught. A closed socket is not a broker
  "rejecting the command" — there is nothing to fall back to, so setup fails
  loudly as it does for every other handshake step.

`clientCommandVersions()` advertises exactly what the library can send or
receive: Publish v1–v2, every other command v1. Deliver is pinned to v1 on
purpose — `DeliverResponseV1::fromStreamBuffer()` can parse a v2 frame, but
nothing consumes `CommittedChunkId`, so advertising v2 would change delivery
behaviour without a consumer.

### Where the negotiated map lives

The ranges are stored on `StreamConnection` (`setCommandVersions()` /
`getCommandVersions()` / `supportsCommandVersion()`) and exposed on the
high-level `Connection` by delegation, plus on `ConnectionInterface`. This is a
deliberate deviation from the issue's literal wording ("store ... on
`Connection`"):

- `Producer` is constructed with a `StreamConnection`, not the high-level
  `Connection` (`src/Client/Connection.php::newProducer()`), so a map stored
  only on `Connection` would not reach the one caller that needs it;
- the negotiated frame sizes (`setMaxFrameSize`, `setOutgoingMaxFrameSize`, …)
  already live on `StreamConnection` for the same reason, so this matches the
  existing shape;
- the public API the issue asks for *is* on `Connection`
  (`supportsCommandVersion()`, `getSupportedCommandVersions()`).

`supportsCommandVersion()` returns `true` for version 1 whenever a command was
not negotiated at all. That is what turns "the broker does not support the
command" into "fall back to v1 for everything" without every call site having to
special-case an empty map.

### Producer version selection

`Producer::sendWithFilter()` no longer hardcodes Publish v2:

- `$filterValue === null` → `PublishRequestV1` (the protocol says to use v1 when
  there is no filter value);
- non-null value and `supportsCommandVersion(PUBLISH, 2)` → `PublishRequestV2`;
- non-null value and v2 not negotiated → `ProtocolException`.

`send()` and `sendBatch()` stay on v1: they never carry a filter value, so v2
would add a field the caller cannot populate.

## Rejected alternatives

- **Check the broker version from the `PeerProperties` reply and skip the
  exchange below 3.11** (what the Go client does). Robust against a broker that
  closes the connection on an unknown frame, but it adds version parsing and a
  second source of truth, and the library already implements commands newer than
  3.11 (`ResolveOffsetSpec`, `0x001f`) so pre-3.11 brokers are not a supported
  target. The issue explicitly asks for the command to be sent. Kept as a
  fallback-timeout risk in `findings-coder.md`.
- **Store the map on `Connection` and pass it (or a resolver closure) into every
  `Producer`.** Keeps the state on the class the issue names, but adds a
  constructor parameter to a class with many direct instantiations (tests,
  `SuperStreamProducer`) and duplicates what `StreamConnection` can own. The
  delegation keeps one source of truth.
- **Advertise Deliver v1–v2** because the parser accepts both. Rejected: nothing
  reads `CommittedChunkId`, so it would enable a wire path (and a delivery
  behaviour change) with no consumer. Deliberately deferred.
- **Silently publish unfiltered when a non-null `$filterValue` cannot be
  sent.** Rejected: dropping the filter value would correlate the wrong messages
  on the consume side with no signal. A `ProtocolException` is explicit.
- **Catch `ConnectionException` around the exchange too.** Rejected: an empty
  map on a dead socket produces a `Connection` whose very next call fails, which
  is worse than a clear failure at `create()`.

## Uncertainties / tradeoffs

- On a broker that ignores the command instead of answering it, connection setup
  pays the 5 s timeout. On a supporting broker (the E2E image, RabbitMQ 4) the
  reply is immediate. Noted as a finding.
- If the exchange times out but the broker's reply arrives late, that stale
  `0x801b` frame can be read by the next uncorrelated `sendMessage()` +
  `readMessage()` call. The window is 5 s wide; the deeper fix (correlation
  matching on the handshake path) is out of scope. Noted as a finding.
- `supportsCommandVersion()` assumes v1 for a command the broker did not list.
  Correct for this protocol (every command has a v1) but if a future command
  ever ships without v1, the guard would need revisiting.
