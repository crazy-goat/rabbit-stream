# Code Decision — issue #444

## Problem

`tests/E2E/ServerInitiatedCloseTest.php` located "its" stream connection in
the RabbitMQ management API by matching `$conn['port'] === self::$port`.
In `/api/connections` the `port` field is the **broker-side** port: every
stream connection on the broker reports the same container-side `5552`,
regardless of which client opened it. The client's ephemeral port is a
different field, `peer_port`. The predicate therefore matched *every* stream
connection, and the test force-closed an arbitrary one — possibly another
client's or a leftover connection — leaving its own connection healthy and
failing the assertion at line ~82 with the misleading "Expected
ConnectionException was not thrown" message.

## Approach chosen

Test-only change, no production code touched. Identify the test's own
connection by **set difference against a snapshot**:

1. Added `private array $preExistingStreamConnectionNames = []`
   (`array<string, true>` set).
2. In `setUp()`, **before** creating the test's own connection, snapshot the
   names of all current stream connections (`getStreamConnectionNames()`),
   then create the connection as before.
3. Added `getStreamConnectionNames(): array<string, true>` which lists
   `/api/connections` via the existing `curlGet()` helper and keeps only
   entries with `protocol === 'stream'` and a non-empty string `name`. The
   `port` predicate is removed entirely.
4. `getStreamConnectionName()` now polls (10s, 1s sleeps, same style as
   before) until a stream connection appears whose name is **not** in the
   snapshot, and returns it. Returns `null` after the timeout as before.
5. Added a comment on the helper explaining that `port` is broker-side and
   why the newly-appeared connection is selected.

The test method body and assertion message are unchanged.

## Rejected alternatives

1. **Match `peer_port` instead of `port`.** `peer_port` *is* the client-side
   ephemeral port, but the test has no direct way to read the local port of
   the socket behind `CrazyGoat\RabbitStream\Client\Connection` — the socket
   is encapsulated in `StreamConnection` and not exposed. Guessing/deriving it
   would require a production API change, which the issue explicitly forbids.
2. **Set a `connection_name` peer property (production change).** The RabbitMQ
   Streams protocol's `peer_properties` supports a `connection_name` key that
   the management API surfaces under `client_properties.connection_name`
   (`/api/connections`). This is the *robust* long-term identifier and would
   make the test deterministic even under concurrent runs, but it requires a
   production API (a way to pass peer properties / a connection name through
   `Connection::create()`), which is out of scope for a test-only bug fix.
   Recorded as a finding instead.
3. **Filter by `vhost`/`user`/`client_properties` heuristics.** All test
   connections share `guest` / `/`; no discriminating field is available
   without extra production metadata. Rejected as unreliable.
4. **Close all stream connections / assume a clean broker.** Would violate the
   "destructive" scoping and break concurrent/leftover connections; also not
   deterministic.

## Uncertainties

- **Two concurrent E2E runs against one broker.** Each run snapshots before
  creating its connection and picks the first newly-appeared stream
  connection. If run A and run B start near-simultaneously, A can pick B's
  connection (and vice versa). A would then force-close B's connection while
  A's own connection stays healthy, and A's `createStream` retry loop would
  not observe a `ConnectionException` — a flake. In CI the `e2e-tests` job
  runs a single suite against a freshly-booted broker, so this does not occur
  today. The `connection_name` peer property (rejected above) is the correct
  fix if concurrent runs are ever needed.
- The snapshot is per-test: PHPUnit calls `setUp()` for each test method, so
  a second test in this class would re-snapshot correctly. This class has a
  single test today.
- Numeric-looking connection names would be coerced to int array keys by PHP.
  Real RabbitMQ stream connection names are of the form
  `"<client-ip>:<port> -> <broker-ip>:5552"`, never purely numeric, so this is
  theoretical; noted for completeness.
