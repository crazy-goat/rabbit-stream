# Findings — #381 ExchangeCommandVersions handshake

## Obstacles / surprises

- **`Producer` does not see the high-level `Connection`.** The issue says to
  store the negotiated ranges on `Connection`, but `Producer` is built with a
  `StreamConnection` (`Connection::newProducer()`), so the state had to live on
  `StreamConnection` (as the negotiated frame sizes already do) and be exposed
  on `Connection` by delegation. See `code-decision-1.md` for why this was
  preferred over threading the map through every `Producer` constructor.
- **`willReturnOnConsecutiveCalls` throws on an exhausted list**, it does not
  return `null`. Every "successful handshake" unit test therefore failed with
  `NoMoreReturnValuesConfiguredException` as soon as the extra step was added;
  all of them now append a canned reply (or explicitly exercise the fallback),
  which is what pins the new step in place.
- **The v1 baseline is load-bearing.** `StreamConnection::supportsCommandVersion()`
  returns `true` for version 1 when the command was never negotiated. Without
  that, a negotiation failure would make every version-gated guard say "not
  supported" for the baseline too. It also means unit tests that mock
  `StreamConnection` see a `false` for *every* version — `Producer` tests had
  to stub `supportsCommandVersion()` explicitly to exercise the v2 path.

## Behaviour changes worth calling out

- **`Producer::sendWithFilter($msg, null)` now sends Publish v1, not v2.**
  Previously it always sent v2 and substituted `null` → `''`
  (`src/Client/Producer.php`, old line ~325), which contradicted the docblock
  ("null ... never matches an active filter"): a zero-length filter value can
  match a consumer that filters on the empty string. The protocol says to use
  v1 when there is no filter value, and the existing
  `tests/E2E/SubscribeFilterE2ETest.php` unfiltered case exercises the new
  path. This is a fix, but it is a wire-visible change for anyone who relied on
  publishing an empty filter value via `null`.

## Bugs / weak spots noticed (with suggested fixes)

1. **`src/Client/Connection.php:330` `negotiateCommandVersions()` abandons a
   late reply.** If the 5 s read times out while the broker's `0x801b` frame is
   still in flight, the frame is read later by the next uncorrelated
   `sendMessage()` + `readMessage()` call (e.g. `createStream()`), which then
   sees an unexpected response — and the real response is consumed by whatever
   call comes after. Low probability on a healthy broker, but the handshake path
   uses correlation-less reads. Suggested fix: use
   `StreamConnection::request()` (correlation-matched) for the exchange, and
   have the uncorrelated handshake/management reads park unexpected responses
   instead of returning them.
2. **`src/Client/Connection.php:338` a broker that silently ignores the command
   costs 5 s on every connect.** `COMMAND_VERSION_EXCHANGE_TIMEOUT` is paid in
   full against such a broker. Suggested fix: read the `version` property from
   the `PeerProperties` reply (`PeerPropertiesResponseV1::getPeerProperty()`,
   currently discarded at `src/Client/Connection.php:228`) and skip the exchange
   below RabbitMQ 3.11, as the Go reference client does.
3. **`src/Response/ExchangeCommandVersionsResponseV1.php:74`
   `fromArray()` reads `$data['correlationId']` without `?? 0`.** A payload
   without the key raises a PHP "Undefined array key" warning before
   `TypeCast::toInt(null)` runs. `PeerPropertiesResponseV1::fromArray()`
   (`:64`) uses `?? 0` for exactly this field. Suggested fix:
   `TypeCast::toInt($data['correlationId'] ?? 0)`.
4. **`src/Client/Connection.php:378` `clientCommandVersions()` is a hand-kept
   list.** A new command added to `KeyEnum`/`src/Request/` is advertised only if
   someone remembers to add it here, and the advertised max version is a second
   place to update alongside the request class's `getVersion()`. Suggested fix:
   derive the map from the request classes (a registry of
   `class => getKey()/getVersion()`), or add a unit test that asserts the
   advertised key set matches the `KeyEnum` request cases.
5. **`src/StreamConnection.php:558` `setCommandVersions()` is public and
   mutating.** Any caller can replace the negotiated map with an arbitrary one.
   Not exploitable, but it widens the low-level API. Suggested fix: keep the
   setter package-private to the client layer, or accept it as the same kind of
   wiring seam as `setMaxFrameSize()`.
6. **`src/Request/PublishRequestV2.php:43` `publisherId` is range-checked in
   `toStreamBuffer()`, not the constructor.** Pre-existing (#404 finding,
   still present): `sendWithFilter()` advances nothing before the failing write,
   so it is harmless today, but the failure surfaces after
   `ensureDeclared()`/`applyBackpressure()`. Suggested fix: validate in the
   constructor for fail-fast symmetry.
7. **Two E2E tests now cover the same command.**
   `tests/E2E/ExchangeCommandVersionsTest.php::testExchangeCommandVersions`
   hand-rolls the exchange through `connectAndOpen()`, while the new
   `testCreateNegotiatesCommandVersionsAgainstTheBroker` exercises it through
   `Connection::create()`. Both are legitimate (one pins the raw request/response
   pair, one pins the handshake), but the manual one could be retired once the
   raw-command path has dedicated unit coverage.

## Round 1 review follow-up (new observations)

- **`request()` alone would not have fixed the late-reply desync.** `request()`
  abandons its correlation id on timeout, and the stale frame is still returned
  by the next *uncorrelated* `readMessage()` — which is what every
  `Connection` management method uses. The fix therefore had to be in
  `readResponse()`: a new `abandonCorrelation()` id list makes it drop (not
  park) a late reply for a timed-out request. This was a genuine new finding
  while addressing R1-2.
- **The broker `version` peer property is what makes the R1-1 gate exact for
  RabbitMQ.** Confirmed end-to-end: with the gate in place, the E2E handshake
  still stores a non-empty negotiated map against RabbitMQ 4, so the `version`
  string parses to >= 3.11. An absent/garbage version deliberately skips the
  exchange (safe direction).
- **A server-initiated `Close` reply to `0x001b` was already surfaced, not
  swallowed.** `0x0016` is in `SERVER_PUSH_KEYS`; `handleServerClose()` echoes
  the reply and closes, then `readResponse()` raises `ConnectionException`,
  which `negotiateCommandVersions()` does not catch. No code change was needed
  for that half of R1-1 — only a test/decision note.

