# #400 TLS support — code decision record

## Approach taken

`StreamConnection` was switched from the ext-sockets API (`\Socket`, `socket_*()`)
to PHP **stream** resources for *both* transports, with a new
`CrazyGoat\RabbitStream\VO\TlsConfig` value object selecting `ssl://` over `tcp://`.

Why not keep ext-sockets for plaintext only and add a stream path for TLS?
There is no way to do TLS over a `\Socket`: ext-openssl only works on stream
resources, and `socket_import_stream()` on an `ssl://` stream reads *ciphertext*
below the SSL layer — any attempt to keep `socket_recv()`/`socket_write()` for
the TLS path desynchronises the SSL state machine. A single shared I/O path
(streams) was therefore the smallest *correct* change, even though it touches
every I/O method.

Key implementation points:

- `connect()` uses `stream_socket_client('ssl://…'|'tcp://…')` with an SSL
  context built from `TlsConfig` (`verify_peer`/`verify_peer_name` default
  **true**, plus optional `cafile`, `local_cert`, `local_pk`, `passphrase`,
  `peer_name`), followed by `stream_socket_enable_crypto()`.
- The stream is put in **non-blocking** mode; every read/write is driven by an
  explicit `stream_select()` loop bounded by `$socketTimeout` (a deadline). This
  preserves the #402 guarantee ("no I/O call may block unboundedly").
- Port 5551 support is simply `Connection::create(port: 5551, tls: new TlsConfig())`;
  the plaintext default port stays 5552 (no BC break).

## What was rejected and why

1. **Keep `\Socket`, add a `stream_socket_enable_crypto()` after
   `socket_connect()`** — impossible; `stream_socket_enable_crypto()` requires a
   stream resource, not a `\Socket`.
2. **`socket_import_stream()` hybrid** (stream for TLS handshake, `\Socket` for
   I/O) — rejected: I/O below the SSL layer reads ciphertext (see above).
3. **Blocking streams + `stream_set_timeout()`** — rejected after a test
   reproduced the failure: PHP's stream layer bounds *reads* with
   `stream_set_timeout()` but a blocking `fwrite()` on a full send buffer blocks
   past the timeout indefinitely (reproduced with the #389 test on an AF_UNIX
   pair). Non-blocking + `stream_select()` deadlines give deterministic timeouts
   for writes too.
4. **Dual code paths** (a `?resource` union of `\Socket`|stream in every method) —
   rejected: doubles the surface of the most subtle code in the library
   (#389/#390 partial-frame handling, #402 timeouts) and both paths would drift.

## Semantics intentionally preserved

- Frame-boundary read timeout with no bytes consumed → `null` (caller retries);
  mid-frame timeout → close + `ConnectionException` (#390).
- Write timeout with nothing sent → `TimeoutException` (retryable); partial
  frame on the wire → close + `ConnectionException` (#389).
- `isConnected()` stays true across benign timeouts. On streams there is no
  sticky socket error state, so the #391 hazard class disappears entirely.

## Uncertainties / follow-ups

- E2E coverage against a real TLS-enabled broker (the issue's last acceptance
  criterion) was **not** implemented in this change (per task scope: unit only).
  Until then, `ssl://` correctness against a live broker is unproven.
- The connect timeout reuses `$socketTimeout` (was: indefinite blocking
  `socket_connect()`). Strictly better, but it is a behaviour change.
- `stream_select()` on `ssl://` streams has a known caveat: a readable state
  does not guarantee decodable plaintext (incomplete TLS record). `readBytes()`
  handles this by retrying empty reads until the deadline instead of treating
  them as EOF — but on a plaintext socket an empty read after a ready state is
  EOF, which is still handled via the `meta['eof']` check.
- PHP's `fwrite()` on a non-blocking stream may internally loop; the write path
  guards with a select before each chunk so this cannot block past the deadline.
