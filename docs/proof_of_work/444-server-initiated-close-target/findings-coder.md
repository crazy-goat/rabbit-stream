# Findings — Coder (issue #444)

## Obstacles / surprises

1. **The squatter must actively read frames to stay alive.** The first
   throwaway script that opened a `Connection` and `sleep()`-ed for 120s was
   dropped by the broker after roughly a heartbeat interval: with no
   `readMessage()`/`readLoop()` call, the library never echoes the broker's
   `Heartbeat` (key `0x0017`). The deterministic repro therefore has to issue
   a request/response round-trip periodically (`createStream`/`deleteStream`
   in a loop). Worth knowing for anyone writing a connection-holding helper.
2. **The original bug was confirmed, not just assumed.** With a live squatter
   connected, the pre-fix test failed exactly as the issue described
   (`Expected ConnectionException was not thrown after server-initiated
   close`, `ServerInitiatedCloseTest.php:82`), and after the fix the test
   passed while the squatter connection remained present in
   `/api/connections` afterwards — proving the test now closes its own
   connection, not the squatter.

## Out-of-scope bugs / places to improve

1. **`tests/E2E/ServerInitiatedCloseTest.php:104-120` — snapshot approach is
   not concurrency-safe.** With two E2E runs sharing one broker, each can pick
   the other's newly-appeared connection and force-close it, so a run's own
   connection stays healthy and the test flakes. Suggested fix: give the
   library a way to advertise a unique `connection_name` via the protocol
   `peer_properties` (RabbitMQ surfaces it under
   `client_properties.connection_name` in `/api/connections`), then match on
   that value — deterministic under any concurrency. This needs a small
   production API (`Connection::create()` accepting peer properties / a
   connection name), deliberately not added here per the issue's "test-only"
   constraint.
2. **`tests/E2E/ServerInitiatedCloseTest.php:122-134` — numeric connection
   names would be coerced to int array keys.** The set is typed
   `array<string, true>`; PHP silently converts a numeric-string key to int,
   so a hypothetical name like `"12345"` would break the `?string` return
   contract. Real stream connection names include `" -> "` and IP:port text,
   so this cannot occur in practice. Suggested fix (if hardening):
   `unset`/re-key, or use `array_values()` and `in_array(..., true)`.
3. **`tests/E2E/ServerInitiatedCloseTest.php:156-170` — the management API
   struct is validated field-by-field with `isset`+`is_string`.** Fine, but
   every consumer of `/api/connections` in tests duplicates this. Suggested
   fix (out of scope): a tiny test helper that returns typed connection
   descriptors once, rather than repeating the `is_array`/`isset` dance in
   each test.
